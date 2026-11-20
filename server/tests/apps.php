<?php
declare(strict_types=1);

// php tests/apps.php – the calls the passenger and driver apps make, through the API router, on a throw-away SQLite database.
$GLOBALS['ZD_CONFIG_OVERRIDE'] = ['dsn' => 'sqlite::memory:', 'debug' => true, 'http' => fn(string $u) => str_contains($u, '/route/v1/')
    ? '{"code":"Ok","routes":[{"distance":6400,"duration":1140,"geometry":"abc"}]}' : null];
require dirname(__DIR__) . '/src/bootstrap.php';

use Zd\{Db, Schema, Api, ApiError, Wallet, Auth};

$pass = 0;
function ok(bool $c, string $what): void { global $pass; if (!$c) { fwrite(STDERR, "FAIL: $what\n"); exit(1); } $pass++; echo "  ✓ $what\n"; }
function api(string $m, string $p, array $in = [], ?string $tok = null): array { return Api::handle($m, $p, $in, $tok); }
function err(callable $f): string { try { $f(); return ''; } catch (ApiError $e) { return $e->getMessage(); } }

// old database without the new columns: install must add them
Db::pdo()->exec('CREATE TABLE trips (id INTEGER PRIMARY KEY AUTOINCREMENT, passenger_id INTEGER NOT NULL)');
Schema::install();
$cols = array_column(Db::all('PRAGMA table_info(trips)'), 'name');
ok(in_array('passenger_rating', $cols, true) && in_array('note', $cols, true) && in_array('service', $cols, true), 'upgrade adds missing columns to an old table');
Db::pdo()->exec('DROP TABLE trips'); Schema::install();

echo "Sign in\n";
$c = api('POST', '/api/auth/otp', ['phone' => '0300 1112223'])['debug_code'];
ok(err(fn() => api('POST', '/api/auth/verify', ['phone' => '03001112223', 'code' => $c, 'role' => 'passenger'])) === 'Enter your name', 'new passenger is asked for a name; the same code still works after');
$p = api('POST', '/api/auth/verify', ['phone' => '03001112223', 'code' => $c, 'role' => 'passenger', 'name' => 'Ahmad'])['token'];
$c = api('POST', '/api/auth/otp', ['phone' => '03111112223'])['debug_code'];
$d = api('POST', '/api/auth/verify', ['phone' => '03111112223', 'code' => $c, 'role' => 'driver', 'name' => 'Imran', 'driver' => ['vehicle' => 'economy', 'plate' => 'LEB 4521']])['token'];
$me = api('GET', '/api/me', [], $d);
ok($me['driver']['verified'] == 0 && $me['driver']['blocked'] !== '', 'new driver waits for checks: ' . $me['driver']['blocked']);
$did = $me['id'];
Db::run('UPDATE drivers SET verified = 1 WHERE user_id = ?', [$did]);
Db::run('UPDATE users SET created_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 86400 * 40), $did]);
$tid = api('POST', '/api/wallet/topups', ['method' => 'jazzcash', 'tid' => '041277312290', 'amount' => 1000], $d)['topup_id'];
$admin = Auth::createStaff('admin', 'Admin', 'admin@example.test', 'long-enough-pass', null);
Wallet::decideTopup($tid, true, $admin);
api('POST', '/api/driver/online', ['online' => 1], $d);
api('POST', '/api/driver/location', ['lat' => 34.005, 'lng' => 71.52], $d);
ok(api('GET', '/api/me', [], $d)['driver']['blocked'] === '', 'driver online with Rs 1,000 wallet');
ok(err(fn() => api('POST', '/api/driver/location', ['lat' => 51.5, 'lng' => -0.1], $d)) === 'Location outside Pakistan', 'location outside Pakistan refused');

echo "Booking\n";
$rt = api('POST', '/api/route', ['from_lat' => 34.0086, 'from_lng' => 71.5249, 'to_lat' => 33.992, 'to_lng' => 71.45, 'service' => 'ride', 'vehicle' => 'economy'], $p);
ok($rt['km'] == 6.4 && $rt['guide']['suggested'] > 0, 'route 6.4 km, suggested Rs ' . $rt['guide']['suggested']);
$t = api('POST', '/api/trips', ['service' => 'ride', 'vehicle' => 'economy', 'from' => 'Shami Road', 'to' => 'Hayatabad Medical Complex', 'from_lat' => 34.0086, 'from_lng' => 71.5249, 'to_lat' => 33.992, 'to_lng' => 71.45, 'offer' => $rt['guide']['suggested'], 'note' => 'Gate 2'], $p);
ok($t['status'] === 'searching' && $t['note'] === 'Gate 2', 'trip searching with note');
ok(api('GET', '/api/my/active', [], $p)['trip']['id'] == $t['id'], 'passenger active trip found');
$req = api('GET', '/api/driver/requests', [], $d)['requests'];
ok(count($req) === 1 && $req[0]['passenger_name'] === 'Ahmad' && $req[0]['pickup_km'] > 0 && $req[0]['pickup_km'] < 3, 'driver sees request, pickup ' . ($req[0]['pickup_km'] ?? '?') . ' km');
$oid = api('POST', '/api/trips/' . $t['id'] . '/offers', ['price' => $t['offer'] + 20], $d)['offer_id'];
$offers = api('GET', '/api/trips/' . $t['id'] . '/offers', [], $p)['offers'];
ok(count($offers) === 1 && $offers[0]['name'] === 'Imran', 'passenger sees the counter offer');
ok(api('GET', '/api/trips/' . $t['id'], [], $p)['offers_open'] === 1, 'trip view counts open offers');
api('POST', '/api/offers/' . $oid . '/accept', [], $p);
$v = api('GET', '/api/trips/' . $t['id'], [], $p);
ok($v['status'] === 'accepted' && $v['driver']['plate'] === 'LEB 4521' && $v['driver']['lat'] == 34.005, 'passenger sees driver, plate and position');
ok(api('GET', '/api/my/active', [], $d)['trip']['id'] == $t['id'], 'driver active trip found');
ok(err(fn() => api('POST', '/api/trips/' . $t['id'] . '/rate', ['stars' => 5], $p)) === 'Rate after the trip is finished', 'cannot rate before the end');
foreach (['arrived', 'started', 'completed'] as $s) api('POST', '/api/trips/' . $t['id'] . '/' . $s, [], $d);
$dv = api('GET', '/api/trips/' . $t['id'], [], $d);
ok($dv['status'] === 'completed' && abs($dv['wallet_taken'] - round(($t['offer'] + 20) * 0.06, 2)) < 0.01, 'driver sees 6% taken: Rs ' . $dv['wallet_taken']);
ok(api('GET', '/api/my/active', [], $p)['trip']['status'] === 'completed', 'passenger still sees the finished trip to rate it');
api('POST', '/api/trips/' . $t['id'] . '/rate', ['stars' => 4, 'note' => 'Good'], $p);
ok(api('GET', '/api/my/active', [], $p)['trip'] === null, 'after rating, no active trip');
ok((float) Db::val('SELECT rating FROM drivers WHERE user_id = ?', [$did]) == 4.0, 'driver rating becomes 4.0');
ok(err(fn() => api('POST', '/api/trips/' . $t['id'] . '/rate', ['stars' => 5], $p)) === 'Already rated', 'only one rating per trip');
$today = api('GET', '/api/me', [], $d)['driver']['today'];
ok((int) $today['trips'] === 1, 'driver today: 1 trip, Rs ' . $today['fares']);
echo "History and help\n";
$v = api('GET', '/api/trips/' . $t['id'], [], $p);
ok($v['ride_minutes'] >= 1 && $v['vehicle_label'] === 'Car economy', 'trip detail: ride time and vehicle name');
ok(api('GET', '/api/me', [], $p)['stats']['trips'] === 1, 'passenger stats: 1 trip');
$h = api('POST', '/api/help', ['trip_id' => $t['id'], 'topic' => 'Lost item', 'message' => 'Left my phone charger'], $p);
ok($h['status'] === 'open' && count(api('GET', '/api/help', [], $p)['requests']) === 1, 'help request saved');
ok(err(fn() => api('POST', '/api/help', ['topic' => 'Nonsense', 'message' => 'hello there'], $p)) === 'Choose what the problem is about', 'unknown help topic refused');
api('POST', '/api/trips/' . $t['id'] . '/hide', [], $p);
ok(count(api('GET', '/api/my/trips', [], $p)['trips']) === 0 && count(api('GET', '/api/my/trips', [], $d)['trips']) === 1, 'removed from passenger history only; driver still sees it');
echo "Maps\n";
ok(api('GET', '/api/config')['maps']['provider'] === 'osm', 'no key: apps use OpenStreetMap');
Zd\Settings::set('google_maps_key', 'AIzaTESTKEY0123456789012345678901234567', $admin);
$m = api('GET', '/api/config')['maps'];
ok($m['provider'] === 'google' && str_starts_with($m['google_key'], 'AIza'), 'with a key: apps use Google Maps');
ok(!Db::val("SELECT 1 FROM audit WHERE detail LIKE '%AIzaTEST%'"), 'the key is not written to the audit log');
echo "\n$pass checks passed\n";

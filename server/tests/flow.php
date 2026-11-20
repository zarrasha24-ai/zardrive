<?php
declare(strict_types=1);

// php tests/flow.php – full money flow on a throw-away SQLite database. Exits 1 on the first failure.
$GLOBALS['HTTP_CALLS'] = 0; $GLOBALS['HTTP_DOWN'] = false;
$GLOBALS['ZD_CONFIG_OVERRIDE'] = ['dsn' => 'sqlite::memory:', 'debug' => true, 'http' => function (string $url) {
    if ($GLOBALS['HTTP_DOWN']) return null;
    $GLOBALS['HTTP_CALLS']++;
    return str_contains($url, 'routed-car/route/v1/driving/') ? '{"code":"Ok","routes":[{"distance":6200,"duration":1080,"geometry":"_p~iF~ps|U_ulLnnqC"}]}' : null;
}];
require dirname(__DIR__) . '/src/bootstrap.php';

use Zd\{Db, Schema, Auth, Trips, Wallet, Ledger, ApiError};

$pass = 0;
function ok(bool $c, string $what): void { global $pass; if (!$c) { fwrite(STDERR, "FAIL: $what\n"); exit(1); } $pass++; echo "  ✓ $what\n"; }
function eq(float $a, float $b): bool { return abs($a - $b) < 0.005; }
function fails(callable $f, string $what, string $needle = ''): void {
    try { $f(); } catch (ApiError $e) { ok($needle === '' || stripos($e->getMessage(), $needle) !== false, "$what → \"{$e->getMessage()}\""); return; }
    ok(false, "$what (expected an error)");
}
function signup(string $phone, string $role, string $name, array $drv = []): int {
    $code = Auth::requestOtp($phone);
    return Auth::verifyOtp($phone, (string) $code, $role, $name, $drv)['user']['id'];
}
function oldUser(int $id): void { Db::run('UPDATE users SET created_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 86400 * 40), $id]); }

Schema::install();
Schema::install(); // twice: must be safe
echo "Sign-up\n";
$p1 = signup('0300 1234567', 'passenger', 'Ahmed');
$p2 = signup('+92 301 2223334', 'passenger', 'Bilal');
$d1 = signup('03111111111', 'driver', 'Imran', ['vehicle' => 'economy', 'plate' => 'LEB 4521']);
$d2 = signup('03122222222', 'driver', 'Sajid', ['vehicle' => 'economy', 'plate' => 'PRA 118']);
$b1 = signup('03133333333', 'driver', 'Usman', ['vehicle' => 'bike', 'plate' => 'PSH 77']);
$agent = Auth::createStaff('agent', 'Support Agent', 'agent@example.test', 'long-enough-pass', null);
$admin = Auth::createStaff('admin', 'Admin', 'admin@example.test', 'long-enough-pass', null);
foreach ([$d1, $d2, $b1] as $d) oldUser($d);
fails(fn() => Auth::verifyOtp('03001234567', '0000', 'passenger'), 'old code cannot be reused', 'expired');
fails(fn() => signup('03111111111', 'passenger', 'X'), 'a driver number cannot sign in as passenger', 'registered as');
ok(Auth::staffLogin('admin@example.test', 'long-enough-pass') !== null && Auth::staffLogin('admin@example.test', 'wrong') === null, 'admin password login works and rejects a wrong password');

echo "Driver gates\n";
fails(fn() => Trips::requestsFor($d1), 'unverified driver gets no requests', 'checked');
Db::run('UPDATE drivers SET verified = 1, online = 1');
fails(fn() => Trips::requestsFor($d1), 'empty wallet gets no requests', 'Top up');

echo "Top-ups\n";
$t1 = Wallet::requestTopup($d1, 'jazzcash', '0412 7731 2290', 1000);
fails(fn() => Wallet::requestTopup($d2, 'jazzcash', '041277312290', 1000), 'same TID twice is refused', 'already used');
fails(fn() => Wallet::decideTopup($t1, true, $admin, 900), 'different amount needs a note', 'differs');
Wallet::decideTopup($t1, true, $admin);
fails(fn() => Wallet::decideTopup($t1, true, $admin), 'cannot approve twice', 'Already');
ok(Wallet::balance($d1) == 1000.0, 'Imran wallet Rs 1,000 after approval');
Wallet::decideTopup(Wallet::requestTopup($d2, 'easypaisa', '993044710012', 2000), true, $admin);
Wallet::decideTopup(Wallet::requestTopup($b1, 'easypaisa', '993055127781', 500), true, $admin);
$rej = Wallet::requestTopup($b1, 'jazzcash', '041288201104', 2000);
fails(fn() => Wallet::decideTopup($rej, false, $admin), 'reject needs a reason', 'reason');
Wallet::decideTopup($rej, false, $admin, null, 'Not on statement');
ok(Wallet::balance($b1) == 500.0, 'rejected top-up adds nothing');

echo "Ride with offers\n";
$g = Trips::guide('ride', 'economy', 6.2);
ok($g['suggested'] == 280.0 && $g['min_offer'] == 196.0, 'fare guide 6.2 km economy: Rs 280, minimum offer Rs 196');
fails(fn() => Trips::create($p1, ['service' => 'ride', 'vehicle' => 'economy', 'from' => 'A', 'to' => 'B', 'km' => 6.2, 'offer' => 150]), 'too-low offer refused', 'at least');
fails(fn() => Trips::create($p1, ['service' => 'food', 'vehicle' => 'economy', 'from' => 'A', 'to' => 'B', 'km' => 3, 'offer' => 200]), 'car cannot take food', 'does not serve');
$trip = Trips::create($p1, ['service' => 'ride', 'vehicle' => 'economy', 'from' => 'University Road', 'to' => 'Saddar', 'km' => 6.2, 'offer' => 380]);
fails(fn() => Trips::create($p1, ['service' => 'ride', 'vehicle' => 'economy', 'from' => 'A', 'to' => 'B', 'km' => 2, 'offer' => 300]), 'one active trip per passenger', 'already');
ok(count(Trips::requestsFor($d1)) === 1, 'Imran sees the request');
fails(fn() => Trips::offer($b1, (int) $trip['id'], 380), 'bike cannot answer a car request', 'Wrong vehicle');
fails(fn() => Trips::offer($d1, (int) $trip['id'], 300), 'driver cannot offer below passenger price', 'or offer more');
$o1 = Trips::offer($d1, (int) $trip['id'], 380);
$o2 = Trips::offer($d2, (int) $trip['id'], 420);
ok(count(Trips::requestsFor($d1)) === 0, 'answered request leaves the driver list');
$offers = Trips::offersFor($p1, (int) $trip['id']);
ok(count($offers) === 2 && (float) $offers[0]['price'] === 380.0, 'passenger sees 2 offers, cheapest first');
fails(fn() => Trips::offersFor($p2, (int) $trip['id']), 'other passenger cannot see them', 'Not your trip');
$acc = Trips::accept($p1, $o1);
ok($acc['status'] === 'accepted' && (int) $acc['driver_id'] === $d1 && (float) $acc['fare'] === 380.0, 'accepted at Rs 380 with Imran');
ok(Db::val('SELECT status FROM offers WHERE id = ?', [$o2]) === 'declined', 'Sajid\'s counter offer declined');
fails(fn() => Trips::advance($d1, (int) $trip['id'], 'completed'), 'cannot skip to completed', 'cannot go');
Trips::advance($d1, (int) $trip['id'], 'arrived');
Trips::advance($d1, (int) $trip['id'], 'started');
fails(fn() => Trips::cancel($p1, (int) $trip['id'], 'changed mind'), 'started trip cannot be cancelled', 'cannot be cancelled');
Trips::advance($d1, (int) $trip['id'], 'completed');
ok(Wallet::balance($d1) == 977.2, 'commission 6% of 380 = 22.80 taken: wallet Rs 977.20');

echo "Expired offer\n";
$t2 = Trips::create($p2, ['service' => 'ride', 'vehicle' => 'economy', 'from' => 'Hayatabad', 'to' => 'Board', 'km' => 8, 'offer' => 400]);
$o3 = Trips::offer($d2, (int) $t2['id'], 400);
Db::run('UPDATE offers SET created_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 60), $o3]);
fails(fn() => Trips::accept($p2, $o3), 'offer older than 30 s cannot be accepted', 'expired');
Trips::cancel($p2, (int) $t2['id'], 'too slow');

echo "Food delivery split\n";
$f = Trips::create($p2, ['service' => 'food', 'vehicle' => 'bike', 'from' => 'Restaurant, Saddar', 'to' => 'Gulbahar', 'km' => 3, 'offer' => 200], $agent);
$of = Trips::offer($b1, (int) $f['id'], 200);
Trips::accept($p2, $of);
foreach (['arrived', 'started', 'completed'] as $s) Trips::advance($b1, (int) $f['id'], $s);
ok(Wallet::balance($b1) == 460.0, 'food DC 200: ZarDrive 20% (40) off rider wallet, rider keeps 160 cash');
ok(Wallet::balance($agent) == 0.0, 'no agent share, even when an agent handled the order');
$f2 = Trips::create($p1, ['service' => 'food', 'vehicle' => 'bike', 'from' => 'Restaurant', 'to' => 'Home', 'km' => 2, 'offer' => 150]);
Trips::accept($p1, Trips::offer($b1, (int) $f2['id'], 150));
foreach (['arrived', 'started', 'completed'] as $s) Trips::advance($b1, (int) $f2['id'], $s);
ok(Wallet::balance($b1) == 430.0, 'second food order: 20% (30) to ZarDrive');

echo "Passes\n";
$dp = Wallet::buyPass($d1, 'day');
ok($dp['price'] == 250.0 && Wallet::balance($d1) == 727.2, 'car day pass Rs 250 from wallet');
fails(fn() => Wallet::buyPass($d1, 'week'), 'cannot stack passes', 'already');
$t3 = Trips::create($p2, ['service' => 'ride', 'vehicle' => 'economy', 'from' => 'X', 'to' => 'Y', 'km' => 5, 'offer' => 300]);
Trips::accept($p2, Trips::offer($d1, (int) $t3['id'], 300));
foreach (['arrived', 'started', 'completed'] as $s) Trips::advance($d1, (int) $t3['id'], $s);
ok(Wallet::balance($d1) == 727.2, 'trip on a day pass: 0% commission');
$wp = Wallet::buyPass($d2, 'week');
ok($wp['price'] == 1500.0 && Wallet::balance($d2) == 500.0, 'week pass = 6 × Rs 250 = Rs 1,500');
ok(eq(Ledger::balance('4200'), -464.29) && eq(Ledger::balance('2300'), -1285.71), 'week pass: day 1 (214.29) earned, rest unearned');
Wallet::recognisePasses();
ok(eq(Ledger::balance('4200'), -464.29), 'running the daily job again earns nothing extra the same day');

echo "Launch offer\n";
$d3 = signup('03144444444', 'driver', 'New Driver', ['vehicle' => 'economy']);
Db::run('UPDATE drivers SET verified = 1, online = 1 WHERE user_id = ?', [$d3]);
Wallet::decideTopup(Wallet::requestTopup($d3, 'jazzcash', '041299990001', 500), true, $admin);
$t4 = Trips::create($p1, ['service' => 'ride', 'vehicle' => 'economy', 'from' => 'X', 'to' => 'Y', 'km' => 5, 'offer' => 300]);
Trips::accept($p1, Trips::offer($d3, (int) $t4['id'], 300));
foreach (['arrived', 'started', 'completed'] as $s) Trips::advance($d3, (int) $t4['id'], $s);
ok(Wallet::balance($d3) == 500.0, 'new driver in first 30 days pays 0%');

echo "Maps (free OSRM)\n";
$rt = Zd\Maps::route(34.0010, 71.4850, 34.0090, 71.5450, 'economy');
ok($rt['km'] == 6.2 && $rt['minutes'] === 18 && $rt['source'] === 'osrm', 'OSRM route: 6.2 km, 18 min');
Zd\Maps::route(34.0010, 71.4850, 34.0090, 71.5450, 'bike');
ok($GLOBALS['HTTP_CALLS'] === 1, 'same route again comes from cache (1 OSRM call only)');
fails(fn() => Zd\Maps::route(51.5, -0.1, 34.0, 71.5), 'point outside Pakistan refused', 'outside Pakistan');
$GLOBALS['HTTP_DOWN'] = true;
$fb = Zd\Maps::route(34.0200, 71.5000, 34.0300, 71.5600, 'economy');
ok($fb['source'] === 'estimate' && $fb['km'] > 5 && $fb['km'] < 9, 'OSRM down: straight-line estimate used (' . $fb['km'] . ' km)');
$GLOBALS['HTTP_DOWN'] = false;
$tc = Trips::create($p2, ['service' => 'ride', 'vehicle' => 'economy', 'from' => 'A', 'to' => 'B', 'km' => 1, 'offer' => 300, 'from_lat' => 34.0010, 'from_lng' => 71.4850, 'to_lat' => 34.0090, 'to_lng' => 71.5450]);
ok((float) $tc['km'] == 6.2 && $tc['route']['cached'] === true, 'trip with map points uses server distance (6.2 km), not the phone\'s 1 km');
Trips::cancel($p2, (int) $tc['id'], 'test');

echo "Refund\n";
fails(fn() => Wallet::refund($d2, 10000, 'bank', $admin, 'leaving'), 'refund above balance refused', 'not more');
Wallet::refund($d2, 100, 'bank', $admin, 'Partial refund requested');
ok(Wallet::balance($d2) == 400.0, 'refund Rs 100 paid back');

echo "Books\n";
$tb = Ledger::trial();
$dr = array_sum(array_column($tb, 'dr')); $cr = array_sum(array_column($tb, 'cr'));
ok(abs($dr - $cr) < 0.001, 'trial balance equal: Dr ' . money($dr) . ' = Cr ' . money($cr));
$drivers = 0.0; foreach ([$d1, $d2, $b1, $d3] as $d) $drivers += Wallet::balance($d);
ok(abs($drivers + Ledger::balance('2100')) < 0.001, 'driver wallets in app (' . money($drivers) . ') = ledger 2100');
ok(Ledger::balance('2200') == 0.0, 'nothing booked to agent shares');
ok(Ledger::balance('4100') == -22.8 && Ledger::balance('4150') == -70.0, 'income: commission 22.80, food share 70.00 (40 + 30)');
ok(Ledger::balance('1010') == 1500.0 && Ledger::balance('1011') == 2500.0 && Ledger::balance('1020') == -100.0, 'cash: JazzCash 1,500 · Easypaisa 2,500 · bank −100 refund');
foreach ($tb as $a) foreach (Db::all('SELECT journal_id, SUM(debit) d, SUM(credit) c FROM journal_lines GROUP BY journal_id HAVING ABS(SUM(debit) - SUM(credit)) > 0.001') as $bad) ok(false, 'unbalanced journal ' . $bad['journal_id']);
ok((int) Db::val('SELECT COUNT(*) FROM journal') === (int) Db::val('SELECT COUNT(DISTINCT event || ref) FROM journal'), 'no journal posted twice');

echo "\nAll $pass checks passed.\n";

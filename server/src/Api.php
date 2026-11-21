<?php
declare(strict_types=1);

namespace Zd;

/** JSON API for the passenger and driver apps. Every request except sign-in needs "Authorization: Bearer <token>". */
final class Api
{
    public static function handle(string $method, string $path, array $in, ?string $bearer): array
    {
        $r = fn(string $m, string $re) => $method === $m && preg_match('#^' . $re . '$#', $path, $GLOBALS['zd_m']);
        $id = fn() => (int) ($GLOBALS['zd_m'][1] ?? 0);

        if ($r('POST', '/api/auth/otp')) return ['sent' => true, 'debug_code' => Auth::requestOtp((string) ($in['phone'] ?? ''))];
        if ($r('POST', '/api/auth/verify')) return Auth::verifyOtp((string) ($in['phone'] ?? ''), (string) ($in['code'] ?? ''), (string) ($in['role'] ?? ''), (string) ($in['name'] ?? ''), (array) ($in['driver'] ?? []));
        if ($r('GET', '/api/config')) return self::config();

        $u = Auth::fromToken($bearer) ?? throw new ApiError('Please sign in', 401);
        $uid = (int) $u['id'];
        $isDriver = $u['role'] === 'driver';
        $needDriver = function () use ($isDriver) { if (!$isDriver) throw new ApiError('Drivers only', 403); };
        $needPassenger = function () use ($u) { if ($u['role'] !== 'passenger') throw new ApiError('Passengers only', 403); };

        // passenger
        if ($r('POST', '/api/route')) {
            $rt = Maps::route((float) ($in['from_lat'] ?? 0), (float) ($in['from_lng'] ?? 0), (float) ($in['to_lat'] ?? 0), (float) ($in['to_lng'] ?? 0), (string) ($in['vehicle'] ?? 'economy'));
            $svc = (string) ($in['service'] ?? '');
            return $rt + (isset(Schema::SERVICES[$svc]) ? ['guide' => Trips::guide($svc, (string) ($in['vehicle'] ?? ''), $rt['km'])] : []);
        }
        if ($r('GET', '/api/places/search')) return ['places' => Places::search((string) ($in['q'] ?? ''), isset($in['lat']) ? (float) $in['lat'] : null, isset($in['lng']) ? (float) $in['lng'] : null)];
        if ($r('GET', '/api/places/reverse')) return ['place' => Places::reverse((float) ($in['lat'] ?? 0), (float) ($in['lng'] ?? 0))];
        if ($r('POST', '/api/fare-guide')) return Trips::guide((string) ($in['service'] ?? ''), (string) ($in['vehicle'] ?? ''), (float) ($in['km'] ?? 0));
        if ($r('POST', '/api/trips')) { $needPassenger(); return Trips::create($uid, $in); }
        if ($r('GET', '/api/trips/(\d+)/offers')) { $needPassenger(); return ['offers' => Trips::offersFor($uid, $id())]; }
        if ($r('POST', '/api/offers/(\d+)/accept')) { $needPassenger(); return Trips::accept($uid, $id()); }
        if ($r('POST', '/api/trips/(\d+)/cancel')) return Trips::cancel($uid, $id(), (string) ($in['reason'] ?? ''));
        if ($r('GET', '/api/trips/(\d+)')) {
            return Trips::view($id(), $uid);
        }
        if ($r('GET', '/api/me')) {
            $me = ['id' => $uid, 'name' => $u['name'], 'phone' => $u['phone'], 'role' => $u['role'], 'since' => $u['created_at']];
            if (!$isDriver) {
                $last = Db::all('SELECT status, cancel_reason FROM trips WHERE passenger_id = ? AND status IN (\'completed\', \'cancelled\') ORDER BY id DESC LIMIT 20', [$uid]);
                $me['stats'] = ['trips' => (int) Db::val("SELECT COUNT(*) FROM trips WHERE passenger_id = ? AND status = 'completed'", [$uid]),
                    'recent' => count($last), 'cancelled_by_you' => count(array_filter($last, fn($x) => $x['status'] === 'cancelled' && str_starts_with((string) $x['cancel_reason'], 'passenger') && !str_contains((string) $x['cancel_reason'], 'raised fare'))),
                    'rating' => Trips::passengerRating($uid)];
            }
            if ($isDriver) {
                $d = Db::one('SELECT vehicle, plate, verified, online, rating, plan, plan_until FROM drivers WHERE user_id = ?', [$uid]);
                $me['driver'] = $d + ['vehicle_label' => Schema::VEHICLES[$d['vehicle']] ?? $d['vehicle'], 'wallet' => Wallet::balance($uid), 'blocked' => Trips::driverBlock($uid),
                    'passes' => ['day' => Wallet::passPrice($d['vehicle'], 'day'), 'week' => Wallet::passPrice($d['vehicle'], 'week')], 'commission_pct' => Settings::num('commission_pct', 6), 'min_wallet' => Settings::num('min_wallet', 200),
                    'today' => Db::one("SELECT COUNT(*) trips, COALESCE(SUM(fare), 0) fares FROM trips WHERE driver_id = ? AND status = 'completed' AND completed_at >= ?", [$uid, date('Y-m-d 00:00:00')]),
                    'trips_total' => (int) Db::val("SELECT COUNT(*) FROM trips WHERE driver_id = ? AND status = 'completed'", [$uid])];
            }
            return $me;
        }
        if ($r('GET', '/api/my/active')) return ['trip' => Trips::active($uid, $u['role'])];
        if ($r('POST', '/api/trips/(\d+)/rate')) return Trips::rateTrip($uid, $id(), (int) ($in['stars'] ?? 0), (string) ($in['note'] ?? ''));
        if ($r('GET', '/api/my/trips')) {
            $rows = $isDriver
                ? Db::all('SELECT * FROM trips WHERE driver_id = ? ORDER BY id DESC LIMIT 50', [$uid])
                : Db::all('SELECT t.*, u.name driver_name FROM trips t LEFT JOIN users u ON u.id = t.driver_id WHERE t.passenger_id = ? AND t.passenger_hidden = 0 ORDER BY t.id DESC LIMIT 50', [$uid]);
            return ['trips' => $rows];
        }
        if ($r('POST', '/api/trips/(\d+)/hide')) {
            $needPassenger();
            $t = Trips::get($id());
            if ((int) $t['passenger_id'] !== $uid) throw new ApiError('Not your trip', 403);
            if (!in_array($t['status'], ['completed', 'cancelled'], true)) throw new ApiError('Only finished trips can be removed', 409);
            Db::run('UPDATE trips SET passenger_hidden = 1 WHERE id = ?', [$t['id']]);   // hidden from the passenger only; the books keep it
            return ['hidden' => true];
        }
        if ($r('POST', '/api/help')) {
            $topics = ['Lost item', 'Fare or payment', 'Driver behaviour', 'Safety', 'App problem', 'Other'];
            $topic = (string) ($in['topic'] ?? '');
            if (!in_array($topic, $topics, true)) throw new ApiError('Choose what the problem is about', 422);
            $msg = trim((string) ($in['message'] ?? ''));
            if (mb_strlen($msg) < 5) throw new ApiError('Tell us a little more', 422);
            $tripId = isset($in['trip_id']) ? (int) $in['trip_id'] : null;
            if ($tripId) { $t = Trips::get($tripId); if ((int) $t['passenger_id'] !== $uid && (int) $t['driver_id'] !== $uid) throw new ApiError('Not your trip', 403); }
            if ((int) Db::val("SELECT COUNT(*) FROM help_requests WHERE user_id = ? AND created_at >= ?", [$uid, date('Y-m-d H:i:s', time() - 3600)]) >= 5) throw new ApiError('You sent several requests; support will reply soon', 429);
            $hid = Db::insert('help_requests', ['trip_id' => $tripId, 'user_id' => $uid, 'topic' => $topic, 'message' => mb_substr($msg, 0, 1000), 'created_at' => now()]);
            return ['help_id' => $hid, 'status' => 'open'];
        }
        if ($r('GET', '/api/help')) return ['requests' => Db::all('SELECT id, trip_id, topic, message, status, created_at FROM help_requests WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$uid])];

        // driver
        if ($r('POST', '/api/driver/online')) {
            $needDriver();
            Db::run('UPDATE drivers SET online = ?, lat = ?, lng = ? WHERE user_id = ?', [!empty($in['online']) ? 1 : 0, $in['lat'] ?? null, $in['lng'] ?? null, $uid]);
            return ['online' => !empty($in['online']), 'blocked' => Trips::driverBlock($uid)];
        }
        if ($r('POST', '/api/driver/location')) {
            $needDriver();
            $lat = (float) ($in['lat'] ?? 0); $lng = (float) ($in['lng'] ?? 0);
            if ($lat < 23 || $lat > 38 || $lng < 60 || $lng > 78) throw new ApiError('Location outside Pakistan', 422);
            Db::run('UPDATE drivers SET lat = ?, lng = ?, loc_at = ? WHERE user_id = ?', [$lat, $lng, now(), $uid]);
            return ['ok' => true];
        }
        if ($r('GET', '/api/driver/requests')) { $needDriver(); return ['requests' => Trips::requestsFor($uid)]; }
        if ($r('POST', '/api/trips/(\d+)/offers')) { $needDriver(); return ['offer_id' => Trips::offer($uid, $id(), (float) ($in['price'] ?? 0))]; }
        if ($r('POST', '/api/trips/(\d+)/(arrived|started|completed)')) { $needDriver(); return Trips::advance($uid, $id(), $GLOBALS['zd_m'][2]); }
        if ($r('GET', '/api/wallet')) {
            return ['balance' => Wallet::balance($uid), 'plan' => Db::one('SELECT plan, plan_until FROM drivers WHERE user_id = ?', [$uid]),
                'history' => Db::all('SELECT kind, amount, balance_after, note, created_at FROM wallet_tx WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$uid])];
        }
        if ($r('POST', '/api/wallet/topups')) { $needDriver(); return ['topup_id' => Wallet::requestTopup($uid, (string) ($in['method'] ?? ''), (string) ($in['tid'] ?? ''), (float) ($in['amount'] ?? 0)), 'status' => 'pending']; }
        if ($r('POST', '/api/wallet/pass')) { $needDriver(); return Wallet::buyPass($uid, (string) ($in['kind'] ?? '')); }

        throw new ApiError('Not found', 404);
    }

    private static function config(): array
    {
        return [
            'city' => Settings::get('city'), 'services' => Schema::SERVICES, 'vehicles' => Schema::VEHICLES, 'serves' => Schema::SERVES,
            'offer_seconds' => (int) Settings::num('offer_seconds', 30), 'min_wallet' => Settings::num('min_wallet', 200),
            // a browser key is public by design; it is locked to zardrive.zarrasha.com in Google Cloud
            'maps' => ['provider' => Settings::get('google_maps_key') ? 'google' : 'osm', 'google_key' => Settings::get('google_maps_key') ?: null],
        ];
    }
}

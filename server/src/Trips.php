<?php
declare(strict_types=1);

namespace Zd;

/**
 * Fair-offer trips: the passenger offers a fare, drivers accept it or counter, the passenger picks one.
 * Status: searching → accepted → arrived → started → completed (or cancelled).
 */
final class Trips
{
    private const NEXT = ['accepted' => 'arrived', 'arrived' => 'started', 'started' => 'completed'];

    public static function rate(string $service, string $vehicle): array
    {
        return Db::one('SELECT * FROM fare_rates WHERE service = ? AND vehicle = ?', [$service, $vehicle])
            ?? throw new ApiError('This vehicle does not serve ' . (Schema::SERVICES[$service] ?? $service), 422);
    }

    /** Suggested fair range for a trip. */
    public static function guide(string $service, string $vehicle, float $km): array
    {
        $r = self::rate($service, $vehicle);
        $mid = max((float) $r['min_fare'], (float) $r['per_km'] * $km);
        $mid = (float) (ceil($mid / 10) * 10);
        return ['suggested' => $mid, 'low' => max((float) $r['min_fare'], $mid - 40), 'high' => $mid + 60, 'min_offer' => r2($mid * Settings::num('min_offer_ratio', 0.7))];
    }

    public static function create(int $passengerId, array $in, ?int $agentId = null): array
    {
        $svc = (string) ($in['service'] ?? '');
        $veh = (string) ($in['vehicle'] ?? '');
        if (!isset(Schema::SERVICES[$svc])) throw new ApiError('Choose a service', 422);
        if (!in_array($veh, Schema::SERVES[$svc], true)) throw new ApiError((Schema::VEHICLES[$veh] ?? 'This vehicle') . ' does not serve ' . Schema::SERVICES[$svc], 422);
        $from = trim((string) ($in['from'] ?? ''));
        $to = trim((string) ($in['to'] ?? ''));
        if ($from === '' || $to === '') throw new ApiError('Enter pickup and drop', 422);
        $route = null;
        if (isset($in['from_lat'], $in['from_lng'], $in['to_lat'], $in['to_lng'])) {
            $route = Maps::route((float) $in['from_lat'], (float) $in['from_lng'], (float) $in['to_lat'], (float) $in['to_lng'], $veh);
            $in['km'] = $route['km'];
        }
        $km = (float) ($in['km'] ?? 0);
        if ($km <= 0 || $km > 1500) throw new ApiError('Distance looks wrong', 422);
        $offer = r2((float) ($in['offer'] ?? 0));
        $g = self::guide($svc, $veh, $km);
        if ($offer < $g['min_offer']) throw new ApiError('Offer at least Rs ' . money($g['min_offer']) . ' for this trip', 422);
        if (Db::val("SELECT 1 FROM trips WHERE passenger_id = ? AND status IN ('searching','accepted','arrived','started')", [$passengerId])) throw new ApiError('You already have a trip in progress', 409);
        $id = Db::insert('trips', [
            'passenger_id' => $passengerId, 'service' => $svc, 'vehicle' => $veh, 'from_text' => mb_substr($from, 0, 180), 'to_text' => mb_substr($to, 0, 180),
            'from_lat' => $in['from_lat'] ?? null, 'from_lng' => $in['from_lng'] ?? null, 'to_lat' => $in['to_lat'] ?? null, 'to_lng' => $in['to_lng'] ?? null,
            'km' => r2($km), 'offer' => $offer, 'agent_id' => $agentId, 'created_at' => now(), 'auto_accept' => empty($in['auto_accept']) ? 0 : 1,
            'note' => trim((string) ($in['note'] ?? '')) !== '' ? mb_substr(trim((string) $in['note']), 0, 180) : null,
        ]);
        return self::get($id) + ['guide' => $g, 'route' => $route];
    }

    public static function get(int $id): array
    {
        return Db::one('SELECT * FROM trips WHERE id = ?', [$id]) ?? throw new ApiError('Trip not found', 404);
    }

    /** A trip as the passenger or driver sees it: the other side's name, vehicle, rating and the driver's last position. */
    public static function view(int $tripId, int $userId): array
    {
        $t = self::get($tripId);
        $isP = (int) $t['passenger_id'] === $userId;
        if (!$isP && (int) $t['driver_id'] !== $userId) throw new ApiError('Not your trip', 403);
        $out = $t;
        if ($t['driver_id']) {
            $d = Db::one('SELECT u.name, d.vehicle, d.plate, d.rating, d.lat, d.lng, d.loc_at FROM users u JOIN drivers d ON d.user_id = u.id WHERE u.id = ?', [$t['driver_id']]);
            $out['driver'] = $d ? ['name' => $d['name'], 'vehicle' => $d['vehicle'], 'vehicle_label' => Schema::VEHICLES[$d['vehicle']] ?? $d['vehicle'], 'plate' => $d['plate'],
                'rating' => (float) $d['rating'], 'lat' => $d['lat'] !== null ? (float) $d['lat'] : null, 'lng' => $d['lng'] !== null ? (float) $d['lng'] : null] : null;
        }
        $p = Db::one('SELECT name FROM users WHERE id = ?', [$t['passenger_id']]);
        $out['passenger'] = ['name' => $p['name'] ?? '', 'rating' => self::passengerRating((int) $t['passenger_id'])];
        $out['offers_open'] = $t['status'] === 'searching' ? count(self::openOffers($tripId)) : 0;
        $start = $t['started_at'] ?: $t['accepted_at'];
        $out['ride_minutes'] = ($t['status'] === 'completed' && $start && $t['completed_at']) ? max(1, (int) round((strtotime($t['completed_at']) - strtotime($start)) / 60)) : null;
        $out['service_label'] = Schema::SERVICES[$t['service']] ?? $t['service'];
        $out['vehicle_label'] = Schema::VEHICLES[$t['vehicle']] ?? $t['vehicle'];
        if ($t['status'] === 'completed' && !$isP) {
            $out['wallet_taken'] = -(float) (Db::val("SELECT COALESCE(SUM(amount), 0) FROM wallet_tx WHERE user_id = ? AND ref = ?", [$userId, 'trip:' . $tripId]) ?? 0);
        }
        return $out;
    }

    /** The trip the user is in now (searching, accepted, arrived, started), or a completed one not yet rated by the passenger. */
    public static function active(int $userId, string $role): ?array
    {
        $col = $role === 'driver' ? 'driver_id' : 'passenger_id';
        $id = Db::val("SELECT id FROM trips WHERE $col = ? AND status IN ('searching','accepted','arrived','started') ORDER BY id DESC LIMIT 1", [$userId]);
        if (!$id && $role === 'passenger') $id = Db::val("SELECT id FROM trips WHERE passenger_id = ? AND status = 'completed' AND passenger_rating IS NULL AND completed_at >= ? ORDER BY id DESC LIMIT 1", [$userId, date('Y-m-d H:i:s', time() - 3600)]);
        return $id ? self::view((int) $id, $userId) : null;
    }

    /** Passenger rates the driver (1–5) after a completed trip; the driver's rating is the average of all ratings. */
    public static function rateTrip(int $userId, int $tripId, int $stars, string $note = ''): array
    {
        $t = self::get($tripId);
        if ($stars < 1 || $stars > 5) throw new ApiError('Give 1 to 5 stars', 422);
        if ($t['status'] !== 'completed') throw new ApiError('Rate after the trip is finished', 409);
        if ((int) $t['passenger_id'] === $userId) {
            if ($t['passenger_rating'] !== null) throw new ApiError('Already rated', 409);
            Db::run('UPDATE trips SET passenger_rating = ?, passenger_note = ? WHERE id = ?', [$stars, mb_substr(trim($note), 0, 180) ?: null, $tripId]);
            $avg = (float) Db::val('SELECT AVG(passenger_rating) FROM trips WHERE driver_id = ? AND passenger_rating IS NOT NULL', [$t['driver_id']]);
            Db::run('UPDATE drivers SET rating = ? WHERE user_id = ?', [round($avg, 2), $t['driver_id']]);
        } elseif ((int) $t['driver_id'] === $userId) {
            if ($t['driver_rating'] !== null) throw new ApiError('Already rated', 409);
            Db::run('UPDATE trips SET driver_rating = ? WHERE id = ?', [$stars, $tripId]);
        } else throw new ApiError('Not your trip', 403);
        return self::get($tripId);
    }

    public static function passengerRating(int $passengerId): ?float
    {
        $v = Db::val('SELECT AVG(driver_rating) FROM trips WHERE passenger_id = ? AND driver_rating IS NOT NULL', [$passengerId]);
        return $v === null ? null : round((float) $v, 1);
    }

    private static function openOffers(int $tripId): array
    {
        $since = date('Y-m-d H:i:s', time() - (int) Settings::num('offer_seconds', 30));
        return Db::all("SELECT id FROM offers WHERE trip_id = ? AND status = 'open' AND created_at >= ?", [$tripId, $since]);
    }

    /** Can this driver receive requests right now? Returns '' when yes, else the reason. */
    public static function driverBlock(int $driverId): string
    {
        $d = Db::one('SELECT * FROM drivers WHERE user_id = ?', [$driverId]);
        if (!$d) return 'Not a driver';
        if (!(int) $d['verified']) return 'Your documents are being checked';
        if (!(int) $d['online']) return 'You are offline';
        $onPass = in_array($d['plan'], ['day', 'week'], true) && $d['plan_until'] > now();
        if (!$onPass && Wallet::balance($driverId) < Settings::num('min_wallet', 200)) return 'Top up: wallet is below Rs ' . money(Settings::num('min_wallet', 200));
        if (Db::val("SELECT 1 FROM trips WHERE driver_id = ? AND status IN ('accepted','arrived','started')", [$driverId])) return 'Finish your current trip first';
        return '';
    }

    public static function requestsFor(int $driverId): array
    {
        if ($why = self::driverBlock($driverId)) throw new ApiError($why, 403);
        $me = Db::one('SELECT vehicle, lat, lng FROM drivers WHERE user_id = ?', [$driverId]);
        $rows = Db::all("SELECT t.id, t.service, t.vehicle, t.from_text, t.to_text, t.from_lat, t.from_lng, t.to_lat, t.to_lng, t.km, t.offer, t.note, t.passenger_id, t.created_at, u.name passenger_name FROM trips t
            JOIN users u ON u.id = t.passenger_id
            WHERE t.status = 'searching' AND t.vehicle = ? AND t.created_at >= ?
              AND NOT EXISTS (SELECT 1 FROM offers o WHERE o.trip_id = t.id AND o.driver_id = ?)
            ORDER BY t.id DESC LIMIT 20", [$me['vehicle'], date('Y-m-d H:i:s', time() - 600), $driverId]);
        foreach ($rows as &$r) {
            $pid = (int) $r['passenger_id'];
            $r['passenger_rating'] = self::passengerRating($pid);
            unset($r['passenger_id']);
            $r['pickup_km'] = ($me['lat'] !== null && $r['from_lat'] !== null) ? round(Maps::straightKm((float) $me['lat'], (float) $me['lng'], (float) $r['from_lat'], (float) $r['from_lng']) * 1.3, 1) : null;
            $r['pickup_min'] = $r['pickup_km'] !== null ? max(1, (int) ceil($r['pickup_km'] / 22 * 60)) : null;
            $r['fair'] = (float) $r['offer'] >= self::guide($r['service'], $r['vehicle'], (float) $r['km'])['suggested'];
            $r['passenger_trips'] = (int) Db::val("SELECT COUNT(*) FROM trips WHERE passenger_id = ? AND status = 'completed'", [$pid]);
        }
        return $rows;
    }

    /** Driver accepts the passenger's price (price = offer) or counters with a higher one. */
    public static function offer(int $driverId, int $tripId, float $price): int
    {
        return Db::tx(function () use ($driverId, $tripId, $price) {
            if ($why = self::driverBlock($driverId)) throw new ApiError($why, 403);
            $t = self::get($tripId);
            if ($t['status'] !== 'searching') throw new ApiError('This request is no longer open', 409);
            if ((string) Db::val('SELECT vehicle FROM drivers WHERE user_id = ?', [$driverId]) !== $t['vehicle']) throw new ApiError('Wrong vehicle for this request', 403);
            $price = r2($price);
            if ($price < (float) $t['offer']) throw new ApiError('You can accept Rs ' . money((float) $t['offer']) . ' or offer more', 422);
            if ($price > 3 * (float) $t['offer']) throw new ApiError('Counter offer is too high', 422);
            if (Db::val("SELECT 1 FROM offers WHERE trip_id = ? AND driver_id = ? AND status = 'open'", [$tripId, $driverId])) throw new ApiError('You already answered this request', 409);
            $oid = Db::insert('offers', ['trip_id' => $tripId, 'driver_id' => $driverId, 'price' => $price, 'created_at' => now()]);
            // auto-accept: the passenger said yes in advance to any driver who takes their own price
            if ((int) ($t['auto_accept'] ?? 0) === 1 && $price <= (float) $t['offer']
                && !Db::val("SELECT 1 FROM trips WHERE driver_id = ? AND status IN ('accepted','arrived','started')", [$driverId])) {
                Db::run("UPDATE trips SET status = 'accepted', driver_id = ?, fare = ?, accepted_at = ? WHERE id = ? AND status = 'searching'", [$driverId, $price, now(), $tripId]);
                Db::run("UPDATE offers SET status = CASE WHEN id = ? THEN 'accepted' ELSE 'declined' END WHERE trip_id = ? AND status = 'open'", [$oid, $tripId]);
            }
            return $oid;
        });
    }

    public static function offersFor(int $passengerId, int $tripId): array
    {
        $t = self::get($tripId);
        if ((int) $t['passenger_id'] !== $passengerId) throw new ApiError('Not your trip', 403);
        $since = date('Y-m-d H:i:s', time() - (int) Settings::num('offer_seconds', 30));
        return Db::all("SELECT o.id, o.price, o.created_at, u.name, d.vehicle, d.plate, d.rating FROM offers o
            JOIN users u ON u.id = o.driver_id JOIN drivers d ON d.user_id = o.driver_id
            WHERE o.trip_id = ? AND o.status = 'open' AND o.created_at >= ? ORDER BY o.price, o.id", [$tripId, $since]);
    }

    public static function accept(int $passengerId, int $offerId): array
    {
        return Db::tx(function () use ($passengerId, $offerId) {
            $o = Db::one('SELECT * FROM offers WHERE id = ?', [$offerId]) ?? throw new ApiError('Offer not found', 404);
            $t = self::get((int) $o['trip_id']);
            if ((int) $t['passenger_id'] !== $passengerId) throw new ApiError('Not your trip', 403);
            if ($t['status'] !== 'searching') throw new ApiError('This trip already has a driver', 409);
            if ($o['status'] !== 'open' || $o['created_at'] < date('Y-m-d H:i:s', time() - (int) Settings::num('offer_seconds', 30))) throw new ApiError('This offer has expired', 410);
            if (Db::val("SELECT 1 FROM trips WHERE driver_id = ? AND status IN ('accepted','arrived','started')", [$o['driver_id']])) throw new ApiError('That driver just took another trip', 409);
            Db::run("UPDATE trips SET status = 'accepted', driver_id = ?, fare = ?, accepted_at = ? WHERE id = ? AND status = 'searching'", [$o['driver_id'], $o['price'], now(), $t['id']]);
            Db::run("UPDATE offers SET status = CASE WHEN id = ? THEN 'accepted' ELSE 'declined' END WHERE trip_id = ? AND status = 'open'", [$offerId, $t['id']]);
            return self::get((int) $t['id']);
        });
    }

    public static function advance(int $driverId, int $tripId, string $to): array
    {
        return Db::tx(function () use ($driverId, $tripId, $to) {
            $t = self::get($tripId);
            if ((int) $t['driver_id'] !== $driverId) throw new ApiError('Not your trip', 403);
            if ((self::NEXT[$t['status']] ?? null) !== $to) throw new ApiError("Trip is {$t['status']}; cannot go to $to", 409);
            $col = ['completed' => 'completed_at', 'started' => 'started_at'][$to] ?? null;
            Db::run('UPDATE trips SET status = ?' . ($col ? ", $col = ?" : '') . ' WHERE id = ?', $col ? [$to, now(), $tripId] : [$to, $tripId]);
            if ($to === 'completed') self::settle(self::get($tripId));
            return self::get($tripId);
        });
    }

    public static function cancel(int $userId, int $tripId, string $reason): array
    {
        return Db::tx(function () use ($userId, $tripId, $reason) {
            $t = self::get($tripId);
            $isPassenger = (int) $t['passenger_id'] === $userId;
            $isDriver = (int) $t['driver_id'] === $userId;
            if (!$isPassenger && !$isDriver) throw new ApiError('Not your trip', 403);
            if (in_array($t['status'], ['started', 'completed', 'cancelled'], true)) throw new ApiError('A ' . $t['status'] . ' trip cannot be cancelled', 409);
            Db::run("UPDATE trips SET status = 'cancelled', cancel_reason = ? WHERE id = ?", [($isDriver ? 'driver: ' : 'passenger: ') . mb_substr(trim($reason) ?: 'no reason', 0, 150), $tripId]);
            Db::run("UPDATE offers SET status = 'declined' WHERE trip_id = ? AND status = 'open'", [$tripId]);
            return self::get($tripId);
        });
    }

    /**
     * Money for a completed trip. The passenger pays the driver in cash; ZarDrive takes its part from the driver's wallet.
     * Rides, courier, intercity, freight: commission % of the fare (0 on a pass or during the launch offer).
     * Food: the delivery charge is split rider / ZarDrive (80 / 20 by default); the rider keeps his share from the cash. No agent share.
     */
    public static function settle(array $t): void
    {
        $fare = (float) $t['fare'];
        $driver = (int) $t['driver_id'];
        $ref = (string) $t['id'];
        if ($t['service'] === 'food') {
            $company = r2($fare * Settings::num('food_company_pct', 20) / 100);
            if ($company <= 0) return;
            $j = Ledger::post('trip', $ref, [['2100', $company, 0, $driver], ['4150', 0, $company]], 'Food delivery ZD-' . $t['id'] . ' · DC ' . money($fare));
            Wallet::add($driver, 'food_share', -$company, 'trip:' . $ref, 'Food ZD-' . $t['id'] . ' · ZarDrive ' . money($company), $j);
            return;
        }
        $d = Db::one('SELECT d.*, u.created_at joined FROM drivers d JOIN users u ON u.id = d.user_id WHERE d.user_id = ?', [$driver]);
        $onPass = in_array($d['plan'], ['day', 'week'], true) && $d['plan_until'] >= ($t['completed_at'] ?? now());
        $launch = strtotime((string) $d['joined']) > time() - 86400 * (int) Settings::num('launch_free_days', 30);
        $pct = $onPass || $launch ? 0.0 : (float) self::rate($t['service'], $t['vehicle'])['commission_pct'];
        $commission = r2($fare * $pct / 100);
        if ($commission <= 0) return;
        $j = Ledger::post('trip', $ref, [['2100', $commission, 0, $driver], ['4100', 0, $commission]], 'Commission ZD-' . $t['id'] . ' · ' . $pct . '% of ' . money($fare));
        Wallet::add($driver, 'commission', -$commission, 'trip:' . $ref, 'ZD-' . $t['id'] . ' · fare ' . money($fare) . ' · ' . $pct . '%', $j);
    }
}

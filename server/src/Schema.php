<?php
declare(strict_types=1);

namespace Zd;

/** Database tables, written once for both MySQL (live) and SQLite (tests). Install is idempotent. */
final class Schema
{
    private const T = [
        'users' => ['id' => 'id', 'role' => 'str', 'name' => 'str', 'phone' => 'str null', 'email' => 'str null', 'pass_hash' => 'str null', 'status' => "str default 'active'", 'created_at' => 'dt'],
        'drivers' => ['user_id' => 'int pk', 'vehicle' => 'str', 'plate' => 'str null', 'verified' => 'int default 0', 'online' => 'int default 0', 'lat' => 'geo null', 'lng' => 'geo null', 'loc_at' => 'dt null', 'rating' => 'money default 5', 'plan' => "str default 'per_trip'", 'plan_until' => 'dt null'],
        'driver_docs' => ['id' => 'id', 'user_id' => 'int', 'kind' => 'str', 'status' => "str default 'pending'", 'note' => 'str null', 'created_at' => 'dt'],
        'tokens' => ['token' => 'str pk', 'user_id' => 'int', 'created_at' => 'dt'],
        'otps' => ['phone' => 'str pk', 'code' => 'str', 'expires_at' => 'dt', 'tries' => 'int default 0'],
        'settings' => ['k' => 'str pk', 'v' => 'text'],
        'fare_rates' => ['id' => 'id', 'service' => 'str', 'vehicle' => 'str', 'min_fare' => 'money', 'per_km' => 'money', 'commission_pct' => 'money'],
        'trips' => ['id' => 'id', 'passenger_id' => 'int', 'service' => 'str', 'vehicle' => 'str', 'from_text' => 'str', 'to_text' => 'str', 'from_lat' => 'geo null', 'from_lng' => 'geo null', 'to_lat' => 'geo null', 'to_lng' => 'geo null', 'km' => 'money', 'offer' => 'money', 'fare' => 'money null', 'driver_id' => 'int null', 'agent_id' => 'int null', 'status' => "str default 'searching'", 'cancel_reason' => 'str null', 'note' => 'str null', 'passenger_rating' => 'int null', 'passenger_note' => 'str null', 'driver_rating' => 'int null', 'created_at' => 'dt', 'accepted_at' => 'dt null', 'started_at' => 'dt null', 'passenger_hidden' => 'int default 0', 'auto_accept' => 'int default 0', 'completed_at' => 'dt null'],
        'offers' => ['id' => 'id', 'trip_id' => 'int', 'driver_id' => 'int', 'price' => 'money', 'status' => "str default 'open'", 'created_at' => 'dt'],
        'wallet_tx' => ['id' => 'id', 'user_id' => 'int', 'kind' => 'str', 'amount' => 'money', 'balance_after' => 'money', 'ref' => 'str null', 'note' => 'str null', 'journal_id' => 'int null', 'created_at' => 'dt'],
        'topups' => ['id' => 'id', 'user_id' => 'int', 'method' => 'str', 'tid' => 'str unique', 'amount' => 'money', 'status' => "str default 'pending'", 'created_at' => 'dt', 'decided_by' => 'int null', 'decided_at' => 'dt null', 'note' => 'str null'],
        'passes' => ['id' => 'id', 'user_id' => 'int', 'kind' => 'str', 'price' => 'money', 'starts_at' => 'dt', 'ends_at' => 'dt', 'days' => 'int', 'days_recognised' => 'int default 0'],
        'accounts' => ['code' => 'str pk', 'name' => 'str', 'type' => 'str'],
        'journal' => ['id' => 'id', 'jv_no' => 'str unique', 'event' => 'str', 'ref' => 'str', 'memo' => 'str null', 'created_by' => 'int null', 'created_at' => 'dt'],
        'journal_lines' => ['id' => 'id', 'journal_id' => 'int', 'account' => 'str', 'debit' => 'money default 0', 'credit' => 'money default 0', 'party_id' => 'int null'],
        'route_cache' => ['k' => 'str pk', 'km' => 'money', 'minutes' => 'int', 'geometry' => 'text', 'source' => 'str', 'created_at' => 'dt'],
        'places' => ['id' => 'id', 'name' => 'str', 'kind' => 'str', 'lat' => 'geo', 'lng' => 'geo', 'area' => 'str null', 'city' => 'str null', 'weight' => 'int default 30', 'search' => 'str', 'skel' => 'str'],
        'help_requests' => ['id' => 'id', 'trip_id' => 'int null', 'user_id' => 'int', 'topic' => 'str', 'message' => 'text null', 'status' => "str default 'open'", 'created_at' => 'dt', 'closed_by' => 'int null', 'closed_at' => 'dt null'],
        'audit' => ['id' => 'id', 'user_id' => 'int null', 'action' => 'str', 'detail' => 'text null', 'created_at' => 'dt'],
    ];

    private const IDX = [
        'ux_users_phone' => 'CREATE UNIQUE INDEX ux_users_phone ON users (phone)',
        'ux_rates' => 'CREATE UNIQUE INDEX ux_rates ON fare_rates (service, vehicle)',
        'ix_trips_status' => 'CREATE INDEX ix_trips_status ON trips (status, vehicle)',
        'ix_offers_trip' => 'CREATE INDEX ix_offers_trip ON offers (trip_id, status)',
        'ix_wallet_user' => 'CREATE INDEX ix_wallet_user ON wallet_tx (user_id, id)',
        'ix_lines_acc' => 'CREATE INDEX ix_lines_acc ON journal_lines (account)',
        'ix_places_search' => 'CREATE INDEX ix_places_search ON places (search)',
        'ix_places_skel' => 'CREATE INDEX ix_places_skel ON places (skel)',
        'ix_places_geo' => 'CREATE INDEX ix_places_geo ON places (lat, lng)',
        'ux_journal_ref' => 'CREATE UNIQUE INDEX ux_journal_ref ON journal (event, ref)',
    ];

    public const ACCOUNTS = [
        ['1010', 'JazzCash – company wallet', 'asset'], ['1011', 'Easypaisa – company wallet', 'asset'], ['1020', 'Bank – main account', 'asset'],
        ['2100', 'Driver wallets (held for drivers)', 'liability'], ['2300', 'Unearned week-pass income', 'liability'],
        ['3010', "Owner's capital", 'equity'],
        ['4100', 'Commission income – rides, courier, intercity, freight', 'income'], ['4150', 'Food delivery income (company share)', 'income'],
        ['4160', 'Restaurant commission income', 'income'], ['4200', 'Pass income', 'income'],
        ['5100', 'Running costs', 'expense'],
    ];

    public const SERVICES = ['ride' => 'Ride', 'food' => 'Food', 'courier' => 'Courier', 'intercity' => 'Intercity', 'freight' => 'Freight'];
    public const VEHICLES = ['bike' => 'Motorbike', 'rickshaw' => 'Rickshaw', 'economy' => 'Car economy', 'comfort' => 'Car comfort (AC)', 'loader' => 'Loader / pickup'];

    /** Which vehicles serve which service. */
    public const SERVES = [
        'ride' => ['bike', 'rickshaw', 'economy', 'comfort'], 'food' => ['bike'], 'courier' => ['bike', 'rickshaw'],
        'intercity' => ['economy', 'comfort'], 'freight' => ['loader'],
    ];

    public static function install(): void
    {
        $sqlite = Db::isSqlite();
        foreach (self::T as $t => $cols) {
            $defs = [];
            foreach ($cols as $c => $spec) $defs[] = $c . ' ' . self::type($spec, $sqlite);
            Db::pdo()->exec('CREATE TABLE IF NOT EXISTS ' . $t . ' (' . implode(', ', $defs) . ')' . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
            // add columns that newer versions introduced (never drops or changes existing ones)
            $have = $sqlite ? array_column(Db::all('PRAGMA table_info(' . $t . ')'), 'name') : array_column(Db::all('SHOW COLUMNS FROM ' . $t), 'Field');
            foreach ($cols as $c => $spec) {
                if (!in_array($c, $have, true)) Db::pdo()->exec('ALTER TABLE ' . $t . ' ADD COLUMN ' . $c . ' ' . str_replace(' NOT NULL', ' NULL', self::type(str_replace(['pk', 'unique'], '', $spec), $sqlite)));  // added columns are optional so old rows stay valid
            }
        }
        foreach (self::IDX as $name => $sql) {
            try { Db::pdo()->exec($sqlite ? str_replace('INDEX ', 'INDEX IF NOT EXISTS ', $sql) : $sql); } catch (\PDOException $e) { /* already there */ }
        }
        foreach (self::ACCOUNTS as [$code, $name, $type]) {
            if (!Db::val('SELECT 1 FROM accounts WHERE code = ?', [$code])) Db::insert('accounts', ['code' => $code, 'name' => $name, 'type' => $type]);
        }
        $defaults = [
            'commission_pct' => '6', 'min_wallet' => '200', 'offer_seconds' => '30', 'min_offer_ratio' => '0.7',
            'food_rider_pct' => '80', 'food_company_pct' => '20', 'restaurant_commission_on' => '0', 'restaurant_commission_pct' => '0',
            'pass_day_bike' => '100', 'pass_day_rickshaw' => '150', 'pass_day_economy' => '250', 'pass_day_comfort' => '300', 'pass_day_loader' => '350',
            'pass_week_days' => '7', 'pass_week_paid_days' => '6', 'launch_free_days' => '30', 'city' => 'Peshawar', 'osrm_url' => 'https://routing.openstreetmap.de/routed-{profile}',
        ];
        foreach ($defaults as $k => $v) if (Db::val('SELECT 1 FROM settings WHERE k = ?', [$k]) === null) Db::insert('settings', ['k' => $k, 'v' => $v]);
        $guide = ['bike' => [100, 25], 'rickshaw' => [150, 35], 'economy' => [200, 45], 'comfort' => [280, 60], 'loader' => [500, 90]];
        foreach (self::SERVES as $svc => $vehs) foreach ($vehs as $v) {
            if (!Db::val('SELECT 1 FROM fare_rates WHERE service = ? AND vehicle = ?', [$svc, $v])) {
                [$min, $km] = $svc === 'food' ? [120, 20] : $guide[$v];
                Db::insert('fare_rates', ['service' => $svc, 'vehicle' => $v, 'min_fare' => $min, 'per_km' => $km, 'commission_pct' => 6]);
            }
        }
    }

    private static function type(string $spec, bool $sqlite): string
    {
        $p = explode(' ', $spec, 2);
        $rest = $p[1] ?? '';
        if ($p[0] === 'id') return $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $base = match ($p[0]) {
            'int' => $sqlite ? 'INTEGER' : 'BIGINT', 'str' => 'VARCHAR(190)', 'text' => 'TEXT', 'dt' => 'DATETIME',
            'money' => 'DECIMAL(14,2)', 'geo' => 'DECIMAL(10,7)', default => 'TEXT',
        };
        $null = str_contains($rest, 'null') ? ' NULL' : ' NOT NULL';
        $rest = str_replace('null', '', $rest);
        $extra = '';
        if (str_contains($rest, 'pk')) $extra .= ' PRIMARY KEY';
        if (str_contains($rest, 'unique')) $extra .= ' UNIQUE';
        if (preg_match("/default ('[^']*'|[0-9.]+)/", $rest, $m)) $extra .= ' DEFAULT ' . $m[1];
        return $base . $null . $extra;
    }
}

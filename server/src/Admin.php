<?php
declare(strict_types=1);

namespace Zd;

/** Web admin: live operations, drivers, fare guide and commission, wallets and top-ups, accounts. */
final class Admin
{
    private static ?array $me = null;

    public static function handle(string $method, string $path, array $post): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => !empty($_SERVER['HTTPS'])]);
        if ($method === 'POST' && !hash_equals($_SESSION['csrf'] ?? '', (string) ($post['csrf'] ?? ''))) { http_response_code(419); echo 'Form expired. Go back and try again.'; return; }
        if ($path === '/admin/setup') { self::setup($method, $post); return; }
        if ($path === '/admin/login') { self::login($method, $post); return; }
        if ($path === '/admin/logout') { session_destroy(); self::go('/admin/login'); }
        self::$me = isset($_SESSION['uid']) ? Db::one("SELECT * FROM users WHERE id = ? AND status = 'active'", [$_SESSION['uid']]) : null;
        if (!self::$me) self::go(Auth::adminCount() ? '/admin/login' : '/admin/setup');
        Wallet::recognisePasses();
        try {
            match (true) {
                $path === '/admin' || $path === '/admin/' => self::dashboard(),
                $path === '/admin/trips' => self::trips(),
                $path === '/admin/drivers' => $method === 'POST' ? self::driversPost($post) : self::drivers(),
                $path === '/admin/rates' => $method === 'POST' ? self::ratesPost($post) : self::rates(),
                $path === '/admin/wallets' => $method === 'POST' ? self::walletsPost($post) : self::wallets(),
                $path === '/admin/accounts' => self::accounts(),
                $path === '/admin/support' => $method === 'POST' ? self::supportPost($post) : self::support(),
                default => self::page('Not found', '<p>Page not found.</p>'),
            };
        } catch (ApiError $e) {
            $_SESSION['flash'] = ['e', $e->getMessage()];
            self::go($path);
        }
    }

    private static function can(string ...$roles): void
    {
        if (!in_array(self::$me['role'], $roles, true)) throw new ApiError('Your role cannot do this', 403);
    }

    // ---------------------------------------------------------------- sign-in

    private static function setup(string $method, array $post): void
    {
        if (Auth::adminCount() > 0) self::go('/admin/login');
        if ($method === 'POST') {
            try {
                if (($post['pass'] ?? '') !== ($post['pass2'] ?? '')) throw new ApiError('Passwords do not match', 422);
                $id = Auth::createStaff('admin', (string) ($post['name'] ?? ''), (string) ($post['email'] ?? ''), (string) ($post['pass'] ?? ''), null);
                session_regenerate_id(true);
                $_SESSION['uid'] = $id;
                self::go('/admin');
            } catch (ApiError $e) { $err = $e->getMessage(); }
        }
        self::bare('First admin', (isset($err) ? '<div class="msg e">' . h($err) . '</div>' : '')
            . '<p class="mut">No admin exists yet. Create the first one; more staff can be added later.</p><form method="post">' . self::csrf()
            . '<label>Name<input name="name" required></label><label>Email<input name="email" type="email" required></label>'
            . '<label>Password (10+ characters)<input name="pass" type="password" minlength="10" required></label><label>Repeat password<input name="pass2" type="password" required></label>'
            . '<button>Create admin</button></form>');
    }

    private static function login(string $method, array $post): void
    {
        if ($method === 'POST') {
            $u = Auth::staffLogin((string) ($post['email'] ?? ''), (string) ($post['pass'] ?? ''));
            if ($u) { session_regenerate_id(true); $_SESSION['uid'] = (int) $u['id']; Audit::log((int) $u['id'], 'login', ''); self::go('/admin'); }
            $err = 'Wrong email or password';
        }
        self::bare('Sign in', (isset($err) ? '<div class="msg e">' . h($err) . '</div>' : '') . '<form method="post">' . self::csrf()
            . '<label>Email<input name="email" type="email" required autofocus></label><label>Password<input name="pass" type="password" required></label><button>Sign in</button></form>');
    }

    // ---------------------------------------------------------------- pages

    private static function dashboard(): void
    {
        $day = date('Y-m-d');
        $k = [
            ['Trips today', (int) Db::val('SELECT COUNT(*) FROM trips WHERE created_at >= ?', [$day]), (int) Db::val("SELECT COUNT(*) FROM trips WHERE status = 'completed' AND completed_at >= ?", [$day]) . ' completed'],
            ['Drivers online', (int) Db::val('SELECT COUNT(*) FROM drivers WHERE online = 1 AND verified = 1'), (int) Db::val('SELECT COUNT(*) FROM drivers WHERE verified = 0') . ' waiting for checks'],
            ['Earned today', 'Rs ' . money(-(float) Db::val("SELECT COALESCE(SUM(l.debit - l.credit),0) FROM journal_lines l JOIN journal j ON j.id = l.journal_id WHERE l.account IN ('4100','4150','4160','4200') AND j.created_at >= ?", [$day])), 'Commission, food share, passes'],
            ['Top-ups to check', (int) Db::val("SELECT COUNT(*) FROM topups WHERE status = 'pending'"), 'Rs ' . money((float) Db::val("SELECT COALESCE(SUM(amount),0) FROM topups WHERE status = 'pending'"))],
        ];
        $b = '<div class="k4">';
        foreach ($k as [$l, $v, $s]) $b .= '<div class="k"><span>' . h($l) . '</span><b>' . h($v) . '</b><small>' . h($s) . '</small></div>';
        $b .= '</div>' . self::tripTable("SELECT t.*, u.name driver FROM trips t LEFT JOIN users u ON u.id = t.driver_id WHERE t.status IN ('searching','accepted','arrived','started') ORDER BY t.id DESC LIMIT 30", 'Trips right now');
        self::page('Live operations', $b);
    }

    private static function trips(): void
    {
        self::page('Trips', self::tripTable('SELECT t.*, u.name driver FROM trips t LEFT JOIN users u ON u.id = t.driver_id ORDER BY t.id DESC LIMIT 200', 'Last 200 trips'));
    }

    private static function tripTable(string $sql, string $title): string
    {
        $h = '<section class="card"><h2>' . h($title) . '</h2><div class="scroll"><table><tr><th>Trip</th><th>Service</th><th>Vehicle</th><th>Route</th><th class="rt">Fare</th><th>Driver</th><th>Status</th></tr>';
        $rows = Db::all($sql);
        foreach ($rows as $t) {
            $h .= '<tr><td class="mono">ZD-' . (int) $t['id'] . '</td><td>' . h(Schema::SERVICES[$t['service']] ?? $t['service']) . '</td><td>' . h(Schema::VEHICLES[$t['vehicle']] ?? $t['vehicle']) . '</td><td>'
                . h($t['from_text']) . ' → ' . h($t['to_text']) . '</td><td class="rt">' . money((float) ($t['fare'] ?? $t['offer'])) . '</td><td>' . h($t['driver'] ?? '—') . '</td><td>' . self::pill($t['status']) . '</td></tr>';
        }
        if (!$rows) $h .= '<tr><td colspan="7" class="mut">No trips.</td></tr>';
        return $h . '</table></div></section>';
    }

    private static function support(): void
    {
        $h = '<section class="card"><h2>Help requests from passengers and drivers</h2><div class="scroll"><table><tr><th>#</th><th>When</th><th>From</th><th>Trip</th><th>About</th><th>Message</th><th>Status</th><th></th></tr>';
        $rows = Db::all("SELECT r.*, u.name, u.phone, u.role FROM help_requests r JOIN users u ON u.id = r.user_id ORDER BY r.status = 'open' DESC, r.id DESC LIMIT 200");
        foreach ($rows as $r) {
            $h .= '<tr><td class="mono">' . (int) $r['id'] . '</td><td>' . h($r['created_at']) . '</td><td>' . h($r['name']) . '<br><span class="mut">' . h($r['role']) . ' · ' . h($r['phone']) . '</span></td><td class="mono">'
                . ($r['trip_id'] ? 'ZD-' . (int) $r['trip_id'] : '—') . '</td><td>' . h($r['topic']) . '</td><td>' . nl2br(h($r['message'])) . '</td><td>' . self::pill($r['status']) . '</td><td>'
                . ($r['status'] === 'open' ? '<form method="post" class="inl">' . self::csrf() . '<input type="hidden" name="id" value="' . (int) $r['id'] . '"><button class="s">Mark solved</button></form>' : '') . '</td></tr>';
        }
        if (!$rows) $h .= '<tr><td colspan="8" class="mut">No help requests yet.</td></tr>';
        self::page('Support', $h . '</table></div></section>');
    }

    private static function supportPost(array $p): void
    {
        self::can('admin', 'agent');
        Db::run("UPDATE help_requests SET status = 'solved', closed_by = ?, closed_at = ? WHERE id = ? AND status = 'open'", [(int) self::$me['id'], now(), (int) ($p['id'] ?? 0)]);
        Audit::log((int) self::$me['id'], 'help.solved', 'request ' . (int) ($p['id'] ?? 0));
        self::go('/admin/support');
    }

    private static function drivers(): void
    {
        $h = '<section class="card"><h2>Drivers</h2><div class="scroll"><table><tr><th>Driver</th><th>Phone</th><th>Vehicle</th><th>Plan</th><th class="rt">Wallet</th><th>Status</th><th></th></tr>';
        foreach (Db::all('SELECT u.id, u.name, u.phone, d.* FROM users u JOIN drivers d ON d.user_id = u.id ORDER BY d.verified, u.id DESC LIMIT 500') as $d) {
            $plan = in_array($d['plan'], ['day', 'week'], true) && $d['plan_until'] > now() ? ucfirst($d['plan']) . ' pass to ' . substr((string) $d['plan_until'], 0, 16) : 'Pay per trip';
            $h .= '<tr><td>' . h($d['name']) . '</td><td class="mono">' . h($d['phone']) . '</td><td>' . h(Schema::VEHICLES[$d['vehicle']] ?? $d['vehicle']) . ' ' . h($d['plate']) . '</td><td>' . h($plan) . '</td><td class="rt">'
                . money(Wallet::balance((int) $d['id'])) . '</td><td>' . ((int) $d['verified'] ? self::pill('verified') : self::pill('waiting checks')) . '</td><td><form method="post" class="inl">' . self::csrf()
                . '<input type="hidden" name="id" value="' . (int) $d['id'] . '"><input type="hidden" name="verified" value="' . ((int) $d['verified'] ? 0 : 1) . '"><button class="s">' . ((int) $d['verified'] ? 'Suspend' : 'Approve') . '</button></form></td></tr>';
        }
        self::page('Drivers', $h . '</table></div></section>');
    }

    private static function driversPost(array $p): void
    {
        self::can('admin');
        Db::run('UPDATE drivers SET verified = ?, online = CASE WHEN ? = 0 THEN 0 ELSE online END WHERE user_id = ?', [(int) $p['verified'], (int) $p['verified'], (int) $p['id']]);
        Audit::log((int) self::$me['id'], 'driver.verify', 'driver ' . (int) $p['id'] . ' → ' . (int) $p['verified']);
        self::flash('Saved');
        self::go('/admin/drivers');
    }

    private static function rates(): void
    {
        $h = '<form method="post">' . self::csrf() . '<section class="card"><h2>Fare guide and commission</h2><p class="mut">The guide sets the passenger\'s suggested range; passengers and drivers still agree the final fare. Changes apply to new trips and are logged.</p><div class="scroll"><table><tr><th>Service</th><th>Vehicle</th><th>Minimum (Rs)</th><th>Per km guide (Rs)</th><th>Commission %</th></tr>';
        foreach (Db::all('SELECT * FROM fare_rates ORDER BY service, vehicle') as $r) {
            $n = 'r[' . (int) $r['id'] . ']';
            $h .= '<tr><td>' . h(Schema::SERVICES[$r['service']]) . '</td><td>' . h(Schema::VEHICLES[$r['vehicle']]) . '</td><td><input name="' . $n . '[min]" value="' . h($r['min_fare']) . '" inputmode="decimal"></td><td><input name="' . $n . '[km]" value="' . h($r['per_km']) . '" inputmode="decimal"></td><td>'
                . ($r['service'] === 'food' ? '<span class="mut">split below</span>' : '<input name="' . $n . '[pct]" value="' . h($r['commission_pct']) . '" inputmode="decimal">') . '</td></tr>';
        }
        $s = fn($k) => h(Settings::get($k));
        $h .= '</table></div></section><section class="card"><h2>Food delivery · delivery charge split</h2><div class="g3">'
            . '<label>Rider %<input name="s[food_rider_pct]" value="' . $s('food_rider_pct') . '"></label><label>ZarDrive %<input name="s[food_company_pct]" value="' . $s('food_company_pct') . '"></label></div>'
            . '<p class="mut">Must add up to 100. No agent share. The food bill goes to the restaurant.</p>'
            . '<label class="chk"><input type="checkbox" name="s[restaurant_commission_on]" value="1"' . (Settings::get('restaurant_commission_on') === '1' ? ' checked' : '') . '> Restaurant commission (off for now)</label></section>'
            . '<section class="card"><h2>Driver wallet and passes</h2><div class="g3"><label>Minimum wallet for requests (Rs)<input name="s[min_wallet]" value="' . $s('min_wallet') . '"></label><label>Launch offer: 0% for first days<input name="s[launch_free_days]" value="' . $s('launch_free_days') . '"></label><label>Week pass = day price ×<input name="s[pass_week_paid_days]" value="' . $s('pass_week_paid_days') . '"></label>';
        foreach (Schema::VEHICLES as $v => $l) $h .= '<label>Day pass · ' . h($l) . ' (Rs)<input name="s[pass_day_' . $v . ']" value="' . $s('pass_day_' . $v) . '"></label>';
        $h .= '</div></section><section class="card"><h2>Maps and routing</h2><p class="mut">Distance, time and route come from free OSRM (OpenStreetMap). The apps show Google Maps for the map itself; without a key they show the free OpenStreetMap map. Each route is cached for 7 days; if OSRM is down a straight-line estimate is used.</p>'
            . '<label>Google Maps key for the apps<input name="s[google_maps_key]" value="" autocomplete="off" placeholder="' . (Settings::get('google_maps_key') ? 'Saved · leave empty to keep it' : 'Starts with AIza… (Maps JavaScript API, restricted to zardrive.zarrasha.com)') . '"></label>'
            . '<label class="chk"><input type="checkbox" name="s[google_maps_key_remove]" value="1"> Remove the saved key (apps go back to OpenStreetMap)</label>'
            . '<label>OSRM server ({profile} = car)<input name="s[osrm_url]" value="' . $s('osrm_url') . '"></label>'
            . '<p class="mut">Routes looked up: ' . (int) Db::val('SELECT COUNT(*) FROM route_cache') . ' · from OSRM ' . (int) Db::val("SELECT COUNT(*) FROM route_cache WHERE source = 'osrm'") . ' · estimated ' . (int) Db::val("SELECT COUNT(*) FROM route_cache WHERE source = 'estimate'") . '</p>';
        self::page('Fare guide and commission', $h . '</section><button>Save changes</button></form>');
    }

    private static function ratesPost(array $p): void
    {
        self::can('admin');
        $s = (array) ($p['s'] ?? []);
        $s['restaurant_commission_on'] = isset($s['restaurant_commission_on']) ? '1' : '0';
        $sum = (float) $s['food_rider_pct'] + (float) $s['food_company_pct'];
        if (abs($sum - 100) > 0.001) throw new ApiError('Food split must add up to 100% (now ' . $sum . '%)', 422);
        Db::tx(function () use ($p, $s) {
            foreach ((array) ($p['r'] ?? []) as $id => $r) {
                $old = Db::one('SELECT * FROM fare_rates WHERE id = ?', [(int) $id]);
                if (!$old) continue;
                $min = (float) $r['min']; $km = (float) $r['km']; $pct = isset($r['pct']) ? (float) $r['pct'] : (float) $old['commission_pct'];
                if ($min < 0 || $km < 0 || $pct < 0 || $pct > 50) throw new ApiError('Check the numbers: commission must be 0–50%', 422);
                if ($min != (float) $old['min_fare'] || $km != (float) $old['per_km'] || $pct != (float) $old['commission_pct']) {
                    Db::run('UPDATE fare_rates SET min_fare = ?, per_km = ?, commission_pct = ? WHERE id = ?', [$min, $km, $pct, (int) $id]);
                    Audit::log((int) self::$me['id'], 'rate', "{$old['service']}/{$old['vehicle']}: min {$old['min_fare']}→$min, km {$old['per_km']}→$km, % {$old['commission_pct']}→$pct");
                }
            }
            foreach ($s as $k => $v) {
                if ($k === 'google_maps_key_remove') { if ($v && Settings::get('google_maps_key')) Settings::set('google_maps_key', '', (int) self::$me['id']); continue; }
                if ($k === 'google_maps_key') {
                    $v = trim((string) $v);
                    if ($v === '' || !empty($s['google_maps_key_remove'])) continue;   // empty = keep the saved key
                    if (!preg_match('/^AIza[0-9A-Za-z_-]{35}$/', $v)) throw new ApiError('That does not look like a Google Maps key (it starts with AIza and has 39 characters)', 422);
                    Settings::set('google_maps_key', $v, (int) self::$me['id']);
                    continue;
                }
                if ($k === 'osrm_url') {
                    if (!preg_match('#^(https://[a-z0-9.-]+|http://(127\.0\.0\.1|localhost))(:\d+)?(/[A-Za-z0-9._{}/-]*)?$#', (string) $v)) throw new ApiError('Routing server must be an https address, or http://127.0.0.1 on the same server', 422);
                    if (Settings::get($k) !== (string) $v) Settings::set($k, (string) $v, (int) self::$me['id']);
                    continue;
                }
                if (!preg_match('/^(food_\w+_pct|restaurant_commission_on|min_wallet|launch_free_days|pass_week_paid_days|pass_day_\w+)$/', (string) $k)) continue;
                if (!is_numeric($v) || (float) $v < 0) throw new ApiError("Check $k", 422);
                if (Settings::get($k) !== (string) $v) Settings::set($k, (string) $v, (int) self::$me['id']);
            }
        });
        self::flash('Saved');
        self::go('/admin/rates');
    }

    private static function wallets(): void
    {
        $held = -Ledger::balance('2100');
        $h = '<div class="k4"><div class="k dark"><span>Held for drivers</span><b>Rs ' . money($held) . '</b><small>Owed back to drivers until used</small></div>'
            . '<div class="k"><span>Top-ups to check</span><b>' . (int) Db::val("SELECT COUNT(*) FROM topups WHERE status = 'pending'") . '</b><small>Match the TID with the statement</small></div>'
            . '<div class="k"><span>Below minimum</span><b>' . self::belowMin() . '</b><small>Not getting requests</small></div>'
            . '<div class="k"><span>Refunded this month</span><b>Rs ' . money(-(float) Db::val("SELECT COALESCE(SUM(amount), 0) FROM wallet_tx WHERE kind = 'refund' AND created_at >= ?", [date('Y-m-01 00:00:00')])) . '</b><small>Unused wallet money paid back</small></div></div>';
        $h .= '<section class="card"><h2>Top-ups to check</h2><div class="scroll"><table><tr><th>Driver</th><th>Method</th><th>TID</th><th class="rt">Amount</th><th>Sent</th><th>Decision</th></tr>';
        $rows = Db::all("SELECT t.*, u.name, u.phone FROM topups t JOIN users u ON u.id = t.user_id WHERE t.status = 'pending' ORDER BY t.id");
        foreach ($rows as $t) {
            $h .= '<tr><td>' . h($t['name']) . '<br><span class="mut">' . h($t['phone']) . '</span></td><td>' . h(ucfirst($t['method'])) . '</td><td class="mono">' . h($t['tid']) . '</td><td class="rt">' . money((float) $t['amount']) . '</td><td>' . h(substr($t['created_at'], 11, 5)) . '</td><td>'
                . '<form method="post" class="row">' . self::csrf() . '<input type="hidden" name="topup" value="' . (int) $t['id'] . '"><input name="amount" value="' . h($t['amount']) . '" aria-label="Amount found on statement" style="width:100px"><input name="note" placeholder="Note (needed if rejecting or amount differs)" style="width:240px">'
                . '<button name="do" value="approve">Approve</button><button name="do" value="reject" class="s">Reject</button></form></td></tr>';
        }
        if (!$rows) $h .= '<tr><td colspan="6" class="mut">Nothing waiting.</td></tr>';
        $h .= '</table></div></section><section class="card"><h2>Driver balances</h2><div class="scroll"><table><tr><th>Driver</th><th>Vehicle</th><th class="rt">Balance</th><th class="rt">Ledger</th></tr>';
        foreach (Db::all('SELECT u.id, u.name, d.vehicle FROM users u JOIN drivers d ON d.user_id = u.id ORDER BY u.name LIMIT 500') as $d) {
            $w = Wallet::balance((int) $d['id']); $l = -Ledger::balance('2100', (int) $d['id']);
            $h .= '<tr><td>' . h($d['name']) . '</td><td>' . h(Schema::VEHICLES[$d['vehicle']]) . '</td><td class="rt">' . money($w) . '</td><td class="rt' . (abs($w - $l) > 0.001 ? ' neg' : '') . '">' . money($l) . '</td></tr>';
        }
        self::page('Driver wallets', $h . '</table></div></section>');
    }

    private static function belowMin(): int
    {
        $n = 0;
        foreach (Db::all('SELECT user_id FROM drivers WHERE verified = 1') as $d) if (Wallet::balance((int) $d['user_id']) < Settings::num('min_wallet', 200)) $n++;
        return $n;
    }

    private static function walletsPost(array $p): void
    {
        self::can('admin', 'accounts');
        Wallet::decideTopup((int) $p['topup'], ($p['do'] ?? '') === 'approve', (int) self::$me['id'], (float) $p['amount'], (string) ($p['note'] ?? ''));
        self::flash(($p['do'] ?? '') === 'approve' ? 'Top-up approved' : 'Top-up rejected');
        self::go('/admin/wallets');
    }

    private static function accounts(): void
    {
        $tb = Ledger::trial();
        $dr = array_sum(array_column($tb, 'dr')); $cr = array_sum(array_column($tb, 'cr'));
        $inc = 0.0; $exp = 0.0;
        $pl = '<section class="card"><h2>Profit and loss · all time</h2><table>';
        foreach ($tb as $a) {
            if ($a['type'] === 'income') { $v = (float) $a['cr'] - (float) $a['dr']; $inc += $v; $pl .= '<tr><td>' . h($a['code'] . ' ' . $a['name']) . '</td><td class="rt">' . money($v) . '</td></tr>'; }
        }
        foreach ($tb as $a) {
            if ($a['type'] === 'expense') { $v = (float) $a['dr'] - (float) $a['cr']; $exp += $v; $pl .= '<tr><td>' . h($a['code'] . ' ' . $a['name']) . '</td><td class="rt">(' . money($v) . ')</td></tr>'; }
        }
        $pl .= '<tr class="tot"><td>Profit / (loss)</td><td class="rt">' . money($inc - $exp) . '</td></tr></table></section>';
        $t = '<section class="card"><h2>Trial balance</h2><p class="' . (abs($dr - $cr) < 0.001 ? 'ok' : 'neg') . '">' . (abs($dr - $cr) < 0.001 ? '✓ Balanced' : '✗ Not balanced') . ' · Dr ' . money($dr) . ' · Cr ' . money($cr) . '</p><table><tr><th>Account</th><th class="rt">Debit</th><th class="rt">Credit</th><th class="rt">Balance</th></tr>';
        foreach ($tb as $a) $t .= '<tr><td>' . h($a['code'] . ' ' . $a['name']) . '</td><td class="rt">' . money((float) $a['dr']) . '</td><td class="rt">' . money((float) $a['cr']) . '</td><td class="rt">' . money((float) $a['dr'] - (float) $a['cr']) . '</td></tr>';
        $t .= '</table></section>';
        $held = -Ledger::balance('2100');
        $walletSum = (float) Db::val('SELECT COALESCE(SUM(w.balance_after),0) FROM wallet_tx w WHERE w.id IN (SELECT MAX(id) FROM wallet_tx GROUP BY user_id) AND w.user_id IN (SELECT user_id FROM drivers)');
        $chk = '<section class="card"><h2>Checks</h2><p class="' . (abs($held - $walletSum) < 0.01 ? 'ok' : 'neg') . '">Driver wallets in the app Rs ' . money($walletSum) . ' · ledger 2100 Rs ' . money($held) . '</p></section>';
        self::page('Accounts', '<div class="g2">' . $pl . $chk . '</div>' . $t);
    }

    // ---------------------------------------------------------------- layout

    private static function pill(string $s): string
    {
        $c = match ($s) { 'completed', 'verified', 'solved' => 'g', 'cancelled' => 'r', 'searching', 'waiting checks' => 'n', default => 'a' };
        return '<span class="pill ' . $c . '">' . h(ucfirst($s)) . '</span>';
    }

    private static function csrf(): string
    {
        $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
        return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
    }

    private static function flash(string $m): void { $_SESSION['flash'] = ['ok', $m]; }

    private static function go(string $to): never { header('Location: ' . $to, true, 303); exit; }

    private static function page(string $title, string $body): void
    {
        $nav = [['/admin', 'Live operations'], ['/admin/trips', 'Trips'], ['/admin/drivers', 'Drivers'], ['/admin/rates', 'Fare guide and commission'], ['/admin/wallets', 'Wallets and top-ups'], ['/admin/accounts', 'Accounts'], ['/admin/support', 'Support']];
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/admin', PHP_URL_PATH);
        $n = '';
        foreach ($nav as [$href, $l]) $n .= '<a href="' . $href . '"' . ($path === $href ? ' class="on" aria-current="page"' : '') . '>' . h($l) . '</a>';
        $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
        $fl = $f ? '<div class="msg ' . ($f[0] === 'e' ? 'e' : '') . '">' . h($f[1]) . '</div>' : '';
        echo self::head($title) . '<div class="app"><nav><div class="brand">Zar<span>Drive</span> <small>Admin</small></div>' . $n
            . '<div class="who">' . h(self::$me['name']) . ' · ' . h(self::$me['role']) . '<br><a href="/admin/logout">Sign out</a></div></nav><main><h1>' . h($title) . '</h1>' . $fl . $body . '</main></div></body></html>';
    }

    private static function bare(string $title, string $body): void
    {
        echo self::head($title) . '<main class="solo"><div class="brand dark">Zar<span>Drive</span></div><h1>' . h($title) . '</h1>' . $body . '</main></body></html>';
    }

    private static function head(string $title): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . h($title) . ' · ZarDrive</title>'
            . '<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet"><style>' . self::css() . '</style></head><body>';
    }

    private static function css(): string
    {
        return <<<'CSS'
*{box-sizing:border-box}body{margin:0;font:14px/1.5 Inter,system-ui,sans-serif;background:#F4F6F8;color:#111418}
.app{display:flex;flex-wrap:wrap;min-height:100vh}nav{flex:1 1 220px;max-width:240px;background:#111418;color:#fff;padding:18px 12px;display:flex;flex-direction:column;gap:4px}
.brand{font-family:"Space Grotesk",sans-serif;font-weight:700;font-size:20px;padding:0 10px 14px}.brand span{color:#FFB020}.brand small{font:500 12px Inter,sans-serif;color:#A9B1B9}.brand.dark{color:#111418;padding:0 0 10px}
nav a{display:flex;align-items:center;min-height:38px;padding:0 10px;border-radius:8px;color:#C9CED4;text-decoration:none}nav a:hover{color:#fff}nav a.on{background:#232931;color:#fff;font-weight:600}
.who{margin-top:auto;border-top:1px solid #2B323A;padding:12px 10px 0;color:#A9B1B9;font-size:12.5px}.who a{color:#fff;min-height:0;padding:4px 0}
main{flex:999 1 560px;min-width:0;padding:22px 28px 48px;max-width:1240px}main.solo{max-width:420px;margin:48px auto;flex:none}
h1{font-family:"Space Grotesk",sans-serif;font-size:26px;margin:0 0 16px}h2{font-size:15px;margin:0 0 10px}
.card{background:#fff;border-radius:14px;padding:16px 18px;margin-bottom:16px}.scroll{overflow-x:auto}
table{width:100%;border-collapse:collapse}td,th{padding:9px 10px;border-bottom:1px solid #F1F3F5;text-align:left;vertical-align:middle}th{font-size:12px;font-weight:500;color:#4A5560;background:#F8F9FA}
.rt{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.mono{font-family:"Space Grotesk",monospace}tr.tot td{font-weight:700;border-top:2px solid #111418}
.k4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}.k{background:#fff;border-radius:14px;padding:14px 16px;display:flex;flex-direction:column;gap:4px}.k span,.k small{color:#4A5560;font-size:12.5px}.k b{font-family:"Space Grotesk",sans-serif;font-size:24px}
.k.dark{background:#111418;color:#fff}.k.dark span,.k.dark small{color:#C9CED4}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:16px}.g3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
label{display:block;font-size:12.5px;color:#4A5560;margin-bottom:10px}input,select{display:block;width:100%;margin-top:4px;height:38px;padding:0 10px;border:1px solid #D5DADF;border-radius:8px;font:inherit;color:#111418;background:#fff}
td input{margin:0;width:110px}label.chk{display:flex;gap:10px;align-items:center;font-size:14px;color:#111418}label.chk input{width:18px;height:18px;margin:0}
button{height:38px;padding:0 14px;border:0;border-radius:8px;background:#111418;color:#fff;font:inherit;font-weight:600;cursor:pointer}button.s{background:#fff;color:#111418;border:1px solid #D5DADF}
form.inl{display:inline}.row{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.row input{margin:0;display:inline-block}
.pill{font-size:12px;font-weight:600;padding:3px 8px;border-radius:6px;background:#F1F3F5;color:#3A434C}.pill.g{background:#E7F6EE;color:#0E6B47}.pill.r{background:#FEF3F2;color:#912018}.pill.a{background:#FFF1D6;color:#6B4B00}
.msg{padding:10px 14px;border-radius:10px;background:#E7F6EE;color:#0E6B47;margin-bottom:14px;font-weight:500}.msg.e{background:#FEF3F2;color:#912018}
.mut{color:#4A5560;font-size:12.5px}.ok{color:#0E6B47;font-weight:600}.neg{color:#912018;font-weight:600}
@media(max-width:900px){nav{max-width:none}.k4{grid-template-columns:1fr 1fr}.g2,.g3{grid-template-columns:1fr}main{padding:16px}}
CSS;
    }
}

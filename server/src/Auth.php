<?php
declare(strict_types=1);

namespace Zd;

/**
 * App users sign in with their phone and a one-time code (no passwords).
 * Admin staff sign in to the web admin with email and password; the first admin is created on /admin/setup.
 */
final class Auth
{
    public static function normalisePhone(string $p): string
    {
        $d = preg_replace('/\D/', '', $p);
        if (str_starts_with($d, '92')) $d = '0' . substr($d, 2);
        if (!preg_match('/^03\d{9}$/', $d)) throw new ApiError('Enter a mobile number like 03001234567', 422);
        return $d;
    }

    /** Sends a 4-digit code. Returns the code only when debug is on (tests). */
    public static function requestOtp(string $phone): ?string
    {
        $phone = self::normalisePhone($phone);
        $code = (string) random_int(1000, 9999);
        Db::run('DELETE FROM otps WHERE phone = ?', [$phone]);
        Db::insert('otps', ['phone' => $phone, 'code' => password_hash($code, PASSWORD_DEFAULT), 'expires_at' => date('Y-m-d H:i:s', time() + 300)]);
        $sms = zd_config()['sms'];
        if (is_callable($sms)) $sms($phone, "ZarDrive code: $code");
        return zd_config()['debug'] ? $code : null;
    }

    /** Verifies the code, creates the user on first sign-in, returns a bearer token. */
    public static function verifyOtp(string $phone, string $code, string $role, string $name = '', array $driver = []): array
    {
        $phone = self::normalisePhone($phone);
        if (!in_array($role, ['passenger', 'driver'], true)) throw new ApiError('Unknown role', 422);
        $o = Db::one('SELECT * FROM otps WHERE phone = ?', [$phone]);
        if (!$o || $o['expires_at'] < now()) throw new ApiError('Code expired, ask for a new one', 401);
        if ((int) $o['tries'] >= 5) throw new ApiError('Too many tries, ask for a new code', 429);
        if (!password_verify($code, $o['code'])) { Db::run('UPDATE otps SET tries = tries + 1 WHERE phone = ?', [$phone]); throw new ApiError('Wrong code', 401); }
        // a new user is asked for a name (and a driver for the vehicle) first; the code stays valid for that second try
        if (!Db::val('SELECT 1 FROM users WHERE phone = ?', [$phone])) {
            if (trim($name) === '') throw new ApiError('Enter your name', 422);
            if ($role === 'driver' && !isset(Schema::VEHICLES[(string) ($driver['vehicle'] ?? '')])) throw new ApiError('Choose your vehicle', 422);
        }
        Db::run('DELETE FROM otps WHERE phone = ?', [$phone]);
        return Db::tx(function () use ($phone, $role, $name, $driver) {
            $u = Db::one('SELECT * FROM users WHERE phone = ?', [$phone]);
            if (!$u) {
                if (trim($name) === '') throw new ApiError('Enter your name', 422);
                $id = Db::insert('users', ['role' => $role, 'name' => mb_substr(trim($name), 0, 80), 'phone' => $phone, 'created_at' => now()]);
                if ($role === 'driver') {
                    $veh = (string) ($driver['vehicle'] ?? '');
                    if (!isset(Schema::VEHICLES[$veh])) throw new ApiError('Choose your vehicle', 422);
                    Db::insert('drivers', ['user_id' => $id, 'vehicle' => $veh, 'plate' => mb_substr((string) ($driver['plate'] ?? ''), 0, 20)]);
                }
                $u = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
            } elseif ($u['role'] !== $role) {
                throw new ApiError('This number is registered as a ' . $u['role'], 409);
            }
            if ($u['status'] !== 'active') throw new ApiError('Account is ' . $u['status'], 403);
            $token = bin2hex(random_bytes(24));
            Db::insert('tokens', ['token' => hash('sha256', $token), 'user_id' => $u['id'], 'created_at' => now()]);
            return ['token' => $token, 'user' => ['id' => (int) $u['id'], 'name' => $u['name'], 'role' => $u['role']]];
        });
    }

    public static function fromToken(?string $bearer): ?array
    {
        if (!$bearer) return null;
        return Db::one('SELECT u.* FROM tokens t JOIN users u ON u.id = t.user_id WHERE t.token = ? AND u.status = ?', [hash('sha256', $bearer), 'active']);
    }

    // ---- admin web

    public static function adminCount(): int { return (int) Db::val("SELECT COUNT(*) FROM users WHERE role = 'admin'"); }

    public static function createStaff(string $role, string $name, string $email, string $pass, ?int $by): int
    {
        if (!in_array($role, ['admin', 'agent', 'accounts'], true)) throw new ApiError('Unknown staff role', 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError('Enter a valid email', 422);
        if (strlen($pass) < 10) throw new ApiError('Password must be at least 10 characters', 422);
        if (Db::val('SELECT 1 FROM users WHERE email = ?', [$email])) throw new ApiError('Email already used', 409);
        $id = Db::insert('users', ['role' => $role, 'name' => trim($name), 'email' => strtolower($email), 'pass_hash' => password_hash($pass, PASSWORD_DEFAULT), 'created_at' => now()]);
        Audit::log($by, 'staff.create', "$role $email");
        return $id;
    }

    public static function staffLogin(string $email, string $pass): ?array
    {
        $u = Db::one("SELECT * FROM users WHERE email = ? AND role IN ('admin','agent','accounts') AND status = 'active'", [strtolower(trim($email))]);
        return $u && password_verify($pass, (string) $u['pass_hash']) ? $u : null;
    }
}

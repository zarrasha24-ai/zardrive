<?php
declare(strict_types=1);

namespace Zd;

final class Settings
{
    private static array $cache = [];

    public static function get(string $k, ?string $def = null): ?string
    {
        if (!array_key_exists($k, self::$cache)) self::$cache[$k] = Db::val('SELECT v FROM settings WHERE k = ?', [$k]);
        return self::$cache[$k] ?? $def;
    }

    public static function num(string $k, float $def = 0): float { return (float) (self::get($k) ?? $def); }

    public static function set(string $k, string $v, ?int $by = null): void
    {
        $old = self::get($k);
        if (Db::val('SELECT 1 FROM settings WHERE k = ?', [$k])) Db::run('UPDATE settings SET v = ? WHERE k = ?', [$v, $k]);
        else Db::insert('settings', ['k' => $k, 'v' => $v]);
        self::$cache[$k] = $v;
        $secret = str_contains($k, 'key');   // keys are never written to the audit log
        Audit::log($by, 'setting', $secret ? "$k: " . ($v === '' ? 'removed' : 'changed') : "$k: " . ($old ?? '–') . " → $v");
    }

    public static function flush(): void { self::$cache = []; }
}

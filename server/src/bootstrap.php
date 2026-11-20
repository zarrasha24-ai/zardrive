<?php
declare(strict_types=1);

/**
 * ZarDrive server bootstrap.
 * Plain PHP 8.2, no outside packages. Config comes from config.local.php (never committed):
 *   return ['dsn' => 'mysql:host=localhost;dbname=zardrive;charset=utf8mb4', 'user' => '...', 'pass' => '...',
 *           'debug' => false, 'tz' => 'Asia/Karachi', 'sms' => null];
 */

spl_autoload_register(function (string $c): void {
    if (str_starts_with($c, 'Zd\\')) {
        $f = __DIR__ . '/' . str_replace('\\', '/', substr($c, 3)) . '.php';
        if (is_file($f)) require $f;
    }
});

function zd_config(): array
{
    static $c = null;
    if ($c === null) {
        $f = dirname(__DIR__) . '/config.local.php';
        $c = (is_file($f) ? require $f : []) + [
            'dsn' => 'sqlite:' . dirname(__DIR__) . '/storage/zardrive.sqlite',
            'user' => null, 'pass' => null, 'debug' => false, 'tz' => 'Asia/Karachi', 'sms' => null, 'http' => null,
        ];
        if (isset($GLOBALS['ZD_CONFIG_OVERRIDE'])) $c = $GLOBALS['ZD_CONFIG_OVERRIDE'] + $c;
    }
    return $c;
}

date_default_timezone_set(zd_config()['tz']);

function h(mixed $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function money(float $v): string { return number_format($v, 2); }
function now(): string { return date('Y-m-d H:i:s'); }
function r2(float $v): float { return round($v + 0.0000001, 2); }

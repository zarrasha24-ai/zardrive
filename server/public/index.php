<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Zd\{Admin, Api, ApiError};

$path = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Passenger app (/app) and driver app (/driver): static files kept next to this file, served from here
// so the web folder on the host only needs index.php and .htaccess.
if (preg_match('#^/(app|driver)(/[A-Za-z0-9._-]*)?$#', $path, $m)) {
    $name = ltrim($m[2] ?? '', '/') ?: 'index.html';
    $file = __DIR__ . '/' . $m[1] . '/' . $name;
    $types = ['html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8',
        'json' => 'application/manifest+json', 'webmanifest' => 'application/manifest+json', 'svg' => 'image/svg+xml', 'png' => 'image/png'];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!str_contains($name, '..') && isset($types[$ext]) && is_file($file)) {
        header('Content-Type: ' . $types[$ext]);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: ' . ($ext === 'html' || $name === 'sw.js' ? 'no-cache' : 'max-age=3600'));
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit('Not found');
}

if (str_starts_with($path, '/api/')) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $raw = file_get_contents('php://input') ?: '';
        $in = $raw !== '' ? (json_decode($raw, true) ?? []) : $_POST;
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $bearer = preg_match('/^Bearer\s+(\S+)$/', $auth, $m) ? $m[1] : null;
        echo json_encode(['ok' => true, 'data' => Api::handle($method, $path, (is_array($in) ? $in : []) + $_GET, $bearer)], JSON_UNESCAPED_UNICODE);
    } catch (ApiError $e) {
        http_response_code($e->status);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('zardrive api: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => zd_config()['debug'] ? $e->getMessage() : 'Something went wrong. Please try again.']);
    }
    exit;
}

if (str_starts_with($path, '/admin')) {
    header('X-Frame-Options: DENY');
    Admin::handle($method, $path, $_POST);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>ZarDrive</title><body style="font-family:system-ui;padding:40px"><h1>ZarDrive</h1><p>Fair-offer rides, food, courier, intercity and freight. Coming soon.</p></body>';

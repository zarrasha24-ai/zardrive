<?php
declare(strict_types=1);

// php tests/places.php [places.tsv.gz] – address search on a throw-away SQLite database.
$GLOBALS['ZD_CONFIG_OVERRIDE'] = ['dsn' => 'sqlite::memory:', 'debug' => true, 'http' => fn() => null];
require dirname(__DIR__) . '/src/bootstrap.php';

use Zd\{Schema, Places, Api, ApiError, Auth};

$pass = 0;
function ok(bool $c, string $what): void { global $pass; if (!$c) { fwrite(STDERR, "FAIL: $what\n"); exit(1); } $pass++; echo "  ✓ $what\n"; }

Schema::install();
$file = $argv[1] ?? sys_get_temp_dir() . '/zd-places-sample.tsv';
if (!isset($argv[1])) {
    file_put_contents($file, "name\tkind\tlat\tlng\tarea\tcity\tweight\n"
        . "Saddar Road\troad\t33.990663\t71.532759\tNothia\tPeshawar Cantt\t45\n"
        . "Saddar\tmarket\t30.188709\t71.438773\tSaddar\tMultan\t45\n"
        . "Hayatabad Medical Complex\thospital\t33.992377\t71.449844\tPhase 1\tUniversity Town\t55\n"
        . "Hayatabad\tarea\t33.977081\t71.433797\t\tJamrud\t65\n"
        . "University Road\troad\t34.005\t71.49\tBoard Bazar\tPeshawar\t44\n"
        . "bad line\n");
}
$n = Places::import($file);
ok($n > 0, "import loaded $n rows");
ok(Places::import($file) === $n, 'import again replaces, not doubles');
ok(Places::norm(' Hayatabad  Ph-3 ') === 'hayatabad ph 3', 'search text is normalised');
ok(Places::search('s') === [], 'one letter returns nothing');

$pesh = [34.0, 71.53];
$r = Places::search('saddar', ...$pesh);
ok($r && str_contains($r[0]['label'] . $r[0]['name'], 'Peshawar') || ($r[0]['lat'] > 33.8 && $r[0]['lat'] < 34.2), 'Saddar near Peshawar comes first: ' . ($r[0]['name'] ?? '-') . ' / ' . ($r[0]['label'] ?? ''));
$r = Places::search('hayatabad medical', ...$pesh);
ok(($r[0]['name'] ?? '') !== '' && stripos($r[0]['name'], 'Hayatabad Medical') === 0, 'two words: ' . ($r[0]['name'] ?? '-'));
$r = Places::search('university road peshawar');
ok(count($r) > 0 && stripos($r[0]['name'], 'University Road') !== false, 'name + city words: ' . ($r[0]['name'] ?? '-') . ' / ' . ($r[0]['label'] ?? ''));
$rev = Places::reverse(33.9907, 71.5328);
ok($rev !== null && $rev['km'] < 0.3, 'map pin → nearest name: ' . ($rev['name'] ?? '-'));
ok(Places::reverse(25.0, 62.0) === null, 'no name in the desert');

$code = Auth::requestOtp('03001112223');
$tok = Auth::verifyOtp('03001112223', (string) $code, 'passenger', 'Test')['token'];
$api = Api::handle('GET', '/api/places/search', ['q' => 'hayatabad', 'lat' => '34.0', 'lng' => '71.53'], $tok);
ok(count($api['places']) > 0, 'API /api/places/search works');
try { Api::handle('GET', '/api/places/search', ['q' => 'x'], null); ok(false, 'needs sign-in'); } catch (ApiError $e) { ok($e->status === 401, 'API needs sign-in'); }

echo "\n$pass checks passed\n";

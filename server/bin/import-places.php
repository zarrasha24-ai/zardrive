<?php
declare(strict_types=1);

// Load the Pakistan place list for address search.
//   php bin/import-places.php storage/pakistan-places.tsv.gz
// Replaces all rows in "places" in one go (old rows stay if anything fails). Takes 1–3 minutes on shared hosting.
if (isset($_SERVER['REQUEST_METHOD']) && PHP_SAPI !== 'cli') { http_response_code(404); exit; }  // never from the web; cPanel cron php may be the CGI build
require dirname(__DIR__) . '/src/bootstrap.php';

$file = $argv[1] ?? dirname(__DIR__) . '/storage/pakistan-places.tsv.gz';
if (!is_file($file)) { fwrite(STDERR, "File not found: $file\n"); exit(1); }
Zd\Schema::install();
$t = microtime(true);
$n = Zd\Places::import($file, fn(int $n) => print("  $n …\n"));
printf("Loaded %d places in %.0f s\n", $n, microtime(true) - $t);

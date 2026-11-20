<?php
declare(strict_types=1);

// php bin/install.php  – creates or updates the database tables and default settings. Safe to run again.
require dirname(__DIR__) . '/src/bootstrap.php';
@mkdir(dirname(__DIR__) . '/storage', 0750, true);
Zd\Schema::install();
echo "ZarDrive database ready.\n";

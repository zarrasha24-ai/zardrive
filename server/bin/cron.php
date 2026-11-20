<?php
declare(strict_types=1);

// Run every 10 minutes: php bin/cron.php  – earns week-pass income day by day and expires stale requests.
require dirname(__DIR__) . '/src/bootstrap.php';
$n = Zd\Wallet::recognisePasses();
$stale = Zd\Db::run("UPDATE trips SET status = 'cancelled', cancel_reason = 'no driver accepted in 10 minutes' WHERE status = 'searching' AND created_at < ?", [date('Y-m-d H:i:s', time() - 600)])->rowCount();
echo date('c') . " passes:$n stale:$stale\n";

<?php
declare(strict_types=1);

/*
 * Scheduled work, run from the command line once an hour:
 *
 *   php /path/to/app/cron/run.php            everything that is due
 *   php /path/to/app/cron/run.php alerts     only the alerts
 *   php /path/to/app/cron/run.php market     re-import market data now
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

if (!App\Config::loaded()) {
    fwrite(STDERR, "config.php not found.\n");
    exit(1);
}

$job = $argv[1] ?? 'auto';
if (!in_array($job, ['auto', 'alerts', 'market', 'prune', 'score', 'backup'], true)) {
    fwrite(STDERR, "Unknown job. Use: auto | alerts | market | prune | score | backup\n");
    exit(1);
}

echo '[' . gmdate('Y-m-d H:i') . " UTC]\n" . implode("\n", App\Jobs::run($job)) . "\n";

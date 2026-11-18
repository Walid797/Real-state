<?php
declare(strict_types=1);

/*
 * Scheduled work, run from the command line once an hour:
 *
 *   php /path/to/app/cron/run.php            everything that is due
 *   php /path/to/app/cron/run.php alerts     only the alerts
 *   php /path/to/app/cron/run.php market     re-import market data now
 *   php /path/to/app/cron/run.php score      read listing e-mails, score homes waiting for it (every 10 minutes)
 *   php /path/to/app/cron/run.php mail       read listing e-mails now
 *   php /path/to/app/cron/run.php county     county records for homes for sale, and their ZIPs' recorded sales
 *   php /path/to/app/cron/run.php status     print usage, recent API calls and the error log
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
if ($job === 'status') {
    echo '[' . gmdate('Y-m-d H:i') . " UTC]\n" . implode("\n", App\Diagnostics::report()) . "\n";
    echo "OpenStreetMap servers:\n  " . implode("\n  ", App\Providers\Overpass::probe()) . "\n";
    exit(0);
}
if (!in_array($job, ['auto', 'alerts', 'market', 'prune', 'score', 'backup', 'mail', 'county'], true)) {
    fwrite(STDERR, "Unknown job. Use: auto | alerts | market | prune | score | backup | mail | county | status\n");
    exit(1);
}

echo '[' . gmdate('Y-m-d H:i') . " UTC]\n" . implode("\n", App\Jobs::run($job)) . "\n";

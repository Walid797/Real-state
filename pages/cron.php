<?php
declare(strict_types=1);

/*
 * HTTP entry for scheduled work, for hosts where a command-line cron is not available:
 *   GET /cron?key=<cron_key>&job=auto|alerts|market|prune|score|backup
 * The command-line version (cron/run.php) is preferred — it has no time limit.
 */

$key = (string) App\Config::get('cron_key', '');
$given = input('key', '', 200);
if ($key === '' || strlen($key) < 16 || !hash_equals($key, $given)) {
    App\Kernel::plain(404, 'Not found', 'Nothing here.');
}

@set_time_limit(280);
ignore_user_abort(true);
$job = in_array(input('job', 'auto'), ['auto', 'alerts', 'market', 'prune', 'score', 'backup'], true) ? input('job', 'auto') : 'auto';

header('Content-Type: text/plain; charset=utf-8');
echo implode("\n", App\Jobs::run($job)), "\n";

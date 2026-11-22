<?php
declare(strict_types=1);

namespace App;

use App\Providers\ZillowResearch;
use App\Services\Alerts;

/** Scheduled work. Called hourly by cron/run.php, or over HTTP by /cron?key=… */
final class Jobs
{
    /** @return string[] one line per thing that happened */
    public static function run(string $job = 'auto', string $ctx = 'cron'): array
    {
        $log = [];
        $lock = fopen(APP_ROOT . '/storage/cron.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['Another run is still in progress — skipped.'];
        }
        try {
            if (Migrator::pending()) {
                $log[] = 'Database updated: ' . implode(', ', Migrator::run()) . '.';
            }
            if (in_array($job, ['auto', 'score', 'intelligence'], true)) {
                foreach (Services\PropertyAnalysis::run() as $line) {
                    $log[] = $line;
                }
            }
            if ($job === 'auto' || $job === 'alerts') {
                $r = Alerts::runDue($ctx);
                $log[] = 'Alerts: ' . $r['checked'] . ' checked, ' . $r['hits'] . ' new.';
                foreach ($r['notes'] as $n) {
                    $log[] = '  ' . $n;
                }
                $d = Alerts::sendDigests();
                if ($d) {
                    $log[] = 'Daily summaries sent: ' . $d . '.';
                }
            }
            if ($job === 'auto' || $job === 'score' || $job === 'mail') {
                // Listing e-mails: every scheduler run, so new homes arrive within about ten minutes.
                foreach (Services\MailListings::run($job === 'mail' ? 200 : 60) as $line) {
                    $log[] = $line;
                }
            }
            if ($job === 'auto' || $job === 'county') {
                foreach (Services\CountyRecords::run($job === 'county' ? 240 : 90) as $line) {
                    $log[] = $line;
                }
            }
            if ($job === 'auto' || $job === 'score') {
                // Homes never scored, or whose record changed since: oldest first, a slice per run.
                $ids = array_column(Db::all(
                    "SELECT id FROM properties WHERE status IN ('for_sale','pending') AND (scored_at IS NULL OR scored_at < updated_at OR scored_at < ?)
                     ORDER BY scored_at IS NOT NULL, scored_at LIMIT 400",
                    [gmdate('Y-m-d H:i:s', time() - 3 * 86400)]
                ), 'id');
                if ($ids) {
                    $log[] = 'Scored ' . Services\Score::refreshMany($ids, $job === 'score' ? 240 : 90) . ' of ' . count($ids) . ' waiting homes.';
                }
            }
            if ($job === 'backup' || ($job === 'auto' && self::due('job_backup_at', 23))) {
                $log[] = 'Backup written: ' . Backup::run() . '.';
                Settings::set('job_backup_at', now());
            }
            if ($job === 'prune' || ($job === 'auto' && self::due('job_prune_at', 20))) {
                $n = Cache::prune();
                Db::run('DELETE FROM login_attempts WHERE attempted_at < ?', [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
                Db::run('DELETE FROM api_log WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);
                Db::run('DELETE FROM notify_log WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);
                Db::run('DELETE FROM snapshots WHERE fetched_at < ?', [gmdate('Y-m-d H:i:s', time() - 60 * 86400)]);
                Settings::set('job_prune_at', now());
                $log[] = "Housekeeping: $n expired cache rows removed.";
            }
            if ($job === 'market' || ($job === 'auto' && self::due('job_market_at', 24 * 27) && (int) Db::val('SELECT COUNT(*) FROM market_regions') > 0)) {
                $r = ZillowResearch::import();
                Settings::set('job_market_at', now());
                $log[] = 'Market: ' . $r['points'] . ' data points for ' . plural($r['regions'], 'area') . '.';
                foreach ($r['errors'] as $e) {
                    $log[] = '  ' . $e;
                }
            }
            Settings::set('job_last_run', now());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $log ?: ['Nothing was due.'];
    }

    private static function due(string $key, int $hours): bool
    {
        $last = Settings::get($key);
        return !$last || strtotime($last . ' UTC') < time() - $hours * 3600;
    }
}

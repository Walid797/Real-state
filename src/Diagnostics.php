<?php
declare(strict_types=1);

namespace App;

/** What happened lately: the tail of the error log and the last provider calls. Admin-only views and `cron/run.php status`. */
final class Diagnostics
{
    public static function logFile(): string
    {
        return APP_ROOT . '/storage/logs/php-error.log';
    }

    /** The last $n lines of the PHP log, newest last. Reads only the end of the file. */
    public static function logTail(int $n = 20): array
    {
        $file = self::logFile();
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }
        $size = filesize($file) ?: 0;
        $h = fopen($file, 'rb');
        if ($h === false) {
            return [];
        }
        $chunk = min($size, 64 * 1024);
        fseek($h, -$chunk, SEEK_END);
        $text = (string) fread($h, $chunk);
        fclose($h);
        $lines = array_values(array_filter(explode("\n", $text), static fn ($l) => trim($l) !== ''));
        if ($chunk < $size) {
            array_shift($lines); // first line is probably cut
        }
        return array_slice($lines, -$n);
    }

    public static function apiCalls(int $n = 10): array
    {
        return Db::all('SELECT provider, endpoint, status, ms, note, context, created_at FROM api_log ORDER BY id DESC LIMIT ' . (int) $n);
    }

    /** @return string[] a plain-text report for the command line */
    public static function report(): array
    {
        $out = [];
        foreach (Quota::PROVIDERS as $id => $label) {
            $out[] = sprintf('%s: %s, %d of %d requests used this month', $label, Settings::has($id . '_key') ? 'key set' : 'no key', Quota::used($id), Quota::limit($id));
        }
        $out[] = 'Homes on file: ' . (int) Db::val('SELECT COUNT(*) FROM properties')
            . ' (for sale ' . (int) Db::val("SELECT COUNT(*) FROM properties WHERE status = 'for_sale'")
            . ', scored ' . (int) Db::val("SELECT COUNT(*) FROM properties WHERE status = 'for_sale' AND scored_at IS NOT NULL") . ')';
        $out[] = 'Last provider calls:';
        foreach (self::apiCalls(10) as $c) {
            $out[] = sprintf('  %s %s %s %d %dms %s%s', $c['created_at'], $c['provider'], $c['endpoint'], $c['status'], $c['ms'], $c['context'], $c['note'] ? ' — ' . $c['note'] : '');
        }
        $out[] = 'Last snapshots:';
        foreach (Db::all('SELECT provider, mode, loc_key, result_count, truncated, fetched_at FROM snapshots ORDER BY id DESC LIMIT 6') as $s) {
            $out[] = sprintf('  %s %s %s %s: %d%s', $s['fetched_at'], $s['provider'], $s['mode'], $s['loc_key'], $s['result_count'], $s['truncated'] ? ' (capped)' : '');
        }
        $out[] = sprintf('Mail: mailbox %s via %s:%s (%s), password %s',
            Settings::get('smtp_user', '') ?: '(none — server mailer)', Settings::get('smtp_host', ''), Settings::get('smtp_port', ''),
            Settings::get('smtp_secure', ''), Settings::has('smtp_pass') ? 'set' : 'not set');
        $out[] = 'Last messages sent:';
        foreach (Db::all('SELECT channel, ok, subject, detail, created_at FROM notify_log ORDER BY id DESC LIMIT 8') as $m) {
            $out[] = sprintf('  %s %s %s "%s" %s', $m['created_at'], $m['channel'], $m['ok'] ? 'OK' : 'FAILED', $m['subject'], $m['detail']);
        }
        $out[] = 'Listing e-mails: ' . (Services\MailListings::enabled() ? 'on' : 'off') . ', last checked ' . (Settings::get('mail_checked_at') ?: 'never')
            . ', last UID ' . (Settings::get('mail_last_uid') ?: '-') . (Settings::get('mail_last_error') ? ', error: ' . Settings::get('mail_last_error') : '');
        foreach (Db::all('SELECT sender, subject, found, added, note, created_at FROM mail_log ORDER BY id DESC LIMIT 5') as $m) {
            $out[] = sprintf('  %s %s "%s" found %d, added %d — %s', $m['created_at'], $m['sender'], mb_substr($m['subject'], 0, 60), $m['found'], $m['added'], $m['note']);
        }
        $out[] = 'County records: ' . (int) Db::val("SELECT COUNT(*) FROM properties WHERE JSON_EXTRACT(COALESCE(details, JSON_OBJECT()), '$.county_record') IS NOT NULL") . ' homes with a Fairfax record, '
            . (int) Db::val("SELECT COUNT(*) FROM properties WHERE source = 'county'") . ' from county sales lists, '
            . (int) Db::val("SELECT COUNT(*) FROM properties WHERE JSON_EXTRACT(COALESCE(details, JSON_OBJECT()), '$.county_tried_at') IS NOT NULL") . ' looked up, '
            . (int) Db::val("SELECT COUNT(*) FROM properties WHERE JSON_EXTRACT(COALESCE(details, JSON_OBJECT()), '$.county_error') = true") . ' failed';
        $out[] = '  Records by source: ' . implode(', ', array_map(static fn ($r) => ($r['s'] ?? 'fairfax') . ' ' . $r['n'],
            Db::all("SELECT JSON_UNQUOTE(JSON_EXTRACT(details, '$.county_record.source')) s, COUNT(*) n FROM properties WHERE JSON_EXTRACT(COALESCE(details, JSON_OBJECT()), '$.county_record') IS NOT NULL GROUP BY s")))
            . '; sales lists by source: ' . implode(', ', array_map(static fn ($r) => $r['s'] . ' ' . $r['n'],
            Db::all("SELECT SUBSTRING_INDEX(source_id, ':', 1) s, COUNT(*) n FROM properties WHERE source = 'county' GROUP BY s")));
        foreach (Db::all("SELECT address, city, state, zip, county, JSON_UNQUOTE(JSON_EXTRACT(details, '$.county_tried_at')) tried FROM properties
                          WHERE status IN ('for_sale','pending') AND county LIKE '%columbia%' LIMIT 4") as $r) {
            $out[] = sprintf('  DC sample: %s, %s %s %s (county %s) tried %s', $r['address'], $r['city'], $r['state'], $r['zip'], $r['county'], $r['tried'] ?? 'never');
        }
        $out[] = '  Counties without a source (homes for sale): ' . (implode(', ', array_map(static fn ($m) => $m['state'] . ' ' . ($m['county'] ?: '(no county)') . ' ' . $m['homes'] . ' [' . $m['zips'] . ']',
            Services\CountyRecords::missing(15))) ?: 'none');
        $out[] = '  Homes for sale by state and county: ' . implode(', ', array_map(static fn ($r) => ($r['county'] ?? '(none)') . ' ' . $r['n'],
            Db::all("SELECT CONCAT(state, ' ', COALESCE(county, '')) county, COUNT(*) n FROM properties WHERE status IN ('for_sale','pending') GROUP BY state, county ORDER BY n DESC LIMIT 15")));
        $saved = json_decode((string) Settings::get('cesium_check', ''), true);
        $out[] = '3D view: ' . (Settings::has('cesium_key') ? 'token set' : 'no token')
            . ($saved ? ', saved check ' . ($saved['ok'] ? 'OK' : 'failed: ' . $saved['why']) . ' (' . ($saved['at'] ?? '?') . ')' : '');
        if (Settings::has('cesium_key')) {
            $now = Providers\CesiumIon::check((string) Settings::get('cesium_key'));
            $out[] = '3D view, checked now from this server: ' . ($now['ok'] ? 'OK — ' : 'FAILED — ') . $now['why'];
        }
        $out[] = 'PHP limits: memory ' . ini_get('memory_limit') . ', time ' . ini_get('max_execution_time') . 's, PHP ' . PHP_VERSION;
        $out[] = 'Log (last 12 lines):';
        foreach (self::logTail(12) ?: ['  (empty)'] as $l) {
            $out[] = '  ' . mb_substr($l, 0, 400);
        }
        return $out;
    }
}

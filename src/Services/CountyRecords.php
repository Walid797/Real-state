<?php
declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\PropertyRepo;
use App\Providers\Fairfax;
use App\Settings;

/**
 * Official county records (free, no limit) applied to the homes on file:
 *   enrich()    — one home: specs, assessed value, tax, lot, last sale, sale history
 *   areaSales() — one ZIP code: every arm's-length sale of the last 12 months, as comparables
 * Only Fairfax County publishes these openly so far; the class is the place to add the next county.
 */
final class CountyRecords
{
    /** Specs the county measures itself — they only fill blanks, since a listing may describe a later remodel. */
    private const FILL_ONLY = ['year_built', 'beds', 'baths', 'sqft', 'lot_sqft', 'has_basement', 'property_type', 'lat', 'lng', 'county'];
    /** Figures the county is the authority on — they replace whatever came from elsewhere. */
    private const AUTHORITATIVE = ['assessed_value', 'tax_annual', 'last_sale_price', 'last_sale_date'];

    private const RETRY_DAYS = 30;
    private const AREA_DAYS = 14;

    public static function covers(array $p): bool
    {
        return Fairfax::covers($p);
    }

    /** Should this home be looked up now? */
    public static function wanted(array $p): bool
    {
        if (!self::covers($p)) {
            return false;
        }
        $d = is_array($p['details'] ?? null) ? $p['details'] : (json_decode((string) ($p['details'] ?? ''), true) ?: []);
        if (!empty($d['county_record'])) {
            return false;
        }
        $tried = $d['county_tried_at'] ?? null;
        $wait = !empty($d['county_error']) ? 86400 : self::RETRY_DAYS * 86400; // the county's server failing: try again tomorrow
        return !$tried || strtotime($tried . ' UTC') < time() - $wait;
    }

    /** @return 'found'|'none'|'error' */
    public static function enrich(array $p): string
    {
        $id = (int) $p['id'];
        Db::run("UPDATE properties SET details = JSON_SET(COALESCE(details, JSON_OBJECT()), '$.county_tried_at', ?, '$.county_error', false) WHERE id = ?", [now(), $id]);
        try {
            $r = Fairfax::home($p);
        } catch (\RuntimeException $e) {
            error_log('[county] ' . $e->getMessage());
            Db::run("UPDATE properties SET details = JSON_SET(details, '$.county_error', true) WHERE id = ?", [$id]);
            return 'error';
        }
        if (!$r || !empty($r['none'])) {
            return 'none';
        }
        $row = ['address' => $p['address'], 'city' => $p['city'], 'state' => $p['state'], 'zip' => $p['zip'], 'details' => ['county_record' => $r['county']]];
        foreach ($r['facts'] as $k => $v) {
            if (in_array($k, self::AUTHORITATIVE, true) || (in_array($k, self::FILL_ONLY, true) && ($p[$k] ?? null) === null)) {
                $row[$k] = $v;
            }
        }
        // A newer recorded sale than the one on file goes into the history too.
        foreach (array_reverse($r['county']['sales'] ?? []) as $s) {
            if ($s['price'] > 1000 && preg_match('/^(Valid and verified|Atypical upgrades)/i', $s['type'])) {
                $row['events'][] = ['event' => 'sold', 'price' => $s['price'], 'date' => $s['date']];
            }
        }
        PropertyRepo::upsert($row, 'county');
        Activity::log('pull', $id, 'Fairfax County record');
        return 'found';
    }

    /** When was this ZIP's sales list last brought in? */
    public static function areaFetchedAt(string $zip): ?string
    {
        return Settings::get('county_sales_' . $zip);
    }

    public static function areaDue(string $zip): bool
    {
        $at = self::areaFetchedAt($zip);
        return !$at || strtotime($at . ' UTC') < time() - self::AREA_DAYS * 86400;
    }

    /**
     * Brings in the last 12 months of recorded sales in one ZIP and re-scores the listings there.
     * @return array{ok:bool, sales:int, message:string}
     */
    public static function areaSales(string $zip, float $budgetSeconds = 60): array
    {
        try {
            $rows = Fairfax::salesInZip($zip, 365, $budgetSeconds);
        } catch (\RuntimeException $e) {
            error_log('[county] sales ' . $zip . ': ' . $e->getMessage());
            return ['ok' => false, 'sales' => 0, 'message' => $e->getMessage()];
        }
        $n = 0;
        foreach ($rows as $r) {
            try {
                PropertyRepo::upsert($r, 'county');
                $n++;
            } catch (\InvalidArgumentException) {
            }
        }
        Settings::set('county_sales_' . $zip, now());
        $ids = array_map('intval', array_column(Db::all("SELECT id FROM properties WHERE zip = ? AND status IN ('for_sale','pending')", [$zip]), 'id'));
        if ($ids) {
            Db::run('UPDATE properties SET scored_at = NULL WHERE id IN (' . implode(',', $ids) . ')');
        }
        return ['ok' => true, 'sales' => $n, 'message' => plural($n, 'recorded sale') . ' from the last 12 months in ' . $zip . ' (Fairfax County records).'];
    }

    /**
     * Scheduled: look up homes for sale that have no county record yet, and refresh the sales of
     * every Fairfax ZIP with homes for sale once every two weeks.
     * @return string[]
     */
    public static function run(float $budgetSeconds = 120): array
    {
        $t = microtime(true);
        $log = [];
        // Only homes that could be in Fairfax County (the county name, or a Fairfax ZIP when it is missing).
        $homes = Db::all(
            "SELECT * FROM properties WHERE state = 'VA' AND status IN ('for_sale','pending')
               AND JSON_EXTRACT(COALESCE(details, JSON_OBJECT()), '$.county_record') IS NULL
               AND ((county LIKE '%fairfax%' AND county NOT LIKE '%city%')
                    OR ((county IS NULL OR county = '') AND (zip LIKE '201__' OR zip LIKE '220__' OR zip LIKE '221__' OR zip LIKE '223__')))
             ORDER BY first_seen_at DESC LIMIT 400"
        );
        $found = $tried = 0;
        foreach ($homes as $p) {
            if (microtime(true) - $t > $budgetSeconds / 2) {
                break;
            }
            $p['details'] = json_decode((string) ($p['details'] ?? ''), true) ?: [];
            if (!self::wanted($p)) {
                continue;
            }
            $tried++;
            if (self::enrich($p) === 'found') {
                $found++;
            }
        }
        $log[] = $tried ? "County records: $found of $tried homes found." : 'County records: no Fairfax home for sale needed a lookup (' . count($homes) . ' without a record, all checked recently).';
        $zips = array_column(Db::all(
            "SELECT zip, COUNT(*) n FROM properties WHERE state = 'VA' AND status IN ('for_sale','pending')
               AND JSON_EXTRACT(COALESCE(details, JSON_OBJECT()), '$.county_record') IS NOT NULL
             GROUP BY zip ORDER BY n DESC"
        ), 'zip');
        foreach ($zips as $zip) {
            if (microtime(true) - $t > $budgetSeconds) {
                break;
            }
            if ($zip !== '' && self::areaDue($zip)) {
                $log[] = '  ' . self::areaSales($zip, max(10, $budgetSeconds - (microtime(true) - $t)))['message'];
            }
        }
        return $log;
    }
}

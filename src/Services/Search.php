<?php
declare(strict_types=1);

namespace App\Services;

use App\Address;
use App\Db;
use App\PropertyRepo;
use App\Providers\OpenWebNinja;
use App\Providers\RentCast;
use App\Quota;
use App\Settings;

/**
 * Search = "make sure the area is in the local database, then query the database".
 *
 * A provider is only called when no recent snapshot covers the request:
 *   - same area + same pushed filters inside the freshness window, or
 *   - a complete unfiltered pull of the area (every filter is then answered locally).
 */
final class Search
{
    /** Request fields that describe a search (also what a saved search / alert stores). */
    public const FIELDS = [
        'q', 'mode', 'price_min', 'price_max', 'beds_min', 'baths_min', 'sqft_min', 'sqft_max',
        'lot_min', 'year_min', 'year_max', 'hoa_max', 'dom_max', 'ppsf_max', 'sold_days', 'types', 'sort',
    ];

    public static function fromRequest(array $src): array
    {
        $f = ['q' => mb_substr(trim((string) ($src['q'] ?? '')), 0, 190)];
        $f['mode'] = ($src['mode'] ?? '') === 'sold' ? 'sold' : 'sale';
        foreach (['price_min', 'price_max', 'beds_min', 'baths_min', 'sqft_min', 'sqft_max', 'lot_min', 'year_min', 'year_max', 'hoa_max', 'dom_max', 'ppsf_max', 'below_median'] as $k) {
            $v = isset($src[$k]) && is_scalar($src[$k]) ? parse_num((string) $src[$k]) : null;
            if ($v !== null && $v >= 0) {
                $f[$k] = $v;
            }
        }
        $days = (int) ($src['sold_days'] ?? 180);
        $f['sold_days'] = in_array($days, [30, 90, 180, 365, 730], true) ? $days : 180;
        $f['types'] = array_values(array_intersect(PropertyRepo::TYPES, (array) ($src['types'] ?? [])));
        $f['sort'] = isset(PropertyRepo::SORTS[$src['sort'] ?? '']) ? $src['sort'] : 'newest';
        return $f;
    }

    /** Only the fields worth keeping in a URL or a saved search. */
    public static function compact(array $f): array
    {
        $out = [];
        foreach (self::FIELDS + [99 => 'below_median'] as $k) {
            $v = $f[$k] ?? null;
            if ($v === null || $v === '' || $v === [] || ($k === 'mode' && $v === 'sale') || ($k === 'sort' && $v === 'newest')
                || ($k === 'sold_days' && (($f['mode'] ?? 'sale') !== 'sold' || (int) $v === 180))) {
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /** "Austin, TX · $300K–$500K · 3+ bd · Single Family" */
    public static function describe(array $f): string
    {
        $bits = [];
        if (!empty($f['q'])) {
            $loc = Address::parseLocation((string) $f['q']);
            $bits[] = $loc['label'] ?? $f['q'];
        }
        if (($f['mode'] ?? 'sale') === 'sold') {
            $bits[] = 'sold in the last ' . self::daysLabel((int) ($f['sold_days'] ?? 180));
        }
        $lo = $f['price_min'] ?? null;
        $hi = $f['price_max'] ?? null;
        if ($lo && $hi) {
            $bits[] = money_short($lo) . '–' . money_short($hi);
        } elseif ($lo) {
            $bits[] = 'from ' . money_short($lo);
        } elseif ($hi) {
            $bits[] = 'up to ' . money_short($hi);
        }
        if (!empty($f['beds_min'])) {
            $bits[] = trim_num($f['beds_min']) . '+ bd';
        }
        if (!empty($f['baths_min'])) {
            $bits[] = trim_num($f['baths_min']) . '+ ba';
        }
        if (!empty($f['sqft_min']) || !empty($f['sqft_max'])) {
            $bits[] = (!empty($f['sqft_min']) ? number_format((float) $f['sqft_min']) : '0') . '–' . (!empty($f['sqft_max']) ? number_format((float) $f['sqft_max']) : '∞') . ' sqft';
        }
        if (!empty($f['year_min'])) {
            $bits[] = 'built ' . (int) $f['year_min'] . '+';
        }
        if (!empty($f['types'])) {
            $bits[] = implode(', ', $f['types']);
        }
        if (isset($f['hoa_max'])) {
            $bits[] = (float) $f['hoa_max'] == 0.0 ? 'no HOA' : 'HOA ≤ $' . number_format((float) $f['hoa_max']);
        }
        if (!empty($f['below_median'])) {
            $bits[] = trim_num($f['below_median']) . '% under area $/sqft';
        }
        return implode(' · ', $bits);
    }

    public static function daysLabel(int $days): string
    {
        return match ($days) {
            30 => '30 days', 90 => '3 months', 180 => '6 months', 365 => 'year', 730 => '2 years',
            default => $days . ' days',
        };
    }

    public static function provider(string $ctx = 'manual'): ?string
    {
        $pref = Settings::get('search_provider', 'auto');
        $rc = RentCast::enabled() && Quota::can(RentCast::ID, $ctx);
        $own = OpenWebNinja::enabled() && Quota::can(OpenWebNinja::ID, $ctx);
        if ($pref === 'rentcast') {
            return $rc ? RentCast::ID : null;
        }
        if ($pref === 'openwebninja') {
            return $own ? OpenWebNinja::ID : null;
        }
        return $rc ? RentCast::ID : ($own ? OpenWebNinja::ID : null);
    }

    /**
     * @return array{loc:?array, rows:array, total:int, snapshot:?array, fetched:bool, notice:?string, error:?string}
     */
    public static function run(array $f, array $opt = []): array
    {
        $ctx = $opt['ctx'] ?? 'manual';
        $out = ['loc' => null, 'rows' => [], 'total' => 0, 'snapshot' => null, 'fetched' => false, 'notice' => null, 'error' => null];
        $loc = Address::parseLocation((string) ($f['q'] ?? ''));
        if ($loc === null) {
            $out['error'] = 'Type a city with its state (Austin, TX), a 5-digit ZIP, or a full street address.';
            return $out;
        }
        $out['loc'] = $loc;
        if ($loc['type'] === 'address') {
            return $out; // the caller sends address lookups to the property page
        }

        if (($opt['fetch'] ?? true) !== false) {
            $snap = self::ensureSnapshot($loc, $f, $ctx, !empty($opt['force']));
            $out['snapshot'] = $snap['snapshot'];
            $out['fetched'] = $snap['fetched'];
            $out['notice'] = $snap['notice'];
        } else {
            $out['snapshot'] = self::latestSnapshot($loc, $f['mode'] ?? 'sale');
        }

        $res = PropertyRepo::search($f + ['loc' => $loc, 'limit' => $opt['limit'] ?? 60, 'offset' => $opt['offset'] ?? 0]);
        $out['rows'] = $res['rows'];
        $out['total'] = $res['total'];
        return $out;
    }

    public static function latestSnapshot(array $loc, string $mode): ?array
    {
        return Db::one('SELECT * FROM snapshots WHERE loc_key = ? AND mode = ? ORDER BY fetched_at DESC LIMIT 1', [$loc['key'], $mode]);
    }

    /**
     * @return array{snapshot:?array, fetched:bool, notice:?string}
     */
    public static function ensureSnapshot(array $loc, array $f, string $ctx = 'manual', bool $force = false, ?int $maxAgeHours = null): array
    {
        $mode = ($f['mode'] ?? 'sale') === 'sold' ? 'sold' : 'sale';
        $pushed = RentCast::pushable($f, $mode === 'sale');
        if ($mode === 'sold') {
            $pushed['days'] = (int) ($f['sold_days'] ?? 180);
        }
        ksort($pushed);
        $sig = $pushed ? md5(json_encode($pushed)) : '';
        $fresh = gmdate('Y-m-d H:i:s', time() - max(1, $maxAgeHours ?? Settings::int('snapshot_hours', 24)) * 3600);

        if (!$force) {
            // 1) the very same request, recently
            $hit = Db::one(
                'SELECT * FROM snapshots WHERE loc_key = ? AND mode = ? AND filter_sig = ? AND fetched_at > ? ORDER BY fetched_at DESC LIMIT 1',
                [$loc['key'], $mode, $sig, $fresh]
            );
            // 2) or a complete pull of the whole area — for "sold", one that reaches back at least as far
            if (!$hit) {
                foreach (Db::all(
                    'SELECT * FROM snapshots WHERE loc_key = ? AND mode = ? AND truncated = 0 AND fetched_at > ? ORDER BY fetched_at DESC',
                    [$loc['key'], $mode, $fresh]
                ) as $s) {
                    $sf = json_decode((string) $s['filter_json'], true) ?: [];
                    $days = (int) ($sf['days'] ?? 0);
                    unset($sf['days']);
                    if ($sf === [] && ($mode === 'sale' || $days >= (int) ($f['sold_days'] ?? 180))) {
                        $hit = $s;
                        break;
                    }
                }
            }
            if ($hit) {
                return ['snapshot' => $hit, 'fetched' => false, 'notice' => null];
            }
        }

        $provider = self::provider($ctx);
        $latest = self::latestSnapshot($loc, $mode);
        if ($provider === null) {
            $notice = (!RentCast::enabled() && !OpenWebNinja::enabled())
                ? 'No data source is connected yet — showing only what’s already saved. Add a free API key in Settings.'
                : ($ctx === 'cron' ? 'Quota reserved for manual searches; used saved data.'
                    : 'This month’s free requests are used up — showing saved results' . ($latest ? ' from ' . ago($latest['fetched_at']) : '') . '.');
            return ['snapshot' => $latest, 'fetched' => false, 'notice' => $notice];
        }

        $started = now();
        if ($provider === RentCast::ID) {
            $res = $mode === 'sold'
                ? RentCast::soldRecords($loc, (int) ($f['sold_days'] ?? 180), $f, $ctx)
                : RentCast::saleListings($loc, $f, $ctx);
        } else {
            $res = OpenWebNinja::search($loc, $mode, $ctx);
            $sig = 'own';
            $pushed = ['partial' => true];
        }
        if (!$res['ok']) {
            return ['snapshot' => $latest, 'fetched' => false, 'notice' => $res['error']];
        }

        Db::tx(static function () use ($res, $provider) {
            foreach ($res['items'] as $item) {
                try {
                    PropertyRepo::upsert($item, $provider);
                } catch (\InvalidArgumentException) {
                    // a row without a usable address — skip it
                }
            }
        });
        if ($mode === 'sale' && $provider === RentCast::ID && $sig === '' && !$res['truncated']) {
            PropertyRepo::retireMissing($loc, $provider, $started);
        }
        $id = Db::insert('snapshots', [
            'provider' => $provider, 'mode' => $mode, 'loc_key' => $loc['key'], 'filter_sig' => $sig,
            'filter_json' => json_encode($pushed), 'result_count' => count($res['items']),
            'truncated' => $res['truncated'] ? 1 : 0, 'fetched_at' => now(),
        ]);
        $notice = null;
        if ($res['truncated'] && $provider === RentCast::ID) {
            $notice = 'RentCast returns at most 500 homes per request and this area has more. Narrow the price or bedrooms to see the rest.';
        }
        return ['snapshot' => Db::one('SELECT * FROM snapshots WHERE id = ?', [$id]), 'fetched' => true, 'notice' => $notice];
    }

    /** Median $/sqft of what is currently for sale in an area — the yardstick for "priced under the market". */
    public static function areaPpsf(array $loc, ?string $type = null): ?float
    {
        $w = "status = 'for_sale' AND list_price > 0 AND sqft > 200";
        $a = [];
        if ($loc['type'] === 'zip') {
            $w .= ' AND zip = ?';
            $a[] = $loc['zip'];
        } elseif ($loc['type'] === 'city') {
            $w .= ' AND state = ? AND city = ?';
            array_push($a, $loc['state'], $loc['city']);
        } else {
            return null;
        }
        if ($type) {
            $w .= ' AND property_type = ?';
            $a[] = $type;
        }
        $vals = array_map('floatval', array_column(Db::all("SELECT list_price / sqft AS v FROM properties WHERE $w", $a), 'v'));
        return count($vals) >= 5 ? median($vals) : null;
    }
}

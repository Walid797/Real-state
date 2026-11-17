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
 * A request is answered from MySQL alone when a recent pull already contains it:
 *   - the very same area + filters, or
 *   - a complete pull whose filters were at least as wide (see covers()).
 *
 * When the area was pulled recently but not in a way that covers the new filters,
 * a search made by hand does NOT spend another request on its own: it shows what
 * is saved and offers "pull these filters" — so fiddling with filters in a big
 * city can't quietly drain a 50-request month. Alerts (cron) do fetch.
 */
final class Search
{
    /** Numeric min / max filters. */
    public const NUMERIC = [
        'price_min', 'price_max', 'beds_min', 'beds_max', 'baths_min', 'baths_max', 'sqft_min', 'sqft_max',
        'lot_min', 'lot_max', 'year_min', 'year_max', 'hoa_min', 'hoa_max', 'dom_min', 'dom_max', 'garage_min',
        'ppsf_max', 'score_min', 'profit_min', 'risk_max', 'below_median',
    ];

    /** Request fields that describe a search (also what a saved profile / alert stores). */
    public const FIELDS = ['q', 'mode', 'sold_days', 'types', 'listing', 'cond', 'c_kitchen', 'c_bathroom', 'c_roof', 'c_hvac', 'sort'];

    public static function fromRequest(array $src): array
    {
        $f = ['q' => mb_substr(trim((string) ($src['q'] ?? '')), 0, 300)];
        $f['mode'] = ($src['mode'] ?? '') === 'sold' ? 'sold' : 'sale';
        foreach (self::NUMERIC as $k) {
            $v = isset($src[$k]) && is_scalar($src[$k]) ? parse_num((string) $src[$k]) : null;
            if ($v !== null && $v >= 0) {
                $f[$k] = $v;
            }
        }
        $days = (int) ($src['sold_days'] ?? 180);
        $f['sold_days'] = in_array($days, [30, 90, 180, 365, 730], true) ? $days : 180;
        $f['types'] = array_values(array_intersect(PropertyRepo::TYPES, (array) ($src['types'] ?? [])));
        $f['listing'] = array_values(array_intersect(array_keys(PropertyRepo::LISTING), (array) ($src['listing'] ?? [])));
        $f['cond'] = array_values(array_intersect(array_keys(Condition::OVERALL), (array) ($src['cond'] ?? [])));
        foreach (['kitchen', 'bathroom', 'roof', 'hvac'] as $part) {
            $v = (string) ($src['c_' . $part] ?? 'any');
            $f['c_' . $part] = isset(Condition::FILTER[$v]) ? $v : 'any';
        }
        $f['sort'] = isset(PropertyRepo::SORTS[$src['sort'] ?? '']) ? $src['sort'] : 'newest';
        return $f;
    }

    /** Only the fields worth keeping in a URL or a saved search. */
    public static function compact(array $f): array
    {
        $out = [];
        foreach (array_merge(self::FIELDS, self::NUMERIC) as $k) {
            $v = $f[$k] ?? null;
            if ($v === null || $v === '' || $v === [] || ($k === 'mode' && $v === 'sale') || ($k === 'sort' && $v === 'newest')
                || (str_starts_with($k, 'c_') && $v === 'any')
                || ($k === 'sold_days' && (($f['mode'] ?? 'sale') !== 'sold' || (int) $v === 180))) {
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /** "Virginia · $150K–$350K · 3–5 bd · Townhouse · needs renovation" */
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
        $span = static function (mixed $lo, mixed $hi, callable $fmt, string $unit = ''): ?string {
            if ($lo && $hi) {
                return $fmt($lo) . '–' . $fmt($hi) . $unit;
            }
            if ($lo) {
                return $fmt($lo) . '+' . $unit;
            }
            return $hi ? 'up to ' . $fmt($hi) . $unit : null;
        };
        $bits[] = $span($f['price_min'] ?? null, $f['price_max'] ?? null, 'money_short');
        $bits[] = $span($f['beds_min'] ?? null, $f['beds_max'] ?? null, 'trim_num', ' bd');
        $bits[] = $span($f['baths_min'] ?? null, $f['baths_max'] ?? null, 'trim_num', ' ba');
        $bits[] = $span($f['sqft_min'] ?? null, $f['sqft_max'] ?? null, static fn ($v) => number_format((float) $v), ' sqft');
        $bits[] = $span($f['year_min'] ?? null, $f['year_max'] ?? null, static fn ($v) => (string) (int) $v, ' built');
        if (!empty($f['types'])) {
            $bits[] = implode(', ', $f['types']);
        }
        foreach ((array) ($f['listing'] ?? []) as $l) {
            $bits[] = mb_strtolower(PropertyRepo::LISTING[$l] ?? $l);
        }
        foreach ((array) ($f['cond'] ?? []) as $c) {
            $bits[] = mb_strtolower(Condition::OVERALL[$c] ?? $c);
        }
        foreach (['kitchen', 'bathroom', 'roof', 'hvac'] as $part) {
            if (($f['c_' . $part] ?? 'any') !== 'any') {
                $bits[] = $part . ' ' . ($f['c_' . $part] === 'updated' ? 'updated' : 'needs work');
            }
        }
        if (isset($f['hoa_max'])) {
            $bits[] = (float) $f['hoa_max'] == 0.0 ? 'no HOA' : 'HOA ≤ $' . number_format((float) $f['hoa_max']);
        }
        if (!empty($f['score_min'])) {
            $bits[] = 'score ' . (int) $f['score_min'] . '+';
        }
        if (!empty($f['profit_min'])) {
            $bits[] = 'profit ' . money_short($f['profit_min']) . '+';
        }
        if (!empty($f['below_median'])) {
            $bits[] = trim_num($f['below_median']) . '% under area $/sqft';
        }
        return implode(' · ', array_filter($bits));
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
     * @return array{loc:?array, rows:array, total:int, snapshot:?array, fetched:bool, partial:bool, notice:?string, error:?string}
     */
    public static function run(array $f, array $opt = []): array
    {
        $ctx = $opt['ctx'] ?? 'manual';
        $out = ['loc' => null, 'rows' => [], 'total' => 0, 'snapshot' => null, 'fetched' => false, 'partial' => false,
            'notice' => null, 'error' => null, 'needs' => []];
        $loc = Address::parseLocation((string) ($f['q'] ?? ''));
        if ($loc === null) {
            $out['error'] = 'Type a state (Virginia), a county (Fairfax County), a city (Richmond, VA), one or more ZIP codes, or a full street address.';
            return $out;
        }
        $out['loc'] = $loc;
        if ($loc['type'] === 'address') {
            return $out; // the caller sends address lookups to the property page
        }

        if (($opt['fetch'] ?? true) !== false) {
            $plan = self::plan($loc, $f, $ctx);
            $out['needs'] = $plan['needs'];
            // One pull for a simple area happens on its own; several pulls wait for a "yes".
            $go = $plan['needs'] && (count($plan['needs']) === 1 && !$plan['recent'] || !empty($opt['confirm']) || !empty($opt['force']));
            if ($go) {
                $notices = [];
                foreach ($plan['needs'] as $fetchLoc) {
                    $snap = self::ensureSnapshot($fetchLoc, $f, $ctx, !empty($opt['force']));
                    $out['fetched'] = $out['fetched'] || $snap['fetched'];
                    $out['snapshot'] = $snap['snapshot'] ?? $out['snapshot'];
                    if ($snap['notice']) {
                        $notices[] = $snap['notice'];
                    }
                    if (!$snap['fetched'] && $snap['notice']) {
                        break; // out of requests or the provider failed: don't keep trying
                    }
                }
                $out['needs'] = [];
                $out['notice'] = $notices ? implode(' ', array_unique($notices)) : null;
            } else {
                $out['partial'] = (bool) $plan['needs'];
            }
            $out['snapshot'] ??= $plan['snapshot'];
        } else {
            $out['snapshot'] = self::latestSnapshot($loc, $f['mode'] ?? 'sale');
        }

        $res = PropertyRepo::search($f + ['loc' => $loc, 'limit' => $opt['limit'] ?? 60, 'offset' => $opt['offset'] ?? 0]);
        $out['rows'] = $res['rows'];
        $out['total'] = $res['total'];
        return $out;
    }

    /**
     * Which provider pulls a search would need. A county is pulled through its state
     * (no county filter upstream); several areas need one pull each.
     * @return array{needs:array, recent:bool, snapshot:?array}
     */
    public static function plan(array $loc, array $f, string $ctx = 'manual'): array
    {
        $needs = [];
        $recent = false;
        $latest = null;
        foreach (Address::areas($loc) as $area) {
            $fetchLoc = $area['type'] === 'county'
                ? ['type' => 'state', 'state' => $area['state'], 'key' => 'state:' . $area['state'], 'label' => Address::STATES[$area['state']] ?? $area['state']]
                : $area;
            $c = self::cover($fetchLoc, $f, null);
            $latest ??= $c['hit'] ?? $c['recent'];
            if ($c['hit'] === null) {
                $needs[$fetchLoc['key']] = $fetchLoc;
                $recent = $recent || $c['recent'] !== null;
            }
        }
        return ['needs' => array_values($needs), 'recent' => $recent, 'snapshot' => $latest];
    }

    /**
     * Does an earlier pull (its server-side filters) contain everything a new request asks for?
     * True when the old pull was at least as wide on every dimension it filtered.
     */
    public static function covers(array $old, array $new): bool
    {
        foreach ($old as $k => $ov) {
            if ($k === 'partial' || !array_key_exists($k, $new)) {
                return false; // the old pull was narrower on something the new request leaves open
            }
            $nv = $new[$k];
            if ($k === 'propertyType') {
                if (array_diff(explode('|', (string) $nv), explode('|', (string) $ov))) {
                    return false;
                }
            } elseif ($k === 'days') {
                if ((int) $nv > (int) $ov) {
                    return false;
                }
            } else {
                [$oMin, $oMax] = self::bounds((string) $ov);
                [$nMin, $nMax] = self::bounds((string) $nv);
                if ($nMin < $oMin || $nMax > $oMax) {
                    return false;
                }
            }
        }
        return true;
    }

    /** "300000:*" → [300000.0, INF] */
    private static function bounds(string $range): array
    {
        [$a, $b] = array_pad(explode(':', $range, 2), 2, '*');
        return [$a === '*' || $a === '' ? -INF : (float) $a, $b === '*' || $b === '' ? INF : (float) $b];
    }

    /** @return array{hit:?array, recent:?array, sig:string, pushed:array} */
    private static function cover(array $loc, array $f, ?int $maxAgeHours): array
    {
        $mode = ($f['mode'] ?? 'sale') === 'sold' ? 'sold' : 'sale';
        $pushed = RentCast::pushable($f, $mode === 'sale');
        if ($mode === 'sold') {
            $pushed['days'] = (int) ($f['sold_days'] ?? 180);
        }
        ksort($pushed);
        $sig = $pushed ? md5(json_encode($pushed)) : '';
        $fresh = gmdate('Y-m-d H:i:s', time() - max(1, $maxAgeHours ?? Settings::int('snapshot_hours', 24)) * 3600);
        $recent = Db::all(
            'SELECT * FROM snapshots WHERE loc_key = ? AND mode = ? AND fetched_at > ? ORDER BY fetched_at DESC',
            [$loc['key'], $mode, $fresh]
        );
        foreach ($recent as $snap) {
            // the very same request — or a complete pull that was at least as wide
            $same = $snap['filter_sig'] === $sig;
            $wider = (int) $snap['truncated'] === 0 && self::covers(json_decode((string) $snap['filter_json'], true) ?: [], $pushed);
            if ($same || $wider) {
                return ['hit' => $snap, 'recent' => $snap, 'sig' => $sig, 'pushed' => $pushed];
            }
        }
        return ['hit' => null, 'recent' => $recent[0] ?? null, 'sig' => $sig, 'pushed' => $pushed];
    }

    public static function latestSnapshot(array $loc, string $mode): ?array
    {
        return Db::one('SELECT * FROM snapshots WHERE loc_key = ? AND mode = ? ORDER BY fetched_at DESC LIMIT 1', [$loc['key'], $mode]);
    }

    /**
     * Makes sure one area (zip, city or state) is in the local database for these filters.
     * @return array{snapshot:?array, fetched:bool, partial:bool, notice:?string}
     */
    public static function ensureSnapshot(array $loc, array $f, string $ctx = 'manual', bool $force = false, ?int $maxAgeHours = null): array
    {
        $mode = ($f['mode'] ?? 'sale') === 'sold' ? 'sold' : 'sale';
        $c = self::cover($loc, $f, $maxAgeHours);
        [$sig, $pushed] = [$c['sig'], $c['pushed']];
        if (!$force) {
            if ($c['hit']) {
                return ['snapshot' => $c['hit'], 'fetched' => false, 'partial' => false, 'notice' => null];
            }
            // Pulled recently, but not these filters: by hand, show what is saved and let the user decide.
            if ($c['recent'] && $ctx === 'manual') {
                return ['snapshot' => $c['recent'], 'fetched' => false, 'partial' => true, 'notice' => null];
            }
        }

        $provider = self::provider($ctx);
        $latest = self::latestSnapshot($loc, $mode);
        if ($provider === null) {
            $notice = (!RentCast::enabled() && !OpenWebNinja::enabled())
                ? 'No data source is connected yet — showing only what’s already saved. Add a free API key in Settings.'
                : ($ctx === 'cron' ? 'Quota reserved for manual searches; used saved data.'
                    : 'This month’s free requests are used up — showing saved results' . ($latest ? ' from ' . ago($latest['fetched_at']) : '') . '.');
            return ['snapshot' => $latest, 'fetched' => false, 'partial' => $latest !== null, 'notice' => $notice];
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
            return ['snapshot' => $latest, 'fetched' => false, 'partial' => $latest !== null, 'notice' => $res['error']];
        }

        $ids = [];
        Db::tx(static function () use ($res, $provider, &$ids) {
            foreach ($res['items'] as $item) {
                try {
                    $ids[] = PropertyRepo::upsert($item, $provider)['id'];
                } catch (\InvalidArgumentException) {
                    // a row without a usable address — skip it
                }
            }
        });
        // Score what just arrived; anything left over is scored by the scheduler.
        if ($mode === 'sale') {
            Score::refreshMany($ids, $ctx === 'cron' ? 60 : 25);
        }
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
        return ['snapshot' => Db::one('SELECT * FROM snapshots WHERE id = ?', [$id]), 'fetched' => true, 'partial' => false, 'notice' => $notice];
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

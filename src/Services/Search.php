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
    public const AREA_MAX_LENGTH = 300;

    /** Numeric min / max filters. */
    public const NUMERIC = [
        'price_min', 'price_max', 'beds_min', 'beds_max', 'baths_min', 'baths_max', 'sqft_min', 'sqft_max',
        'lot_min', 'lot_max', 'year_min', 'year_max', 'hoa_min', 'hoa_max', 'dom_min', 'dom_max', 'garage_min',
        'ppsf_max', 'score_min', 'profit_min', 'risk_max', 'below_median',
    ];

    /** Request fields that describe a search (also what a saved profile / alert stores). */
    public const FIELDS = ['q', 'scope', 'view', 'search_kind', 'mode', 'sold_days', 'types', 'listing', 'cond', 'c_kitchen', 'c_bathroom', 'c_roof', 'c_hvac', 'sort'];

    public static function fromRequest(array $src): array
    {
        $f = ['q' => mb_substr(trim((string) ($src['q'] ?? '')), 0, self::AREA_MAX_LENGTH)];
        $f['scope'] = ($src['scope']??'') === 'saved' ? 'saved' : 'area';
        $f['view'] = $f['scope']==='saved' && ($src['view']??'')==='opportunities' ? 'opportunities' : 'all';
        $f['search_kind'] = ($src['search_kind'] ?? '') === 'building' ? 'building' : 'auto';
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
        if ($f['scope']==='saved') {
            $f['q']='';
            $f['mode']='sale';
            if ($f['view']==='opportunities') {
                $f['score_min']=max(70,$f['score_min']??0);
                if (!isset(PropertyRepo::SORTS[$src['sort']??''])) $f['sort']='score';
            }
        }
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
                || ($k === 'search_kind' && in_array($v, ['area','auto'], true))
                || ($k === 'scope' && $v === 'area') || ($k === 'view' && $v === 'all')
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
        if (($f['scope']??'')==='saved') $bits[] = t(($f['view']??'')==='opportunities' ? 'Saved opportunities' : 'Saved homes');
        if (!empty($f['q'])) {
            $loc = self::location($f);
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

    public static function location(array $f, bool $fetch = false): ?array
    {
        if (($f['scope']??'')==='saved') return ['type'=>'saved','key'=>'saved:all','label'=>t(($f['view']??'')==='opportunities' ? 'Saved opportunities' : 'Saved homes')];
        $address = Address::parseLocation((string) ($f['q'] ?? ''));
        if ($address && $address['type'] !== 'city') {
            return $address;
        }
        if (($f['search_kind'] ?? '') === 'building') {
            $loc = BuildingSearch::location((string) ($f['q'] ?? ''));
            return $loc ? $loc + ['buildings'=>BuildingSearch::resolve($loc,$fetch)] : null;
        }
        return SmartLocation::resolve((string) ($f['q'] ?? ''), $fetch);
    }

    public static function locationProvider(array $loc, string $ctx = 'manual'): ?string
    {
        if ($loc['type']==='saved') return null;
        return $loc['type'] === 'building'
            ? (OpenWebNinja::enabled() && Quota::can(OpenWebNinja::ID, $ctx) ? OpenWebNinja::ID : null)
            : self::provider($ctx);
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
            'notice' => null, 'error' => null, 'needs' => [], 'homes' => null];
        $loc = self::location($f, ($opt['fetch'] ?? true) !== false);
        if ($loc === null) {
            $out['error'] = 'Could not identify this address or place reliably. Add its city, state or ZIP code.';
            return $out;
        }
        if ($loc['type'] === 'building') {
            $loc['buildings'] ??= BuildingSearch::resolve($loc, ($opt['fetch'] ?? true) !== false);
        }
        $out['loc'] = $loc;
        if ($loc['type'] === 'address') {
            return $out; // the caller sends address lookups to the property page
        }

        if (($opt['fetch'] ?? true) !== false && $loc['type']!=='saved') {
            $plan = self::plan($loc, $f, $ctx);
            $out['needs'] = $plan['needs'];
            $confirmed = !empty($opt['confirm']);
            $force = !empty($opt['force']);
            // Multiple areas require confirmation even when they share one provider request.
            $go = $plan['needs'] && ($confirmed || $force || ($loc['type'] !== 'multi' && !$plan['recent']));
            if ($go) {
                $notices = [];
                foreach ($plan['needs'] as $fetchLoc) {
                    $snap = self::ensureSnapshot($fetchLoc, $f, $ctx, $force, confirmed: $confirmed);
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
            $out['snapshot'] = $loc['type']==='saved' ? null : self::latestSnapshot($loc, $f['mode'] ?? 'sale');
        }

        if (($opt['fetch'] ?? true) !== false && $loc['type']!=='saved' && ($f['mode'] ?? 'sale') === 'sale') {
            $out['homes'] = HomesSearch::run($loc, $ctx);
            if (!empty($out['homes']['available']) && !RentCast::enabled() && !OpenWebNinja::enabled()) {
                $out['notice'] = 'Results include public Homes.com listings. RentCast and Zillow data are not connected.';
            }
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
        if ($loc['type']==='saved') return ['needs'=>[],'recent'=>false,'snapshot'=>null];
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
    /** Scoring done inside a page request; the rest is left to the scheduler. */
    public const WEB_SCORE_MAX = 500;
    public const WEB_SCORE_SECONDS = 6;

    /**
     * Which fresh homes to score first: the cheapest per square foot are the likeliest
     * opportunities and the ones people open first.
     * @param int[] $ids
     * @return int[]
     */
    public static function scoreFirst(array $ids, int $max): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) <= $max) {
            return $ids;
        }
        $in = implode(',', $ids);
        return array_map('intval', array_column(Db::all(
            "SELECT id FROM properties WHERE id IN ($in)
             ORDER BY (sqft IS NULL OR sqft = 0), list_price / NULLIF(sqft, 0), list_price LIMIT " . (int) $max
        ), 'id'));
    }

    public static function ensureSnapshot(array $loc, array $f, string $ctx = 'manual', bool $force = false, ?int $maxAgeHours = null, bool $confirmed = false): array
    {
        $mode = ($f['mode'] ?? 'sale') === 'sold' ? 'sold' : 'sale';
        $c = self::cover($loc, $f, $maxAgeHours);
        [$sig, $pushed] = [$c['sig'], $c['pushed']];
        if (!$force) {
            if ($c['hit']) {
                return ['snapshot' => $c['hit'], 'fetched' => false, 'partial' => false, 'notice' => null];
            }
            // Pulled recently, but not these filters: by hand, show what is saved and let the user decide.
            if ($c['recent'] && $ctx === 'manual' && !$confirmed) {
                return ['snapshot' => $c['recent'], 'fetched' => false, 'partial' => true, 'notice' => null];
            }
        }

        $provider = self::locationProvider($loc, $ctx);
        $latest = self::latestSnapshot($loc, $mode);
        if ($provider === null) {
            if ($loc['type'] === 'building') {
                return ['snapshot' => $latest, 'fetched' => false, 'partial' => true,
                    'notice' => 'Building addresses and saved homes are shown below. Live building listings need a connected Zillow-data source with requests remaining.'];
            }
            $notice = (!RentCast::enabled() && !OpenWebNinja::enabled())
                ? 'No data source is connected yet — showing only what’s already saved. Add a free API key in Settings.'
                : ($ctx === 'cron' ? 'Quota reserved for manual searches; used saved data.'
                    : 'This month’s free requests are used up — showing saved results' . ($latest ? ' from ' . ago($latest['fetched_at']) : '') . '.');
            return ['snapshot' => $latest, 'fetched' => false, 'partial' => $latest !== null, 'notice' => $notice];
        }

        // Fetching can take a while; finish and save even if the browser or the CDN gives up waiting.
        @set_time_limit(150);
        if ($ctx !== 'cron') {
            ignore_user_abort(true);
        }
        $started = now();
        $t0 = microtime(true);
        RentCast::$fresh = $force; // a deliberate refresh asks the provider again; anything else may reuse a kept answer
        try {
            if ($provider === RentCast::ID) {
                $res = $mode === 'sold'
                    ? RentCast::soldRecords($loc, (int) ($f['sold_days'] ?? 180), $f, $ctx)
                    : RentCast::saleListings($loc, $f, $ctx);
            } else {
                $res = OpenWebNinja::search($loc, $mode, $ctx);
                $sig = 'own';
                $pushed = ['partial' => true];
            }
        } finally {
            RentCast::$fresh = false;
        }
        // RentCast refused the key (no active plan, wrong key): use the Zillow-data source instead if it is set up.
        $fellBack = false;
        if (!$res['ok'] && $provider === RentCast::ID && in_array(RentCast::$lastStatus, [401, 403], true)
            && Settings::get('search_provider', 'auto') === 'auto' && OpenWebNinja::enabled() && Quota::can(OpenWebNinja::ID, $ctx)) {
            $res = OpenWebNinja::search($loc, $mode, $ctx);
            $provider = OpenWebNinja::ID;
            $sig = 'own';
            $pushed = ['partial' => true];
            $fellBack = $res['ok'];
        }
        if (!$res['ok']) {
            return ['snapshot' => $latest, 'fetched' => false, 'partial' => $latest !== null, 'notice' => $res['error']];
        }
        if ($loc['type'] === 'building') {
            $res['items'] = array_values(array_filter($res['items'], static fn ($item) => BuildingSearch::matches($item, $loc)));
        }
        $t1 = microtime(true);

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
        if ($mode === 'sale' && $provider === RentCast::ID && $sig === '' && !$res['truncated']) {
            PropertyRepo::retireMissing($loc, $provider, $started);
        }
        // Recorded before any scoring, so a page cut off from here on never pays for the same pull twice.
        $id = Db::insert('snapshots', [
            'provider' => $provider, 'mode' => $mode, 'loc_key' => $loc['key'], 'filter_sig' => $sig,
            'filter_json' => json_encode($pushed), 'result_count' => count($res['items']),
            'truncated' => $res['truncated'] ? 1 : 0, 'fetched_at' => now(),
        ]);
        $t2 = microtime(true);
        // Score a first handful now so the list is useful at once; the scheduler does the rest within minutes.
        $scored = 0;
        if ($mode === 'sale' && $ids) {
            $scored = $ctx === 'cron' ? Score::refreshMany($ids, 60) : Score::refreshMany(self::scoreFirst($ids, self::WEB_SCORE_MAX), self::WEB_SCORE_SECONDS);
        }
        error_log(sprintf(
            '[info] pull %s %s %s: %d homes%s · provider %.1fs · saved %.1fs · scored %d in %.1fs',
            $provider, $mode, $loc['key'], count($res['items']), $res['truncated'] ? ' (capped)' : '',
            $t1 - $t0, $t2 - $t1, $scored, microtime(true) - $t2
        ));
        $notice = $fellBack ? 'RentCast refused the key, so these came from the Zillow-data source (OpenWeb Ninja) instead.' : null;
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

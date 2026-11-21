<?php
declare(strict_types=1);

namespace App\Services;

use App\Cache;
use App\Db;
use App\Geo;

/**
 * "Should we buy it?" — one reasoned answer per home, from everything on file about it:
 * the deal (profit, ROI, the most to offer), price against comparable sales, the assessment
 * and the area, the seller's position (price cuts, time on market), the area (flips, value
 * trend, flood zone, neighbourhood), the building (age, condition, layout, basement), the land
 * (lot against the area, land share of the value) and running costs (tax, HOA).
 *
 * Every factor is shown with its reason, so the verdict can be checked line by line. It reads
 * only what is already stored (no requests), and it is an analysis, not an appraisal.
 * All strings pass through t() at render time; values are filled with sprintf.
 */
final class Verdict
{
    public const VERDICTS = [
        'buy' => 'Worth buying at the asking price',
        'offer' => 'Worth buying at the right price',
        'lower' => 'Not at this price',
        'pass' => 'Pass',
        'unknown' => 'Not enough data to decide',
    ];

    public const GROUPS = [
        'price' => 'Price and value', 'seller' => 'The seller’s position', 'area' => 'The area',
        'building' => 'The building', 'land' => 'The land', 'costs' => 'Running costs', 'data' => 'How sure this is',
    ];

    /**
     * @return array{verdict:string, points:int, offer:?int, confidence:string, factors:list<array{group:string, tone:int, text:string, args:array}>, checks:list<array{text:string, args:array}>, summary:array{text:string, args:array}}
     */
    public static function for(array $p, ?array $deal = null): array
    {
        $deal ??= Deal::analyse($p);
        $f = [];
        $add = static function (string $group, int $tone, string $text, array $args = []) use (&$f): void {
            $f[] = ['group' => $group, 'tone' => $tone, 'text' => $text, 'args' => $args];
        };
        $checks = [];
        $check = static function (string $text, array $args = []) use (&$checks): void {
            $checks[] = ['text' => $text, 'args' => $args];
        };
        $d = is_array($p['details'] ?? null) ? $p['details'] : (json_decode((string) ($p['details'] ?? ''), true) ?: []);
        $cr = (array) ($d['county_record'] ?? []);
        $price = (int) ($p['list_price'] ?? 0);
        $forSale = in_array($p['status'] ?? '', ['for_sale', 'pending'], true) && $price > 0;
        $sqft = (int) ($p['sqft'] ?? 0);
        $zip = (string) ($p['zip'] ?? '');

        /* ── price and value ─────────────────────────────────────────── */
        $offer = null;
        if ($deal['ok']) {
            $profit = (float) $deal['base']['profit'];
            $roi = (float) $deal['base']['roi'];
            $target = (float) $deal['target'];
            $offer = (int) (floor($deal['max_offer'] / 1000) * 1000);
            if ($profit >= $target) {
                $add('price', 3, 'Projected profit %1$s (%2$s return), above the %3$s you aim for.', [money($profit), pct($roi, 0), money($target)]);
            } elseif ($profit > 0) {
                $add('price', 1, 'Projected profit %1$s (%2$s return) — positive but under the %3$s you aim for.', [money($profit), pct($roi, 0), money($target)]);
            } else {
                $add('price', -3, 'At this price the flip loses money: %s after all costs.', [money($profit)]);
            }
            $pess = (float) $deal['scenarios']['pessimistic']['profit'];
            if ($profit > 0 && $pess < 0) {
                $add('price', -1, 'In the cautious case (lower ARV, costlier renovation, two extra months) it loses %s.', [money(abs($pess))]);
            } elseif ($pess > 0) {
                $add('price', 1, 'Even the cautious case still makes %s.', [money($pess)]);
            }
            if ($forSale && $offer > 0) {
                $gap = ($price - $offer) / $price * 100;
                if ($price <= $offer) {
                    $add('price', 2, 'The asking price is %s under the most you could pay and still make your target.', [self::plain(-$gap)]);
                } elseif ($gap <= 10) {
                    $add('price', 0, 'Asking is %1$s over the most to pay (%2$s) — within normal negotiating range.', [self::plain($gap), money($offer)]);
                } else {
                    $add('price', -2, 'Asking is %1$s over the most to pay (%2$s).', [self::plain($gap), money($offer)]);
                }
            }
        }
        $areaPpsf = Score::areaPpsf($p);
        if ($forSale && $sqft > 0 && $areaPpsf) {
            $ratio = $price / $sqft / $areaPpsf;
            $txt = 'Price per sq ft %1$s against %2$s typical in the area (%3$s).';
            $args = ['$' . number_format($price / $sqft), '$' . number_format($areaPpsf), pct(($ratio - 1) * 100, 0)];
            $add('price', match (true) { $ratio <= 0.85 => 2, $ratio <= 0.95 => 1, $ratio >= 1.15 => -2, $ratio >= 1.05 => -1, default => 0 }, $txt, $args);
        }
        $assessed = (int) ($cr['assessed_total'] ?? $p['assessed_value'] ?? 0);
        if ($forSale && $assessed > 0) {
            $r = $price / $assessed;
            $add('price', $r <= 0.9 ? 1 : ($r >= 1.3 ? -1 : 0), 'Asking is %1$s of the assessed value (%2$s). Assessments trail the market, so this is a rough check.', [self::plain($r * 100), money($assessed)]);
        }

        /* ── the seller's position ───────────────────────────────────── */
        if ($forSale) {
            $events = Db::all("SELECT event, price, event_date FROM price_events WHERE property_id = ? AND event IN ('listed','relisted','price_change') ORDER BY event_date", [(int) $p['id']]);
            $peak = 0;
            $cuts = 0;
            foreach ($events as $e) {
                if ($e['event'] === 'price_change' && $peak > 0 && (int) $e['price'] < $peak) {
                    $cuts++;
                }
                $peak = max($peak, (int) $e['price']);
            }
            if ($cuts > 0 && $peak > $price) {
                $add('seller', 1, 'The price has been cut %1$d time(s), %2$s below its high of %3$s — room to negotiate.', [$cuts, self::plain(($peak - $price) / $peak * 100), money($peak)]);
            }
            $dom = \App\PropertyRepo::dom($p);
            $areaDom = AreaPace::forZip($zip)['market']['active_days'] ?? null;
            if ($dom !== null && $dom >= 45 && $areaDom && $dom >= 2 * $areaDom) {
                $add('seller', 1, 'On the market %1$d days, about twice the area’s %2$d — the seller may take less.', [$dom, (int) $areaDom]);
            } elseif ($dom !== null && $dom <= 7) {
                $add('seller', 0, 'Listed only %d days ago — expect competition; offers below asking are less likely.', [$dom]);
            }
        }

        /* ── the area ────────────────────────────────────────────────── */
        $pace = AreaPace::forZip($zip);
        $fl = $pace['flips'];
        if ($fl['count'] >= 10) {
            $add('area', 2, 'An active flip market: %1$d flips in 3 years, typically %2$s months from purchase to resale and %3$s over the purchase price.', [$fl['count'], trim_num((float) $fl['months']), '+' . $fl['gain_pct'] . '%']);
        } elseif ($fl['count'] >= 3) {
            $add('area', 1, '%1$d flips in 3 years here, typically %2$s months and %3$s over the purchase price.', [$fl['count'], trim_num((float) $fl['months']), '+' . $fl['gain_pct'] . '%']);
        } elseif (CountyRecords::sourceFor($p) !== null && CountyRecords::areaFetchedAt($zip)) {
            $add('area', -1, 'Few or no flips here in 3 years — buyers for a renovated home are less proven.', []);
        }
        if ($pace['market']['renovated'] >= 3 && $pace['market']['renovated_days'] !== null && $pace['market']['renovated_days'] <= 30) {
            $add('area', 1, 'Renovated homes here leave the market in about %d days.', [$pace['market']['renovated_days']]);
        }
        $trend = self::valueTrend($zip);
        if ($trend !== null) {
            $add('area', $trend >= 4 ? 1 : ($trend <= -3 ? -2 : 0), 'Typical home values in %1$s changed %2$s over the last 12 months (Zillow Home Value Index).', [$zip, pct($trend, 1)]);
        }
        $flood = self::cached('fema', 'zone', $p, 5);
        if (is_array($flood)) {
            if (!empty($flood['sfha'])) {
                $add('area', -3, 'In a FEMA high-risk flood zone (%s): flood insurance is required with a mortgage and buyers are fewer.', [(string) ($flood['zone'] ?? '')]);
                $check('Get a flood insurance quote before offering.');
            } elseif (($flood['risk'] ?? '') === 'moderate') {
                $add('area', -1, 'In a moderate flood-risk zone (%s).', [(string) ($flood['zone'] ?? '')]);
            }
        }
        $tract = self::cached('census', 'tract', $p, 4);
        $acs = is_array($tract) ? Cache::get(Cache::key('census', 'acs', ['s' => $tract['state'], 'c' => $tract['county'], 't' => $tract['tract']])) : null;
        if (is_array($acs)) {
            if (($acs['owner_share'] ?? null) !== null) {
                $add('area', $acs['owner_share'] >= 60 ? 1 : ($acs['owner_share'] < 35 ? -1 : 0), 'Owner-occupied homes in this census tract: %s — the usual buyers of a renovated home.', [self::plain((float) $acs['owner_share'])]);
            }
            if (($acs['vacancy'] ?? null) !== null && $acs['vacancy'] >= 12) {
                $add('area', -1, 'Vacancy in the tract is high: %s.', [self::plain((float) $acs['vacancy'])]);
            }
        }

        /* ── the building ────────────────────────────────────────────── */
        $year = (int) ($p['year_built'] ?? 0);
        $remodel = (int) ($cr['year_remodeled'] ?? 0);
        if ($year > 0) {
            if ($year < 1950 && $remodel < 1990) {
                $add('building', -1, 'Built in %d with no recorded remodel: wiring, plumbing and the foundation may need more than cosmetics.', [$year]);
            } elseif ($year >= 2000) {
                $add('building', 1, 'Built in %d — younger systems, fewer surprises behind the walls.', [$year]);
            }
            if ($year < 1978) {
                $check('Built before 1978: plan for lead-paint rules during renovation.');
            }
        }
        if ($remodel > 0 && $remodel >= (int) gmdate('Y') - 10) {
            $add('building', 0, 'Remodelled in %d per the county — less to fix, but also less value to add.', [$remodel]);
        }
        $overall = (string) $deal['condition'];
        $condLabel = Condition::OVERALL[$overall] ?? $overall;
        if ($overall === 'not_disclosed') {
            $add('building', -1, 'The condition isn’t known from the listing, so the renovation budget is a general estimate.', []);
            $check('See the inside (or photos) before trusting the renovation estimate.');
        } else {
            $add('building', 0, 'Condition from the listing: %1$s — renovation estimated at %2$s.', [$condLabel, money($deal['inputs']['reno'] ?? null)]);
        }
        $cdu = strtolower((string) ($cr['condition'] ?? ''));
        if ($cdu !== '' && preg_match('/poor|fair|unsound/', $cdu)) {
            $add('building', -1, 'The county rates the building’s condition “%s”.', [(string) $cr['condition']]);
        }
        $beds = (float) ($p['beds'] ?? 0);
        $baths = (float) ($p['baths'] ?? 0);
        if ($beds >= 3 && $baths > 0 && $baths <= 1) {
            $add('building', 1, '%1$s bedrooms with %2$s bath — adding a bathroom usually adds more value than it costs.', [trim_num($beds), trim_num($baths)]);
        } elseif ($beds > 0 && $beds <= 2 && in_array($p['property_type'] ?? '', ['Single Family', 'Townhouse'], true)) {
            $add('building', -1, 'Only %s bedrooms — fewer buyers for a house.', [trim_num($beds)]);
        }
        if (!empty($p['has_basement'])) {
            $add('building', 1, 'Has a basement — space that can be finished for more living area.', []);
            $check('Look for water in the basement (stains, smell, sump pump).');
        }

        /* ── the land ────────────────────────────────────────────────── */
        $lot = (int) ($p['lot_sqft'] ?? 0);
        $areaLot = self::areaLot($zip, (string) ($p['property_type'] ?? ''));
        if ($lot > 0 && $areaLot) {
            $r = $lot / $areaLot;
            $add('land', $r >= 1.5 ? 1 : ($r <= 0.6 ? -1 : 0), 'Lot of %1$s sq ft against %2$s typical for this kind of home here.', [number_format($lot), number_format($areaLot)]);
        }
        $land = (int) ($cr['assessed_land'] ?? 0);
        if ($land > 0 && $assessed > 0 && $land / $assessed >= 0.6) {
            $add('land', 1, 'The land is %s of the assessed value — the lot itself carries the price; worth asking whether a rebuild beats a renovation.', [self::plain($land / $assessed * 100)]);
        }
        if (!empty($cr['zoning'])) {
            $add('land', 0, 'Zoning: %s.', [(string) $cr['zoning']]);
        }

        /* ── running costs ───────────────────────────────────────────── */
        $hoa = (float) ($p['hoa_fee'] ?? 0);
        if ($hoa >= 400) {
            $add('costs', -1, 'HOA of %s a month adds to holding costs and narrows the buyer pool.', [money($hoa)]);
            $check('Read the HOA documents for rental or renovation rules and special assessments.');
        }
        $tax = (float) ($p['tax_annual'] ?? 0);
        if ($tax > 0 && $price > 0 && $tax / $price * 100 >= 1.5) {
            $add('costs', -1, 'Property tax is high for the price: %1$s a year (%2$s).', [money($tax), self::plain($tax / $price * 100, 1)]);
        }

        /* ── how sure this is ────────────────────────────────────────── */
        $arvConf = (int) ($deal['arv']['confidence'] ?? 0);
        if (($deal['arv']['from'] ?? '') === 'asking') {
            $add('data', -1, 'ARV comes from asking prices nearby, not recorded sales — treat the profit as a rough guide.', []);
        } elseif ($deal['ok']) {
            $add('data', 0, 'ARV: %s', [(string) ($deal['arv']['basis'] ?? '')]);
        }
        $confidence = match (true) {
            !$deal['ok'] => 'low',
            $arvConf >= 60 && $overall !== 'not_disclosed' => 'high',
            $arvConf >= 35 => 'medium',
            default => 'low',
        };

        /* ── verdict ─────────────────────────────────────────────────── */
        $points = (int) array_sum(array_column($f, 'tone'));
        $floodHigh = is_array($flood) && !empty($flood['sfha']);
        if (!$deal['ok']) {
            $verdict = 'unknown';
        } else {
            $profit = (float) $deal['base']['profit'];
            $verdict = match (true) {
                $profit >= (float) $deal['target'] && $points >= 4 => 'buy',
                $profit > 0 && $points >= 0 => 'offer',
                $offer > 0 && $forSale && $offer >= $price * 0.75 => 'lower',
                default => 'pass',
            };
            if ($floodHigh && $verdict === 'buy') {
                $verdict = 'offer';
            }
            // "Buy at asking" also needs the cautious case not to lose money, and some confidence.
            if ($verdict === 'buy' && ($confidence === 'low' || (float) $deal['scenarios']['pessimistic']['profit'] < 0)) {
                $verdict = 'offer';
            }
        }
        $summary = match ($verdict) {
            'buy' => ['The numbers work at the asking price and most of what matters points the same way. Offer up to %s.', [money($offer)]],
            'offer' => ['A deal is there if the price is right: offer no more than %s.', [money($offer)]],
            'lower' => ['At the asking price the profit isn’t there. It works only at about %s or less.', [money($offer)]],
            'pass' => ['Even well below asking the numbers don’t work. Move on unless something here is wrong.', []],
            default => ['Missing: %s. Pull recorded sales or the listing for this home to get a verdict.', [implode(', ', (array) ($deal['missing'] ?? []))]],
        };
        $check('Confirm the ARV with an agent’s comparable sales before an offer.');
        return [
            'verdict' => $verdict, 'points' => $points, 'offer' => $offer, 'confidence' => $confidence,
            'factors' => $f, 'checks' => $checks, 'summary' => ['text' => $summary[0], 'args' => $summary[1]],
        ];
    }

    private static function plain(float $v, int $dec = 0): string
    {
        return number_format($v, $dec) . '%';
    }

    /** ZHVI change over 12 months for the ZIP, when its market data is on file. */
    private static function valueTrend(string $zip): ?float
    {
        if ($zip === '') {
            return null;
        }
        $rows = Db::all(
            "SELECT s.value FROM market_series s JOIN market_regions r ON r.id = s.region_id
             WHERE r.kind = 'zip' AND r.name = ? AND s.metric = 'zhvi' ORDER BY s.period DESC LIMIT 13",
            [$zip]
        );
        if (count($rows) < 13 || (float) $rows[12]['value'] <= 0) {
            return null;
        }
        return ((float) $rows[0]['value'] / (float) $rows[12]['value'] - 1) * 100;
    }

    /** Median lot of homes of this type recorded in the ZIP. */
    private static function areaLot(string $zip, string $type): ?int
    {
        if ($zip === '') {
            return null;
        }
        $lots = array_map('intval', array_column(Db::all(
            'SELECT lot_sqft FROM properties WHERE zip = ? AND lot_sqft > 0' . ($type !== '' ? ' AND property_type = ?' : '') . ' LIMIT 2000',
            $type !== '' ? [$zip, $type] : [$zip]
        ), 'lot_sqft'));
        if (count($lots) < 8) {
            return null;
        }
        sort($lots);
        return $lots[intdiv(count($lots), 2)];
    }

    /** A cached free lookup (FEMA zone, census tract) — never a request. */
    private static function cached(string $provider, string $what, array $p, int $digits): mixed
    {
        if (!Geo::valid($p['lat'] ?? null, $p['lng'] ?? null)) {
            return null;
        }
        return Cache::get(Cache::key($provider, $what, ['lat' => round((float) $p['lat'], $digits), 'lng' => round((float) $p['lng'], $digits)]));
    }
}

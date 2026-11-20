<?php
declare(strict_types=1);

namespace App\Services;

use App\Db;

/**
 * How fast homes move in one ZIP code, from what is on file:
 *
 *   flips   — homes bought and sold again within 18 months for at least 20% more: the
 *             time investors actually took from purchase to resale (renovation, listing and
 *             closing included) and the gross gain. From the county sale histories.
 *   market  — days on market of listings: those still for sale, and those that left the
 *             market in the last 12 months, split into renovated / not when the listing said so.
 *
 * Medians, not averages, so one odd sale does not move the figure. Every number carries its count.
 */
final class AreaPace
{
    /** A buy and a resale closer than this is a paperwork transfer, not a flip. */
    private const MIN_DAYS = 45;
    /** Longer than this is a hold, not a flip. */
    private const MAX_DAYS = 548;
    /** The resale must beat the purchase by at least this much. */
    private const MIN_GAIN = 0.20;
    /** Below this many flips the figure is shown but not used in the calculator. */
    public const MIN_FLIPS = 5;

    private static array $memo = [];

    /**
     * @return array{zip:string, flips:array{count:int, last_year:int, months:?float, months_p25:?float, months_p75:?float, gain_pct:?float, resale:?int, examples:list<array>},
     *               market:array{active:int, active_days:?int, sold:int, sold_days:?int, renovated:int, renovated_days:?int, other:int, other_days:?int}}
     */
    public static function forZip(string $zip): array
    {
        $zip = substr(preg_replace('/\D/', '', $zip) ?? '', 0, 5);
        if (isset(self::$memo[$zip])) {
            return self::$memo[$zip];
        }
        return self::$memo[$zip] = ['zip' => $zip, 'flips' => self::flips($zip), 'market' => self::market($zip)];
    }

    /** Months from purchase to sale for the deal calculator, when the area has enough flips. */
    public static function holdingMonths(string $zip): ?array
    {
        $f = self::forZip($zip)['flips'];
        if ($f['count'] < self::MIN_FLIPS || $f['months'] === null) {
            return null;
        }
        return ['months' => max(2.0, min(18.0, round($f['months'] * 2) / 2)), 'count' => $f['count']];
    }

    private static function flips(string $zip): array
    {
        $empty = ['count' => 0, 'last_year' => 0, 'months' => null, 'months_p25' => null, 'months_p75' => null, 'gain_pct' => null, 'resale' => null, 'examples' => []];
        if ($zip === '') {
            return $empty;
        }
        $rows = Db::all(
            "SELECT e.property_id, e.event_date, e.price, p.address, p.city
             FROM price_events e JOIN properties p ON p.id = e.property_id
             WHERE p.zip = ? AND e.event = 'sold' AND e.price > 10000 AND e.event_date >= ?
             ORDER BY e.property_id, e.event_date",
            [$zip, gmdate('Y-m-d', time() - 4 * 365 * 86400)]
        );
        $since = gmdate('Y-m-d', time() - 3 * 365 * 86400);
        $yearAgo = gmdate('Y-m-d', time() - 365 * 86400);
        $months = $gains = $resales = $examples = [];
        $lastYear = 0;
        $prev = null;
        foreach ($rows as $r) {
            if ($prev !== null && $prev['property_id'] === $r['property_id']) {
                $days = (int) round((strtotime($r['event_date']) - strtotime($prev['event_date'])) / 86400);
                $buy = (int) $prev['price'];
                $sell = (int) $r['price'];
                if ($days >= self::MIN_DAYS && $days <= self::MAX_DAYS && $sell >= $buy * (1 + self::MIN_GAIN) && $buy >= $sell * 0.25 && $r['event_date'] >= $since) {
                    $months[] = $days / 30.44;
                    $gains[] = ($sell - $buy) / $buy * 100;
                    $resales[] = $sell;
                    if ($r['event_date'] >= $yearAgo) {
                        $lastYear++;
                    }
                    $examples[] = ['id' => (int) $r['property_id'], 'address' => $r['address'], 'bought' => $prev['event_date'], 'buy' => $buy,
                        'sold' => $r['event_date'], 'sell' => $sell, 'months' => round($days / 30.44, 1)];
                }
            }
            $prev = $r;
        }
        if (!$months) {
            return $empty;
        }
        usort($examples, static fn ($a, $b) => strcmp($b['sold'], $a['sold']));
        return [
            'count' => count($months),
            'last_year' => $lastYear,
            'months' => round(self::pct($months, 50), 1),
            'months_p25' => round(self::pct($months, 25), 1),
            'months_p75' => round(self::pct($months, 75), 1),
            'gain_pct' => round(self::pct($gains, 50)),
            'resale' => (int) round(self::pct($resales, 50)),
            'examples' => array_slice($examples, 0, 5),
        ];
    }

    private static function market(string $zip): array
    {
        $active = array_map('intval', array_column(Db::all(
            "SELECT days_on_market FROM properties WHERE zip = ? AND status IN ('for_sale','pending') AND days_on_market IS NOT NULL",
            [$zip]
        ), 'days_on_market'));
        // Listings that ended in the last 12 months: listed date to the date they came off (sold or removed).
        $ended = Db::all(
            "SELECT p.id, MAX(l.event_date) listed, MIN(o.event_date) gone, COALESCE(c.overall, 'not_disclosed') overall
             FROM properties p
             JOIN price_events l ON l.property_id = p.id AND l.event IN ('listed','relisted')
             JOIN price_events o ON o.property_id = p.id AND o.event IN ('removed','sold') AND o.event_date > l.event_date
             LEFT JOIN property_condition c ON c.property_id = p.id
             WHERE p.zip = ? AND o.event_date >= ?
             GROUP BY p.id, c.overall",
            [$zip, gmdate('Y-m-d', time() - 365 * 86400)]
        );
        $sold = $reno = $other = [];
        foreach ($ended as $e) {
            $d = (int) round((strtotime($e['gone']) - strtotime($e['listed'])) / 86400);
            if ($d < 0 || $d > 730) {
                continue;
            }
            $sold[] = $d;
            if (in_array($e['overall'], ['recently_renovated', 'move_in_ready'], true)) {
                $reno[] = $d;
            } elseif ($e['overall'] !== 'not_disclosed') {
                $other[] = $d;
            }
        }
        $med = static fn (array $a) => $a ? (int) round(self::pct($a, 50)) : null;
        return [
            'active' => count($active), 'active_days' => $med($active),
            'sold' => count($sold), 'sold_days' => $med($sold),
            'renovated' => count($reno), 'renovated_days' => $med($reno),
            'other' => count($other), 'other_days' => $med($other),
        ];
    }

    /** Percentile with linear interpolation. @param float[]|int[] $a */
    private static function pct(array $a, float $p): float
    {
        sort($a);
        $n = count($a);
        if ($n === 1) {
            return (float) $a[0];
        }
        $i = ($n - 1) * $p / 100;
        $lo = (int) floor($i);
        $hi = (int) ceil($i);
        return (float) $a[$lo] + ((float) $a[$hi] - (float) $a[$lo]) * ($i - $lo);
    }
}

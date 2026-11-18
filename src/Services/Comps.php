<?php
declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Geo;

/**
 * Comparable sales from the local database.
 *
 * A comp is a recorded sale near the subject, recent, and of similar size.
 * Each one gets a 0–100 similarity score; the value range comes from the
 * middle half of the comps' price per square foot applied to the subject.
 */
final class Comps
{
    public const RADII = [0.5, 1, 2, 5];
    public const MONTHS = [3, 6, 12, 24];

    /**
     * @return array{comps:array, stats:?array, radius:float, months:int, widened:bool}
     */
    public static function find(array $subject, array $opt = []): array
    {
        $empty = ['comps' => [], 'stats' => null, 'radius' => (float) ($opt['radius'] ?? 1), 'months' => (int) ($opt['months'] ?? 6), 'widened' => false];
        if (!Geo::valid($subject['lat'] ?? null, $subject['lng'] ?? null)) {
            return $empty;
        }
        $explicit = isset($opt['radius']) || isset($opt['months']);
        $radius = (float) ($opt['radius'] ?? 1);
        $months = (int) ($opt['months'] ?? 6);
        $comps = self::query($subject, $radius, $months, $opt);

        // Nothing chosen by hand and too few results → widen once, and say so.
        $widened = false;
        if (!$explicit && count($comps) < 4) {
            foreach ([[2, 6], [2, 12], [5, 12]] as [$r, $m]) {
                $try = self::query($subject, $r, $m, $opt);
                if (count($try) > count($comps)) {
                    [$comps, $radius, $months, $widened] = [$try, (float) $r, $m, true];
                }
                if (count($comps) >= 4) {
                    break;
                }
            }
        }
        $comps = array_slice($comps, 0, (int) ($opt['limit'] ?? 15));
        return ['comps' => $comps, 'stats' => self::stats($comps, $subject), 'radius' => $radius, 'months' => $months, 'widened' => $widened];
    }

    private static function query(array $s, float $radius, int $months, array $opt): array
    {
        $lat = (float) $s['lat'];
        $lng = (float) $s['lng'];
        $box = Geo::box($lat, $lng, $radius);
        $cutoff = gmdate('Y-m-d', strtotime("-$months months"));
        $rows = Db::all(
            'SELECT id, address, city, state, zip, lat, lng, property_type, beds, baths, sqft, lot_sqft, year_built,
                    last_sale_price, last_sale_date, source
             FROM properties
             WHERE last_sale_price > 0 AND last_sale_date >= ? AND id <> ?
               AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?
             LIMIT 3000',
            [$cutoff, (int) ($s['id'] ?? 0), $box['lat1'], $box['lat2'], $box['lng1'], $box['lng2']]
        );
        $sqft = (float) ($s['sqft'] ?? 0);
        $sameType = ($opt['same_type'] ?? true) && !empty($s['property_type']);
        $out = [];
        foreach ($rows as $r) {
            $d = Geo::miles($lat, $lng, (float) $r['lat'], (float) $r['lng']);
            if ($d > $radius) {
                continue;
            }
            if ($sameType && !empty($r['property_type']) && $r['property_type'] !== $s['property_type']) {
                continue;
            }
            $rs = (float) ($r['sqft'] ?? 0);
            // Size must be within ±35% when both are known; a sale without sqft is kept but scores lower.
            if ($sqft > 0 && $rs > 0 && abs($rs - $sqft) / $sqft > 0.35) {
                continue;
            }
            $r['distance'] = $d;
            $r['ppsf'] = $rs > 0 ? (float) $r['last_sale_price'] / $rs : null;
            $r['score'] = self::score($s, $r, $d, $radius, $months);
            $out[] = $r;
        }
        usort($out, static fn ($a, $b) => $b['score'] <=> $a['score']);
        return $out;
    }

    private static function score(array $s, array $r, float $dist, float $radius, int $months): int
    {
        $closeness = 1 - min(1.0, $dist / max(0.1, $radius));
        $sqft = (float) ($s['sqft'] ?? 0);
        $rs = (float) ($r['sqft'] ?? 0);
        $size = ($sqft > 0 && $rs > 0) ? 1 - min(1.0, abs($rs - $sqft) / $sqft / 0.35) : 0.4;
        $beds = self::near($s['beds'] ?? null, $r['beds'] ?? null, 2);
        $baths = self::near($s['baths'] ?? null, $r['baths'] ?? null, 2);
        $age = self::near($s['year_built'] ?? null, $r['year_built'] ?? null, 30);
        $ageDays = max(0, (time() - (int) strtotime($r['last_sale_date'] . ' UTC')) / 86400);
        $recency = 1 - min(1.0, $ageDays / max(30, $months * 30.4));
        $score = 0.30 * $closeness + 0.30 * $size + 0.10 * $beds + 0.05 * $baths + 0.07 * $age + 0.18 * $recency;
        return (int) round($score * 100);
    }

    private static function near(mixed $a, mixed $b, float $span): float
    {
        if (!is_numeric($a) || !is_numeric($b)) {
            return 0.5;
        }
        return 1 - min(1.0, abs((float) $a - (float) $b) / $span);
    }

    /**
     * Similar homes for sale near a home: same type, size within ±35%, nearest first widening to 3 miles.
     * Used for a rough value only when no recorded sales are on file.
     * @return array{rows: array<int, array{ppsf: float, distance: float}>, radius: float}
     */
    public static function listings(array $s, int $want = 5): array
    {
        if (!Geo::valid($s['lat'] ?? null, $s['lng'] ?? null) || (float) ($s['sqft'] ?? 0) <= 0) {
            return ['rows' => [], 'radius' => 0.0];
        }
        $lat = (float) $s['lat'];
        $lng = (float) $s['lng'];
        $sqft = (float) $s['sqft'];
        $best = ['rows' => [], 'radius' => 0.0];
        foreach ([1.0, 2.0, 3.0] as $radius) {
            $box = Geo::box($lat, $lng, $radius);
            $rows = Db::all(
                "SELECT lat, lng, sqft, list_price, property_type FROM properties
                 WHERE status IN ('for_sale','pending') AND list_price > 0 AND sqft > 200 AND id <> ?
                   AND sqft BETWEEN ? AND ? AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?
                 LIMIT 1500",
                [(int) ($s['id'] ?? 0), $sqft * 0.65, $sqft * 1.35, $box['lat1'], $box['lat2'], $box['lng1'], $box['lng2']]
            );
            $out = [];
            foreach ($rows as $r) {
                if (!empty($s['property_type']) && !empty($r['property_type']) && $r['property_type'] !== $s['property_type']) {
                    continue;
                }
                $d = Geo::miles($lat, $lng, (float) $r['lat'], (float) $r['lng']);
                if ($d <= $radius) {
                    $out[] = ['ppsf' => (float) $r['list_price'] / (float) $r['sqft'], 'distance' => $d];
                }
            }
            $best = ['rows' => $out, 'radius' => $radius];
            if (count($out) >= $want) {
                break;
            }
        }
        return $best;
    }

    public static function stats(array $comps, array $subject): ?array
    {
        if (!$comps) {
            return null;
        }
        $prices = array_map(static fn ($c) => (float) $c['last_sale_price'], $comps);
        $ppsf = array_values(array_filter(array_column($comps, 'ppsf')));
        $sqft = (float) ($subject['sqft'] ?? 0);
        $stats = [
            'count' => count($comps),
            'median_price' => median($prices),
            'low_price' => min($prices),
            'high_price' => max($prices),
            'median_ppsf' => $ppsf ? median($ppsf) : null,
            'value' => null, 'value_low' => null, 'value_high' => null,
        ];
        // Three or more comps with sqft → estimate from $/sqft; fewer is not enough to call it a range.
        if ($sqft > 0 && count($ppsf) >= 3) {
            $stats['value'] = (int) round(median($ppsf) * $sqft, -2);
            $stats['value_low'] = (int) round(percentile($ppsf, 0.25) * $sqft, -2);
            $stats['value_high'] = (int) round(percentile($ppsf, 0.75) * $sqft, -2);
        }
        return $stats;
    }
}

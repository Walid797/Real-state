<?php
declare(strict_types=1);

namespace App\Services;

use App\Address;
use App\Cache;
use App\Db;
use App\PropertyRepo;

/**
 * Opportunity score (0–100, higher is better) with the team's weights:
 *   net profit 30 · ROI 20 · price vs area 15 · ARV credibility 15 ·
 *   condition / renovation load 10 · liquidity 5 · data completeness 5
 *
 * Risk score (0–100, higher is riskier) is separate. Missing facts lower the
 * confidence and raise the risk; they are never treated as good news.
 */
final class Score
{
    public const WEIGHTS = [
        'profit' => ['Net profit', 30], 'roi' => ['Return on investment', 20], 'price' => ['Price vs the area', 15],
        'arv' => ['ARV backed by sales', 15], 'condition' => ['Renovation load', 10], 'liquidity' => ['How fast homes sell', 5],
        'data' => ['How complete the data is', 5],
    ];

    /** Recomputes and stores the analysis for one home. Returns the full breakdown. */
    public static function refresh(int $propertyId): ?array
    {
        $p = Db::one('SELECT * FROM properties WHERE id = ?', [$propertyId]);
        if (!$p) {
            return null;
        }
        $condition = Condition::refresh($p);
        $p = Db::one('SELECT * FROM properties WHERE id = ?', [$propertyId]);
        $deal = Deal::analyse($p, $condition);
        $s = self::compute($p, $deal, $condition);
        Db::update('properties', [
            'score' => $s['score'], 'risk' => $s['risk'], 'confidence' => $s['confidence'],
            'est_arv' => $deal['inputs']['arvValue'] ? (int) $deal['inputs']['arvValue'] : null,
            'est_reno' => $deal['inputs']['reno'] !== null ? (int) $deal['inputs']['reno'] : null,
            'est_profit' => $deal['ok'] ? (int) round($deal['base']['profit']) : null,
            'est_roi' => $deal['ok'] ? round($deal['base']['roi'], 1) : null,
            'scored_at' => now(),
        ], $propertyId);
        return ['deal' => $deal, 'score' => $s, 'condition' => $condition];
    }

    /** @param int[] $ids */
    public static function refreshMany(array $ids, float $budgetSeconds = 60): int
    {
        $t = microtime(true);
        $n = 0;
        foreach ($ids as $id) {
            if (microtime(true) - $t > $budgetSeconds) {
                break;
            }
            self::refresh((int) $id);
            $n++;
        }
        return $n;
    }

    /**
     * @return array{score:?int, risk:int, confidence:int, parts:array<string,int>, risks:string[], missing:string[]}
     */
    public static function compute(array $p, array $deal, array $condition): array
    {
        $parts = array_fill_keys(array_keys(self::WEIGHTS), 0);
        $risks = [];
        $missing = [];
        $lerp = static fn (float $v, float $lo, float $hi, int $max) => (int) round(max(0.0, min(1.0, ($v - $lo) / ($hi - $lo))) * $max);

        // Data completeness — also feeds confidence.
        $facts = ['sqft' => 'living area', 'beds' => 'bedrooms', 'baths' => 'bathrooms', 'year_built' => 'year built', 'lat' => 'map position', 'tax_annual' => 'property tax'];
        $have = 0;
        foreach ($facts as $col => $label) {
            if ($p[$col] !== null && $p[$col] !== '' && (float) $p[$col] > 0) {
                $have++;
            } else {
                $missing[] = $label;
            }
        }
        $condKnown = $condition['overall'] !== 'not_disclosed';
        if (!$condKnown) {
            $missing[] = 'condition';
        }
        $completeness = ($have + ($condKnown ? 1 : 0) + (!empty($p['description']) ? 1 : 0)) / (count($facts) + 2);
        $parts['data'] = (int) round($completeness * 5);

        $arvConf = (int) ($deal['arv']['confidence'] ?? 0);
        if (($deal['source']['arv'] ?? 'est') === 'set') {
            $arvConf = max($arvConf, 70); // someone on the team set it
        }
        $parts['arv'] = (int) round($arvConf / 100 * 15);

        if ($deal['ok']) {
            $profit = $deal['base']['profit'];
            $roi = $deal['base']['roi'];
            $parts['profit'] = $lerp($profit, 0, 70000, 30);
            $parts['roi'] = $lerp($roi, 0, 25, 20);
            $renoShare = $deal['inputs']['reno'] / max(1, $deal['inputs']['purchase']);
            $parts['condition'] = $condKnown ? 10 - $lerp($renoShare, 0.08, 0.45, 8) : 4;
            if ($profit / max(1, $deal['inputs']['arvValue']) < 0.08) {
                $risks[] = 'Thin margin: under 8% of ARV';
            }
            if ($renoShare > 0.3) {
                $risks[] = 'Renovation is over 30% of the price';
            }
            if ($deal['scenarios']['pessimistic']['profit'] < 0) {
                $risks[] = 'Loses money in the cautious scenario';
            }
        } else {
            $missing = array_merge($missing, $deal['missing'] ?? []);
        }

        // Price per sqft against what is for sale nearby.
        $area = self::areaPpsf($p);
        $pp = ppsf($deal['inputs']['purchase'] ?? null, $p['sqft']);
        if ($area && $pp) {
            $parts['price'] = $lerp(1 - $pp / $area, -0.05, 0.25, 15);
        } else {
            $parts['price'] = 5;
        }

        $dom = PropertyRepo::dom($p);
        $parts['liquidity'] = $dom === null ? 2 : 5 - $lerp((float) $dom, 21, 150, 4);

        $score = $deal['ok'] ? array_sum($parts) : null;

        // ── risk ───────────────────────────────────────────────────────
        $risk = 0;
        if ($condition['overall'] === 'major_repair') {
            $risk += 30;
            $risks[] = 'Major repairs mentioned';
        } elseif (!$condKnown) {
            $risk += 15;
            $risks[] = 'Condition not disclosed';
        }
        $risk += (int) round((100 - $arvConf) * 0.25);
        if ($arvConf < 40) {
            $risks[] = 'Few comparable sales behind the ARV';
        }
        foreach ($risks as $r) {
            $risk += match ($r) {
                'Thin margin: under 8% of ARV' => 15, 'Renovation is over 30% of the price' => 10, 'Loses money in the cautious scenario' => 10, default => 0,
            };
        }
        if ($dom !== null && $dom > 90) {
            $risk += 8;
            $risks[] = 'On the market ' . $dom . ' days';
        }
        $year = (int) ($p['year_built'] ?? 0);
        if ($year > 0 && $year < 1950) {
            $risk += 8;
            $risks[] = 'Built before 1950';
        }
        $flags = array_filter(explode(',', (string) ($p['flags'] ?? '')));
        if (array_intersect($flags, ['foreclosure', 'auction', 'as_is', 'short_sale'])) {
            $risk += 10;
            $risks[] = 'Distressed sale (title, access or timing risk)';
        }
        $flood = self::floodRisk($p);
        if ($flood === 'high') {
            $risk += 15;
            $risks[] = 'FEMA flood zone';
        }

        $confidence = (int) round($arvConf * 0.6 + $completeness * 40);
        return ['score' => $score, 'risk' => min(100, $risk), 'confidence' => max(0, min(100, $confidence)),
            'parts' => $parts, 'risks' => array_values(array_unique($risks)), 'missing' => array_values(array_unique($missing))];
    }

    /** Median asking $/sqft of homes for sale in the same ZIP (or city), same type when possible. */
    public static function areaPpsf(array $p): ?float
    {
        static $memo = [];
        $key = ($p['zip'] ?: $p['city'] . $p['state']) . '|' . ($p['property_type'] ?? '');
        if (array_key_exists($key, $memo)) {
            return $memo[$key];
        }
        $loc = $p['zip'] !== '' ? ['type' => 'zip', 'zip' => $p['zip']] : ['type' => 'city', 'city' => $p['city'], 'state' => $p['state']];
        $v = Search::areaPpsf($loc, $p['property_type'] ?: null) ?? Search::areaPpsf($loc);
        return $memo[$key] = $v;
    }

    /** Uses the cached FEMA answer only — never calls out while scoring. */
    private static function floodRisk(array $p): ?string
    {
        if (!\App\Geo::valid($p['lat'], $p['lng'])) {
            return null;
        }
        $hit = Cache::get(Cache::key('fema', 'zone', ['lat' => round((float) $p['lat'], 5), 'lng' => round((float) $p['lng'], 5)]));
        return is_array($hit) ? ($hit['risk'] ?? null) : null;
    }

    public static function band(?int $score): string
    {
        return match (true) {
            $score === null => 'none',
            $score >= 70 => 'high',
            $score >= 50 => 'mid',
            default => 'low',
        };
    }
}

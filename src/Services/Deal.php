<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth;
use App\Db;
use App\Settings;

/**
 * Fix-and-flip arithmetic, per the team's spec:
 *
 *   Total investment = purchase + closing + renovation (+ contingency) + holding + financing
 *   Net profit       = ARV − total investment − selling costs
 *   ROI              = net profit ÷ total investment × 100
 *
 * Every input is either typed by someone (kept in `deals`) or estimated; the
 * result says which, so an estimate is never mistaken for a fact.
 */
final class Deal
{
    /** key => [label, default, unit] — editable under Settings. */
    public const SETTINGS = [
        'deal_closing_pct' => ['Buying closing costs', 2, '% of price'],
        'deal_selling_pct' => ['Selling costs (commission, transfer, concessions)', 7, '% of ARV'],
        'deal_months' => ['Months from purchase to sale', 6, 'months'],
        'deal_carry_monthly' => ['Insurance, utilities, upkeep', 250, '$ a month'],
        'deal_tax_pct' => ['Property tax, when the record has none', 1.0, '% of price a year'],
        'deal_loan_ltv' => ['Loan amount', 0, '% of price (0 = cash)'],
        'deal_loan_rate' => ['Loan interest', 11, '% a year'],
        'deal_loan_points' => ['Lender points', 2, '% of loan'],
        'deal_contingency_pct' => ['Contingency', 10, '% of renovation'],
        'deal_target_profit' => ['Profit you aim for', 30000, '$ per deal'],
        'reno_light' => ['Renovation — move-in ready / renovated', 8, '$ per sqft'],
        'reno_medium' => ['Renovation — partially renovated', 30, '$ per sqft'],
        'reno_heavy' => ['Renovation — needs renovation', 55, '$ per sqft'],
        'reno_major' => ['Renovation — major repairs', 85, '$ per sqft'],
        'reno_unknown' => ['Renovation — condition not disclosed', 35, '$ per sqft'],
    ];

    /** Editable fields of the shared calculator. */
    public const FIELDS = [
        'purchase_price', 'closing_pct', 'reno_cost', 'holding_months', 'holding_monthly', 'loan_ltv_pct',
        'loan_rate_pct', 'loan_points_pct', 'selling_pct', 'arv', 'contingency_pct', 'actual_purchase', 'actual_costs', 'actual_sale',
    ];

    public static function setting(string $key): float
    {
        $v = Settings::get($key);
        return $v !== null && is_numeric($v) ? (float) $v : (float) self::SETTINGS[$key][1];
    }

    public static function saved(int $propertyId): array
    {
        return Db::one('SELECT * FROM deals WHERE property_id = ?', [$propertyId]) ?? [];
    }

    public static function save(int $propertyId, array $in): void
    {
        $before = self::saved($propertyId);
        $row = ['property_id' => $propertyId, 'updated_by' => Auth::userId() ?: null, 'updated_at' => now()];
        $changed = [];
        foreach (self::FIELDS as $f) {
            $v = isset($in[$f]) && is_scalar($in[$f]) ? parse_num((string) $in[$f]) : null;
            if ($v !== null && ($v < 0 || $v > 1e9)) {
                $v = null;
            }
            $row[$f] = $v === null ? null : (str_ends_with($f, '_pct') || $f === 'holding_months' ? round($v, 2) : (int) round($v));
            if ((string) ($before[$f] ?? '') !== (string) ($row[$f] ?? '') && (float) ($before[$f] ?? 0) != (float) ($row[$f] ?? 0)) {
                $changed[] = str_replace('_', ' ', $f);
            }
        }
        $names = array_keys($row);
        Db::run(
            'INSERT INTO deals (`' . implode('`,`', $names) . '`) VALUES (:' . implode(',:', $names) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn ($c) => "`$c` = VALUES(`$c`)", array_diff($names, ['property_id']))),
            $row
        );
        if ($changed) {
            Activity::log('deal', $propertyId, 'Changed ' . implode(', ', array_slice($changed, 0, 6)));
        }
    }

    /**
     * ARV from recorded sales nearby: renovated homes sell toward the top of the
     * neighbourhood, so the upper quartile of comparable $/sqft × this home's size.
     * @return array{value:?int, low:?int, high:?int, confidence:int, basis:string, comps:int}
     */
    public static function arv(array $p): array
    {
        $none = ['value' => null, 'low' => null, 'high' => null, 'confidence' => 0, 'basis' => 'Not enough data', 'comps' => 0];
        $sqft = (float) ($p['sqft'] ?? 0);
        $c = Comps::find($p, ['months' => 12, 'radius' => 1, 'limit' => 25]);
        if (!$c['comps'] || $c['stats'] === null) {
            $c = Comps::find($p, []); // let it widen
        }
        $ppsf = array_values(array_filter(array_column($c['comps'], 'ppsf')));
        if ($sqft > 0 && count($ppsf) >= 3) {
            $q3 = percentile($ppsf, 0.75);
            $med = median($ppsf);
            $spread = $med > 0 ? (percentile($ppsf, 0.75) - percentile($ppsf, 0.25)) / $med : 1.0;
            $avgDist = array_sum(array_column($c['comps'], 'distance')) / max(1, count($c['comps']));
            $conf = 35 + min(30, count($ppsf) * 4) - (int) round(min(1.0, $spread) * 30) - (int) round(min(3.0, $avgDist) * 5) + ($c['months'] <= 12 ? 10 : 0);
            return [
                'value' => (int) round($q3 * $sqft, -3),
                'low' => (int) round($med * $sqft, -3),
                'high' => (int) round(percentile($ppsf, 0.9) * $sqft, -3),
                'confidence' => max(10, min(95, $conf)),
                'basis' => count($ppsf) . ' sales within ' . trim_num($c['radius']) . ' mi, last ' . $c['months'] . ' months',
                'comps' => count($ppsf),
            ];
        }
        $avm = (int) ($p['est_value_high'] ?? 0) ?: (int) ($p['est_value'] ?? 0);
        if ($avm > 0) {
            return ['value' => $avm, 'low' => (int) ($p['est_value'] ?? $avm), 'high' => $avm, 'confidence' => 25,
                'basis' => ($p['est_source'] === 'zestimate' ? 'Zestimate' : 'RentCast estimate') . ' (no sold comps on file)', 'comps' => 0];
        }
        // Last resort, free: what similar homes nearby are asking. Asking is not selling, so confidence stays low.
        $ask = Comps::listings($p);
        $askPpsf = array_column($ask['rows'], 'ppsf');
        if ($sqft > 0 && count($askPpsf) >= 5) {
            $spread = (percentile($askPpsf, 0.75) - percentile($askPpsf, 0.25)) / max(1.0, median($askPpsf));
            return [
                'value' => (int) round(percentile($askPpsf, 0.65) * $sqft, -3),
                'low' => (int) round(median($askPpsf) * $sqft, -3),
                'high' => (int) round(percentile($askPpsf, 0.85) * $sqft, -3),
                'confidence' => max(12, min(30, 30 - (int) round(min(1.0, $spread) * 15) + min(5, count($askPpsf) - 5))),
                'basis' => 'Asking prices of ' . count($askPpsf) . ' similar homes for sale within ' . trim_num($ask['radius']) . ' mi — no recorded sales yet',
                'comps' => 0, 'from' => 'asking',
            ];
        }
        return $none;
    }

    public static function renoEstimate(array $p, string $overall): ?int
    {
        $sqft = (float) ($p['sqft'] ?? 0);
        if ($sqft <= 0) {
            return null;
        }
        $rate = self::setting(Condition::tier($overall));
        // Older homes hide more: +15% before 1960, +8% before 1980.
        $year = (int) ($p['year_built'] ?? 0);
        $age = $year > 0 && $year < 1960 ? 1.15 : ($year > 0 && $year < 1980 ? 1.08 : 1.0);
        return (int) round($sqft * $rate * $age, -2);
    }

    /**
     * @return array full breakdown; null figures mean "not enough to say"
     */
    public static function analyse(array $p, ?array $condition = null, ?array $saved = null, ?array $arv = null): array
    {
        $saved ??= self::saved((int) $p['id']);
        $condition ??= Condition::get((int) $p['id']);
        $arv ??= self::arv($p);
        $src = [];
        $pick = static function (string $field, mixed $estimate) use ($saved, &$src) {
            if (isset($saved[$field]) && $saved[$field] !== null) {
                $src[$field] = 'set';
                return (float) $saved[$field];
            }
            $src[$field] = 'est';
            return $estimate === null ? null : (float) $estimate;
        };

        $listed = in_array($p['status'] ?? '', ['for_sale', 'pending'], true) && (int) $p['list_price'] > 0;
        $purchase = $pick('purchase_price', $listed ? (int) $p['list_price'] : ((int) ($p['last_sale_price'] ?? 0) ?: null));
        $arvValue = $pick('arv', $arv['value']);
        $reno = $pick('reno_cost', self::renoEstimate($p, $condition['overall']));
        $closingPct = $pick('closing_pct', self::setting('deal_closing_pct'));
        $sellingPct = $pick('selling_pct', self::setting('deal_selling_pct'));
        // Months held: how long flips in this ZIP actually took, when there are enough of them.
        $pace = AreaPace::holdingMonths((string) ($p['zip'] ?? ''));
        $months = $pick('holding_months', $pace['months'] ?? self::setting('deal_months'));
        // Tax from the record when there is one; otherwise a stated rate on the price.
        $taxYear = (float) ($p['tax_annual'] ?? 0);
        $taxEstimated = $taxYear <= 0 && $purchase;
        if ($taxEstimated) {
            $taxYear = $purchase * self::setting('deal_tax_pct') / 100;
        }
        $monthly = $pick('holding_monthly', self::setting('deal_carry_monthly') + $taxYear / 12 + (float) ($p['hoa_fee'] ?? 0));
        $ltv = $pick('loan_ltv_pct', self::setting('deal_loan_ltv'));
        $rate = $pick('loan_rate_pct', self::setting('deal_loan_rate'));
        $points = $pick('loan_points_pct', self::setting('deal_loan_points'));
        $contPct = $pick('contingency_pct', self::setting('deal_contingency_pct'));

        $out = [
            'inputs' => compact('purchase', 'arvValue', 'reno', 'closingPct', 'sellingPct', 'months', 'monthly', 'ltv', 'rate', 'points', 'contPct'),
            'source' => $src, 'arv' => $arv, 'condition' => $condition['overall'], 'ok' => false, 'tax_estimated' => $taxEstimated,
            'months_from' => ($src['holding_months'] ?? '') === 'est' ? ($pace ? 'flips' : 'default') : 'set', 'months_flips' => $pace['count'] ?? 0,
        ];
        if (!$purchase || !$arvValue || $reno === null) {
            $out['missing'] = array_keys(array_filter(['purchase price' => !$purchase, 'ARV' => !$arvValue, 'renovation cost (needs sqft)' => $reno === null]));
            return $out;
        }

        $calc = static function (float $price, float $arvV, float $renoV, float $m) use ($closingPct, $sellingPct, $monthly, $ltv, $rate, $points, $contPct): array {
            $closing = $price * $closingPct / 100;
            $contingency = $renoV * $contPct / 100;
            $holding = $m * $monthly;
            $loan = $price * $ltv / 100;
            $financing = $loan * ($rate / 100 / 12 * $m + $points / 100);
            $total = $price + $closing + $renoV + $contingency + $holding + $financing;
            $selling = $arvV * $sellingPct / 100;
            $profit = $arvV - $total - $selling;
            return [
                'closing' => $closing, 'contingency' => $contingency, 'holding' => $holding, 'financing' => $financing, 'loan' => $loan,
                'total' => $total, 'selling' => $selling, 'profit' => $profit, 'roi' => $total > 0 ? $profit / $total * 100 : 0.0,
                'cash' => $total - $loan,
            ];
        };
        $base = $calc($purchase, $arvValue, $reno, $months);

        // Highest price that still leaves the target profit (closing and loan costs scale with price).
        $target = self::setting('deal_target_profit');
        $perDollar = 1 + $closingPct / 100 + $ltv / 100 * ($rate / 100 / 12 * $months + $points / 100);
        $fixed = $reno * (1 + $contPct / 100) + $months * $monthly;
        $maxOffer = ($arvValue * (1 - $sellingPct / 100) - $fixed - $target) / $perDollar;

        $out = array_merge($out, [
            'ok' => true,
            'base' => $base,
            'max_offer' => max(0, $maxOffer),
            'rule70' => max(0, $arvValue * 0.70 - $reno),
            'break_even' => $base['total'] / max(0.01, 1 - $sellingPct / 100),
            'target' => $target,
            'scenarios' => [
                'optimistic' => $calc($purchase, $arvValue * 1.05, $reno * 0.9, max(1, $months - 1)),
                'typical' => $base,
                'pessimistic' => $calc($purchase, $arvValue * 0.92, $reno * 1.25, $months + 2),
            ],
        ]);
        if (isset($saved['actual_sale']) && $saved['actual_sale'] && isset($saved['actual_purchase']) && $saved['actual_purchase']) {
            $out['actual'] = (float) $saved['actual_sale'] - (float) $saved['actual_purchase'] - (float) ($saved['actual_costs'] ?? 0);
        }
        return $out;
    }
}

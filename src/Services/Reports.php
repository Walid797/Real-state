<?php
declare(strict_types=1);

namespace App\Services;

use App\Db;

/** The report tables: shown on /reports, printed to PDF, exported to Excel. */
final class Reports
{
    /** @return array<int, array{key:string, title:string, note:string, header:array, rows:array, widths?:array, money?:array}> */
    public static function tables(): array
    {
        $t = [];

        $t[] = [
            'key' => 'county', 'title' => 'By county', 'note' => 'Homes for sale on file, grouped by county.',
            'header' => ['County', 'State', 'For sale', 'Avg price', 'Median $/sqft', 'Price cuts (30 days)', 'Score 70+', 'Avg est. profit'],
            'rows' => self::grouped("COALESCE(NULLIF(p.county, ''), 'Unknown')", 'p.state'),
            'widths' => [22, 6, 9, 12, 12, 16, 10, 14], 'money' => [3, 7],
        ];
        $t[] = [
            'key' => 'city', 'title' => 'By city', 'note' => 'The 40 cities with the most homes for sale on file.',
            'header' => ['City', 'State', 'For sale', 'Avg price', 'Median $/sqft', 'Price cuts (30 days)', 'Score 70+', 'Avg est. profit'],
            'rows' => array_slice(self::grouped("COALESCE(NULLIF(p.city, ''), 'Unknown')", 'p.state'), 0, 40),
            'widths' => [22, 6, 9, 12, 12, 16, 10, 14], 'money' => [3, 7],
        ];

        $type = Db::all(
            "SELECT p.property_type AS t, COUNT(*) AS n, ROUND(AVG(p.list_price)) AS avg_price,
                    ROUND(AVG(CASE WHEN p.sqft > 0 THEN p.list_price / p.sqft END)) AS ppsf,
                    ROUND(AVG(p.est_profit)) AS profit, ROUND(AVG(p.score)) AS score, ROUND(AVG(p.days_on_market)) AS dom
             FROM properties p WHERE p.status = 'for_sale' AND p.property_type IN ('Single Family','Townhouse')
             GROUP BY p.property_type ORDER BY n DESC"
        );
        $t[] = [
            'key' => 'type', 'title' => 'House vs townhouse', 'note' => 'Homes for sale on file.',
            'header' => ['Type', 'For sale', 'Avg price', 'Avg $/sqft', 'Avg est. profit', 'Avg score', 'Avg days on market'],
            'rows' => array_map(static fn ($r) => [$r['t'], (int) $r['n'], (int) $r['avg_price'], (int) $r['ppsf'], $r['profit'] === null ? null : (int) $r['profit'], $r['score'] === null ? null : (int) $r['score'], $r['dom'] === null ? null : (int) $r['dom']], $type),
            'widths' => [16, 9, 12, 10, 14, 9, 16], 'money' => [2, 4],
        ];

        $homes = static fn (string $order, string $where) => Db::all(
            "SELECT p.id, p.address, p.city, p.state, p.list_price, p.est_arv, p.est_reno, p.est_profit, p.est_roi, p.score, p.risk
             FROM properties p WHERE p.status = 'for_sale' AND $where ORDER BY $order LIMIT 25"
        );
        $homeRows = static fn (array $rows) => array_map(static fn ($r) => [$r['address'], $r['city'], (int) $r['list_price'], $r['est_arv'] === null ? null : (int) $r['est_arv'],
            $r['est_reno'] === null ? null : (int) $r['est_reno'], $r['est_profit'] === null ? null : (int) $r['est_profit'],
            $r['est_roi'] === null ? null : (float) $r['est_roi'], $r['score'] === null ? null : (int) $r['score'], $r['risk'] === null ? null : (int) $r['risk'], (int) $r['id']], $rows);
        $homeHeader = ['Address', 'City', 'Price', 'Est. ARV', 'Est. renovation', 'Est. profit', 'ROI %', 'Score', 'Risk'];
        $t[] = ['key' => 'profit', 'title' => 'Highest estimated profit', 'note' => 'Top 25 homes for sale.', 'header' => $homeHeader,
            'rows' => $homeRows($homes('p.est_profit DESC', 'p.est_profit IS NOT NULL')), 'widths' => [30, 16, 11, 11, 13, 11, 7, 7, 7], 'money' => [2, 3, 4, 5], 'link' => 9];
        $t[] = ['key' => 'risk', 'title' => 'Highest risk', 'note' => 'Top 25 homes for sale by risk score.', 'header' => $homeHeader,
            'rows' => $homeRows($homes('p.risk DESC', 'p.risk IS NOT NULL')), 'widths' => [30, 16, 11, 11, 13, 11, 7, 7, 7], 'money' => [2, 3, 4, 5], 'link' => 9];

        $stage = Db::all(
            "SELECT w.stage, COUNT(*) AS n, ROUND(SUM(p.est_profit)) AS profit FROM watchlist w JOIN properties p ON p.id = w.property_id GROUP BY w.stage"
        );
        $byStage = array_column($stage, null, 'stage');
        $t[] = [
            'key' => 'stage', 'title' => 'Favorites', 'note' => 'Every home the team has saved, by status.',
            'header' => ['Status', 'Homes', 'Sum of est. profit'],
            'rows' => array_map(static fn ($k, $l) => [$l, (int) ($byStage[$k]['n'] ?? 0), isset($byStage[$k]['profit']) ? (int) $byStage[$k]['profit'] : null], array_keys(Pipeline::STAGES), Pipeline::STAGES),
            'widths' => [20, 8, 16], 'money' => [2],
        ];

        $done = Db::all(
            "SELECT p.id, p.address, p.city, p.est_profit, d.actual_purchase, d.actual_costs, d.actual_sale, w.stage
             FROM deals d JOIN properties p ON p.id = d.property_id LEFT JOIN watchlist w ON w.property_id = p.id
             WHERE d.actual_purchase IS NOT NULL OR d.actual_sale IS NOT NULL ORDER BY d.updated_at DESC"
        );
        $t[] = [
            'key' => 'actual', 'title' => 'Predicted vs actual', 'note' => 'Projects with actual numbers entered on the home’s deal card.',
            'header' => ['Address', 'City', 'Status', 'Predicted profit', 'Bought for', 'All costs', 'Sold for', 'Actual profit', 'Difference'],
            'rows' => array_map(static function ($r) {
                $actual = ($r['actual_sale'] && $r['actual_purchase']) ? (int) $r['actual_sale'] - (int) $r['actual_purchase'] - (int) $r['actual_costs'] : null;
                return [$r['address'], $r['city'], Pipeline::STAGES[$r['stage'] ?? ''] ?? '', $r['est_profit'] === null ? null : (int) $r['est_profit'],
                    $r['actual_purchase'] === null ? null : (int) $r['actual_purchase'], $r['actual_costs'] === null ? null : (int) $r['actual_costs'],
                    $r['actual_sale'] === null ? null : (int) $r['actual_sale'], $actual, $actual !== null && $r['est_profit'] !== null ? $actual - (int) $r['est_profit'] : null, (int) $r['id']];
            }, $done),
            'widths' => [30, 16, 12, 14, 12, 12, 12, 13, 12], 'money' => [3, 4, 5, 6, 7, 8], 'link' => 9,
        ];
        return $t;
    }

    private static function grouped(string $nameExpr, string $stateExpr): array
    {
        $since = gmdate('Y-m-d', time() - 30 * 86400);
        $rows = Db::all(
            "SELECT $nameExpr AS name, $stateExpr AS st, COUNT(*) AS n, ROUND(AVG(p.list_price)) AS avg_price,
                    SUM(p.score >= 70) AS good, ROUND(AVG(p.est_profit)) AS profit,
                    SUM(EXISTS (SELECT 1 FROM price_events e WHERE e.property_id = p.id AND e.event = 'price_change' AND e.event_date >= ?)) AS cuts
             FROM properties p WHERE p.status = 'for_sale' AND p.list_price > 0
             GROUP BY name, st ORDER BY n DESC LIMIT 200",
            [$since]
        );
        $out = [];
        foreach ($rows as $r) {
            $vals = array_map('floatval', array_column(Db::all(
                "SELECT p.list_price / p.sqft AS v FROM properties p WHERE p.status = 'for_sale' AND p.sqft > 0 AND $nameExpr = ? AND $stateExpr = ?",
                [$r['name'], $r['st']]
            ), 'v'));
            $out[] = [$r['name'], $r['st'], (int) $r['n'], (int) $r['avg_price'], $vals ? (int) round(median($vals)) : null, (int) $r['cuts'], (int) $r['good'], $r['profit'] === null ? null : (int) $r['profit']];
        }
        return $out;
    }
}

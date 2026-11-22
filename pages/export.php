<?php
declare(strict_types=1);

use App\Address;
use App\Export\Xlsx;
use App\PropertyRepo;
use App\Services\Condition;
use App\Services\Pipeline;
use App\Services\Reports;
use App\Services\Search;

/*
 * /export.xlsx?…search…   the homes of a search, as Excel
 * /export.csv?…search…    same, as CSV
 * /export.xlsx?report=1   every report table, one sheet each
 */

$fmt = $params['fmt'] ?? 'csv';
$site = App\Address::slug((string) App\Settings::get('site_name', 'Plat'), '-', true);

if (isset($_GET['report'])) {
    $x = new Xlsx();
    foreach (Reports::tables() as $t) {
        $rows = isset($t['link']) ? array_map(static fn ($r) => array_slice($r, 0, $t['link']), $t['rows']) : $t['rows'];
        $x->addSheet($t['title'], $t['header'], $rows, $t['widths'] ?? []);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $x->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $site . '-report-' . gmdate('Y-m-d') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    end_buffers();
    readfile($tmp);
    unlink($tmp);
    exit;
}

$f = Search::fromRequest($_GET);
$loc = Search::location($f);
if (!$loc || $loc['type'] === 'address') {
    redirect('/search');
}
$res = Search::run($f, ['fetch' => false, 'limit' => 2000]);
$loc = $res['loc'];
$rows = $res['rows'];
$sold = $f['mode'] === 'sold';

$header = ['Address', 'City', 'County', 'State', 'ZIP', $sold ? 'Sale price' : 'List price', $sold ? 'Sale date' : 'Listed', 'Price per sqft',
    'Beds', 'Baths', 'Sqft', 'Lot sqft', 'Year built', 'Type', 'HOA per month', 'Garage', 'Basement', 'Condition', 'Flags',
    'Opportunity score', 'Risk', 'Est. ARV', 'Est. renovation', 'Est. net profit', 'Est. ROI %', 'Team status', 'Handled by', 'Original listing', 'Link'];
$data = [];
foreach ($rows as $r) {
    $price = $sold ? $r['last_sale_price'] : $r['list_price'];
    $pp = ppsf($price, $r['sqft']);
    $num = static fn ($v) => $v === null || $v === '' ? null : (str_contains((string) $v, '.') ? (float) $v : (int) $v);
    $data[] = [
        $r['address'], $r['city'], $r['county'], $r['state'], $r['zip'], $num($price), $sold ? $r['last_sale_date'] : $r['listed_date'],
        $pp ? (int) round($pp) : null, $num($r['beds']), $num($r['baths']), $num($r['sqft']), $num($r['lot_sqft']), $num($r['year_built']),
        $r['property_type'], $num($r['hoa_fee']), $num($r['garage_spaces']), $r['has_basement'] === null ? null : ($r['has_basement'] ? 'Yes' : 'No'),
        Condition::OVERALL[$r['cond_overall'] ?? 'not_disclosed'] ?? null,
        implode(', ', array_map(static fn ($fl) => Condition::FLAGS[$fl] ?? $fl, array_filter(explode(',', (string) $r['flags'])))),
        $num($r['score']), $num($r['risk']), $num($r['est_arv']), $num($r['est_reno']), $num($r['est_profit']), $num($r['est_roi']),
        $r['watch_stage'] ? Pipeline::STAGES[$r['watch_stage']] ?? $r['watch_stage'] : null, $r['owner_name'],
        $r['source_url'], abs_url('/property/' . (int) $r['id']),
    ];
}
$name = Address::slug($loc['label'], '-', true) . ($sold ? '-sold' : '-for-sale') . '-' . gmdate('Y-m-d');

if ($fmt === 'xlsx') {
    $x = new Xlsx();
    $x->addSheet($sold ? 'Sales' : 'For sale', $header, $data, [34, 16, 14, 6, 8, 12, 11, 9, 6, 6, 8, 9, 8, 14, 9, 7, 9, 20, 18, 9, 7, 11, 11, 12, 9, 16, 14, 30, 34]);
    $x->addSheet('Search', ['Setting', 'Value'], [['Area', $loc['label']], ['Filters', Search::describe($f)], ['Exported', gmdate('Y-m-d H:i') . ' UTC'],
        ['Note', 'ARV, renovation, profit and scores are estimates, not appraisals or inspections.']], [16, 80]);
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $x->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $name . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    end_buffers();
    readfile($tmp);
    unlink($tmp);
    exit;
}

// CSV: spreadsheet apps run cells that start with = + - @ as formulas; neutralise them.
$safe = static function (mixed $v): string {
    $s = (string) ($v ?? '');
    return ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false && !is_numeric($s)) ? "'" . $s : $s;
};
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '.csv"');
end_buffers();
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $header, ',', '"', '');
foreach ($data as $row) {
    fputcsv($out, array_map($safe, $row), ',', '"', '');
}
fclose($out);

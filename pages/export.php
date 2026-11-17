<?php
declare(strict_types=1);

use App\Address;
use App\PropertyRepo;
use App\Services\Search;

$f = Search::fromRequest($_GET);
$loc = Address::parseLocation($f['q']);
if (!$loc || $loc['type'] === 'address') {
    redirect('/');
}
$rows = PropertyRepo::search($f + ['loc' => $loc, 'limit' => 1000])['rows'];
$sold = $f['mode'] === 'sold';

// Spreadsheet apps run cells that start with = + - @ as formulas; neutralise them.
$safe = static function (mixed $v): string {
    $s = (string) ($v ?? '');
    return ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false && !is_numeric($s)) ? "'" . $s : $s;
};

$name = Address::slug($loc['label'], '-', true) . ($sold ? '-sold' : '-for-sale') . '-' . gmdate('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Address', 'City', 'State', 'ZIP', $sold ? 'Sale price' : 'List price', $sold ? 'Sale date' : 'Listed', 'Price per sqft',
    'Beds', 'Baths', 'Sqft', 'Lot sqft', 'Year built', 'Type', 'HOA per month', 'Latitude', 'Longitude', 'Link'], ',', '"', '');
foreach ($rows as $r) {
    $price = $sold ? $r['last_sale_price'] : $r['list_price'];
    $pp = ppsf($price, $r['sqft']);
    fputcsv($out, array_map($safe, [
        $r['address'], $r['city'], $r['state'], $r['zip'], $price, $sold ? $r['last_sale_date'] : $r['listed_date'],
        $pp ? round($pp) : '', $r['beds'], $r['baths'], $r['sqft'], $r['lot_sqft'], $r['year_built'], $r['property_type'],
        $r['hoa_fee'], $r['lat'], $r['lng'], abs_url('/property/' . (int) $r['id']),
    ]), ',', '"', '');
}
fclose($out);

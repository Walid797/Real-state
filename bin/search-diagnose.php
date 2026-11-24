<?php
declare(strict_types=1);

/*
 * Read-only search check: how queries are understood and what the local data holds.
 * Never calls a paid provider (locations are read with fetch = false).
 *   php bin/search-diagnose.php
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Db;
use App\PropertyRepo;
use App\Services\Search;

echo "== For-sale data completeness by source\n";
foreach (Db::all("SELECT source, COUNT(*) n, SUM(beds IS NULL) no_beds, SUM(baths IS NULL) no_baths, SUM(sqft IS NULL OR sqft = 0) no_sqft,
    SUM(property_type IS NULL OR property_type = '') no_type, SUM(county IS NULL OR county = '') no_county, SUM(zip IS NULL OR zip = '') no_zip,
    SUM(lat IS NULL) no_geo FROM properties WHERE status IN ('for_sale','pending') AND list_price > 0 GROUP BY source") as $r) {
    echo json_encode($r), "\n";
}
echo "== Property types (for sale)\n";
foreach (Db::all("SELECT COALESCE(property_type,'(none)') t, COUNT(*) n FROM properties WHERE status IN ('for_sale','pending') GROUP BY t ORDER BY n DESC") as $r) {
    echo $r['t'], ': ', $r['n'], "\n";
}
echo "== Counties (for sale, top 25)\n";
foreach (Db::all("SELECT state, COALESCE(county,'(none)') c, COUNT(*) n FROM properties WHERE status IN ('for_sale','pending') GROUP BY state, c ORDER BY n DESC LIMIT 25") as $r) {
    echo $r['state'], ' | ', $r['c'], ': ', $r['n'], "\n";
}
echo "== Cities (for sale, top 25)\n";
foreach (Db::all("SELECT state, city, COUNT(*) n FROM properties WHERE status IN ('for_sale','pending') GROUP BY state, city ORDER BY n DESC LIMIT 25") as $r) {
    echo $r['state'], ' | ', $r['city'], ': ', $r['n'], "\n";
}
echo "== Beds / baths values (for sale)\n";
echo json_encode(Db::all("SELECT beds, COUNT(*) n FROM properties WHERE status IN ('for_sale','pending') GROUP BY beds ORDER BY beds")), "\n";
echo json_encode(Db::all("SELECT baths, COUNT(*) n FROM properties WHERE status IN ('for_sale','pending') GROUP BY baths ORDER BY baths")), "\n";

echo "== Queries\n";
$queries = [
    'Fairfax County', 'fairfax county va', 'Fairfax County, Loudoun County', 'Fairfax County and Arlington County', 'Fairfax', 'Falls Church',
    'falls church, va', 'Falls Church, Arlington, Alexandria', 'Fairfax City', 'City of Fairfax', '22041', '22041 22042', '22041, 22042, 22044',
    'Seminary Rd', 'Seminary Road Falls Church', 'Columbia Pike, Arlington', 'Leesburg Pike Falls Church VA',
    '5501 Seminary Rd', '5501 Seminary Rd, Falls Church, VA 22041', '5501 seminary road falls church va', '5501 Seminary Rd Apt 1703S Falls Church VA 22041',
    'Skyline Square', 'Skyline Square, Falls Church, VA', 'Prince William County; Manassas', 'Virginia', 'Montgomery County, MD', 'Miami-Dade County, FL', 'Lee County FL',
    'Fairfax County 3 bed under 500k', 'townhouse in falls church', '3 bed townhouse in Falls Church under 500k', '۳ خوابه زیر ۵۰۰ هزار در Fairfax County',
    'condos in Arlington price reduced', 'houses in Vienna with basement', 'Fairfax County; 22041; Seminary Rd',
];
$filters = ['none' => [], 'beds3' => ['beds_min' => 3], 'baths2' => ['baths_min' => 2], 'p500' => ['price_max' => 500000], 'all' => ['beds_min' => 3, 'baths_min' => 2, 'price_max' => 700000]];
foreach ($queries as $q) {
    $t0 = microtime(true);
    $typed = Search::fromRequest(['q' => $q]);
    try {
        $loc = Search::location($typed, true); // free map services only; no listing provider is called
    } catch (Throwable $e) {
        echo str_pad($q, 48), ' ERROR ', $e->getMessage(), "\n";
        continue;
    }
    if (!$loc) {
        echo str_pad($q, 48), " → not understood\n";
        continue;
    }
    $counts = [];
    if ($loc['type'] !== 'address') {
        foreach ($filters as $name => $extra) {
            $f = Search::fromRequest(['q' => $q] + $extra);
            $f = $extra ? $f : $typed;
            $counts[] = $name . '=' . PropertyRepo::search($f + ['loc' => $loc, 'limit' => 1])['total'];
        }
    } else {
        $counts[] = 'homes on file there: ' . count($loc['ids'] ?? []);
    }
    $pulls = array_map(static fn ($n) => $n['key'] . (isset($n['radius']) ? ' (' . $n['radius'] . ' mi)' : ''), Search::plan($loc, $typed)['needs']);
    printf("%s → %s [%s] %s%s · pulls: %s (%.2fs)\n", str_pad($q, 48), $loc['type'], $loc['label'] ?? '', $typed['understood'] ? '{' . implode(', ', $typed['understood']) . '} ' : '',
        implode(' ', $counts), implode(', ', $pulls) ?: '-', microtime(true) - $t0);
}

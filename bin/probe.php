<?php
declare(strict_types=1);

/*
 * Command-line only: prints what the county open-data services and this host offer, so a new
 * data source can be built against what the server really sees. Reads public pages; writes nothing.
 *   php bin/probe.php county
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

$get = static function (string $url): ?array {
    $r = App\Http::get($url, [], 25);
    echo '# ' . $url . ' -> ' . ($r['status'] ?: 'no answer ' . ($r['error'] ?? '')) . ' ' . $r['ms'] . "ms\n";
    $d = json_decode((string) ($r['body'] ?? ''), true);
    return is_array($d) ? $d : null;
};

echo 'php ' . PHP_VERSION . ' imap=' . (extension_loaded('imap') ? 'yes' : 'no') . ' openssl=' . (extension_loaded('openssl') ? 'yes' : 'no') . ' zip=' . (extension_loaded('zip') ? 'yes' : 'no') . "\n";

$base = 'https://services1.arcgis.com/ioennV6PpG5Xodq0/ArcGIS/rest/services';
$q = static fn (string $path, array $p) => $get("$base/$path/query?" . http_build_query($p + ['f' => 'json']));
$show = static function (?array $d, int $n = 3): void {
    foreach (array_slice((array) ($d['features'] ?? []), 0, $n) as $f) {
        echo '   ' . json_encode($f) . "\n";
    }
    if (isset($d['count'])) { echo '   count ' . $d['count'] . "\n"; }
    if (isset($d['error'])) { echo '   error ' . json_encode($d['error']) . "\n"; }
};
$show($q('OpenData_A5/FeatureServer/1', ['where' => '1=1', 'outFields' => 'SALEVAL_DESC', 'returnDistinctValues' => 'true', 'returnGeometry' => 'false']), 40);
$since = gmdate('Y-m-d', time() - 365 * 86400);
$show($q('OpenData_A5/FeatureServer/1', ['where' => "SALEDT >= DATE '$since' AND PRICE > 0", 'returnCountOnly' => 'true']));
$show($q('OpenData_A5/FeatureServer/1', ['where' => "SALEDT >= DATE '$since' AND PRICE > 0", 'outFields' => '*', 'orderByFields' => 'SALEDT DESC', 'resultRecordCount' => 3]));
$show($q('OpenData_A7/FeatureServer/1', ['where' => "ADRADD IS NOT NULL", 'outFields' => 'PARID,ADRNO,ADRADD,ADRDIR,ADRSTR,ADRSUF,ADRSUF2,CITYNAME,ZIP1', 'resultRecordCount' => 4]));
$show($q('OpenData_A7/FeatureServer/1', ['where' => "ADRDIR IS NOT NULL", 'outFields' => 'PARID,ADRNO,ADRADD,ADRDIR,ADRSTR,ADRSUF,ADRSUF2,CITYNAME,ZIP1', 'resultRecordCount' => 2]));
$show($q('OpenData_A7/FeatureServer/1', ['where' => "ZIP1 = '22030'", 'returnCountOnly' => 'true']));
$show($q('OpenData_A6/FeatureServer/2', ['where' => "PARID = '0604 16F 0017'", 'outFields' => '*']));
$show($q('OpenData_A6/FeatureServer/3', ['where' => "PARID = '0604 16F 0017'", 'outFields' => '*']));
$show($q('OpenData_A6/FeatureServer/1', ['where' => "PARID = '0604 16F 0017'", 'outFields' => '*']));
$show($q('OpenData_A5/FeatureServer/1', ['where' => "PARID = '0604 16F 0017'", 'outFields' => '*', 'orderByFields' => 'SALEDT DESC']), 6);
foreach (['Parcels', 'Address_Points', 'Tax_Administration_Real_Estate_Parcels_Data'] as $svc) {
    $l = $get("$base/$svc/FeatureServer/layers?f=json");
    foreach (array_merge((array) ($l['layers'] ?? []), (array) ($l['tables'] ?? [])) as $t) {
        echo "== $svc/{$t['id']} {$t['name']} geom=" . ($t['geometryType'] ?? '-') . ' centroid=' . json_encode($t['supportsReturningGeometryCentroid'] ?? null) . "\n   ";
        echo implode(' ', array_map(static fn ($f) => $f['name'], (array) ($t['fields'] ?? []))) . "\n";
    }
}
$show($q('Parcels/FeatureServer/0', ['where' => "PIN = '0604 16F 0017'", 'outFields' => '*', 'outSR' => '4326', 'returnGeometry' => 'false', 'returnCentroid' => 'true']));
$show($q('Address_Points/FeatureServer/0', ['where' => "1=1", 'outFields' => '*', 'outSR' => '4326', 'resultRecordCount' => 2]));

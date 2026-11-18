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
$list = $get($base . '?f=json');
foreach ((array) ($list['services'] ?? []) as $s) {
    if (preg_match('/open|tax|parcel|sale|dwell|assess|address/i', (string) $s['name'])) {
        echo '  service ' . $s['name'] . ' ' . $s['type'] . "\n";
    }
}
foreach (['OpenData_A5', 'OpenData_A6', 'OpenData_A7', 'OpenData_A8', 'OpenData_A4', 'OpenData_A3'] as $svc) {
    $l = $get("$base/$svc/FeatureServer/layers?f=json");
    if (!$l) {
        continue;
    }
    foreach (array_merge((array) ($l['layers'] ?? []), (array) ($l['tables'] ?? [])) as $t) {
        $edit = $t['editingInfo']['dataLastEditDate'] ?? $t['editingInfo']['lastEditDate'] ?? null;
        echo "== $svc/{$t['id']} {$t['name']} max={$t['maxRecordCount']} edited=" . ($edit ? gmdate('Y-m-d', (int) ($edit / 1000)) : '?') . "\n   ";
        echo implode(' ', array_map(static fn ($f) => $f['name'] . ':' . str_replace('esriFieldType', '', $f['type']), (array) ($t['fields'] ?? []))) . "\n";
        if (preg_match('/dwell|sale|parcel|legal|address/i', (string) $t['name']) && !preg_match('/addition|commercial/i', (string) $t['name'])) {
            $q = $get("$base/$svc/FeatureServer/{$t['id']}/query?where=1%3D1&outFields=*&returnGeometry=false&resultRecordCount=2&f=json");
            foreach ((array) ($q['features'] ?? []) as $f) {
                echo '   sample ' . json_encode($f['attributes']) . "\n";
            }
            $c = $get("$base/$svc/FeatureServer/{$t['id']}/query?where=1%3D1&returnCountOnly=true&f=json");
            echo '   rows ' . ($c['count'] ?? '?') . "\n";
        }
    }
}

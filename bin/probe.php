<?php
declare(strict_types=1);

/*
 * Command-line only, read-only: prints what public open-data services return to this server,
 * so a county source can be configured against what the server really sees.
 *   php bin/probe.php            every URL in bin/probe-urls.txt
 * An ArcGIS directory (…/rest/services or a folder) lists its services; a service lists its layers
 * with their fields and one sample row; any other URL prints the start of its body.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

$get = static function (string $url): array {
    $r = App\Http::get($url, [], 25);
    return [$r['status'], (string) ($r['body'] ?? ''), $r['error'] ?? null, $r['ms']];
};
$lines = file(__DIR__ . '/probe-urls.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
foreach ($lines as $url) {
    $url = trim($url);
    if ($url === '' || $url[0] === '#') {
        continue;
    }
    $namesOnly = str_starts_with($url, 'names ');
    $url = $namesOnly ? substr($url, 6) : $url;
    $isArc = str_contains($url, '/rest/services') && !str_contains($url, '/query');
    $u = $isArc ? $url . (str_contains($url, '?') ? '&' : '?') . 'f=json' : $url;
    [$st, $body, $err, $ms] = $get($u);
    echo "\n### $url -> " . ($st ?: 'no answer ' . $err) . " {$ms}ms\n";
    $d = json_decode($body, true);
    if (!$isArc || !is_array($d)) {
        echo '   ' . mb_substr(preg_replace('/\s+/', ' ', $body) ?? '', 0, 2500) . "\n";
        continue;
    }
    if (isset($d['services']) || isset($d['folders'])) {
        echo '   folders: ' . implode(', ', (array) ($d['folders'] ?? [])) . "\n";
        foreach ((array) ($d['services'] ?? []) as $s) {
            echo '   service ' . $s['name'] . ' ' . $s['type'] . "\n";
        }
        continue;
    }
    $layers = array_merge((array) ($d['layers'] ?? []), (array) ($d['tables'] ?? []));
    if ($namesOnly) {
        echo '   ' . implode(' | ', array_map(static fn ($t) => $t['id'] . ' ' . $t['name'], $layers)) . "\n";
        continue;
    }
    if (isset($d['fields'])) {
        $layers = [$d + ['id' => '']];
        $url = preg_replace('#/\d+$#', '', $url);
    }
    foreach ($layers as $t) {
        $lu = $url . '/' . $t['id'];
        $info = isset($t['fields']) ? $t : (json_decode($get($lu . '?f=json')[1], true) ?: []);
        echo "== {$t['id']} {$t['name']} geom=" . ($info['geometryType'] ?? '-') . ' max=' . ($info['maxRecordCount'] ?? '?') . "\n   ";
        echo implode(' ', array_map(static fn ($f) => $f['name'], (array) ($info['fields'] ?? []))) . "\n";
        if (!empty($info['fields']) && count($layers) <= 25) {
            $q = json_decode($get($lu . '/query?where=1%3D1&outFields=*&returnGeometry=false&resultRecordCount=1&f=json')[1], true);
            $row = $q['features'][0]['attributes'] ?? ($q['error']['message'] ?? null);
            echo '   sample ' . mb_substr((string) json_encode($row), 0, 1500) . "\n";
        }
    }
}

<?php
declare(strict_types=1);

use App\Geo;
use App\PropertyRepo;
use App\Providers\Overpass;

/*
 * Places near a home (OpenStreetMap), for the property page.
 *   GET  → the kept tally, or the query for the browser to run itself
 *   POST → the browser hands back its tally to keep (CSRF-checked by the Kernel, validated here)
 */

$p = PropertyRepo::find((int) $params['id']);
if (!$p || !Geo::valid($p['lat'], $p['lng'])) {
    json_out(['places' => null], 404);
}
$lat = (float) $p['lat'];
$lng = (float) $p['lng'];
session_write_close();

$shape = static function (array $places): array {
    $out = [];
    foreach (Overpass::KINDS as $k => $label) {
        $out[] = ['label' => $label, 'radius' => Overpass::RADIUS[$k] ?? 1] + $places[$k];
    }
    return $out;
};

if (is_post()) {
    $body = json_decode((string) file_get_contents('php://input', false, null, 0, 20000), true);
    json_out(['kept' => Overpass::store($lat, $lng, $body['places'] ?? null)]);
}

$kept = Overpass::cached($lat, $lng);
if ($kept !== null) {
    json_out(['places' => $shape($kept)]);
}
json_out([
    'places' => null,
    'lookup' => [
        'lat' => $lat, 'lng' => $lng, 'query' => Overpass::query($lat, $lng), 'servers' => Overpass::URLS,
        'kinds' => Overpass::KINDS, 'radius' => Overpass::RADIUS,
    ],
]);

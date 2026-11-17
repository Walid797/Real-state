<?php
declare(strict_types=1);

use App\Geo;
use App\PropertyRepo;
use App\Providers\Overpass;

/* Places within a mile of a home (OpenStreetMap). JSON for the property page. */

$p = PropertyRepo::find((int) $params['id']);
if (!$p || !Geo::valid($p['lat'], $p['lng'])) {
    json_out(['places' => null], 404);
}
session_write_close();
$places = Overpass::around((float) $p['lat'], (float) $p['lng']);
$out = null;
if ($places !== null) {
    $out = [];
    foreach (Overpass::KINDS as $k => $label) {
        $out[] = ['label' => $label, 'radius' => $k === 'highway' ? 3 : 1] + $places[$k];
    }
}
json_out(['places' => $out]);

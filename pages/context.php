<?php
declare(strict_types=1);

use App\Db;
use App\Geo;
use App\PropertyRepo;
use App\Providers\Census;
use App\Providers\Fema;

/* Flood zone (FEMA) and neighbourhood figures (Census) for one home. Both are free and cached for months. */

$p = PropertyRepo::find((int) $params['id']);
if (!$p || !Geo::valid($p['lat'], $p['lng'])) {
    json_out(['flood' => null, 'census' => null], 404);
}
session_write_close(); // these lookups can take a few seconds; don't hold the session lock meanwhile

$lat = (float) $p['lat'];
$lng = (float) $p['lng'];

$flood = Fema::zoneAt($lat, $lng);

$census = null;
$tract = Census::tractAt($lat, $lng);
if ($tract) {
    $census = Census::tractStats($tract);
    if ($census) {
        $census['tract_name'] = $tract['name'];
    }
    if (!$p['county'] && !empty($tract['county_name'])) {
        Db::run('UPDATE properties SET county = ? WHERE id = ?', [mb_substr($tract['county_name'], 0, 100), $p['id']]);
    }
}

json_out(['flood' => $flood, 'census' => $census]);

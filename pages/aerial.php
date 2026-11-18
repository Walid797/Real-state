<?php
declare(strict_types=1);

use App\Db;
use App\Geo;
use App\Quota;
use App\Settings;

/*
 * POST /api/aerial/{id} — hands the browser what it needs to open a 3D view of one home,
 * and counts the view against the monthly allowance (the free Cesium plan meters each
 * opening of Google's 3D tiles). POST + CSRF, so another site cannot spend views.
 */

$p = Db::one('SELECT id, address, city, state, lat, lng FROM properties WHERE id = ?', [(int) $params['id']]);
if (!$p || !Geo::valid($p['lat'], $p['lng'])) {
    json_out(['error' => 'This home has no map position.'], 404);
}
if (!Settings::has('cesium_key')) {
    json_out(['error' => 'The 3D view is not set up yet. An admin can turn it on under Settings → 3D view.'], 409);
}
if (!Quota::can('cesium')) {
    json_out(['error' => 'This month’s free 3D views are used up. They reset in ' . plural(Quota::resetsIn(), 'day') . '.'], 429);
}
Quota::spend('cesium');
Quota::log('cesium', '/3d-view/' . (int) $p['id'], 200, 0, null, 'manual');

json_out([
    'token' => (string) Settings::get('cesium_key'),
    'lat' => (float) $p['lat'],
    'lng' => (float) $p['lng'],
    'label' => $p['address'],
    'left' => Quota::left('cesium'),
]);

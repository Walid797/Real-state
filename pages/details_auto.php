<?php
declare(strict_types=1);

use App\PropertyRepo;
use App\Services\ListingDetails;

/*
 * POST /api/details/{id} — the property page asks for this once, right after it opens, when the
 * home has no listing details yet. The site then fetches them itself (within the monthly cap).
 */

$p = PropertyRepo::find((int) $params['id']);
if (!$p) {
    json_out(['status' => 'none', 'message' => 'Not found.'], 404);
}
session_write_close();
@set_time_limit(60);
if (!ListingDetails::wanted($p)) {
    json_out(['status' => 'skipped', 'message' => '']);
}
json_out(ListingDetails::fetch($p, 'auto'));

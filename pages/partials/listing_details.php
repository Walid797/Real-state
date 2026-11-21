<?php
/** Property details from the listing (Zillow data), fetched by the site itself. Expects $p, $d, $id. */
use App\Services\ListingDetails;

$extraLabels = [
    'listing_status' => 'Listing status', 'coming_soon' => 'Coming soon', 'style' => 'Style', 'levels' => 'Levels', 'fireplaces' => 'Fireplaces',
    'hoa_includes' => 'HOA fee includes', 'subdivision' => 'Subdivision', 'community' => 'Community', 'pets' => 'Pets',
    'construction' => 'Construction', 'flooring' => 'Flooring', 'laundry' => 'Laundry', 'lot_features' => 'Lot', 'water_heater' => 'Water heater',
    'parcel_id' => 'Parcel number',
];
$extra = (array) ($d['listing_extra_zillow'] ?? []);
$extra['parcel_id'] = $d['parcel_id'] ?? null;
$kv = [];
foreach ($extraLabels as $k => $label) {
    if (!empty($extra[$k])) {
        $kv[$label] = $k === 'levels' && is_numeric($extra[$k]) ? $extra[$k] . ' ' . ((int) $extra[$k] === 1 ? 'level' : 'levels') : $extra[$k];
    }
}
if (!empty($d['appliances'])) {
    $kv['Appliances'] = implode(', ', (array) $d['appliances']);
}
foreach (['heating' => 'Heating', 'cooling' => 'Cooling', 'parking' => 'Parking', 'interior' => 'Interior'] as $k => $label) {
    if (!empty($d[$k])) {
        $kv[$label] = implode(', ', (array) $d[$k]);
    }
}
$autoNow = ListingDetails::wanted($p);
?>
<section class="section" id="listing-details"<?= $autoNow ? ' data-auto-details="/api/details/' . (int) $id . '" data-csrf="' . e(App\Auth::csrfToken()) . '"' : '' ?>>
  <div class="section-head"><h2><?= te('Property details') ?></h2><?php if (!empty($d['zillow'])): ?><span class="aside"><?= te('from the listing, via Zillow data') ?></span><?php endif; ?></div>
  <?php if ($kv): ?>
  <dl class="kv"><?php foreach ($kv as $k => $v): ?><div><dt><?= te($k) ?></dt><dd><?= e($v) ?></dd></div><?php endforeach; ?></dl>
  <?php elseif ($autoNow): ?>
  <p class="blank auto-details-msg"><span class="spin" aria-hidden="true"></span><?= te('Fetching the full listing — taxes, HOA, features and photos…') ?></p>
  <?php elseif (!empty($d['zillow'])): ?>
  <p class="blank"><?= te('The listing on Zillow lists no further details.') ?></p>
  <?php elseif (!App\Providers\OpenWebNinja::enabled()): ?>
  <p class="blank"><?= te('Full listing details (taxes, HOA, features, photos) come from Zillow data.') ?> <?= App\Auth::can('admin') ? '<a href="/settings#sources">' . te('Add the free OpenWeb Ninja key') . '</a> ' . te('and they are fetched by themselves when a home is opened.') : te('An admin can turn this on under Settings.') ?></p>
  <?php else: ?>
  <p class="blank"><?= match (true) {
      !empty($d['zillow_error']) => 'The last try to fetch the listing didn’t get through (' . e(ago($d['zillow_tried_at'])) . '). The site tries again tomorrow, or use “Read the listing” now.',
      !empty($d['zillow_tried_at']) => 'Zillow had no listing for this address when last checked (' . e(ago($d['zillow_tried_at'])) . '). The site checks again next month.',
      default => 'This month’s automatic fetches are used up; use “Read the listing” to fetch this one by hand.',
  } ?></p>
  <?php endif; ?>
</section>

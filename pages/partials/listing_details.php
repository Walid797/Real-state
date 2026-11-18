<?php
/** Property details: facts pasted from the listing page, and Zillow's fact sheet. Expects $p, $d, $id, $canEdit. */
use App\Services\ListingText;

$extraLabels = [
    'listing_status' => 'Listing status', 'coming_soon' => 'Coming soon', 'style' => 'Style', 'levels' => 'Levels', 'fireplaces' => 'Fireplaces',
    'hoa_includes' => 'HOA fee includes', 'subdivision' => 'Subdivision', 'community' => 'Community', 'pets' => 'Pets',
    'construction' => 'Construction', 'flooring' => 'Flooring', 'laundry' => 'Laundry', 'lot_features' => 'Lot', 'water_heater' => 'Water heater',
    'parcel_id' => 'Parcel number',
];
$extra = array_merge((array) ($d['listing_extra_zillow'] ?? []), (array) ($d['listing_extra'] ?? []));
$kv = [];
foreach ($extraLabels as $k => $label) {
    if (!empty($extra[$k])) {
        $kv[$label] = $k === 'levels' && is_numeric($extra[$k]) ? $extra[$k] . ' ' . ((int) $extra[$k] === 1 ? 'level' : 'levels') : $extra[$k];
    }
}
if (!empty($d['appliances'])) {
    $kv['Appliances'] = implode(', ', (array) $d['appliances']);
}
$groups = (array) ($d['listing_facts'] ?? []);
$pastedAt = $d['listing_pasted_at'] ?? null;
?>
<section class="section" id="listing-details">
  <div class="section-head"><h2>Property details</h2><?php if ($pastedAt): ?><span class="aside">pasted <?= e(ago($pastedAt)) ?></span><?php elseif (!empty($d['zillow'])): ?><span class="aside">from Zillow data</span><?php endif; ?></div>
  <?php if ($kv): ?>
  <dl class="kv"><?php foreach ($kv as $k => $v): ?><div><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd></div><?php endforeach; ?></dl>
  <?php endif; ?>
  <?php if ($groups): ?>
  <div class="fact-groups">
    <?php foreach ($groups as $title => $lines): ?>
    <div class="fact-group"><h3><?= e($title) ?></h3><ul><?php foreach ($lines as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul></div>
    <?php endforeach; ?>
  </div>
  <?php elseif (!$kv): ?>
  <p class="blank">The automatic listing feed has only the basics. Paste the “Property details” block from the listing on Homes.com, Zillow, Realtor.com or Redfin — taxes, HOA, parking, heating, parcel number and the rest are filled in, free.</p>
  <?php endif; ?>
  <?php if ($canEdit): ?>
  <details class="paste-box"<?= $groups ? '' : ' open' ?>>
    <summary><?= $groups ? 'Paste newer details' : 'Paste listing details' ?></summary>
    <form method="post" action="/property/<?= (int) $id ?>/act">
      <?= csrf_field() ?><input type="hidden" name="do" value="paste">
      <label class="field"><span>Copied from the listing page</span>
        <textarea class="input" name="listing_text" rows="7" maxlength="<?= ListingText::MAX ?>" placeholder="Home Type&#10;Condominium&#10;Est. Annual Taxes&#10;$4,417&#10;…"></textarea>
        <small>On the listing, select everything from “Property details” down to the end of the facts, copy, and paste here. Nothing is fetched from those sites.</small></label>
      <div class="form-foot"><button class="btn btn-primary" type="submit"><?= icon('check', 15) ?>Read and save</button></div>
    </form>
  </details>
  <?php endif; ?>
</section>

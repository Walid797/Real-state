<?php
/** Fairfax County public record for one home. Expects $p, $d, $id. */
use App\Services\CountyRecords;

$cr = (array) ($d['county_record'] ?? []);
if (!$cr && !CountyRecords::covers($p)) {
    return;
}
$labels = [
    'style' => 'Style', 'condition' => 'Condition (county)', 'grade' => 'Build quality', 'year_remodeled' => 'Remodelled',
    'effective_year' => 'Effective year built', 'basement' => 'Basement', 'basement_type' => 'Basement access', 'rec_room_sqft' => 'Rec room',
    'fireplaces' => 'Fireplaces', 'heating' => 'Heating / cooling', 'roof' => 'Roof', 'exterior' => 'Exterior walls',
    'land_use' => 'Land use', 'zoning' => 'Zoning', 'water' => 'Water', 'sewer' => 'Sewer', 'gas' => 'Gas', 'exemption' => 'Tax exemption',
];
$kv = [];
foreach ($labels as $k => $label) {
    if (isset($cr[$k]) && $cr[$k] !== '') {
        $kv[$label] = $k === 'rec_room_sqft' ? number_format((int) $cr[$k]) . ' sq ft' : (string) $cr[$k];
    }
}
$sales = (array) ($cr['sales'] ?? []);
?>
<section class="section" id="county-record">
  <div class="section-head"><h2>County record</h2><span class="aside">Fairfax County Department of Tax Administration · free, official</span></div>
  <?php if (!$cr): ?>
    <p class="blank"><?= empty($d['county_tried_at'])
        ? 'This home looks like it is in Fairfax County; its record is looked up the next time the page opens.'
        : 'No Fairfax County parcel matched this address (checked ' . e(ago($d['county_tried_at'])) . '). It may be in Fairfax City, Falls Church or another county, or the listing spells the street differently.' ?></p>
  <?php else: ?>
    <?php if (!empty($cr['assessed_total'])): ?>
    <div class="kpis kpis-sm">
      <div class="kpi"><span>Assessed <?= e((string) ($cr['assessment_year'] ?? '')) ?></span><b><?= e(money($cr['assessed_total'])) ?></b>
        <small><?= !empty($cr['assessed_prior']) ? 'year before ' . e(money($cr['assessed_prior'])) : '&nbsp;' ?></small></div>
      <div class="kpi"><span>Land / building</span><b><?= e(money_short($cr['assessed_land'] ?? null)) ?> / <?= e(money_short($cr['assessed_building'] ?? null)) ?></b><small>&nbsp;</small></div>
      <?php if (!empty($cr['tax_rate']) && !empty($p['tax_annual'])): ?>
      <div class="kpi"><span>Real estate tax a year</span><b><?= e(money($p['tax_annual'])) ?></b>
        <small>$<?= e(rtrim(rtrim(number_format((float) $cr['tax_rate'], 4), '0'), '.')) ?> per $100 · special districts extra</small></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($kv): ?>
    <dl class="kv mt-m"><?php foreach ($kv as $k => $v): ?><div><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd></div><?php endforeach; ?></dl>
    <?php endif; ?>
    <?php if ($sales): ?>
    <div class="table-wrap mt-m">
      <table class="table sale-table">
        <thead><tr><th>Recorded</th><th class="r">Price</th><th>County’s note on the sale</th></tr></thead>
        <tbody>
        <?php foreach ($sales as $s): ?>
          <tr><td><?= e(fmt_date($s['date'])) ?></td><td class="r"><?= (int) $s['price'] > 1 ? e(money($s['price'])) : '—' ?></td><td class="soft"><?= e($s['type'] ?: '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <p class="section-note">Parcel <?= e($cr['parid']) ?> (search it on the county’s real estate assessment site to see the full card) · read <?= e(ago($cr['fetched_at'] ?? null)) ?>. Beds, baths and size from the county fill only what the listing left blank.</p>
  <?php endif; ?>
</section>

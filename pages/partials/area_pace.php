<?php
/** How fast homes move in this home's ZIP: flips from county sale histories, and days on market. Expects $p. */
use App\Services\AreaPace;

if (empty($p['zip'])) {
    return;
}
$pace = AreaPace::forZip((string) $p['zip']);
$fl = $pace['flips'];
$mk = $pace['market'];
if ($fl['count'] === 0 && $mk['active'] === 0 && $mk['sold'] === 0) {
    return;
}
?>
<section class="section" id="pace">
  <div class="section-head"><h2><?= te('How fast homes sell here') ?></h2><span class="aside"><?= te('ZIP %s', e((string) $p['zip'])) ?></span></div>
  <?php if ($fl['count'] > 0): ?>
  <div class="kpis kpis-sm">
    <div class="kpi"><span><?= te('Flips: purchase to resale') ?></span><b><?= te('%s months', e(trim_num((float) $fl['months']))) ?></b>
      <small><?= te('middle half %1$s–%2$s months', e(trim_num((float) $fl['months_p25'])), e(trim_num((float) $fl['months_p75']))) ?></small></div>
    <div class="kpi"><span><?= te('Flip resale over purchase') ?></span><b>+<?= (int) $fl['gain_pct'] ?>%</b><small><?= te('median resale %s', e(money($fl['resale']))) ?></small></div>
    <div class="kpi"><span><?= te('Flips found') ?></span><b><?= (int) $fl['count'] ?></b><small><?= te('%d in the last 12 months', (int) $fl['last_year']) ?></small></div>
  </div>
  <p class="section-note"><?= te('A flip here is a home bought and sold again within 18 months for at least 20% more, from the county’s recorded sales of the last 3 years. The time includes renovation, listing and closing. Gross gain is before renovation and selling costs.') ?>
    <?php if ($fl['count'] < AreaPace::MIN_FLIPS): ?><?= te('Too few flips to set the calculator’s months held — it keeps the default.') ?><?php endif; ?></p>
  <?php if ($fl['examples']): ?>
  <div class="table-wrap mt-m">
    <table class="table sale-table">
      <thead><tr><th><?= te('Home') ?></th><th><?= te('Bought') ?></th><th><?= te('Resold') ?></th><th class="r"><?= te('Months') ?></th><th class="r"><?= te('Gain') ?></th></tr></thead>
      <tbody>
      <?php foreach ($fl['examples'] as $x): ?>
        <tr><td><a href="/property/<?= (int) $x['id'] ?>"><?= e($x['address']) ?></a></td>
          <td><?= e(fmt_date($x['bought'], 'M Y')) ?> · <?= e(money_short($x['buy'])) ?></td>
          <td><?= e(fmt_date($x['sold'], 'M Y')) ?> · <?= e(money_short($x['sell'])) ?></td>
          <td class="r"><?= e(trim_num((float) $x['months'])) ?></td>
          <td class="r">+<?= (int) round(($x['sell'] - $x['buy']) / max(1, $x['buy']) * 100) ?>%</td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php else: ?>
  <p class="blank"><?= te('No flips on file for this ZIP yet. They appear once the county’s recorded sales are brought in (free where the county publishes them).') ?></p>
  <?php endif; ?>
  <?php if ($mk['active'] || $mk['sold']): ?>
  <dl class="kv mt-m">
    <?php if ($mk['active']): ?><div><dt><?= te('Days on market, homes for sale now') ?></dt><dd><?= te('%1$d days (median of %2$d)', (int) $mk['active_days'], (int) $mk['active']) ?></dd></div><?php endif; ?>
    <?php if ($mk['sold']): ?><div><dt><?= te('Listed to off the market, last 12 months') ?></dt><dd><?= te('%1$d days (median of %2$d)', (int) $mk['sold_days'], (int) $mk['sold']) ?></dd></div><?php endif; ?>
    <?php if ($mk['renovated'] >= 3): ?><div><dt><?= te('…of those, renovated or move-in ready') ?></dt><dd><?= te('%1$d days (median of %2$d)', (int) $mk['renovated_days'], (int) $mk['renovated']) ?></dd></div><?php endif; ?>
    <?php if ($mk['other'] >= 3): ?><div><dt><?= te('…of those, needing work') ?></dt><dd><?= te('%1$d days (median of %2$d)', (int) $mk['other_days'], (int) $mk['other']) ?></dd></div><?php endif; ?>
  </dl>
  <p class="section-note"><?= te('Days on market come from the listings on file, so they cover only homes the site has seen listed.') ?></p>
  <?php endif; ?>
</section>

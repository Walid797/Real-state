<?php
/** Region evidence inside the purchase verdict. Uses stored data until the user requests an update. */
$regional = App\Services\RegionalDemand::for($p);
?>
<div class="regional-demand mt-l" id="regional-demand">
  <div class="section-head"><h2><?= te('Regional demand and resale outlook') ?><?= tip_open() ?><?= te('Review the region alongside the purchase price, renovation budget and comparable sales.') ?><?= tip_close() ?></h2>

  </div>
  <?php if ($regional['county']): ?><p class="small mt-s"><bdi><?= e($regional['county']) ?></bdi> · <?= te('ACS survey period %s', $regional['acs_period']) ?></p><?php endif; ?>
  <?php if (!$regional['has_coordinates']): ?><p class="note mt-s"><?= te('Add verified map coordinates to identify the county for public statistics.') ?></p><?php endif; ?>
  <div class="regional-summary">
    <div><span><?= te('Area score') ?></span><strong><?= $regional['score'] === null ? '—' : $regional['score'] . '/100' ?></strong></div>
    <div><span><?= te('Data coverage') ?></span><strong><?= (int) $regional['coverage'] ?>/100</strong></div>
    <p><?= te('A score is calculated only for factors with verified data. The overall score requires data for at least 50 of the 100 possible points, including the housing market.') ?></p>
  </div>
  <div class="regional-groups">
    <?php foreach ($regional['groups'] as $group): ?>
    <article class="regional-group">
      <header><h3><?= te($group['label']) ?><?php if (!empty($group['note'])): ?><?= tip(te($group['note']), t($group['label'])) ?><?php endif; ?></h3></header>
      <p class="regional-points"><?= $group['points'] === null ? te('Needs verification') : te('Score earned: %1$s/%2$d', trim_num($group['points']), $group['weight']) ?></p>
      <?php if ($group['metrics']): ?>
      <dl>
        <?php foreach ($group['metrics'] as $label => $value): ?><div><dt><?= te($label) ?></dt><dd><bdi><?= e($value) ?></bdi></dd></div><?php endforeach; ?>
      </dl>
      <?php else: ?><p class="small muted"><?= te('Verified figures are unavailable for this item.') ?></p><?php endif; ?>
      <p class="regional-sources small">
        <?php foreach ($group['links'] as $label => $link): ?>
        <a href="<?= e($link) ?>"<?= str_starts_with($link, '#') ? '' : ' target="_blank" rel="noopener noreferrer"' ?>><?= te($label) ?></a>
        <?php endforeach; ?>
      </p>
    </article>
    <?php endforeach; ?>
  </div>
  <details class="regional-method mt-m">
    <summary><?= te('How the regional score is calculated') ?></summary>
    <p class="small mt-s"><?= te('Each category has a share of the 100-point model. Its available indicators determine how many of those points it earns. The overall score is adjusted to the categories with data; missing categories add no points or penalties.') ?></p>
    <ul class="small mt-s"><?php foreach ($regional['method'] as $label => $rule): ?><li><strong><?= te($label) ?></strong> — <?= te($rule) ?></li><?php endforeach; ?></ul>
    <p class="small mt-s"><?= te('County ACS data is provided through Census Reporter. Historical growth uses Census ACS when its free key is configured. BLS data is monthly and not seasonally adjusted. Public responses are cached by county. Source access limits may delay updates.') ?></p>
  </details>
</div>

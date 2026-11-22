<?php
/** Region evidence inside the purchase verdict. Uses stored data until the user requests an update. */
$regional = App\Services\RegionalDemand::for($p);
?>
<div class="regional-demand mt-l" id="regional-demand">
  <div class="section-head"><h2><?= te('Regional demand and resale outlook') ?></h2>

  </div>
  <p class="small soft"><?= te('Review the region alongside the purchase price, renovation budget and comparable sales.') ?></p>
  <?php if ($regional['county']): ?><p class="small mt-s"><bdi><?= e($regional['county']) ?></bdi> · <?= te('ACS survey period %s', $regional['acs_period']) ?></p><?php endif; ?>
  <?php if (!$regional['has_coordinates']): ?><p class="note mt-s"><?= te('Add verified map coordinates to identify the county for public statistics.') ?></p><?php endif; ?>
  <div class="regional-summary">
    <div><span><?= te('Regional evidence score') ?></span><strong><?= $regional['score'] === null ? '—' : $regional['score'] . '/100' ?></strong></div>
    <div><span><?= te('Scored weight covered') ?></span><strong><?= (int) $regional['coverage'] ?>/100</strong></div>
    <p><?= te('Unknown factors remain unscored. The overall score needs at least 50 points of covered weight including housing-market evidence.') ?></p>
  </div>
  <div class="regional-groups">
    <?php foreach ($regional['groups'] as $group): ?>
    <article class="regional-group">
      <header><h3><?= te($group['label']) ?></h3><span class="small"><?= te('Weight %d', $group['weight']) ?></span></header>
      <p class="regional-points"><?= $group['points'] === null ? te('Needs verification') : te('%1$s of %2$d points', trim_num($group['points']), $group['weight']) ?></p>
      <?php if ($group['metrics']): ?>
      <dl>
        <?php foreach ($group['metrics'] as $label => $value): ?><div><dt><?= te($label) ?></dt><dd><bdi><?= e($value) ?></bdi></dd></div><?php endforeach; ?>
      </dl>
      <?php else: ?><p class="small muted"><?= te('Verified figures are unavailable for this item.') ?></p><?php endif; ?>
      <p class="small soft mt-s"><?= te($group['note']) ?></p>
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
    <p class="small mt-s"><?= te('This is the proposed investment model with your weights. Available signals within each group are averaged, multiplied by its weight, then normalized over covered weight. Missing groups add no points or penalties.') ?></p>
    <ul class="small mt-s"><?php foreach ($regional['method'] as $label => $rule): ?><li><strong><?= te($label) ?></strong> — <?= te($rule) ?></li><?php endforeach; ?></ul>
    <p class="small mt-s"><?= te('County ACS data is provided through Census Reporter. Historical growth uses Census ACS when its free key is configured. BLS data is monthly and not seasonally adjusted. Public responses are cached by county. Source access limits may delay updates.') ?></p>
  </details>
</div>

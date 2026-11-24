<?php
/** "Should we buy it?" — the reasoned verdict at the end of a home's page. Expects $p and $deal (Deal::analyse). */
use App\Services\Verdict;

$v = Verdict::for($p, $deal ?? null);
$fmt = static function (array $item): string {
    $args = array_map(static fn ($a) => is_string($a) ? t($a) : $a, $item['args']);
    return e(t($item['text'], ...$args));
};
$byGroup = [];
foreach ($v['factors'] as $fa) {
    $byGroup[$fa['group']][] = $fa;
}
$tone = ['buy' => 'good', 'offer' => 'mid', 'lower' => 'warn', 'pass' => 'bad', 'unknown' => 'none'][$v['verdict']];
$confLabel = ['high' => 'High confidence', 'medium' => 'Medium confidence', 'low' => 'Low confidence'][$v['confidence']];
?>
<section class="section verdict verdict-<?= $tone ?>" id="verdict">
  <div class="section-head"><h2><?= te('Should we buy it?') ?><?= tip_open() ?><?= te('This weighs everything on this page — the deal, comparable sales, the assessment, the seller’s position, flips and values in the area, flood zone, neighbourhood, the building and the land. It is an analysis of the data on file, not an appraisal or an inspection.') ?><?= tip_close() ?></h2><span class="aside"><?= te($confLabel) ?></span></div>
  <div class="verdict-head">
    <span class="verdict-badge"><?= te(Verdict::VERDICTS[$v['verdict']]) ?></span>
    <?php if ($v['offer'] && $v['verdict'] !== 'pass'): ?><span class="verdict-offer"><?= te('Offer up to') ?> <b><?= e(money($v['offer'])) ?></b></span><?php endif; ?>
  </div>
  <p class="verdict-summary"><?= $fmt($v['summary']) ?></p>
  <div class="verdict-grid">
    <?php foreach (Verdict::GROUPS as $g => $label): if (empty($byGroup[$g])) { continue; } ?>
    <div class="verdict-group">
      <h3><?= te($label) ?></h3>
      <ul>
        <?php foreach ($byGroup[$g] as $fa): ?>
        <li class="<?= $fa['tone'] > 0 ? 'v-plus' : ($fa['tone'] < 0 ? 'v-minus' : 'v-note') ?>"><span class="sign" aria-hidden="true"><?= $fa['tone'] > 0 ? '+' : ($fa['tone'] < 0 ? '−' : '·') ?></span><span><?= $fmt($fa) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if ($v['checks']): ?>
  <h3 class="mt-l"><?= te('Check before an offer') ?></h3>
  <ul class="verdict-checks">
    <?php foreach ($v['checks'] as $c): ?><li><?= $fmt($c) ?></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <div data-intelligence-url="/api/property-analysis/<?= (int) $p['id'] ?>/status" data-property-id="<?= (int) $p['id'] ?>" data-csrf="<?= e(App\Auth::csrfToken()) ?>">
    <?php $report = App\Services\PropertyAnalysis::publicReport((int) $p['id']); include APP_ROOT . '/pages/partials/intelligence.php'; ?>
  </div>
  <?php include APP_ROOT . '/pages/partials/regional_demand.php'; ?>
</section>

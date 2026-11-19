<?php
/* Opportunity / risk / confidence strip under the property header. Expects $sc, $deal, $p. */
use App\Services\Score;

$band = Score::band($sc['score']);
$riskBand = $sc['risk'] >= 60 ? 'high' : ($sc['risk'] >= 35 ? 'mid' : 'low');
?>
<section class="scorebar" aria-label="<?= te('Investment summary') ?>">
  <div class="sb-score score-<?= $band ?>">
    <b><?= $sc['score'] !== null ? (int) $sc['score'] : '—' ?></b>
    <span><?= te('Opportunity') ?><br><?= te('score / 100') ?></span>
  </div>
  <div class="sb-cell">
    <span class="eyebrow"><?= te('Est. net profit') ?></span>
    <b class="<?= $deal['ok'] && $deal['base']['profit'] < 0 ? 'up' : '' ?>"><?= $deal['ok'] ? e(money_short(abs($deal['base']['profit']))) . ($deal['base']['profit'] < 0 ? ' loss' : '') : '—' ?></b>
    <span><?= $deal['ok'] ? 'ROI ' . number_format($deal['base']['roi'], 1) . '%' : e('Needs ' . implode(', ', $deal['missing'] ?? [])) ?></span>
  </div>
  <div class="sb-cell">
    <span class="eyebrow"><?= te('After-repair value') ?></span>
    <b><?= $deal['inputs']['arvValue'] ? e(money_short($deal['inputs']['arvValue'])) : '—' ?></b>
    <span><?= ($deal['source']['arv'] ?? 'est') === 'set' ? te('set by the team') : e($deal['arv']['basis']) ?></span>
  </div>
  <div class="sb-cell">
    <span class="eyebrow"><?= te('Max offer') ?></span>
    <b><?= $deal['ok'] ? e(money_short($deal['max_offer'])) : '—' ?></b>
    <span><?= $deal['ok'] ? te('for %1$s profit · 70%% rule %2$s', money_short($deal['target']), money_short($deal['rule70'])) : '' ?></span>
  </div>
  <div class="sb-cell">
    <span class="eyebrow"><?= te('Risk') ?></span>
    <b class="risk-<?= $riskBand ?>"><?= (int) $sc['risk'] ?><small>/100</small></b>
    <span><?= te('Confidence') ?> <?= (int) $sc['confidence'] ?>%</span>
  </div>
</section>
<?php
// What would make these numbers better, offered right here instead of a row of dashes.
$arvFrom = ($deal['source']['arv'] ?? 'est') === 'set' ? 'set' : ($deal['arv']['from'] ?? (($deal['arv']['comps'] ?? 0) > 0 ? 'sales' : ($deal['inputs']['arvValue'] ? 'avm' : 'none')));
$needSales = in_array($arvFrom, ['asking', 'none'], true) && $hasGeo;
$needText = ($cond['overall'] ?? 'not_disclosed') === 'not_disclosed' && empty($p['description']);
$canPull = App\Auth::can('edit');
?>
<?php if ($arvFrom !== 'set' && ($needSales || $needText || !empty($deal['tax_estimated']))): ?>
<div class="sb-next">
  <p class="small">
    <?php if ($arvFrom === 'asking'): ?><?= te('Figures use') ?> <strong><?= te('asking prices') ?></strong> <?= te('of similar homes nearby, so treat them as a first look.') ?><?php elseif ($arvFrom === 'none'): ?><?= te('No sales or similar listings nearby yet, so there is no after-repair value to work from.') ?><?php endif; ?>
    <?php if ($needText): ?> <?= te('The listing text isn’t on file, so condition is unknown and renovation is priced at the “not disclosed” rate.') ?><?php endif; ?>
    <?php if (!empty($deal['tax_estimated'])): ?> <?= te('Property tax is estimated at') ?> <?= e(trim_num(App\Services\Deal::setting('deal_tax_pct'))) ?><?= te('% of the price.') ?><?php endif; ?>
  </p>
  <?php if ($canPull && ($needSales || $needText)): ?>
  <div class="actions">
    <?php if ($needSales): ?><?= $pull('sales', 'Recorded sales nearby', '1 RentCast request', $rcLeft > 0) ?><?php endif; ?>
    <?php if ($needText): ?><?= $pull('zillow', 'Read the listing', '1 Zillow-data request', $ownLeft > 0) ?><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($sc['risks'] || $sc['missing']): ?>
<p class="risk-line">
  <?php foreach (array_slice($sc['risks'], 0, 5) as $r): ?><span class="chip chip-bad"><?= e($r) ?></span><?php endforeach; ?>
  <?php if ($sc['missing']): ?><span class="chip"><?= te('Missing:') ?> <?= e(implode(', ', array_slice($sc['missing'], 0, 5))) ?></span><?php endif; ?>
</p>
<?php endif; ?>

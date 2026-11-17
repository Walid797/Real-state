<?php
/* Opportunity / risk / confidence strip under the property header. Expects $sc, $deal, $p. */
use App\Services\Score;

$band = Score::band($sc['score']);
$riskBand = $sc['risk'] >= 60 ? 'high' : ($sc['risk'] >= 35 ? 'mid' : 'low');
?>
<section class="scorebar" aria-label="Investment summary">
  <div class="sb-score score-<?= $band ?>">
    <b><?= $sc['score'] !== null ? (int) $sc['score'] : '—' ?></b>
    <span>Opportunity<br>score / 100</span>
  </div>
  <div class="sb-cell">
    <span class="eyebrow">Est. net profit</span>
    <b class="<?= $deal['ok'] && $deal['base']['profit'] < 0 ? 'up' : '' ?>"><?= $deal['ok'] ? e(money_short(abs($deal['base']['profit']))) . ($deal['base']['profit'] < 0 ? ' loss' : '') : '—' ?></b>
    <span><?= $deal['ok'] ? 'ROI ' . number_format($deal['base']['roi'], 1) . '%' : e('Needs ' . implode(', ', $deal['missing'] ?? [])) ?></span>
  </div>
  <div class="sb-cell">
    <span class="eyebrow">After-repair value</span>
    <b><?= $deal['inputs']['arvValue'] ? e(money_short($deal['inputs']['arvValue'])) : '—' ?></b>
    <span><?= ($deal['source']['arv'] ?? 'est') === 'set' ? 'set by the team' : e($deal['arv']['basis']) ?></span>
  </div>
  <div class="sb-cell">
    <span class="eyebrow">Max offer</span>
    <b><?= $deal['ok'] ? e(money_short($deal['max_offer'])) : '—' ?></b>
    <span><?= $deal['ok'] ? 'for ' . e(money_short($deal['target'])) . ' profit · 70% rule ' . e(money_short($deal['rule70'])) : '' ?></span>
  </div>
  <div class="sb-cell">
    <span class="eyebrow">Risk</span>
    <b class="risk-<?= $riskBand ?>"><?= (int) $sc['risk'] ?><small>/100</small></b>
    <span>Confidence <?= (int) $sc['confidence'] ?>%</span>
  </div>
</section>
<?php if ($sc['risks'] || $sc['missing']): ?>
<p class="risk-line">
  <?php foreach (array_slice($sc['risks'], 0, 5) as $r): ?><span class="chip chip-bad"><?= e($r) ?></span><?php endforeach; ?>
  <?php if ($sc['missing']): ?><span class="chip">Missing: <?= e(implode(', ', array_slice($sc['missing'], 0, 5))) ?></span><?php endif; ?>
</p>
<?php endif; ?>

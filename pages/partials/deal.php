<?php
/* Deal calculator. Expects $deal, $sc, $p, $id, $canEdit. */
use App\Services\Deal;
use App\Services\Score;

$in = $deal['inputs'];
$src = $deal['source'];
$saved = Deal::saved($id);
$tag = static fn (string $k) => ($src[$k] ?? 'est') === 'set' ? '<span class="src src-set" title="Typed by the team">set</span>' : '<span class="src" title="Estimated — type a figure to override">est.</span>';
$field = static function (string $name, string $label, mixed $estimate, string $unit = '$', string $srcKey = '') use ($saved, $tag, $canEdit): void {
    $v = $saved[$name] ?? null;
    $ph = $estimate === null ? '' : ($unit === '$' ? number_format((float) $estimate) : rtrim(rtrim(number_format((float) $estimate, 2), '0'), '.'));
    echo '<label class="field"><span>' . e($label) . ' ' . $tag($srcKey ?: $name) . '</span>'
        . '<span class="affix' . ($unit === '$' ? ' affix-pre' : ' affix-post') . '" data-unit="' . e($unit) . '">'
        . '<input class="input" name="' . $name . '" inputmode="decimal" value="' . ($v === null ? '' : e($unit === '$' ? number_format((float) $v) : rtrim(rtrim((string) $v, '0'), '.'))) . '" placeholder="' . e($ph) . '"' . ($canEdit ? '' : ' disabled') . '></span></label>';
};
?>
<section class="section" id="deal">
  <div class="section-head"><h2>Deal analysis</h2><span class="aside">estimates in grey · type to override · shared with the team</span></div>

  <?php if ($deal['ok']): $b = $deal['base']; ?>
  <div class="deal-sum">
    <div><span>Total investment</span><b><?= e(money($b['total'])) ?></b><small>cash needed <?= e(money($b['cash'])) ?></small></div>
    <div><span>Net profit</span><b class="<?= $b['profit'] < 0 ? 'up' : 'down' ?>"><?= e(($b['profit'] < 0 ? '−' : '') . money(abs($b['profit']))) ?></b><small>after selling costs</small></div>
    <div><span>ROI</span><b><?= number_format($b['roi'], 1) ?>%</b><small>profit ÷ total investment</small></div>
    <div><span>Break-even sale</span><b><?= e(money($deal['break_even'])) ?></b><small>ARV <?= e(money($in['arvValue'])) ?></small></div>
  </div>

  <div class="table-wrap">
    <table class="table deal-table">
      <thead><tr><th>Line</th><th class="r">Cautious</th><th class="r">Typical</th><th class="r">Hopeful</th></tr></thead>
      <tbody>
      <?php $sx = $deal['scenarios'];
      $rows = [
          'Purchase price' => fn ($x) => $in['purchase'],
          'Closing costs (buy)' => fn ($x) => $x['closing'],
          'Renovation + contingency' => fn ($x) => $x['total'] - $in['purchase'] - $x['closing'] - $x['holding'] - $x['financing'],
          'Holding costs' => fn ($x) => $x['holding'],
          'Financing' => fn ($x) => $x['financing'],
          'Total investment' => fn ($x) => $x['total'],
          'Selling costs' => fn ($x) => $x['selling'],
          'Net profit' => fn ($x) => $x['profit'],
      ];
      foreach ($rows as $label => $get): $strong = in_array($label, ['Total investment', 'Net profit'], true); ?>
        <tr<?= $strong ? ' class="strong"' : '' ?>><td><?= e($label) ?></td>
          <?php foreach (['pessimistic', 'typical', 'optimistic'] as $k): $v = $get($sx[$k]); ?>
          <td class="r<?= $label === 'Net profit' ? ($v < 0 ? ' up' : ' down') : '' ?>"><?= e(($v < 0 ? '−' : '') . money(abs($v), '$0')) ?></td>
          <?php endforeach; ?></tr>
      <?php endforeach; ?>
        <tr><td>ROI</td><?php foreach (['pessimistic', 'typical', 'optimistic'] as $k): ?><td class="r"><?= number_format($sx[$k]['roi'], 1) ?>%</td><?php endforeach; ?></tr>
      </tbody>
    </table>
  </div>
  <p class="section-note">Cautious: ARV −8%, renovation +25%, two extra months. Hopeful: ARV +5%, renovation −10%, a month faster. Estimates, not appraisals or inspections — check them before an offer.</p>
  <?php else: ?>
  <p class="note mt-m"><?= icon('info', 16) ?><span>Not enough to run the numbers yet: <?= e(implode(', ', $deal['missing'] ?? [])) ?>. Type them below, or pull this home’s record and nearby sales.</span></p>
  <?php endif; ?>

  <details class="deal-edit"<?= $deal['ok'] ? '' : ' open' ?>>
    <summary><?= icon('calculator', 15) ?><?= $canEdit ? 'Change the numbers' : 'Show the inputs' ?></summary>
    <form method="post" action="/property/<?= $id ?>/act#deal">
      <?= csrf_field() ?><input type="hidden" name="do" value="deal">
      <div class="deal-grid">
        <?php
        $field('purchase_price', 'Purchase price', $in['purchase'], '$', 'purchase_price');
        $field('arv', 'After-repair value (ARV)', $deal['arv']['value'], '$', 'arv');
        $field('reno_cost', 'Renovation', $in['reno'], '$', 'reno_cost');
        $field('contingency_pct', 'Contingency', $in['contPct'], '%');
        $field('closing_pct', 'Closing costs', $in['closingPct'], '%');
        $field('selling_pct', 'Selling costs', $in['sellingPct'], '%');
        $field('holding_months', 'Months held', $in['months'], 'mo');
        $field('holding_monthly', 'Holding per month', $in['monthly'], '$');
        $field('loan_ltv_pct', 'Loan (% of price)', $in['ltv'], '%');
        $field('loan_rate_pct', 'Interest rate', $in['rate'], '%');
        $field('loan_points_pct', 'Lender points', $in['points'], '%');
        ?>
      </div>
      <p class="hint">Renovation estimate: <?= e(App\Services\Condition::OVERALL[$deal['condition']] ?? '') ?> × <?= $p['sqft'] ? number_format((int) $p['sqft']) . ' sqft' : 'unknown size' ?> (rates under Settings). Holding = insurance &amp; utilities + property tax + HOA.</p>
      <?php if (in_array($p['watch_stage'] ?? '', ['purchased', 'sold'], true)): ?>
      <h4 class="eyebrow mt-l">Actual results</h4>
      <div class="deal-grid">
        <?php $field('actual_purchase', 'Actual purchase', null); $field('actual_costs', 'Actual costs (all)', null); $field('actual_sale', 'Actual sale', null); ?>
      </div>
      <?php if (isset($deal['actual'])): ?><p class="small">Actual profit <b class="<?= $deal['actual'] < 0 ? 'up' : 'down' ?>"><?= e(money(abs($deal['actual']))) ?></b> vs <?= e(money($deal['base']['profit'] ?? 0)) ?> predicted.</p><?php endif; ?>
      <?php endif; ?>
      <?php if ($canEdit): ?>
      <div class="form-foot"><button class="btn btn-primary" type="submit">Save and recalculate</button><span class="small muted">Leave a box empty to go back to the estimate.<?= $saved ? ' Last changed ' . e(ago($saved['updated_at'])) . '.' : '' ?></span></div>
      <?php endif; ?>
    </form>
  </details>

  <details class="deal-edit">
    <summary><?= icon('gauge', 15) ?>How the score of <?= $sc['score'] !== null ? (int) $sc['score'] : '—' ?> is made</summary>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Factor</th><th class="r">Points</th><th class="r">Out of</th></tr></thead>
        <tbody>
        <?php foreach (Score::WEIGHTS as $k => [$label, $max]): ?>
          <tr><td><?= e($label) ?></td><td class="r"><?= (int) $sc['parts'][$k] ?></td><td class="r muted"><?= $max ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="section-note">Risk <?= (int) $sc['risk'] ?>/100 is separate: it rises with undisclosed condition, thin margins, few comparable sales, old construction, distressed sales and flood zones.</p>
  </details>
</section>

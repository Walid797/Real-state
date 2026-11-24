<?php
declare(strict_types=1);

use App\Services\Reports;
use App\View;

/* Reports for decisions. Print → “Save as PDF” gives the PDF version; Excel has every table. */

$tables = Reports::tables();
View::header('Reports', 'reports');
?>
<header class="page-head">
  <div>
    <h1 class="display"><?= te('Reports') ?><?= tip_open() ?><?= te('Where the homes are, what they cost, which ones look best — and how the team’s finished projects compared with the estimates.') ?><?= tip_close() ?></h1>
  </div>
  <div class="actions no-print">
    <a class="btn" href="/export.xlsx?report=1"><?= icon('file-spreadsheet', 15) ?><?= te('Excel') ?></a>
    <button class="btn" type="button" data-print><?= icon('printer', 15) ?><?= te('PDF / print') ?></button>
  </div>
</header>
<p class="print-only small"><?= te('Printed') ?> <?= e(fmt_date(now(), 'F j, Y g:i a')) ?> <?= te('· estimates, not appraisals.') ?></p>

<nav class="setting-nav no-print" aria-label="<?= te('Report sections') ?>">
  <?php foreach ($tables as $t): ?><a href="#r-<?= $t['key'] ?>"><?= te($t['title']) ?></a><?php endforeach; ?>
</nav>

<?php foreach ($tables as $t): ?>
<section class="section report" id="r-<?= $t['key'] ?>">
  <div class="section-head"><h2><?= te($t['title']) ?><?= tip_open() ?><?= te($t['note']) ?><?= tip_close() ?></h2></div>
  <?php if (!$t['rows']): ?>
    <p class="blank"><?= te('Nothing to show yet.') ?></p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><?php foreach ($t['header'] as $i => $h): ?><th<?= $i >= 2 ? ' class="r"' : '' ?>><?= te($h) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($t['rows'] as $row): ?>
        <tr>
        <?php foreach ($t['header'] as $i => $_): $v = $row[$i] ?? null; ?>
          <td<?= $i >= 2 ? ' class="r"' : '' ?>><?php
            if ($i === 0 && isset($t['link'])) {
                echo '<a class="main" href="/property/' . (int) $row[$t['link']] . '">' . e($v) . '</a>';
            } elseif ($v === null || $v === '') {
                echo '<span class="muted">—</span>';
            } elseif (in_array($i, $t['money'] ?? [], true)) {
                echo e(((float) $v < 0 ? '−' : '') . money(abs((float) $v), '$0'));
            } elseif (is_float($v)) {
                echo e(number_format($v, 1));
            } elseif (is_int($v)) {
                echo e(number_format($v));
            } else {
                echo e(t((string) $v));
            }
          ?></td>
        <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endforeach; ?>
<?php
View::footer();

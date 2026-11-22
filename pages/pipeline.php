<?php
declare(strict_types=1);

use App\Auth;
use App\Db;
use App\PropertyRepo;
use App\Services\Pipeline;
use App\Services\Score;
use App\View;

/* The team pipeline: every home someone is working, grouped by status, with who handles it. */

$who = input('who');           // '' = everyone, 'me' = mine
$show = input('show', 'open');  // open | closed | all

$w = [];
$a = [];
if ($who === 'me') {
    $w[] = 'w.owner_id = ?';
    $a[] = Auth::userId();
}
if ($show === 'open') {
    $w[] = "w.stage NOT IN ('rejected','sold')";
} elseif ($show === 'closed') {
    $w[] = "w.stage IN ('rejected','purchased','sold')";
}
$rows = Db::all(
    "SELECT p.*, w.stage, w.owner_id, w.updated_at AS stage_at, w.source_url AS watch_url,
            COALESCE(NULLIF(ou.name, ''), ou.email) AS owner_name, COALESCE(NULLIF(ub.name, ''), ub.email) AS stage_by,
            (SELECT COUNT(*) FROM notes n WHERE n.property_id = p.id) AS note_count,
            (SELECT e.price FROM price_events e WHERE e.property_id = p.id AND e.event IN ('listed','relisted') AND e.price > 0 ORDER BY e.event_date ASC, e.id ASC LIMIT 1) AS first_price
     FROM watchlist w JOIN properties p ON p.id = w.property_id
     LEFT JOIN users ou ON ou.id = w.owner_id LEFT JOIN users ub ON ub.id = w.updated_by"
    . ($w ? ' WHERE ' . implode(' AND ', $w) : '') .
    " ORDER BY FIELD(w.stage, 'offer','inspection','contact_agent','under_review','new','purchased','sold','rejected'), w.updated_at DESC",
    $a
);
$byStage = [];
foreach ($rows as $r) {
    $byStage[$r['stage']][] = $r;
}
$counts = array_column(Db::all('SELECT stage, COUNT(*) AS n FROM watchlist GROUP BY stage'), 'n', 'stage');
$canEdit = Auth::can('edit');

View::header('Favorites', 'pipeline');
?>
<header class="page-head">
  <div>
    <h1 class="display"><?= te('Favorites') ?></h1>
    <p><?= te('Homes the team is working. One status per home, one person handling it — so nobody chases a house someone else is already negotiating.') ?></p>
  </div>
  <?php if ($canEdit): ?><div class="actions"><a class="btn btn-primary" href="/add?watch=1"><?= icon('plus', 15) ?><?= te('Add from a link') ?></a></div><?php endif; ?>
</header>

<div class="stage-strip" role="list">
  <?php foreach (Pipeline::STAGES as $k => $l): ?>
  <a role="listitem" class="stage-pill stage-<?= $k ?>" href="<?= e(url('/pipeline', ['who' => $who, 'show' => empty($byStage[$k]) ? 'all' : $show])) ?>#st-<?= $k ?>"><span><?= te($l) ?></span><b><?= (int) ($counts[$k] ?? 0) ?></b></a>
  <?php endforeach; ?>
</div>

<form class="toolbar" method="get" action="/pipeline">
  <span class="seg seg-sm" role="group" aria-label="<?= te('Whose') ?>">
    <label><input type="radio" name="who" value=""<?= checked($who !== 'me') ?> data-autosubmit><span><?= te('Everyone') ?></span></label>
    <label><input type="radio" name="who" value="me"<?= checked($who === 'me') ?> data-autosubmit><span><?= te('Handled by me') ?></span></label>
  </span>
  <span class="seg seg-sm" role="group" aria-label="<?= te('Which') ?>">
    <?php foreach (['open' => 'Open', 'closed' => 'Closed', 'all' => 'All'] as $k => $l): ?>
    <label><input type="radio" name="show" value="<?= $k ?>"<?= checked($show === $k) ?> data-autosubmit><span><?= te($l) ?></span></label>
    <?php endforeach; ?>
  </span>
</form>

<?php if (!$rows): ?>
  <div class="empty">
    <?= View::sketch('pipeline-empty') ?>
    <h2 class="display"><?= te('Nothing here.') ?></h2>
    <p><?= te('Star a home in the search results, or paste a link from Homes.com, Zillow, Realtor.com or Redfin. It lands here as “New”.') ?></p>
    <p class="actions"><a class="btn btn-primary" href="/search"><?= te('Search') ?></a><?php if ($canEdit): ?><a class="btn" href="/add?watch=1"><?= te('Add from a link') ?></a><?php endif; ?></p>
  </div>
<?php endif; ?>

<?php if ($rows && Auth::can('edit')): $bulk = ['type' => 'pipeline', 'form' => 'bulk-pipeline', 'back' => $_SERVER['REQUEST_URI'] ?? '/pipeline', 'all' => 'Remove all favorites', 'count' => count($rows)]; include APP_ROOT . '/pages/partials/bulk_bar.php'; endif; ?>

<div class="deal-board">
<?php foreach (Pipeline::STAGES as $stage => $label):
    if (empty($byStage[$stage]) && $show !== 'all') { continue; } ?>
<section class="section board-lane" id="st-<?= $stage ?>">
  <div class="section-head"><h2><?= te($label) ?></h2><span class="lane-count"><?= count($byStage[$stage] ?? []) ?></span></div>
  <?php foreach ($byStage[$stage] ?? [] as $r):
      $sale = in_array($r['status'], ['for_sale', 'pending'], true);
      $price = $sale ? $r['list_price'] : $r['last_sale_price'];
      $first = (int) ($r['first_price'] ?? 0);
      $delta = $sale && $first && $price && $first !== (int) $price ? ($price - $first) / $first * 100 : null;
      $band = Score::band($r['score'] !== null ? (int) $r['score'] : null);
  ?>
  <article class="board-home">
    <div class="board-title">
      <?php if ($canEdit): ?><input class="bulk-pick" type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" form="bulk-pipeline" aria-label="<?= te('Select') ?> <?= e($r['address']) ?>"><?php endif; ?>
      <a class="addr" dir="auto" href="/property/<?= (int) $r['id'] ?>"><?= e($r['address']) ?></a>
      <span class="score-badge sm score-<?= $band ?>" title="<?= te('Opportunity score') ?>"><?= $r['score'] !== null ? (int) $r['score'] : '—' ?></span></div>
    <p class="small muted"><?= e($r['city']) ?>, <?= e($r['state']) ?> · <?= e(PropertyRepo::facts($r) ?: t('Details not on file')) ?></p>
    <dl class="board-facts">
      <div><dt><?= te($sale ? 'Asking price' : ($r['status'] === 'sold' ? 'Last sold' : 'Price')) ?></dt><dd><?= e(money($price)) ?>
        <?php if ($delta !== null): ?><small class="<?= $delta < 0 ? 'down' : 'up' ?>"><?= e(pct($delta)) ?> <?= te('since listed') ?></small><?php endif; ?></dd></div>
      <div><dt><?= te('Est. profit') ?></dt><dd class="<?= ($r['est_profit'] ?? 0) < 0 ? 'up' : 'down' ?>"><?= $r['est_profit'] !== null ? (($r['est_profit'] < 0 ? '−' : '') . e(money_short(abs((int) $r['est_profit'])))) : '—' ?></dd></div>
      <div><dt><?= te('Handled by') ?></dt><dd><?= e($r['owner_name'] ?: t('No one yet')) ?></dd></div>
      <div><dt><?= te('Updated') ?></dt><dd><?= e(ago($r['stage_at'])) ?><?= $r['stage_by'] ? ' · ' . te('by %s', $r['stage_by']) : '' ?></dd></div>
    </dl>
    <?php if ((int) $r['note_count']): ?><p class="small muted"><?= e(plural((int) $r['note_count'], 'note')) ?></p><?php endif; ?>
    <?php if ($canEdit): ?>
    <form method="post" action="/property/<?= (int) $r['id'] ?>/act">
      <?= csrf_field() ?><input type="hidden" name="do" value="stage"><input type="hidden" name="back" value="<?= e(url('/pipeline', ['who' => $who, 'show' => $show])) ?>">
      <label class="sr" for="st<?= (int) $r['id'] ?>"><?= te('Status') ?></label>
      <select class="select stage" id="st<?= (int) $r['id'] ?>" name="stage" data-autosubmit>
        <?php foreach (Pipeline::STAGES as $k => $l): ?><option value="<?= $k ?>"<?= selected($r['stage'], $k) ?>><?= te($l) ?></option><?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </article>
  <?php endforeach; ?>
</section>
<?php endforeach; ?>
</div>
<?php
View::footer();

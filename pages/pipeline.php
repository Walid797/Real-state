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

View::header('Pipeline', 'pipeline');
?>
<header class="page-head">
  <div>
    <h1 class="display">Pipeline</h1>
    <p>Homes the team is working. One status per home, one person handling it — so nobody chases a house someone else is already negotiating.</p>
  </div>
  <?php if ($canEdit): ?><div class="actions"><a class="btn btn-primary" href="/add?watch=1"><?= icon('plus', 15) ?>Add from a link</a></div><?php endif; ?>
</header>

<div class="stage-strip" role="list">
  <?php foreach (Pipeline::STAGES as $k => $l): ?>
  <a role="listitem" class="stage-pill stage-<?= $k ?>" href="#st-<?= $k ?>"><span><?= e($l) ?></span><b><?= (int) ($counts[$k] ?? 0) ?></b></a>
  <?php endforeach; ?>
</div>

<form class="toolbar" method="get" action="/pipeline">
  <span class="seg seg-sm" role="group" aria-label="Whose">
    <label><input type="radio" name="who" value=""<?= checked($who !== 'me') ?> data-autosubmit><span>Everyone</span></label>
    <label><input type="radio" name="who" value="me"<?= checked($who === 'me') ?> data-autosubmit><span>Handled by me</span></label>
  </span>
  <span class="seg seg-sm" role="group" aria-label="Which">
    <?php foreach (['open' => 'Open', 'closed' => 'Closed', 'all' => 'All'] as $k => $l): ?>
    <label><input type="radio" name="show" value="<?= $k ?>"<?= checked($show === $k) ?> data-autosubmit><span><?= $l ?></span></label>
    <?php endforeach; ?>
  </span>
</form>

<?php if (!$rows): ?>
  <div class="empty">
    <?= View::sketch('pipeline-empty') ?>
    <h2 class="display">Nothing here.</h2>
    <p>Star a home in the search results, or paste a link from Homes.com, Zillow, Realtor.com or Redfin. It lands here as “New”.</p>
    <p class="actions"><a class="btn btn-primary" href="/search">Search</a><?php if ($canEdit): ?><a class="btn" href="/add?watch=1">Add from a link</a><?php endif; ?></p>
  </div>
<?php endif; ?>

<?php foreach (Pipeline::STAGES as $stage => $label): if (empty($byStage[$stage])) { continue; } ?>
<section class="section" id="st-<?= $stage ?>">
  <div class="section-head"><h2><?= e($label) ?></h2><span class="aside"><?= plural(count($byStage[$stage]), 'home') ?></span></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Home</th><th class="r">Price</th><th class="r">Est. profit</th><th class="r">Score</th><th>Handled by</th><th>Updated</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($byStage[$stage] as $r):
          $sale = in_array($r['status'], ['for_sale', 'pending'], true);
          $price = $sale ? (int) $r['list_price'] : (int) $r['last_sale_price'];
          $first = (int) ($r['first_price'] ?? 0);
          $delta = ($sale && $first > 0 && $price > 0 && $first !== $price) ? ($price - $first) / $first * 100 : null;
          $band = Score::band($r['score'] !== null ? (int) $r['score'] : null);
      ?>
        <tr>
          <td><a class="main" href="/property/<?= (int) $r['id'] ?>"><?= e($r['address']) ?></a>
            <span class="sub"><?= e($r['city']) ?>, <?= e($r['state']) ?> · <?= e(PropertyRepo::facts($r) ?: 'details not on file') ?><?= (int) $r['note_count'] ? ' · ' . plural((int) $r['note_count'], 'note') : '' ?></span></td>
          <td class="r"><?= e(money($price)) ?><?php if ($delta !== null): ?><span class="sub <?= $delta < 0 ? 'down' : 'up' ?>"><?= e(pct($delta)) ?> since listed</span><?php else: ?><span class="sub"><?= $sale ? 'asking' : ($r['status'] === 'sold' ? 'sold' : 'off market') ?></span><?php endif; ?></td>
          <td class="r"><?= $r['est_profit'] !== null ? '<span class="' . ((int) $r['est_profit'] < 0 ? 'up' : 'down') . '">' . e(money_short(abs((int) $r['est_profit']))) . '</span>' : '—' ?></td>
          <td class="r"><span class="score-badge sm score-<?= $band ?>"><?= $r['score'] !== null ? (int) $r['score'] : '—' ?></span></td>
          <td><?= $r['owner_name'] ? e($r['owner_name']) : '<span class="muted">No one</span>' ?></td>
          <td class="nowrap"><?= e(ago($r['stage_at'])) ?><?= $r['stage_by'] ? '<span class="sub">by ' . e($r['stage_by']) . '</span>' : '' ?></td>
          <td>
            <?php if ($canEdit): ?>
            <form method="post" action="/property/<?= (int) $r['id'] ?>/act">
              <?= csrf_field() ?><input type="hidden" name="do" value="stage"><input type="hidden" name="back" value="/pipeline">
              <label class="sr" for="st<?= (int) $r['id'] ?>">Status</label>
              <select class="select stage" id="st<?= (int) $r['id'] ?>" name="stage" data-autosubmit>
                <?php foreach (Pipeline::STAGES as $k => $l): ?><option value="<?= $k ?>"<?= selected($r['stage'], $k) ?>><?= e($l) ?></option><?php endforeach; ?>
              </select>
            </form>
            <?php else: ?><?= e($label) ?><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endforeach; ?>
<?php
View::footer();

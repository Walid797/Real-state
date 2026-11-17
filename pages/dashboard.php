<?php
declare(strict_types=1);

use App\Address;
use App\Auth;
use App\Db;
use App\PropertyRepo;
use App\Quota;
use App\Services\Activity;
use App\Services\Pipeline;
use App\Services\Score;
use App\Settings;
use App\View;

/* The first screen: where the opportunities stand today. */

$state = Address::homeState();
$stateName = Address::STATES[$state] ?? $state;
$weekAgo = gmdate('Y-m-d', time() - 7 * 86400);
$k = [
    'homes' => (int) Db::val("SELECT COUNT(*) FROM properties WHERE status IN ('for_sale','pending')"),
    'good' => (int) Db::val("SELECT COUNT(*) FROM properties WHERE status = 'for_sale' AND score >= 70"),
    'cuts' => (int) Db::val("SELECT COUNT(DISTINCT e.property_id) FROM price_events e JOIN properties p ON p.id = e.property_id
                             WHERE e.event = 'price_change' AND e.event_date >= ? AND p.status = 'for_sale'
                               AND e.price < (SELECT MAX(e2.price) FROM price_events e2 WHERE e2.property_id = e.property_id AND e2.id <> e.id AND e2.event_date <= e.event_date)", [$weekAgo]),
    'review' => (int) Db::val("SELECT COUNT(*) FROM watchlist WHERE stage IN ('under_review','contact_agent','inspection','offer')"),
    'new' => (int) Db::val("SELECT COUNT(*) FROM properties WHERE status = 'for_sale' AND first_seen_at >= ?", [$weekAgo . ' 00:00:00']),
    'mine' => (int) Db::val("SELECT COUNT(*) FROM watchlist WHERE owner_id = ? AND stage IN ('under_review','contact_agent','inspection','offer')", [Auth::userId()]),
];
$top = Db::all(
    "SELECT p.*, w.stage, COALESCE(NULLIF(ou.name, ''), ou.email) AS owner_name FROM properties p
     LEFT JOIN watchlist w ON w.property_id = p.id LEFT JOIN users ou ON ou.id = w.owner_id
     WHERE p.status = 'for_sale' AND p.score IS NOT NULL AND (w.stage IS NULL OR w.stage NOT IN ('rejected','sold'))
     ORDER BY p.score DESC, p.est_profit DESC LIMIT 8"
);
$cuts = Db::all(
    "SELECT p.id, p.address, p.city, p.state, p.list_price, p.score, e.price, e.event_date,
            (SELECT MAX(e2.price) FROM price_events e2 WHERE e2.property_id = p.id AND e2.id <> e.id AND e2.event_date <= e.event_date) AS before_price
     FROM price_events e JOIN properties p ON p.id = e.property_id
     WHERE e.event = 'price_change' AND e.event_date >= ? AND p.status = 'for_sale'
     HAVING before_price > price ORDER BY e.event_date DESC, e.id DESC LIMIT 6",
    [gmdate('Y-m-d', time() - 21 * 86400)]
);
$stages = array_column(Db::all('SELECT stage, COUNT(*) AS n FROM watchlist GROUP BY stage'), 'n', 'stage');
$activity = Activity::recent(10);
$alertsOn = (int) Db::val('SELECT COUNT(*) FROM alerts WHERE enabled = 1 AND user_id = ?', [Auth::userId()]);
$lastRun = Settings::get('job_last_run');
$hello = Auth::name() ? explode(' ', Auth::name())[0] : '';

View::header('Dashboard', 'dashboard');
?>
<?php if (Auth::can('admin') && Settings::has('rentcast_key') && ($rcProblem = App\Providers\RentCast::problem())): ?>
<div class="callout callout-bad mb-m" role="alert"><?= icon('triangle-alert', 16) ?><div>
  <strong><?= e($rcProblem['why']) ?></strong>
  <span class="small soft">Until then searches show only homes already on file<?= App\Providers\OpenWebNinja::enabled() ? ' or come from the Zillow-data source' : '' ?>.</span>
  <a class="btn btn-sm mt-s" href="<?= e(App\Providers\RentCast::DASHBOARD) ?>" target="_blank" rel="noopener noreferrer"><?= icon('external-link', 14) ?>Open the RentCast API dashboard</a>
</div></div>
<?php endif; ?>
<header class="page-head">
  <div>
    <p class="eyebrow"><?= e(fmt_date(now(), 'l, F j')) ?></p>
    <h1 class="display"><?= $hello ? 'Hello, ' . e($hello) . '.' : 'Dashboard' ?></h1>
    <p><?= $k['good'] ? plural($k['good'], 'strong opportunity', 'strong opportunities') . ' on file' : 'No strong opportunities on file yet' ?><?= $k['mine'] ? ', ' . plural($k['mine'], 'home') . ' waiting on you' : '' ?>.</p>
  </div>
  <form class="quick-search" method="get" action="/search" role="search">
    <label class="sr" for="dq">Search an area</label>
    <?= icon('search', 16) ?><input class="input" id="dq" type="search" name="q" placeholder="<?= e($stateName) ?>, a county, a city or ZIPs" required>
    <input type="hidden" name="types[]" value="Single Family"><input type="hidden" name="types[]" value="Townhouse">
    <input type="hidden" name="sort" value="score">
    <button class="btn btn-primary" type="submit">Search</button>
  </form>
</header>

<div class="kpis">
  <a class="kpi" href="/search?<?= e(http_build_query(['q' => $stateName])) ?>"><span>Homes for sale on file</span><b><?= number_format($k['homes']) ?></b><small><?= $k['new'] ? '+' . number_format($k['new']) . ' this week' : 'none new this week' ?></small></a>
  <a class="kpi kpi-good" href="/search?<?= e(http_build_query(['q' => $stateName, 'score_min' => 70, 'sort' => 'score'])) ?>"><span>Strong opportunities</span><b><?= number_format($k['good']) ?></b><small>score 70 or more</small></a>
  <a class="kpi" href="/search?<?= e(http_build_query(['q' => $stateName, 'listing' => ['reduced'], 'sort' => 'score'])) ?>"><span>Price cuts this week</span><b><?= number_format($k['cuts']) ?></b><small>still for sale</small></a>
  <a class="kpi" href="/pipeline"><span>Under team review</span><b><?= number_format($k['review']) ?></b><small><?= $k['mine'] ? $k['mine'] . ' handled by you' : 'none handled by you' ?></small></a>
</div>

<div class="dash-grid">
  <section class="section">
    <div class="section-head"><h2>Best opportunities now</h2><a class="aside" href="/search?<?= e(http_build_query(['q' => $stateName, 'sort' => 'score'])) ?>">All, by score</a></div>
    <?php if ($top): ?>
    <ul class="plain-list opp-list">
      <?php foreach ($top as $r): $band = Score::band((int) $r['score']); ?>
      <li>
        <span class="score-badge sm score-<?= $band ?>"><?= (int) $r['score'] ?></span>
        <a class="main" href="/property/<?= (int) $r['id'] ?>"><?= e($r['address']) ?>, <?= e($r['city']) ?>
          <span class="sub"><?= e(money($r['list_price'])) ?> · <?= e(PropertyRepo::facts($r)) ?><?= $r['stage'] ? ' · ' . e(Pipeline::STAGES[$r['stage']]) . ($r['owner_name'] ? ' (' . e($r['owner_name']) . ')' : '') : '' ?></span></a>
        <span class="end"><?php if ($r['est_profit'] !== null): ?><b class="<?= (int) $r['est_profit'] < 0 ? 'up' : 'down' ?> num"><?= e(money_short(abs((int) $r['est_profit']))) ?></b><br>est. profit<?php endif; ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="blank">Search an area — every listing that comes in is scored on profit, ROI, price, ARV evidence, condition and risk.</p>
    <?php endif; ?>
  </section>

  <section class="section">
    <div class="section-head"><h2>Recent price cuts</h2></div>
    <?php if ($cuts): ?>
    <ul class="plain-list">
      <?php foreach ($cuts as $c): $d = ($c['price'] - $c['before_price']) / $c['before_price'] * 100; ?>
      <li><a class="main" href="/property/<?= (int) $c['id'] ?>"><?= e($c['address']) ?><span class="sub"><?= e($c['city']) ?> · <?= e(fmt_date($c['event_date'], 'M j')) ?></span></a>
        <span class="end"><span class="num"><?= e(money_short($c['before_price'])) ?> → <b><?= e(money_short($c['price'])) ?></b></span><br><span class="down"><?= e(pct($d)) ?></span></span></li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="blank">Price cuts show up here when an area is pulled again and an asking price has dropped.</p>
    <?php endif; ?>

    <div class="section-head mt-l"><h2>Pipeline</h2><a class="aside" href="/pipeline">Open</a></div>
    <div class="stage-mini">
      <?php foreach (Pipeline::STAGES as $key => $label): ?>
      <a href="/pipeline#st-<?= $key ?>"><span><?= e($label) ?></span><b><?= (int) ($stages[$key] ?? 0) ?></b></a>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="section">
    <div class="section-head"><h2>Team activity</h2></div>
    <?php if ($activity): ?>
    <ul class="plain-list feed">
      <?php foreach ($activity as $a): ?>
      <li><span><strong><?= e(Activity::who($a)) ?></strong> · <?= e($a['detail']) ?>
        <?php if ($a['address']): ?><a class="sub" href="/property/<?= (int) $a['property_id'] ?>"><?= e($a['address']) ?>, <?= e($a['city']) ?></a><?php endif; ?></span>
        <span class="end"><?= e(ago($a['created_at'])) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="blank">Statuses, notes and deal changes from everyone on the team appear here.</p>
    <?php endif; ?>

    <div class="section-head mt-l"><h2>Shortcuts</h2></div>
    <div class="shortcuts">
      <a href="/search"><?= icon('search', 16) ?>Search homes</a>
      <a href="/pipeline"><?= icon('kanban', 16) ?>Saved homes</a>
      <a href="/alerts"><?= icon('bell', 16) ?>Alerts<?= $alertsOn ? ' · ' . $alertsOn . ' on' : '' ?></a>
      <a href="/reports"><?= icon('file-spreadsheet', 16) ?>Reports</a>
      <?php if (Auth::can('edit')): ?><a href="/add"><?= icon('link-2', 16) ?>Add from a link</a><?php endif; ?>
      <?php if (Auth::can('admin')): ?><a href="/users"><?= icon('users', 16) ?>Team</a><?php endif; ?>
    </div>
    <?php if (Auth::can('admin')): ?>
    <p class="small muted mt-m">
      <?php foreach (Quota::PROVIDERS as $pid => $label): if (!Settings::has($pid . '_key')) { continue; } ?>
        <?= e($label) ?>: <?= Quota::left($pid) ?> requests left this month. <?php endforeach; ?>
      Scheduler <?= $lastRun ? 'ran ' . e(ago($lastRun)) : 'hasn’t run yet' ?>.
    </p>
    <?php endif; ?>
  </section>
</div>
<p class="section-note">Profit, ARV and scores are estimates from public data and your settings. They are not appraisals, inspections or advice.</p>
<?php
View::footer();

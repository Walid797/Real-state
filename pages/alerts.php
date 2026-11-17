<?php
declare(strict_types=1);

use App\Db;
use App\Notify\Notifier;
use App\Quota;
use App\Services\Alerts;
use App\Services\Search;
use App\Settings;
use App\View;

$mineOnly = !App\Auth::can('admin') || input('who') !== 'all';
$alerts = Db::all(
    'SELECT a.*, COALESCE(NULLIF(u.name, \'\'), u.email) AS owner,
            (SELECT COUNT(*) FROM alert_hits h WHERE h.alert_id = a.id AND h.notified = 1) AS sent
     FROM alerts a LEFT JOIN users u ON u.id = a.user_id' . ($mineOnly ? ' WHERE a.user_id = ' . App\Auth::userId() : '') . '
     ORDER BY a.enabled DESC, a.created_at DESC'
);
$hits = Db::all(
    'SELECT h.created_at, h.summary, h.price, a.name AS alert_name, a.id AS alert_id, p.id AS pid, p.address, p.city, p.state
     FROM alert_hits h JOIN alerts a ON a.id = h.alert_id JOIN properties p ON p.id = h.property_id
     WHERE h.notified = 1' . ($mineOnly ? ' AND a.user_id = ' . App\Auth::userId() : '') . ' ORDER BY h.created_at DESC, h.id DESC LIMIT 12'
);
$ready = Notifier::ready(Notifier::recipient(Db::one('SELECT * FROM users WHERE id = ?', [App\Auth::userId()])));
$lastRun = Settings::get('job_last_run');

// What the alerts will cost against the allowance kept for them.
$provider = Settings::has('rentcast_key') ? 'rentcast' : (Settings::has('openwebninja_key') ? 'openwebninja' : null);
$need = array_sum(array_map([Alerts::class, 'monthlyCost'], $alerts));
$limit = $provider ? Quota::limit($provider) : 0;
$forAlerts = $provider ? max(0, $limit - Quota::reserve($provider)) : 0;

View::header('Alerts', 'alerts');
?>
<header class="page-head">
  <div>
    <h1 class="display">Alerts</h1>
    <p>Tell it what you’re after. When a match turns up you get the price, the facts and the links by email, WhatsApp or Telegram.</p>
  </div>
  <div class="actions"><a class="btn btn-primary" href="/alerts/new"><?= icon('plus', 15) ?>New alert</a></div>
</header>

<?php if (!array_filter($ready)): ?>
<p class="note"><?= icon('triangle-alert', 16) ?><span>No delivery channel is set up, so alerts have nowhere to go. <a href="/account">Add your Telegram on My account</a>.</span></p>
<?php endif; ?>
<?php if ($alerts && (!$lastRun || strtotime($lastRun . ' UTC') < time() - 3 * 3600)): ?>
<p class="note mt-s"><?= icon('clock', 16) ?><span>The scheduler <?= $lastRun ? 'last ran ' . e(ago($lastRun)) : 'has never run' ?>. Alerts are only checked when it runs — <a href="/settings#schedule">see the one-line cron setup</a>.</span></p>
<?php endif; ?>

<?php if (!$alerts): ?>
  <div class="empty">
    <h2 class="display">No alerts yet.</h2>
    <p>Three kinds are worth having: a <strong>new listing</strong> that fits your budget and size, a <strong>price cut</strong> on a home you’re watching, and a <strong>new sale</strong> near a home you care about.</p>
    <p><a class="btn btn-primary" href="/alerts/new">Create the first one</a></p>
  </div>
<?php else: ?>

  <?php if ($provider && $need > 0): $over = $need > $forAlerts; ?>
  <section class="card">
    <div class="budget">
      <div>
        <h3>Monthly requests</h3>
        <p class="small <?= $over ? 'up' : 'muted' ?>">
          Your alerts need about <?= $need ?> <?= e(Quota::PROVIDERS[$provider]) ?> requests a month; <?= $forAlerts ?> of <?= $limit ?> are set aside for them.
          <?= $over ? 'Check less often, or the later ones each month will fall back to saved data.' : 'That fits.' ?>
        </p>
      </div>
      <a class="btn btn-sm" href="/settings#sources">Change the split</a>
      <svg viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true">
        <rect class="t" width="100" height="8"/>
        <rect class="<?= $over ? 'over' : 'a' ?>" width="<?= $limit ? min(100, round($need / $limit * 100)) : 0 ?>" height="8"/>
      </svg>
      <span></span>
    </div>
  </section>
  <?php endif; ?>

  <section class="section">
    <div class="section-head"><h2>Your alerts</h2><span class="aside"><?= $lastRun ? 'scheduler ran ' . e(ago($lastRun)) : '' ?></span></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>On</th><th>Alert</th><th>Checks</th><th>Sends to</th><th>Last check</th><th class="r">Sent</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($alerts as $a):
            $crit = json_decode((string) $a['criteria'], true) ?: [];
            $desc = Search::describe($crit + ['q' => $a['location'], 'mode' => $a['kind'] === 'new_sale' ? 'sold' : 'sale', 'sold_days' => 90]);
            if ($a['kind'] === 'price_drop') {
                $desc = trim(($a['location'] !== '' ? $a['location'] . ' · ' : '') . 'pipeline homes' . (!empty($crit['min_drop_pct']) ? ' · cut of ' . trim_num($crit['min_drop_pct']) . '% or more' : ''));
            }
        ?>
          <tr>
            <td>
              <form method="post" action="/alerts/<?= (int) $a['id'] ?>/act">
                <?= csrf_field() ?><input type="hidden" name="do" value="toggle">
                <button class="toggle" type="submit" aria-pressed="<?= $a['enabled'] ? 'true' : 'false' ?>" title="<?= $a['enabled'] ? 'On — click to pause' : 'Paused — click to turn on' ?>"><span class="sr"><?= $a['enabled'] ? 'Pause' : 'Turn on' ?> <?= e($a['name']) ?></span></button>
              </form>
            </td>
            <td><a class="main" href="/alerts/<?= (int) $a['id'] ?>"><?= e($a['name']) ?></a>
              <span class="sub"><?= e(Alerts::KINDS[$a['kind']]) ?> · <?= e($desc) ?><?= !$mineOnly ? ' · ' . e((string) $a['owner']) : '' ?><?= $a['delivery'] === 'daily' ? ' · daily summary' : '' ?></span></td>
            <td class="nowrap"><?= e(Alerts::FREQUENCIES[(int) $a['every_hours']] ?? 'Every ' . (int) $a['every_hours'] . 'h') ?>
              <span class="sub"><?= $a['use_quota'] && $a['location'] !== '' ? '≈ ' . Alerts::monthlyCost(['enabled' => 1] + $a) . ' requests/mo' : 'saved data only' ?></span></td>
            <td><?php foreach (array_filter(explode(',', $a['channels'])) as $ch): ?>
              <span class="tag<?= empty($ready[$ch]) ? ' tag-bad' : '' ?>" title="<?= empty($ready[$ch]) ? 'Not set up yet' : '' ?>"><?= e(Notifier::CHANNELS[$ch] ?? $ch) ?></span>
            <?php endforeach; ?></td>
            <td><?= $a['last_checked_at'] ? e(ago($a['last_checked_at'])) : '<span class="muted">Not yet</span>' ?>
              <?php if ($a['last_note']): ?><span class="sub"><?= e($a['last_note']) ?></span><?php endif; ?></td>
            <td class="r"><?= (int) $a['sent'] ?></td>
            <td class="r nowrap">
              <form method="post" action="/alerts/<?= (int) $a['id'] ?>/act" class="inline">
                <?= csrf_field() ?><input type="hidden" name="do" value="run">
                <button class="linkbtn" type="submit">Check now</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="section">
    <div class="section-head"><h2>Recently sent</h2></div>
    <?php if (!$hits): ?>
      <p class="blank">Nothing sent yet. The first check of each alert only notes what already matches; you hear about what comes after.</p>
    <?php else: ?>
    <ul class="plain-list">
      <?php foreach ($hits as $h): ?>
      <li>
        <a class="main" href="/property/<?= (int) $h['pid'] ?>"><?= e($h['address']) ?>, <?= e($h['city']) ?>
          <span class="sub"><?= e($h['summary']) ?> · <?= e($h['alert_name']) ?></span></a>
        <span class="end"><span class="num"><?= e(money($h['price'])) ?></span><br><?= e(ago($h['created_at'])) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php
View::footer();

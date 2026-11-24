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
    <h1 class="display"><?= te('Alerts') ?><?= tip_open() ?><?= te('Tell it what you’re after. When a match turns up you get the price, the facts and the links by email, WhatsApp or Telegram.') ?><?= tip_close() ?></h1>
  </div>
  <div class="actions"><a class="btn btn-primary" href="/alerts/new"><?= icon('plus', 15) ?><?= te('New alert') ?></a></div>
</header>

<?php if (!array_filter($ready)): ?>
<p class="note"><?= icon('triangle-alert', 16) ?><span><?= te('No delivery channel is set up, so alerts have nowhere to go.') ?> <a href="/account"><?= te('Add your Telegram on My account') ?></a>.</span></p>
<?php endif; ?>
<?php if ($alerts && (!$lastRun || strtotime($lastRun . ' UTC') < time() - 3 * 3600)): ?>
<p class="note mt-s"><?= icon('clock', 16) ?><span><?= te('The scheduler') ?> <?= $lastRun ? 'last ran ' . e(ago($lastRun)) : 'has never run' ?><?= te('. Alerts are only checked when it runs —') ?> <a href="/settings#schedule"><?= te('see the one-line cron setup') ?></a>.</span></p>
<?php endif; ?>

<?php if (!$alerts): ?>
  <div class="empty">
    <?= View::sketch('alerts-empty') ?>
    <h2 class="display"><?= te('No alerts yet.') ?></h2>
    <p><?= te('Three kinds are worth having: a') ?> <strong><?= te('new listing') ?></strong> <?= te('that fits your budget and size, a') ?> <strong><?= te('price cut') ?></strong> <?= te('on a home you’re watching, and a') ?> <strong><?= te('new sale') ?></strong> <?= te('near a home you care about.') ?></p>
    <p><a class="btn btn-primary" href="/alerts/new"><?= te('Create the first one') ?></a></p>
  </div>
<?php else: ?>

  <?php if ($provider && $need > 0): $over = $need > $forAlerts; ?>
  <section class="card">
    <div class="budget">
      <div>
        <h3><?= te('Monthly requests') ?></h3>
        <p class="small <?= $over ? 'up' : 'muted' ?>">
          <?= te('Your alerts need about') ?> <?= $need ?> <?= e(Quota::PROVIDERS[$provider]) ?> <?= te('requests a month;') ?> <?= $forAlerts ?> <?= te('of') ?> <?= $limit ?> <?= te('are set aside for them.') ?>
          <?= te($over ? 'Check less often, or the later ones each month will fall back to saved data.' : 'That fits.') ?>
        </p>
      </div>
      <a class="btn btn-sm" href="/settings#sources"><?= te('Change the split') ?></a>
      <svg viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true">
        <rect class="t" width="100" height="8"/>
        <rect class="<?= $over ? 'over' : 'a' ?>" width="<?= $limit ? min(100, round($need / $limit * 100)) : 0 ?>" height="8"/>
      </svg>
      <span></span>
    </div>
  </section>
  <?php endif; ?>

  <section class="section">
    <div class="section-head"><h2><?= te('Your alerts') ?></h2><span class="aside"><?= $lastRun ? 'scheduler ran ' . e(ago($lastRun)) : '' ?></span></div>
    <?php $bulk = ['type' => 'alerts', 'form' => 'bulk-alerts', 'back' => '/alerts', 'all' => App\Auth::can('admin') ? 'Delete all alerts' : 'Delete all my alerts', 'count' => count($alerts)]; include APP_ROOT . '/pages/partials/bulk_bar.php'; ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th><?= te('On') ?></th><th><?= te('Alert') ?></th><th><?= te('Checks') ?></th><th><?= te('Sends to') ?></th><th><?= te('Last check') ?></th><th class="r"><?= te('Sent') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($alerts as $a):
            $crit = json_decode((string) $a['criteria'], true) ?: [];
            $desc = Search::describe($crit + ['q' => $a['location'], 'mode' => $a['kind'] === 'new_sale' ? 'sold' : 'sale', 'sold_days' => 90]);
            if ($a['kind'] === 'price_drop') {
                $desc = trim(($a['location'] !== '' ? $a['location'] . ' · ' : '') . 'favorite homes' . (!empty($crit['min_drop_pct']) ? ' · cut of ' . trim_num($crit['min_drop_pct']) . '% or more' : ''));
            }
        ?>
          <tr>
            <td>
              <input class="bulk-pick" type="checkbox" name="ids[]" value="<?= (int) $a['id'] ?>" form="bulk-alerts" aria-label="<?= te('Select') ?> <?= e($a['name']) ?>">
              <form method="post" action="/alerts/<?= (int) $a['id'] ?>/act" class="inline">
                <?= csrf_field() ?><input type="hidden" name="do" value="toggle">
                <button class="toggle" type="submit" aria-pressed="<?= $a['enabled'] ? 'true' : 'false' ?>" title="<?= te($a['enabled'] ? 'On — click to pause' : 'Paused — click to turn on') ?>"><span class="sr"><?= te($a['enabled'] ? 'Pause' : 'Turn on') ?> <?= e($a['name']) ?></span></button>
              </form>
            </td>
            <td><a class="main" href="/alerts/<?= (int) $a['id'] ?>"><?= e($a['name']) ?></a>
              <span class="sub"><?= te(Alerts::KINDS[$a['kind']]) ?> · <?= e($desc) ?><?= !$mineOnly ? ' · ' . e((string) $a['owner']) : '' ?><?= $a['delivery'] === 'daily' ? ' · ' . te('Daily summary') : '' ?></span></td>
            <td class="nowrap"><?= te(Alerts::FREQUENCIES[(int) $a['every_hours']] ?? t('Every %dh', (int) $a['every_hours'])) ?>
              <span class="sub"><?= $a['use_quota'] && $a['location'] !== '' ? te('≈ %d requests/mo', Alerts::monthlyCost(['enabled' => 1] + $a)) : te('saved data only') ?></span></td>
            <td><?php foreach (array_filter(explode(',', $a['channels'])) as $ch): ?>
              <span class="tag<?= empty($ready[$ch]) ? ' tag-bad' : '' ?>" title="<?= empty($ready[$ch]) ? te('Not set up yet') : '' ?>"><?= te(Notifier::CHANNELS[$ch] ?? $ch) ?></span>
            <?php endforeach; ?></td>
            <td><?= $a['last_checked_at'] ? e(ago($a['last_checked_at'])) : '<span class="muted">' . te('Not yet') . '</span>' ?>
              <?php if ($a['last_note']): ?><span class="sub"><?= e($a['last_note']) ?></span><?php endif; ?></td>
            <td class="r"><?= (int) $a['sent'] ?></td>
            <td class="r nowrap">
              <form method="post" action="/alerts/<?= (int) $a['id'] ?>/act" class="inline">
                <?= csrf_field() ?><input type="hidden" name="do" value="run">
                <button class="linkbtn" type="submit"><?= te('Check now') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="section">
    <div class="section-head"><h2><?= te('Recently sent') ?></h2></div>
    <?php if (!$hits): ?>
      <p class="blank"><?= te('Nothing sent yet. The first check of each alert only notes what already matches; you hear about what comes after.') ?></p>
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

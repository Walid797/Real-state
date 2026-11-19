<?php
declare(strict_types=1);

use App\Address;
use App\Db;
use App\Notify\Notifier;
use App\PropertyRepo;
use App\Services\Alerts;
use App\Services\Search;
use App\View;

$id = isset($params['id']) ? (int) $params['id'] : 0;
$alert = $id ? Db::one('SELECT * FROM alerts WHERE id = ?', [$id]) : null;
// Alerts are personal; admins may open anyone's to help.
if ($alert && (int) $alert['user_id'] !== App\Auth::userId() && !App\Auth::can('admin')) {
    $alert = null;
    $id = 0;
}
$owner = $alert && $alert['user_id'] ? Db::one('SELECT * FROM users WHERE id = ?', [$alert['user_id']]) : Db::one('SELECT * FROM users WHERE id = ?', [App\Auth::userId()]);
$ready = Notifier::ready(Notifier::recipient($owner));
if ($id && !$alert) {
    redirect('/alerts');
}

$crit = $alert ? (json_decode((string) $alert['criteria'], true) ?: []) : Search::compact(Search::fromRequest($_GET));
if (!$alert && empty($crit['types']) && !isset($_GET['q'])) {
    $crit['types'] = ['Single Family', 'Townhouse'];
}
$form = [
    'name' => $alert['name'] ?? '',
    'kind' => $alert['kind'] ?? (isset(Alerts::KINDS[$_GET['kind'] ?? '']) ? $_GET['kind'] : 'new_listing'),
    'location' => $alert['location'] ?? input('q', '', 190),
    'every_hours' => (int) ($alert['every_hours'] ?? 24),
    'channels' => $alert ? array_filter(explode(',', $alert['channels'])) : array_keys(array_filter($ready)),
    'delivery' => $alert['delivery'] ?? 'instant',
    'use_quota' => (int) ($alert['use_quota'] ?? 1),
    'enabled' => (int) ($alert['enabled'] ?? 1),
];
$errors = [];

if (is_post()) {
    $form['name'] = input('name', '', 120);
    $form['kind'] = isset(Alerts::KINDS[input('kind')]) ? input('kind') : 'new_listing';
    $form['location'] = input('location', '', 190);
    $form['every_hours'] = isset(Alerts::FREQUENCIES[(int) input('every_hours')]) ? (int) input('every_hours') : 24;
    $form['delivery'] = input('delivery') === 'daily' ? 'daily' : 'instant';
    $form['channels'] = array_values(array_intersect(array_keys(Notifier::CHANNELS), (array) ($_POST['channels'] ?? [])));
    $form['use_quota'] = isset($_POST['use_quota']) ? 1 : 0;
    $form['enabled'] = isset($_POST['enabled']) ? 1 : 0;

    $crit = Search::compact(Search::fromRequest($_POST));
    unset($crit['q'], $crit['mode'], $crit['sort'], $crit['sold_days']);
    $drop = parse_num(input('min_drop_pct'));
    if ($form['kind'] === 'price_drop') {
        $crit = $drop ? ['min_drop_pct' => min(90, $drop)] : [];
    }
    if ($form['kind'] !== 'new_listing') {
        unset($crit['below_median']);
    }

    $loc = Address::parseLocation($form['location']);
    if ($form['kind'] !== 'price_drop' && (!$loc || $loc['type'] === 'address')) {
        $errors[] = 'Give the area as a city and state (Austin, TX) or a ZIP code.';
    }
    if ($form['kind'] === 'price_drop' && $form['location'] !== '' && (!$loc || $loc['type'] === 'address')) {
        $errors[] = 'The area should be a city and state or a ZIP — or leave it empty to cover the whole watchlist.';
    }
    if (!$form['channels']) {
        $errors[] = 'Pick at least one place to send the alert.';
    }
    if ($form['name'] === '') {
        $form['name'] = $form['kind'] === 'price_drop'
            ? 'Price cuts' . ($loc ? ' in ' . $loc['label'] : ' on my watchlist')
            : mb_substr(Search::describe($crit + ['q' => $form['location']]), 0, 110);
    }

    if (!$errors) {
        $data = [
            'name' => $form['name'], 'kind' => $form['kind'], 'location' => $loc['label'] ?? '',
            'criteria' => json_encode($crit), 'every_hours' => $form['every_hours'],
            'channels' => implode(',', $form['channels']), 'delivery' => $form['delivery'], 'enabled' => $form['enabled'], 'use_quota' => $form['use_quota'],
        ];
        if ($alert) {
            // Changed criteria → start from a fresh baseline so old matches are not re-announced as new.
            if ($alert['criteria'] !== $data['criteria'] || $alert['location'] !== $data['location'] || $alert['kind'] !== $data['kind']) {
                Db::run('DELETE FROM alert_hits WHERE alert_id = ?', [$id]);
                $data['last_checked_at'] = null;
                $data['last_note'] = null;
            }
            Db::update('alerts', $data, $id);
            flash('Alert saved.');
        } else {
            $id = Db::insert('alerts', $data + ['user_id' => App\Auth::userId(), 'created_at' => now()]);
            $first = Alerts::runOne(Db::one('SELECT * FROM alerts WHERE id = ?', [$id]), 'manual', false);
            flash('Alert is on. ' . $first['note']);
        }
        redirect('/alerts');
    }
}

$history = $alert ? Db::all(
    'SELECT h.created_at, h.summary, h.price, h.notified, p.id AS pid, p.address, p.city, p.state
     FROM alert_hits h JOIN properties p ON p.id = h.property_id WHERE h.alert_id = ? AND h.notified = 1
     ORDER BY h.created_at DESC, h.id DESC LIMIT 40',
    [$id]
) : [];
$v = static fn (string $k) => isset($crit[$k]) ? e(is_float($crit[$k]) && $crit[$k] >= 1000 ? number_format($crit[$k]) : (string) $crit[$k]) : '';

View::header($alert ? $alert['name'] : 'New alert', 'alerts');
?>
<nav class="crumbs"><a href="/alerts"><?= icon('arrow-left', 14) ?><?= te('Alerts') ?></a></nav>
<div class="narrow">
  <header class="page-head">
    <div>
      <h1 class="display"><?= $alert ? e($alert['name']) : 'New alert' ?></h1>
      <?php if ($alert): ?><p><?= te('Created') ?> <?= e(fmt_date($alert['created_at'])) ?> · <?= $alert['last_checked_at'] ? 'last checked ' . e(ago($alert['last_checked_at'])) : 'not checked yet' ?></p><?php endif; ?>
    </div>
  </header>

  <?php foreach ($errors as $err): ?><p class="flash flash-err" role="alert"><?= te($err) ?></p><?php endforeach; ?>

  <form method="post" action="<?= $alert ? '/alerts/' . $id : '/alerts/new' ?>">
    <?= csrf_field() ?>
    <div class="form-grid">
      <label class="field c6"><span><?= te('Tell me about') ?></span>
        <select class="select" name="kind" data-kind-switch>
          <?php foreach (Alerts::KINDS as $k => $label): ?><option value="<?= $k ?>"<?= selected($form['kind'], $k) ?>><?= te($label) ?></option><?php endforeach; ?>
        </select></label>
      <label class="field c6"><span><?= te('Area') ?></span>
        <input class="input" name="location" value="<?= e($form['location']) ?>" placeholder="Virginia · Fairfax County · Richmond · 22030, 20176">
        <small data-kind="price_drop"><?= te('Optional. Empty covers every home in the pipeline.') ?></small></label>

      <?php foreach ([['Price ($)', 'price'], ['Bedrooms', 'beds'], ['Bathrooms', 'baths'], ['Living area (sqft)', 'sqft'], ['Year built', 'year'], ['Lot (sqft)', 'lot']] as [$label, $k]): ?>
      <div class="field c4" data-kind="new_listing new_sale"><span><?= te($label) ?></span><div class="pair">
        <input class="input" name="<?= $k ?>_min" inputmode="decimal" placeholder="<?= te('Min') ?>" value="<?= $v($k . '_min') ?>" aria-label="<?= te($label) ?> <?= te('minimum') ?>">
        <input class="input" name="<?= $k ?>_max" inputmode="decimal" placeholder="<?= te('Max') ?>" value="<?= $v($k . '_max') ?>" aria-label="<?= te($label) ?> <?= te('maximum') ?>"></div></div>
      <?php endforeach; ?>
      <label class="field c4" data-kind="new_listing"><span><?= te('HOA up to, $/mo') ?></span><input class="input" name="hoa_max" inputmode="numeric" value="<?= $v('hoa_max') ?>" placeholder="<?= te('0 = none') ?>"></label>
      <label class="field c4" data-kind="new_listing"><span><?= te('Opportunity score, at least') ?></span><input class="input" name="score_min" inputmode="numeric" value="<?= $v('score_min') ?>" placeholder="e.g. 60"></label>
      <label class="field c4" data-kind="new_listing"><span><?= te('Est. profit, at least ($)') ?></span><input class="input" name="profit_min" inputmode="numeric" value="<?= $v('profit_min') ?>" placeholder="e.g. 35,000"></label>

      <div class="field c12" data-kind="new_listing"><span><?= te('Listing status') ?></span>
        <div class="actions">
          <?php foreach (PropertyRepo::LISTING as $k => $label): ?>
          <label class="check"><input type="checkbox" name="listing[]" value="<?= $k ?>"<?= checked(in_array($k, (array) ($crit['listing'] ?? []), true)) ?>><?= te($label) ?></label>
          <?php endforeach; ?>
        </div><small><?= te('None ticked = any active listing.') ?></small></div>
      <div class="field c12" data-kind="new_listing"><span><?= te('Condition') ?></span>
        <div class="actions">
          <?php foreach (App\Services\Condition::OVERALL as $k => $label): ?>
          <label class="check"><input type="checkbox" name="cond[]" value="<?= $k ?>"<?= checked(in_array($k, (array) ($crit['cond'] ?? []), true)) ?>><?= te($label) ?></label>
          <?php endforeach; ?>
        </div><small><?= te('None ticked = any condition.') ?></small></div>

      <div class="field c12" data-kind="new_listing new_sale"><span><?= te('Property type') ?></span>
        <div class="actions">
          <?php foreach (PropertyRepo::TYPES as $t): ?>
          <label class="check"><input type="checkbox" name="types[]" value="<?= e($t) ?>"<?= checked(in_array($t, (array) ($crit['types'] ?? []), true)) ?>><?= te($t) ?></label>
          <?php endforeach; ?>
        </div>
        <small><?= te('None ticked means any type.') ?></small></div>

      <label class="field c6" data-kind="new_listing"><span><?= te('Only if priced under the area by') ?></span>
        <input class="input" name="below_median" inputmode="decimal" value="<?= $v('below_median') ?>" placeholder="e.g. 10 (%)">
        <small><?= te('Compares the home’s $/sqft with the median of everything for sale in the area. Leave empty to skip.') ?></small></label>
      <label class="field c6" data-kind="price_drop"><span><?= te('Only cuts of at least') ?></span>
        <input class="input" name="min_drop_pct" inputmode="decimal" value="<?= e((string) ($crit['min_drop_pct'] ?? '')) ?>" placeholder="e.g. 3 (%)">
        <small><?= te('Leave empty to hear about every cut.') ?></small></label>

      <label class="field c6"><span><?= te('Check') ?></span>
        <select class="select" name="every_hours">
          <?php foreach (Alerts::FREQUENCIES as $h => $label): ?><option value="<?= $h ?>"<?= selected($form['every_hours'], $h) ?>><?= te($label) ?> <?= te('— about') ?> <?= (int) ceil(720 / $h) ?> <?= te('requests a month') ?></option><?php endforeach; ?>
        </select></label>
      <div class="field c6"><span><?= te('When') ?></span>
        <span class="seg" role="group" aria-label="<?= te('When to send') ?>">
          <label><input type="radio" name="delivery" value="instant"<?= checked($form['delivery'] !== 'daily') ?>><span><?= te('As soon as found') ?></span></label>
          <label><input type="radio" name="delivery" value="daily"<?= checked($form['delivery'] === 'daily') ?>><span><?= te('Daily summary') ?></span></label>
        </span></div>
      <div class="field c6"><span><?= te('Send to') ?></span>
        <div class="actions">
          <?php foreach (Notifier::CHANNELS as $ch => $label): ?>
          <label class="check<?= empty($ready[$ch]) ? ' is-off' : '' ?>"><input type="checkbox" name="channels[]" value="<?= $ch ?>"<?= checked(in_array($ch, $form['channels'], true) && !empty($ready[$ch])) ?><?= empty($ready[$ch]) ? ' disabled' : '' ?>><?= te($label) ?></label>
          <?php endforeach; ?>
        </div>
        <?php if (in_array(false, $ready, true)): ?><small><?= te('Greyed-out ones aren’t set up.') ?> <a href="/account"><?= te('Add your Telegram') ?></a> <?= te('· email needs an admin to set up the mailbox.') ?></small><?php endif; ?></div>

      <div class="field c12">
        <label class="check"><input type="checkbox" name="use_quota"<?= checked($form['use_quota']) ?>><?= te('Pull fresh data for this area on each check') ?></label>
        <small><?= te('Uses one request per check. Unticked, the alert only looks at what your own searches have already saved.') ?></small>
      </div>
      <div class="field c12">
        <label class="check"><input type="checkbox" name="enabled"<?= checked($form['enabled']) ?>><?= te('Alert is on') ?></label>
      </div>
      <label class="field c12"><span><?= te('Name') ?></span>
        <input class="input" name="name" value="<?= e($form['name']) ?>" placeholder="<?= te('Leave empty and it names itself') ?>"></label>
    </div>

    <div class="form-foot">
      <button class="btn btn-primary" type="submit"><?= te($alert ? 'Save changes' : 'Create alert') ?></button>
      <a class="btn btn-ghost" href="/alerts"><?= te('Cancel') ?></a>
    </div>
  </form>

  <?php if ($alert): ?>
  <section class="section">
    <div class="section-head"><h2><?= te('What it has sent') ?></h2>
      <form method="post" action="/alerts/<?= $id ?>/act" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="run"><button class="linkbtn" type="submit"><?= te('Check now') ?></button></form></div>
    <?php if (!$history): ?>
      <p class="blank"><?= e($alert['last_note'] ?: 'Nothing yet.') ?></p>
    <?php else: ?>
    <ul class="plain-list">
      <?php foreach ($history as $h): ?>
      <li><a class="main" href="/property/<?= (int) $h['pid'] ?>"><?= e($h['address']) ?>, <?= e($h['city']) ?><span class="sub"><?= e($h['summary']) ?></span></a>
        <span class="end"><span class="num"><?= e(money($h['price'])) ?></span><br><?= e(fmt_date($h['created_at'], 'M j, g:i a')) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>

  <section class="section">
    <div class="section-head"><h2><?= te('Remove') ?></h2></div>
    <form method="post" action="/alerts/<?= $id ?>/act" class="mt-m" data-confirm="<?= te('Delete this alert and its history?') ?>">
      <?= csrf_field() ?><input type="hidden" name="do" value="delete">
      <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash-2', 14) ?><?= te('Delete this alert') ?></button>
    </form>
  </section>
  <?php endif; ?>
</div>
<?php
View::footer();

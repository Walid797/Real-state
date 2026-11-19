<?php
declare(strict_types=1);

use App\Address;
use App\Db;
use App\Providers\RentCast;
use App\Providers\ZillowResearch;
use App\Quota;
use App\View;

/* ── Actions ─────────────────────────────────────────────────────────── */
if (is_post()) {
    $do = input('do');
    if ($do === 'add') {
        $loc = Address::parseLocation(input('q', '', 190));
        // Zillow Research publishes series per ZIP and per city — not for a whole state, a county or several areas at once.
        if (!$loc || !in_array($loc['type'], ['zip', 'city'], true) || empty($loc[$loc['type']])) {
            flash($loc && in_array($loc['type'], ['state', 'county', 'multi'], true)
                ? 'Market trends work one city or one ZIP at a time (for example Richmond, VA or 23220). Add each one you want to follow.'
                : 'Type a ZIP code, or a city with its state (Richmond, VA).', 'err');
            redirect('/market');
        }
        $kind = $loc['type'] === 'zip' ? 'zip' : 'city';
        $name = $kind === 'zip' ? $loc['zip'] : $loc['city'];
        $state = $loc['state'] ?? '';
        $rid = (int) Db::val('SELECT id FROM market_regions WHERE kind = ? AND name = ? AND state = ?', [$kind, $name, $state]);
        if (!$rid) {
            $rid = Db::insert('market_regions', ['kind' => $kind, 'name' => $name, 'state' => $state, 'added_at' => now()]);
        }
        @set_time_limit(300);
        $r = ZillowResearch::import($rid);
        if ($r['points'] > 0) {
            flash($loc['label'] . ' added — ' . number_format($r['points']) . ' monthly figures from Zillow Research.');
        } else {
            flash($loc['label'] . ' added, but Zillow Research has no series under that name' . ($r['errors'] ? ' (' . $r['errors'][0] . ')' : '') . '. Check the spelling, or try the ZIP.', 'warn');
        }
        redirect('/market/' . $rid);
    }
    $rid = (int) input('id');
    $region = Db::one('SELECT * FROM market_regions WHERE id = ?', [$rid]);
    if (!$region) {
        redirect('/market');
    }
    if ($do === 'remove') {
        Db::run('DELETE FROM market_regions WHERE id = ?', [$rid]);
        flash('Stopped tracking ' . $region['name'] . '.');
        redirect('/market');
    }
    if ($do === 'import') {
        @set_time_limit(300);
        $r = ZillowResearch::import($rid);
        flash($r['errors'] ? $r['errors'][0] : 'Updated — ' . number_format($r['points']) . ' monthly figures.', $r['errors'] ? 'warn' : 'ok');
    }
    if ($do === 'rentcast' && $region['kind'] === 'zip') {
        $r = RentCast::market($region['name']);
        if (!$r['ok']) {
            flash($r['error'], 'err');
        } else {
            $current = $r['now'];
            unset($current['history'], $current['dataByPropertyType'], $current['dataByBedrooms']);
            $n = ZillowResearch::storeRentCast($rid, $r['history'], $current);
            flash('Listing statistics added — ' . $n . ' figures.');
        }
    }
    redirect('/market/' . $rid);
}

$series = static function (int $rid): array {
    $out = [];
    foreach (Db::all('SELECT metric, period, value FROM market_series WHERE region_id = ? ORDER BY period', [$rid]) as $r) {
        $out[$r['metric']][$r['period']] = (float) $r['value'];
    }
    return $out;
};
/** Latest value and change over 12 months for one series. */
$summary = static function (array $s): ?array {
    if (!$s) {
        return null;
    }
    $dates = array_keys($s);
    $last = end($dates);
    $yearAgo = date('Y-m-01', strtotime($last . ' -12 months'));
    $yoy = isset($s[$yearAgo]) && $s[$yearAgo] > 0 ? ($s[$last] - $s[$yearAgo]) / $s[$yearAgo] * 100 : null;
    return ['value' => $s[$last], 'date' => $last, 'yoy' => $yoy];
};

/* ── One area ────────────────────────────────────────────────────────── */
if (isset($params['id'])) {
    $region = Db::one('SELECT * FROM market_regions WHERE id = ?', [(int) $params['id']]);
    if (!$region) {
        redirect('/market');
    }
    $rid = (int) $region['id'];
    $label = $region['kind'] === 'zip' ? $region['name'] : $region['name'] . ', ' . $region['state'];
    $data = $series($rid);
    $charts = [
        'value' => ['Home values', 'usd', array_intersect_key($data, array_flip(['zhvi', 'median_sale', 'median_list']))],
        'ppsf' => ['Asking price per sqft', 'usd', array_intersect_key($data, array_flip(['median_ppsf']))],
        'supply' => ['Supply', 'num', array_intersect_key($data, array_flip(['inventory', 'listings']))],
        'speed' => ['Speed', 'num', array_intersect_key($data, array_flip(['days_pending', 'median_dom']))],
    ];
    $zh = $summary($data['zhvi'] ?? []);

    View::header(t('%s market', $label), 'market');
    View::script('/assets/vendor/chart.umd.js');
    ?>
    <nav class="crumbs"><a href="/market"><?= icon('arrow-left', 14) ?><?= te('Market') ?></a></nav>
    <header class="page-head">
      <div>
        <h1 class="display"><?= e($label) ?></h1>
        <p><?php if ($zh): ?><?= te('Typical home value') ?> <strong><?= e(money($zh['value'])) ?></strong> in <?= e(fmt_date($zh['date'], 'F Y')) ?><?php if ($zh['yoy'] !== null): ?>, <span class="<?= $zh['yoy'] >= 0 ? 'up' : 'down' ?>"><?= e(pct($zh['yoy'])) ?></span> <?= te('over twelve months') ?><?php endif; ?>.<?php else: ?><?= te('No series on file yet.') ?><?php endif; ?></p>
      </div>
      <div class="actions">
        <a class="btn btn-sm" href="<?= e(url('/search', ['q' => $label])) ?>"><?= icon('search', 14) ?><?= te('Homes for sale') ?></a>
        <form method="post" action="/market" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="import"><input type="hidden" name="id" value="<?= $rid ?>">
          <button class="btn btn-sm" type="submit"><?= icon('refresh-cw', 14) ?><?= te('Update from Zillow') ?><span class="cost"><?= te('free') ?></span></button></form>
        <?php if ($region['kind'] === 'zip' && RentCast::enabled()): ?>
        <form method="post" action="/market" class="inline" data-confirm="<?= te('Pull listing statistics for this ZIP? It uses one RentCast request.') ?>"><?= csrf_field() ?><input type="hidden" name="do" value="rentcast"><input type="hidden" name="id" value="<?= $rid ?>">
          <button class="btn btn-sm" type="submit"<?= Quota::can('rentcast') ? '' : ' disabled' ?>><?= te('Listing statistics') ?><span class="cost"><?= te('1 request') ?></span></button></form>
        <?php endif; ?>
      </div>
    </header>

    <?php if (!$data): ?>
      <div class="empty">
        <h2 class="display"><?= te('Nothing came back for') ?> <?= e($label) ?>.</h2>
        <p><?= te('Zillow Research names areas its own way. A ZIP code is the surest match; for a city use the exact name Zillow uses.') ?></p>
      </div>
    <?php endif; ?>

    <?php foreach ($charts as $key => [$title, $format, $set]):
        if (!$set) { continue; }
        $labels = [];
        foreach ($set as $s) { $labels = array_merge($labels, array_keys($s)); }
        $labels = array_values(array_unique($labels));
        sort($labels);
        $labels = array_slice($labels, -84);
        $payload = ['format' => $format, 'labels' => array_map(static fn ($d) => fmt_date($d, 'M Y'), $labels), 'series' => []];
        foreach ($set as $metric => $s) {
            $payload['series'][] = ['name' => ZillowResearch::LABELS[$metric] ?? $metric, 'data' => array_map(static fn ($d) => $s[$d] ?? null, $labels)];
        }
    ?>
    <section class="section">
      <div class="section-head"><h2><?= e($title) ?></h2><span class="aside"><?= e(fmt_date($labels[0], 'M Y')) ?> – <?= e(fmt_date(end($labels), 'M Y')) ?></span></div>
      <div class="chart-box"><canvas data-chart="chart-<?= $key ?>" role="img" aria-label="<?= e($title) ?> <?= te('over time') ?>"></canvas></div>
      <script type="application/json" id="chart-<?= $key ?>"><?= json_embed($payload) ?></script>
    </section>
    <?php endforeach; ?>

    <?php if ($data): ?>
    <section class="section">
      <div class="section-head"><h2><?= te('Latest figures') ?></h2></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th><?= te('Measure') ?></th><th><?= te('Month') ?></th><th class="r"><?= te('Value') ?></th><th class="r"><?= te('12-month change') ?></th></tr></thead>
          <tbody>
          <?php foreach ($data as $metric => $s): $m = $summary($s); $usd = in_array($metric, ['zhvi', 'median_sale', 'median_list', 'median_ppsf'], true); ?>
            <tr><td><?= e(ZillowResearch::LABELS[$metric] ?? $metric) ?></td><td><?= e(fmt_date($m['date'], 'M Y')) ?></td>
              <td class="r"><?= $usd ? e(money($m['value'])) : e(num($m['value'])) ?></td>
              <td class="r"><?= $m['yoy'] !== null ? e(pct($m['yoy'])) : '—' ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="section-note"><?= te('Home values are Zillow’s Home Value Index (typical home, smoothed, seasonally adjusted). Metro figures cover the whole metropolitan area named after this city. Listing statistics come from RentCast and describe asking prices, not sales.') ?></p>
    </section>
    <?php endif; ?>

    <form method="post" action="/market" class="mt-l" data-confirm="<?= te('Stop tracking this area and delete its figures?') ?>">
      <?= csrf_field() ?><input type="hidden" name="do" value="remove"><input type="hidden" name="id" value="<?= $rid ?>">
      <button class="linkbtn danger small" type="submit"><?= te('Stop tracking') ?> <?= e($label) ?></button>
    </form>
    <?php
    View::footer();
    return;
}

/* ── All tracked areas ───────────────────────────────────────────────── */
$regions = Db::all('SELECT * FROM market_regions ORDER BY added_at DESC');

View::header('Market', 'market');
?>
<header class="page-head">
  <div>
    <h1 class="display"><?= te('Market') ?></h1>
    <p><?= te('Follow a ZIP or a city month by month: what a typical home is worth, how fast homes go, how many are for sale.') ?></p>
  </div>
  <form class="actions" method="post" action="/market">
    <?= csrf_field() ?><input type="hidden" name="do" value="add">
    <label><span class="sr"><?= te('ZIP or city') ?></span><input class="input" name="q" placeholder="33602 or Tampa, FL" required></label>
    <button class="btn btn-primary" type="submit"><?= icon('plus', 15) ?><?= te('Track it') ?></button>
  </form>
</header>

<?php if (!$regions): ?>
  <div class="empty">
    <?= View::sketch('market-empty') ?>
    <h2 class="display"><?= te('No areas tracked.') ?></h2>
    <p><?= te('Add one above. The numbers come from Zillow Research’s public files — free, updated monthly, and they don’t touch your request allowance. The first import of an area takes up to a minute.') ?></p>
  </div>
<?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th><?= te('Area') ?></th><th class="r"><?= te('Typical home value') ?></th><th class="r"><?= te('12 months') ?></th><th class="r"><?= te('5 years') ?></th><th><?= te('Data through') ?></th></tr></thead>
      <tbody>
      <?php foreach ($regions as $r):
          $s = $series((int) $r['id'])['zhvi'] ?? [];
          $m = $summary($s);
          $five = null;
          if ($m) { $d5 = date('Y-m-01', strtotime($m['date'] . ' -60 months')); $five = isset($s[$d5]) && $s[$d5] > 0 ? ($m['value'] - $s[$d5]) / $s[$d5] * 100 : null; }
      ?>
        <tr>
          <td><a class="main" href="/market/<?= (int) $r['id'] ?>"><?= e($r['kind'] === 'zip' ? $r['name'] : $r['name'] . ', ' . $r['state']) ?></a>
            <span class="sub"><?= te($r['kind'] === 'zip' ? 'ZIP code' : 'City') ?></span></td>
          <td class="r"><?= $m ? e(money($m['value'])) : '—' ?></td>
          <td class="r"><?= $m && $m['yoy'] !== null ? '<span class="' . ($m['yoy'] >= 0 ? 'up' : 'down') . '">' . e(pct($m['yoy'])) . '</span>' : '—' ?></td>
          <td class="r"><?= $five !== null ? e(pct($five, 0)) : '—' ?></td>
          <td><?= $m ? e(fmt_date($m['date'], 'M Y')) : '<span class="muted">no data</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="section-note"><?= te('Refreshed automatically once a month by the scheduler.') ?></p>
<?php endif; ?>
<?php
View::footer();

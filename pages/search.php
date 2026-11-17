<?php
declare(strict_types=1);

use App\Address;
use App\Auth;
use App\Db;
use App\Geo;
use App\PropertyRepo;
use App\Providers\Census;
use App\Quota;
use App\Services\Condition;
use App\Services\ExternalSites;
use App\Services\Search;
use App\Services\Score;
use App\View;

$f = Search::fromRequest($_GET);
$state = Address::homeState();
$stateName = Address::STATES[$state] ?? $state;

/* ── A street address goes straight to that home's page ─────────────── */
if ($f['q'] !== '') {
    $loc = Address::parseLocation($f['q']);
    if ($loc && $loc['type'] === 'address') {
        $hash = Address::hash($loc['address'], $loc['city'], $loc['state'], $loc['zip']);
        $id = (int) Db::val('SELECT id FROM properties WHERE addr_hash = ?', [$hash]);
        if (!$id && Auth::can('edit')) {
            $geo = Census::geocode(Address::full($loc)); // free; gives coordinates, ZIP and county
            $id = PropertyRepo::upsert([
                'address' => $loc['address'], 'city' => $loc['city'], 'state' => $loc['state'],
                'zip' => $loc['zip'] ?: ($geo['zip'] ?? ''), 'lat' => $geo['lat'] ?? null, 'lng' => $geo['lng'] ?? null,
                'county' => $geo['county'] ?? null,
            ], 'manual')['id'];
            flash($geo ? 'New address. Nothing is known about it yet — pull its record below.' : 'Saved the address, but the Census geocoder couldn’t place it on the map. Check the spelling.', $geo ? 'ok' : 'warn');
        }
        if ($id) {
            redirect('/property/' . $id);
        }
        flash('That address isn’t on file yet.', 'warn');
        redirect('/search');
    }
}

$typesDefault = ['Single Family', 'Townhouse'];
$val = static fn (string $k) => isset($f[$k]) ? e(rtrim(rtrim(number_format((float) $f[$k], 2, '.', ''), '0'), '.')) : '';
$money = static fn (string $k) => isset($f[$k]) ? e(number_format((float) $f[$k])) : '';

/** The full filter form — used on the landing page and in the results drawer. */
$filterForm = static function (bool $landing) use ($f, $val, $money, $typesDefault, $stateName): void {
    $types = $landing && !$f['types'] ? $typesDefault : $f['types'];
    $pair = static function (string $label, string $min, string $max, string $hint = '', bool $isMoney = false) use ($val, $money): void {
        $v = $isMoney ? $money : $val;
        echo '<div class="field"><span>' . e($label) . '</span><div class="pair">'
            . '<input class="input" name="' . $min . '" inputmode="decimal" placeholder="Min" value="' . $v($min) . '" aria-label="' . e($label) . ' minimum">'
            . '<input class="input" name="' . $max . '" inputmode="decimal" placeholder="Max" value="' . $v($max) . '" aria-label="' . e($label) . ' maximum">'
            . '</div>' . ($hint ? '<small>' . e($hint) . '</small>' : '') . '</div>';
    };
    ?>
    <div class="filters">
      <fieldset class="fgroup">
        <legend><?= $landing ? 'Area' : 'Type' ?></legend>
        <?php if ($landing): ?>
        <label class="field"><span>Where</span>
          <input class="input" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="<?= e($stateName) ?> · Fairfax County · Richmond · 22030, 20176" required autocomplete="off">
          <small>The whole state, a county, a city, or several ZIPs. Separate several areas with “;”.</small></label>
        <div class="field"><span>Listings</span>
          <span class="seg" role="group" aria-label="For sale or sold">
            <label><input type="radio" name="mode" value="sale"<?= checked($f['mode'] === 'sale') ?>><span>For sale</span></label>
            <label><input type="radio" name="mode" value="sold"<?= checked($f['mode'] === 'sold') ?>><span>Sold</span></label>
          </span></div>
        <?php endif; ?>
        <div class="field"><span>Property type</span>
          <div class="checks-row">
            <?php foreach (PropertyRepo::TYPES as $t): ?>
            <label class="check"><input type="checkbox" name="types[]" value="<?= e($t) ?>"<?= checked(in_array($t, $types, true)) ?>><?= e($t) ?></label>
            <?php endforeach; ?>
          </div><small>None ticked = any type.</small></div>
      </fieldset>

      <fieldset class="fgroup">
        <legend>Price and size</legend>
        <?php $pair('Purchase price ($)', 'price_min', 'price_max', '', true); ?>
        <?php $pair('Bedrooms', 'beds_min', 'beds_max'); ?>
        <?php $pair('Bathrooms', 'baths_min', 'baths_max'); ?>
        <?php $pair('Living area (sqft)', 'sqft_min', 'sqft_max'); ?>
        <?php $pair('Lot (sqft)', 'lot_min', 'lot_max', '1 acre = 43,560 sqft'); ?>
        <?php $pair('Year built', 'year_min', 'year_max'); ?>
        <?php $pair('HOA ($ a month)', 'hoa_min', 'hoa_max', 'Max 0 = no HOA. Unknown fees are kept.'); ?>
        <?php $pair('Days on market', 'dom_min', 'dom_max'); ?>
        <label class="field"><span>Garage / parking, at least</span>
          <select class="select" name="garage_min"><option value="">Any</option>
            <?php foreach ([1, 2, 3] as $n): ?><option value="<?= $n ?>"<?= selected($f['garage_min'] ?? '', $n) ?>><?= $n ?>+ spaces</option><?php endforeach; ?>
          </select></label>
        <?php if ($f['mode'] === 'sold'): ?>
        <label class="field"><span>Sold within</span>
          <select class="select" name="sold_days">
            <?php foreach ([30, 90, 180, 365, 730] as $d): ?><option value="<?= $d ?>"<?= selected($f['sold_days'], $d) ?>><?= e(ucfirst(Search::daysLabel($d))) ?></option><?php endforeach; ?>
          </select></label>
        <?php endif; ?>
      </fieldset>

      <fieldset class="fgroup">
        <legend>Listing status</legend>
        <div class="checks">
          <?php foreach (PropertyRepo::LISTING as $k => $label): ?>
          <label class="check"><input type="checkbox" name="listing[]" value="<?= $k ?>"<?= checked(in_array($k, $f['listing'], true)) ?>><?= e($label) ?></label>
          <?php endforeach; ?>
        </div>
        <small class="hint">None ticked = all active listings. Ticked ones are combined with “or”.</small>
      </fieldset>

      <fieldset class="fgroup">
        <legend>Renovation &amp; condition</legend>
        <div class="field"><span>Overall condition</span>
          <div class="checks">
            <?php foreach (Condition::OVERALL as $k => $label): ?>
            <label class="check"><input type="checkbox" name="cond[]" value="<?= $k ?>"<?= checked(in_array($k, $f['cond'], true)) ?>><?= e($label) ?></label>
            <?php endforeach; ?>
          </div><small>None ticked = all conditions.</small></div>
        <?php foreach (['kitchen' => 'Kitchen', 'bathroom' => 'Bathroom', 'roof' => 'Roof', 'hvac' => 'HVAC'] as $part => $label): ?>
        <div class="field row-field"><span><?= $label ?></span>
          <span class="seg seg-sm" role="group" aria-label="<?= $label ?>">
            <?php foreach (Condition::FILTER as $k => $l): ?>
            <label><input type="radio" name="c_<?= $part ?>" value="<?= $k ?>"<?= checked(($f['c_' . $part] ?? 'any') === $k) ?>><span><?= $l ?></span></label>
            <?php endforeach; ?>
          </span></div>
        <?php endforeach; ?>
        <small class="hint">Read from the listing text. Anything not mentioned is “not disclosed”, never assumed fine.</small>
      </fieldset>

      <fieldset class="fgroup">
        <legend>Investment</legend>
        <label class="field"><span>Opportunity score, at least</span>
          <select class="select" name="score_min"><option value="">Any</option>
            <?php foreach ([40, 50, 60, 70, 80] as $n): ?><option value="<?= $n ?>"<?= selected($f['score_min'] ?? '', $n) ?>><?= $n ?> / 100</option><?php endforeach; ?>
          </select></label>
        <label class="field"><span>Estimated profit, at least ($)</span>
          <input class="input" name="profit_min" inputmode="numeric" value="<?= $money('profit_min') ?>" placeholder="40,000"></label>
        <label class="field"><span>Risk, at most</span>
          <select class="select" name="risk_max"><option value="">Any</option>
            <?php foreach ([30 => 'Low (≤ 30)', 50 => 'Moderate (≤ 50)', 70 => 'High (≤ 70)'] as $n => $l): ?><option value="<?= $n ?>"<?= selected($f['risk_max'] ?? '', $n) ?>><?= $l ?></option><?php endforeach; ?>
          </select></label>
        <?php if ($landing): ?>
        <label class="field"><span>Sort by</span>
          <select class="select" name="sort">
            <?php foreach (PropertyRepo::SORTS as $k => $label): ?><option value="<?= e($k) ?>"<?= selected($f['sort'], $k) ?>><?= e($label) ?></option><?php endforeach; ?>
          </select></label>
        <?php endif; ?>
      </fieldset>
    </div>
    <?php
};

/* ── Landing: no query yet ──────────────────────────────────────────── */
if ($f['q'] === '') {
    $saved = Db::all('SELECT * FROM saved_searches WHERE user_id = ? ORDER BY COALESCE(last_run_at, created_at) DESC LIMIT 12', [Auth::userId()]);
    View::header('Search', 'search');
    ?>
    <header class="page-head">
      <div>
        <h1 class="display">Find the next deal</h1>
        <p>Search all of <?= e($stateName) ?> or any part of it. Every filter takes a minimum and a maximum; leave a box empty for “any”.</p>
      </div>
      <div class="actions">
        <a class="btn" href="<?= e(url('/search', ['q' => $stateName, 'types' => $typesDefault, 'sort' => 'score'])) ?>"><?= icon('trophy', 15) ?>Statewide best opportunities</a>
      </div>
    </header>

    <form method="get" action="/search" role="search" class="filter-page">
      <?php $filterForm(true); ?>
      <div class="form-foot sticky-foot">
        <button class="btn btn-primary" type="submit"><?= icon('search', 15) ?>Search</button>
        <a class="btn btn-ghost" href="/search">Reset</a>
        <span class="small muted">Already-pulled areas cost nothing. A new area uses one request; several areas ask first.</span>
      </div>
    </form>

    <section class="section">
      <div class="section-head"><h2>My search profiles</h2><span class="aside">each can have its own alert</span></div>
      <?php if ($saved): ?>
      <ul class="plain-list">
        <?php foreach ($saved as $s): $p = json_decode($s['params'], true) ?: []; ?>
        <li>
          <a class="main" href="<?= e(url('/search', $p)) ?>"><?= e($s['name']) ?><span class="sub"><?= e(Search::describe($p)) ?></span></a>
          <span class="end nowrap">
            <a class="linkbtn" href="<?= e(url('/alerts/new', $p + ['kind' => ($p['mode'] ?? '') === 'sold' ? 'new_sale' : 'new_listing'])) ?>">Alert me</a> ·
            <form method="post" action="/search/act" class="inline" data-confirm="Delete this profile?">
              <?= csrf_field() ?><input type="hidden" name="do" value="unsave"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="linkbtn muted" type="submit">Delete</button>
            </form></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <p class="blank">Run a search and press <strong>Save as profile</strong> — for example “Townhouses under $350K” or “Fixer-uppers with high profit”.</p>
      <?php endif; ?>
    </section>
    <?php
    View::footer();
    return;
}

/* ── Results ────────────────────────────────────────────────────────── */
$per = 40;
$page = max(1, (int) ($_GET['page'] ?? 1));
$res = Search::run($f, ['limit' => $per, 'offset' => ($page - 1) * $per]);
$loc = $res['loc'];
$mode = $f['mode'];
$query = Search::compact($f);

$points = [];
if ($loc && $res['total'] > 0) {
    foreach (PropertyRepo::search($f + ['loc' => $loc, 'limit' => 600])['rows'] as $r) {
        if (!Geo::valid($r['lat'], $r['lng'])) {
            continue;
        }
        $price = $mode === 'sold' ? $r['last_sale_price'] : $r['list_price'];
        $points[] = [
            'id' => (int) $r['id'], 'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'],
            'label' => money_short($price), 'price' => money($price), 'title' => $r['address'],
            'sub' => trim(PropertyRepo::facts($r) . ($r['score'] !== null && $mode === 'sale' ? ' · score ' . (int) $r['score'] : '')),
            'href' => '/property/' . (int) $r['id'], 'sold' => $mode === 'sold', 'band' => $mode === 'sale' ? Score::band($r['score'] !== null ? (int) $r['score'] : null) : null,
        ];
    }
}

$areaLoc = $loc && in_array($loc['type'], ['zip', 'city'], true) ? $loc : [];
if ($areaLoc && $res['rows']) {
    $areaLoc += ['city' => $res['rows'][0]['city'], 'state' => $res['rows'][0]['state']];
}
$elsewhere = $areaLoc ? ExternalSites::forArea($areaLoc) : [];
$snap = $res['snapshot'];
$provider = Search::provider();
$needs = $res['needs'];
$left = $provider ? Quota::left($provider) : 0;
$activeFilters = count(array_diff_key($query, array_flip(['q', 'mode', 'sort'])));

View::header(($loc['label'] ?? 'Search') . ($mode === 'sold' ? ' — sold' : ''), 'search', ['wide' => true, 'map' => true]);
View::script('/assets/vendor/leaflet/leaflet.js');
?>
<form class="sbar" method="get" action="/search" role="search">
  <div class="sbar-row">
    <label class="q"><span class="sr">Area</span><?= icon('search', 16) ?>
      <input class="input" type="search" name="q" value="<?= e($f['q']) ?>" autocomplete="off" required></label>
    <span class="seg" role="group" aria-label="Listing type">
      <label><input type="radio" name="mode" value="sale"<?= checked($mode === 'sale') ?> data-autosubmit><span>For sale</span></label>
      <label><input type="radio" name="mode" value="sold"<?= checked($mode === 'sold') ?> data-autosubmit><span>Sold</span></label>
    </span>
    <details class="drop drop-wide"<?= isset($_GET['open']) ? ' open' : '' ?>>
      <summary class="btn"><?= icon('filter', 14) ?>Filters<?php if ($activeFilters): ?><span class="count"><?= $activeFilters ?></span><?php endif; ?></summary>
      <div class="drop-panel drop-panel-wide">
        <?php $filterForm(false); ?>
        <div class="form-foot"><button class="btn btn-primary" type="submit">Apply</button>
          <a class="btn btn-ghost" href="<?= e(url('/search', ['q' => $f['q'], 'mode' => $mode === 'sold' ? 'sold' : null])) ?>">Clear all</a></div>
      </div>
    </details>
    <label><span class="sr">Sort</span>
      <select class="select sort-select" name="sort" data-autosubmit>
        <?php foreach (PropertyRepo::SORTS as $k => $label): ?><option value="<?= e($k) ?>"<?= selected($f['sort'], $k) ?>><?= e($label) ?></option><?php endforeach; ?>
      </select></label>
    <button class="btn btn-primary" type="submit">Search</button>
  </div>
  <?php if ($activeFilters): ?><p class="sbar-desc"><?= e(Search::describe($f)) ?></p><?php endif; ?>
</form>

<div class="results">
  <div class="results-list">
    <?php if ($res['error']): ?>
      <div class="empty">
        <h1 class="display">Couldn’t read that place.</h1>
        <p><?= e($res['error']) ?></p>
      </div>
    <?php else: ?>
      <header class="results-head">
        <h1 class="display"><?= number_format($res['total']) ?> <?= $mode === 'sold' ? ($res['total'] === 1 ? 'sale' : 'sales') : ($res['total'] === 1 ? 'home' : 'homes') ?> <span class="muted-h">in <?= e($loc['label']) ?></span></h1>
        <p class="results-meta">
          <?php if ($snap && $res['partial']): ?>
            From saved data (last pull <?= e(ago($snap['fetched_at'])) ?>). These filters or areas weren’t all pulled, so there may be more.
          <?php elseif ($snap): ?>
            <?= $res['fetched'] ? 'Just pulled' : 'Pulled' ?> from <strong><?= e(Quota::PROVIDERS[$snap['provider']] ?? $snap['provider']) ?></strong>
            <?= $res['fetched'] ? '— requests used.' : e(ago($snap['fetched_at'])) . ' — this search cost nothing.' ?>
          <?php else: ?>
            From saved data only.
          <?php endif; ?>
          <?php if ($mode === 'sale'): ?> Profit, ARV and scores are estimates — open a home to see how each was worked out.<?php endif; ?>
          <?php $waiting = $mode === 'sale' ? count(array_filter($res['rows'], static fn ($r) => $r['score'] === null && empty($r['scored_at']))) : 0; ?>
          <?php if ($waiting): ?> <span class="wait-note"><?= plural($waiting, 'home') ?> on this page still being scored — reload in a few minutes.</span><?php endif; ?>
        </p>
      </header>

      <?php if ($needs && $provider && Auth::can('edit')): $n = count($needs); ?>
      <div class="cost-card">
        <div>
          <strong><?= $n === 1 ? 'This area' : $n . ' areas' ?> not pulled with these filters yet</strong>
          <p class="small soft"><?= e(implode(' · ', array_column($needs, 'label'))) ?> — uses <?= plural($n, 'request') ?> of the <?= $left ?> left this month.<?= in_array('state', array_column($needs, 'type'), true) ? ' A statewide pull returns up to 500 homes; tighter price and size filters make those 500 count.' : '' ?></p>
        </div>
        <form method="post" action="/search/act" class="inline">
          <?= csrf_field() ?><input type="hidden" name="do" value="pull"><input type="hidden" name="params" value="<?= e(json_encode($query)) ?>">
          <button class="btn btn-primary" type="submit"<?= $left >= 1 ? '' : ' disabled' ?>><?= icon('download', 14) ?>Pull <?= $n === 1 ? 'it' : 'them' ?><span class="cost"><?= plural(min($n, $left), 'request') ?></span></button>
        </form>
      </div>
      <?php endif; ?>

      <?php if ($res['notice']): ?>
      <p class="note mt-s" role="status"><?= icon('info', 16) ?><span><?= e($res['notice']) ?></span></p>
      <?php endif; ?>

      <div class="results-tools">
        <form method="post" action="/search/act" class="inline">
          <?= csrf_field() ?><input type="hidden" name="do" value="save">
          <input type="hidden" name="params" value="<?= e(json_encode($query)) ?>">
          <button class="btn btn-sm" type="submit"><?= icon('bookmark', 14) ?>Save as profile</button>
        </form>
        <a class="btn btn-sm" href="<?= e(url('/alerts/new', $query + ['kind' => $mode === 'sold' ? 'new_sale' : 'new_listing'])) ?>"><?= icon('bell', 14) ?>Alert me</a>
        <?php if ($res['total'] > 0): ?>
        <a class="btn btn-sm" href="<?= e(url('/export.xlsx', $query)) ?>"><?= icon('file-spreadsheet', 14) ?>Excel</a>
        <?php endif; ?>
        <?php if ($provider && !$needs && Auth::can('edit')): ?>
        <form method="post" action="/search/act" class="inline" data-confirm="Pull these areas again? Each area uses one request.">
          <?= csrf_field() ?><input type="hidden" name="do" value="refresh">
          <input type="hidden" name="params" value="<?= e(json_encode($query)) ?>">
          <button class="btn btn-sm btn-ghost" type="submit"><?= icon('refresh-cw', 14) ?>Refresh</button>
        </form>
        <?php endif; ?>
      </div>

      <?php if (!$res['rows']): ?>
        <div class="empty">
          <?php
          $any = PropertyRepo::search(['loc' => $loc, 'mode' => $mode, 'sold_days' => $f['sold_days'], 'limit' => 1])['total'];
          if ($any > 0): ?>
            <h2 class="display">Nothing matches those filters.</h2>
            <p><?= plural($any, 'home') ?> <?= $mode === 'sold' ? 'sold' : 'are for sale' ?> in <?= e($loc['label']) ?> without them. Condition and investment filters only match homes whose listing text was read or analysed.</p>
            <p><a class="btn" href="<?= e(url('/search', ['q' => $f['q'], 'mode' => $mode === 'sold' ? 'sold' : null])) ?>">Clear filters</a></p>
          <?php elseif (!$provider && !$snap): ?>
            <h2 class="display">Nothing on file for <?= e($loc['label']) ?> yet.</h2>
            <p>Connect RentCast (free, 50 requests a month) and this search fills in. Or add homes from any site by pasting their link.</p>
            <p class="actions"><?php if (Auth::can('admin')): ?><a class="btn btn-primary" href="/settings#sources">Connect a source</a><?php endif; ?><a class="btn" href="/add">Add a home</a></p>
          <?php elseif ($needs): ?>
            <h2 class="display">Not pulled yet.</h2>
            <p>Use the button above to pull <?= e($loc['label']) ?>.</p>
          <?php else: ?>
            <h2 class="display">No <?= $mode === 'sold' ? 'recorded sales' : 'listings' ?> came back.</h2>
            <p><?= $mode === 'sold' ? 'Try a longer period. Virginia publishes sale prices, but some counties lag.' : 'Check the spelling, or try the ZIP code.' ?></p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="rows">
        <?php foreach ($res['rows'] as $r):
            $price = $mode === 'sold' ? $r['last_sale_price'] : $r['list_price'];
            $pp = ppsf($price, $r['sqft']);
            $dom = PropertyRepo::dom($r);
            $band = Score::band($r['score'] !== null ? (int) $r['score'] : null);
            $flags = array_filter(explode(',', (string) $r['flags']));
        ?>
          <article class="hrow hrow-x" data-pid="<?= (int) $r['id'] ?>">
            <div class="price"><?= e(money($price)) ?>
              <?php if ($pp): ?><small>$<?= number_format($pp) ?>/sqft</small><?php endif; ?></div>
            <div class="body">
              <a class="addr" href="/property/<?= (int) $r['id'] ?>"><?= e($r['address']) ?></a>
              <span class="where"><?= e($r['city']) ?>, <?= e($r['state']) ?> <?= e($r['zip']) ?></span>
              <div class="facts"><?= e(PropertyRepo::facts($r) ?: 'Details not on file') ?><?= $r['property_type'] ? ' · ' . e($r['property_type']) : '' ?></div>
              <div class="chips">
                <?php if ($mode === 'sale' && $r['est_profit'] !== null): ?>
                  <span class="chip <?= (int) $r['est_profit'] > 0 ? 'chip-good' : 'chip-bad' ?>">Profit <?= e(money_short(abs((int) $r['est_profit']))) ?><?= (int) $r['est_profit'] < 0 ? ' loss' : '' ?></span>
                  <?php if ($r['est_arv']): ?><span class="chip">ARV <?= e(money_short($r['est_arv'])) ?></span><?php endif; ?>
                <?php endif; ?>
                <?php if ($r['cond_overall'] && $r['cond_overall'] !== 'not_disclosed'): ?><span class="chip chip-cond"><?= e(Condition::OVERALL[$r['cond_overall']]) ?></span><?php endif; ?>
                <?php foreach ($flags as $fl): ?><span class="chip chip-warn"><?= e(Condition::FLAGS[$fl] ?? $fl) ?></span><?php endforeach; ?>
                <?php if ($r['has_basement']): ?><span class="chip">Basement</span><?php endif; ?>
                <?php if ($r['watch_stage']): ?><span class="chip chip-stage"><?= e(App\Services\Pipeline::STAGES[$r['watch_stage']] ?? $r['watch_stage']) ?><?= $r['owner_name'] ? ' · ' . e($r['owner_name']) : '' ?></span><?php endif; ?>
              </div>
            </div>
            <div class="side">
              <?php if ($mode === 'sale'): ?>
              <?php if ($r['score'] === null && empty($r['scored_at'])): ?>
              <span class="score-badge score-wait" title="Being scored — ready within a few minutes">…</span>
              <?php else: ?>
              <span class="score-badge score-<?= $band ?>" title="Opportunity score<?= $r['risk'] !== null ? ' · risk ' . (int) $r['risk'] : '' ?>"><?= $r['score'] !== null ? (int) $r['score'] : '—' ?></span>
              <?php endif; ?>
              <?php endif; ?>
              <span class="side-meta">
                <?php if ($mode === 'sold'): ?>Sold <?= e(fmt_date($r['last_sale_date'])) ?>
                <?php elseif ($dom !== null): ?><?= $dom === 0 ? 'Listed today' : ($dom === 1 ? '1 day' : $dom . ' days') ?><?php endif; ?>
              </span>
              <?php if (Auth::can('edit')): ?>
              <form method="post" action="/property/<?= (int) $r['id'] ?>/act">
                <?= csrf_field() ?><input type="hidden" name="do" value="<?= $r['watch_id'] ? 'unwatch' : 'watch' ?>"><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '/search') ?>">
                <button class="star<?= $r['watch_id'] ? ' is-on' : '' ?>" type="submit" title="<?= $r['watch_id'] ? 'In the pipeline — remove' : 'Add to the team pipeline' ?>"><?= icon('star', 17) ?><span class="sr"><?= $r['watch_id'] ? 'Remove from pipeline' : 'Add to pipeline' ?></span></button>
              </form>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
        </div>
        <?= View::pager($res['total'], $per, $page, $query) ?>
      <?php endif; ?>

      <?php if ($elsewhere): ?>
      <p class="elsewhere"><span>Same area on</span>
        <?php foreach ($elsewhere as $s): ?><a href="<?= e($s['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($s['name']) ?></a><?php endforeach; ?>
      </p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <aside class="results-map" aria-label="Map of results">
    <div id="map"></div>
    <script type="application/json" id="map-data"><?= json_embed(['points' => $points, 'wheel' => true]) ?></script>
  </aside>
</div>
<?php
View::footer();

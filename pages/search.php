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
    $loc = Search::location($f, true);
    if ($loc && $loc['type'] === 'address') {
        if (Auth::can('edit') && empty($loc['partial'])) {
            App\Services\HomesSearch::run($loc);
            $loc = Search::withHomes(array_diff_key($loc, ['ids' => 1]));
        }
        $ids = $loc['ids'] ?? [];
        if (count($ids) === 1) {
            redirect('/property/' . $ids[0]);
        }
        if (!$ids) {
            if (!empty($loc['partial'])) {
                flash(t('No home at %s is on file yet. Add the town or ZIP code so it can be found.', $loc['address']), 'warn');
            } elseif (!Auth::can('edit')) {
                flash('That address isn’t on file yet.', 'warn');
                redirect('/search');
            } else {
                $added = Search::addAddress($loc);
                flash($added['placed'] ? 'New address. Nothing is known about it yet — pull its record below.' : 'Saved the address, but the Census geocoder couldn’t place it on the map. Check the spelling.', $added['placed'] ? 'ok' : 'warn');
                redirect('/property/' . $added['id']);
            }
        }
        // Several homes at this address (units of one building): listed below.
    }
}

$typesDefault = ['Single Family', 'Townhouse'];
$val = static fn (string $k) => isset($f[$k]) ? e(rtrim(rtrim(number_format((float) $f[$k], 2, '.', ''), '0'), '.')) : '';
$money = static fn (string $k) => isset($f[$k]) ? e(number_format((float) $f[$k])) : '';
$countyExample = 'Fairfax County, Loudoun County, VA';

/** The full filter form — used on the landing page and in the results drawer. */
$filterForm = static function (bool $landing) use ($f, $val, $money, $typesDefault, $countyExample): void {
    $types = $f['types'];
    $pair = static function (string $label, string $min, string $max, string $hint = '', bool $isMoney = false) use ($val, $money): void {
        $v = $isMoney ? $money : $val;
        echo '<div class="field"><span>' . te($label) . '</span><div class="pair">'
            . '<input class="input" name="' . $min . '" inputmode="decimal" placeholder="' . te('Min') . '" value="' . $v($min) . '" aria-label="' . te('%s minimum', t($label)) . '">'
            . '<input class="input" name="' . $max . '" inputmode="decimal" placeholder="' . te('Max') . '" value="' . $v($max) . '" aria-label="' . te('%s maximum', t($label)) . '">'
            . '</div>' . ($hint ? '<small>' . te($hint) . '</small>' : '') . '</div>';
    };
    ?>
    <div class="filters">
      <fieldset class="fgroup">
        <legend><?= te($landing ? 'Area' : 'Type') ?></legend>
        <?php if ($landing): ?>
        <label class="field"><span><?= te('Where') ?></span>
          <input class="input" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="<?= te('Address, building, street, city, county or ZIP — add “3 bed under 500k” if you like') ?>" maxlength="<?= Search::AREA_MAX_LENGTH ?>" required autocomplete="off" aria-describedby="search-area-help">
          <small id="search-area-help"><?= te('Type it any way: a full or partial address (with or without commas, ZIP or state), an apartment, a building name, a street, a city, a county or ZIP codes. Several places together: separate them with commas, “;” or “and”. Filters can be typed too; the boxes below always win.') ?></small>
          <ul class="search-examples small" dir="ltr">
            <?php foreach (['5501 Seminary Rd Apt 1703 S Falls Church VA 22041', '5501 Seminary Rd', 'Seminary Rd, Falls Church', 'Skyline Square, Falls Church, VA',
                'Fairfax County, Loudoun County, VA', '22041 22042 22044', 'Fairfax County; Arlington; 22041', '3 bed townhouse in Falls Church under 500k',
                '۳ خوابه زیر ۵۰۰ هزار در Fairfax County'] as $example): ?>
            <li><a href="<?= e(url('/search', ['q' => $example])) ?>"><bdi><?= e($example) ?></bdi></a></li>
            <?php endforeach; ?>
          </ul></label>
        <div class="field"><span><?= te('Listings') ?></span>
          <span class="seg" role="group" aria-label="<?= te('For sale or sold') ?>">
            <label><input type="radio" name="mode" value="sale"<?= checked($f['mode'] === 'sale') ?>><span><?= te('For sale') ?></span></label>
            <label><input type="radio" name="mode" value="sold"<?= checked($f['mode'] === 'sold') ?>><span><?= te('Sold') ?></span></label>
          </span></div>
        <?php endif; ?>
        <div class="field"><span><?= te('Property type') ?></span>
          <div class="checks-row">
            <?php foreach (PropertyRepo::TYPES as $t): ?>
            <label class="check"><input type="checkbox" name="types[]" value="<?= e($t) ?>"<?= checked(in_array($t, $types, true)) ?>><?= te($t) ?></label>
            <?php endforeach; ?>
          </div><small><?= te('None ticked = any type.') ?></small></div>
      </fieldset>

      <fieldset class="fgroup">
        <legend><?= te('Price and size') ?></legend>
        <?php $pair('Purchase price ($)', 'price_min', 'price_max', '', true); ?>
        <?php $pair('Bedrooms', 'beds_min', 'beds_max'); ?>
        <?php $pair('Bathrooms', 'baths_min', 'baths_max'); ?>
        <?php $pair('Living area (sqft)', 'sqft_min', 'sqft_max'); ?>
        <?php $pair('Lot (sqft)', 'lot_min', 'lot_max', '1 acre = 43,560 sqft'); ?>
        <?php $pair('Year built', 'year_min', 'year_max'); ?>
        <?php $pair('HOA ($ a month)', 'hoa_min', 'hoa_max', 'Max 0 = no HOA. Unknown fees are kept.'); ?>
        <?php $pair('Days on market', 'dom_min', 'dom_max'); ?>
        <label class="field"><span><?= te('Garage / parking, at least') ?></span>
          <select class="select" name="garage_min"><option value=""><?= te('Any') ?></option>
            <?php foreach ([1, 2, 3] as $n): ?><option value="<?= $n ?>"<?= selected($f['garage_min'] ?? '', $n) ?>><?= $n ?><?= te('+ spaces') ?></option><?php endforeach; ?>
          </select></label>
        <?php if ($f['mode'] === 'sold'): ?>
        <label class="field"><span><?= te('Sold within') ?></span>
          <select class="select" name="sold_days">
            <?php foreach ([30, 90, 180, 365, 730] as $d): ?><option value="<?= $d ?>"<?= selected($f['sold_days'], $d) ?>><?= e(ucfirst(t(Search::daysLabel($d)))) ?></option><?php endforeach; ?>
          </select></label>
        <?php endif; ?>
      </fieldset>

      <fieldset class="fgroup">
        <legend><?= te('Listing status') ?></legend>
        <div class="checks">
          <?php foreach (PropertyRepo::LISTING as $k => $label): ?>
          <label class="check"><input type="checkbox" name="listing[]" value="<?= $k ?>"<?= checked(in_array($k, $f['listing'], true)) ?>><?= te($label) ?></label>
          <?php endforeach; ?>
        </div>
        <small class="hint"><?= te('None ticked = all active listings. Ticked ones are combined with “or”.') ?></small>
      </fieldset>

      <fieldset class="fgroup">
        <legend><?= te('Renovation & condition') ?></legend>
        <div class="field"><span><?= te('Overall condition') ?></span>
          <div class="checks">
            <?php foreach (Condition::OVERALL as $k => $label): ?>
            <label class="check"><input type="checkbox" name="cond[]" value="<?= $k ?>"<?= checked(in_array($k, $f['cond'], true)) ?>><?= te($label) ?></label>
            <?php endforeach; ?>
          </div><small><?= te('None ticked = all conditions.') ?></small></div>
        <?php foreach (['kitchen' => 'Kitchen', 'bathroom' => 'Bathroom', 'roof' => 'Roof', 'hvac' => 'HVAC'] as $part => $label): ?>
        <div class="field row-field"><span><?= te($label) ?></span>
          <span class="seg seg-sm" role="group" aria-label="<?= te($label) ?>">
            <?php foreach (Condition::FILTER as $k => $l): ?>
            <label><input type="radio" name="c_<?= $part ?>" value="<?= $k ?>"<?= checked(($f['c_' . $part] ?? 'any') === $k) ?>><span><?= te($l) ?></span></label>
            <?php endforeach; ?>
          </span></div>
        <?php endforeach; ?>
        <small class="hint"><?= te('Read from the listing text. Anything not mentioned is “not disclosed”, never assumed fine.') ?></small>
      </fieldset>

      <fieldset class="fgroup">
        <legend><?= te('Investment') ?></legend>
        <label class="field"><span><?= te('Opportunity score, at least') ?></span>
          <select class="select" name="score_min"><option value=""><?= te('Any') ?></option>
            <?php foreach ([40, 50, 60, 70, 80] as $n): ?><option value="<?= $n ?>"<?= selected($f['score_min'] ?? '', $n) ?>><?= $n ?> / 100</option><?php endforeach; ?>
          </select></label>
        <label class="field"><span><?= te('Estimated profit, at least ($)') ?></span>
          <input class="input" name="profit_min" inputmode="numeric" value="<?= $money('profit_min') ?>" placeholder="40,000"></label>
        <label class="field"><span><?= te('Risk, at most') ?></span>
          <select class="select" name="risk_max"><option value=""><?= te('Any') ?></option>
            <?php foreach ([30 => 'Low (≤ 30)', 50 => 'Moderate (≤ 50)', 70 => 'High (≤ 70)'] as $n => $l): ?><option value="<?= $n ?>"<?= selected($f['risk_max'] ?? '', $n) ?>><?= te($l) ?></option><?php endforeach; ?>
          </select></label>
        <?php if ($landing): ?>
        <label class="field"><span><?= te('Sort by') ?></span>
          <select class="select" name="sort">
            <?php foreach (PropertyRepo::SORTS as $k => $label): ?><option value="<?= e($k) ?>"<?= selected($f['sort'], $k) ?>><?= te($label) ?></option><?php endforeach; ?>
          </select></label>
        <?php endif; ?>
      </fieldset>
    </div>
    <?php
};

/* ── Landing: no query yet ──────────────────────────────────────────── */
if ($f['q'] === '' && $f['scope'] !== 'saved') {
    $saved = Db::all('SELECT * FROM saved_searches WHERE user_id = ? ORDER BY COALESCE(last_run_at, created_at) DESC LIMIT 12', [Auth::userId()]);
    View::header('Search', 'search');
    ?>
    <header class="page-head">
      <div>
        <h1 class="display"><?= te('Find the next deal') ?></h1>
        <p><?= te('Search all of') ?> <?= e($stateName) ?> <?= te('or any part of it. Every filter takes a minimum and a maximum; leave a box empty for “any”.') ?></p>
      </div>
      <div class="actions">
        <a class="btn" href="<?= e(url('/search', ['q' => $stateName, 'types' => $typesDefault, 'sort' => 'score'])) ?>"><?= icon('trophy', 15) ?><?= te('Statewide best opportunities') ?></a>
      </div>
    </header>

    <form method="get" action="/search" role="search" class="filter-page">
      <?php $filterForm(true); ?>
      <div class="form-foot sticky-foot">
        <button class="btn btn-primary" type="submit"><?= icon('search', 15) ?><?= te('Search') ?></button>
        <a class="btn btn-ghost" href="/search"><?= te('Reset') ?></a>
        <span class="small muted"><?= te('Already-pulled areas cost nothing. A new area uses one request; several areas ask first.') ?></span>
      </div>
    </form>

    <section class="section">
      <div class="section-head"><h2><?= te('My search profiles') ?></h2><span class="aside"><?= te('each can have its own alert') ?></span></div>
      <?php if ($saved): $bulk = ['type' => 'profiles', 'form' => 'bulk-profiles', 'back' => '/search', 'all' => 'Delete all my profiles', 'count' => count($saved)]; include APP_ROOT . '/pages/partials/bulk_bar.php'; ?>
      <ul class="plain-list">
        <?php foreach ($saved as $s): $p = json_decode($s['params'], true) ?: []; ?>
        <li>
          <input class="bulk-pick" type="checkbox" name="ids[]" value="<?= (int) $s['id'] ?>" form="bulk-profiles" aria-label="<?= te('Select') ?> <?= e($s['name']) ?>">
          <a class="main" href="<?= e(url('/search', $p)) ?>"><?= e($s['name']) ?><span class="sub"><?= e(Search::describe($p)) ?></span></a>
          <span class="end nowrap">
            <?php if (!in_array(Search::location($p)['type'] ?? '',['building','saved'],true)): ?><a class="linkbtn" href="<?= e(url('/alerts/new', $p + ['kind' => ($p['mode'] ?? '') === 'sold' ? 'new_sale' : 'new_listing'])) ?>"><?= te('Alert me') ?></a> ·<?php endif; ?>
            <form method="post" action="/search/act" class="inline" data-confirm="<?= te('Delete this profile?') ?>">
              <?= csrf_field() ?><input type="hidden" name="do" value="unsave"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="linkbtn muted" type="submit"><?= te('Delete') ?></button>
            </form></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <p class="blank"><?= te('Run a search and press') ?> <strong><?= te('Save as profile') ?></strong> <?= te('— for example “Townhouses under $350K” or “Fixer-uppers with high profit”.') ?></p>
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
$provider = $loc ? Search::locationProvider($loc) : null;
$needs = $res['needs'];
$left = $provider ? Quota::left($provider) : 0;
$activeFilters = count(array_diff_key($query, array_flip(['q', 'mode', 'sort', 'scope', 'view'])));

View::header($mode === 'sold' ? t('%s — sold', $loc['label'] ?? t('Search')) : ($loc['label'] ?? 'Search'), 'search', ['wide' => true, 'map' => true]);
View::script('/assets/vendor/leaflet/leaflet.js');
?>
<form class="sbar" method="get" action="/search" role="search">
  <?php if ($f['scope']==='saved'): ?><input type="hidden" name="scope" value="saved"><input type="hidden" name="view" value="<?= e($f['view']) ?>"><?php endif; ?>
  <div class="sbar-row">
    <label class="q"><span class="sr"><?= te('Area') ?></span><?= icon('search', 16) ?>
      <input class="input" type="search" name="q" value="<?= e($f['scope']==='saved' ? $loc['label'] : $f['q']) ?>" placeholder="<?= te('Address, building, street, city, county or ZIP — add “3 bed under 500k” if you like') ?>" maxlength="<?= Search::AREA_MAX_LENGTH ?>" autocomplete="off" <?= $f['scope']==='saved' ? 'readonly' : 'required' ?> aria-describedby="search-area-help"></label>
    <?php if ($f['scope']!=='saved'): ?><span class="seg" role="group" aria-label="<?= te('Listing type') ?>">
      <label><input type="radio" name="mode" value="sale"<?= checked($mode === 'sale') ?> data-autosubmit><span><?= te('For sale') ?></span></label>
      <label><input type="radio" name="mode" value="sold"<?= checked($mode === 'sold') ?> data-autosubmit><span><?= te('Sold') ?></span></label>
    </span><?php endif; ?>
    <details class="drop drop-wide"<?= isset($_GET['open']) ? ' open' : '' ?>>
      <summary class="btn"><?= icon('filter', 14) ?><?= te('Filters') ?><?php if ($activeFilters): ?><span class="count"><?= $activeFilters ?></span><?php endif; ?></summary>
      <div class="drop-panel drop-panel-wide">
        <?php $filterForm(false); ?>
        <div class="form-foot"><button class="btn btn-primary" type="submit"><?= te('Apply') ?></button>
          <a class="btn btn-ghost" href="<?= e(url('/search', array_intersect_key($query,array_flip(['q','scope','view','mode'])))) ?>"><?= te('Clear all') ?></a></div>
      </div>
    </details>
    <label><span class="sr"><?= te('Sort') ?></span>
      <select class="select sort-select" name="sort" data-autosubmit>
        <?php foreach (PropertyRepo::SORTS as $k => $label): ?><option value="<?= e($k) ?>"<?= selected($f['sort'], $k) ?>><?= te($label) ?></option><?php endforeach; ?>
      </select></label>
    <button class="btn btn-primary" type="submit"><?= te('Search') ?></button>
  </div>
  <?php if ($f['scope']==='saved'): ?>
  <p class="sbar-desc" id="search-area-help"><?= te('Showing saved homes across all areas. This view does not call external data sources.') ?> <a href="/search"><?= te('Search a new area') ?></a></p>
  <?php else: ?><p class="sbar-desc" id="search-area-help"><?= te('Type it any way: a full or partial address (with or without commas, ZIP or state), an apartment, a building name, a street, a city, a county or ZIP codes. Several places together: separate them with commas, “;” or “and”. Filters can be typed too; the boxes below always win.') ?></p><?php endif; ?>
  <?php if ($loc && $loc['type']!=='saved'): $typeLabels=['building'=>'Building name','city'=>'City','county'=>'County','state'=>'State','zip'=>'ZIP','multi'=>'Multiple areas','street'=>'Street','address'=>'Address']; ?>
  <p class="sbar-desc search-detected" data-detected="<?= e($loc['type']) ?>"><?= te('Detected automatically: %s',t($typeLabels[$loc['type']]??'Address')) ?>
    <?php if ($loc['type'] === 'multi'): ?><span class="detected-parts"><?php foreach ($loc['parts'] as $part): ?><span class="chip"><?= te($typeLabels[$part['type']] ?? 'Address') ?>: <bdi><?= e($part['label']) ?></bdi></span><?php endforeach; ?></span><?php endif; ?></p>
  <?php endif; ?>
  <?php if (!empty($f['understood'])): ?>
  <p class="sbar-desc search-understood"><?= te('Filters understood from your search:') ?> <?php foreach ($f['understood'] as $u): ?><span class="chip chip-good"><?= e($u) ?></span><?php endforeach; ?>
    <span class="muted"><?= te('Change them under Filters.') ?></span></p>
  <?php endif; ?>
  <?php if ($activeFilters): ?><p class="sbar-desc"><?= e(Search::describe($f)) ?></p><?php endif; ?>
</form>

<div class="results<?= ($loc['type'] ?? '') === 'building' && !$points ? ' results-building' : '' ?>">
  <div class="results-list">
    <?php if ($res['error']): ?>
      <div class="empty">
        <?= View::sketch('search-unclear') ?>
        <h1 class="display"><?= te('Couldn’t read that place.') ?></h1>
        <p><?= te($res['error']) ?></p>
      </div>
    <?php else: ?>
      <header class="results-head">
        <h1 class="display"><?= e(plural($res['total'], $mode === 'sold' ? 'sale' : 'home')) ?> <span class="muted-h"><?= te('in %s', $loc['label']) ?></span></h1>
        <p class="results-meta">
          <?php if ($snap && $res['partial']): ?>
            <?= te('From saved data (last pull') ?> <?= e(ago($snap['fetched_at'])) ?><?= te('). These filters or areas weren’t all pulled, so there may be more.') ?>
          <?php elseif ($snap): ?>
            <?= te($res['fetched'] ? 'Just pulled from' : 'Pulled from') ?> <strong><?= e(Quota::PROVIDERS[$snap['provider']] ?? $snap['provider']) ?></strong>
            <?= $res['fetched'] ? te('— requests used.') : te('%s — this search cost nothing.', ago($snap['fetched_at'])) ?>
          <?php else: ?>
            <?= te(!empty($res['homes']['available']) ? 'Saved homes plus Homes.com public listings.' : 'From saved data only.') ?>
          <?php endif; ?>
          <?php if ($mode === 'sale'): ?> <?= te('Profit, ARV and scores are estimates — open a home to see how each was worked out.') ?><?php endif; ?>
          <?php $waiting = $mode === 'sale' ? count(array_filter($res['rows'], static fn ($r) => $r['score'] === null && empty($r['scored_at']))) : 0; ?>
          <?php if ($waiting): ?> <span class="wait-note"><?= plural($waiting, 'home') ?> <?= te('on this page still being scored — reload in a few minutes.') ?></span><?php endif; ?>
        </p>
      </header>

      <?php if ($res['homes'] !== null): $homes = $res['homes']; ?>
      <div class="homes-search-status note mt-s" role="status">
        <strong>Homes.com</strong>
        <?php if ($homes['available']): ?>
          <span><?= te('%d listings verified for the requested location.', $homes['count']) ?> <?= te('First-page coverage; this is not the complete market.') ?></span>
          <small><?= te($homes['cached'] ? 'Using cached source pages.' : 'Source pages checked now.') ?> <?= te('Source checked') ?> <bdi><?= e($homes['observed_at']) ?> UTC</bdi></small>
        <?php else: ?><span><?= te('Homes.com did not return verified listings for this search. Other sources and saved results remain available.') ?></span><?php endif; ?>
        <?php if ($homes['limited']): ?><small><?= te('Only part of the requested areas was checked within this search time limit.') ?></small><?php endif; ?>
        <?php foreach ($homes['errors'] as $error): ?><small><?= te($error) ?></small><?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($loc['type'] === 'building'): ?>
      <section class="building-matches callout mt-s">
        <div><h2><?= te('Building address matches') ?></h2>
          <p class="small soft"><?= te('Map addresses identify the building, not an individual apartment or a current sale. Open the correct building or search its full address with the unit number.') ?></p>
          <?php foreach ($loc['buildings'] as $building): ?>
          <article class="building-match">
            <strong><bdi><?= e($building['name']) ?></bdi></strong><span><bdi><?= e(Address::full($building)) ?></bdi></span>
            <div class="actions"><a class="btn btn-sm" href="<?= e(url('/search', ['q' => Address::full($building)])) ?>"><?= te('Open this address') ?></a>
              <?php if ($building['source_url']): ?><a class="small" href="<?= e($building['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= te('OpenStreetMap source') ?></a><?php endif; ?></div>
          </article>
          <?php endforeach; ?>
          <?php if (!$loc['buildings']): ?><p><?= te('No verified map address was found for this building name. Add its city and state, or search the street address directly.') ?></p><?php endif; ?>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($needs && $provider && Auth::can('edit')): $n = count($needs); ?>
      <div class="cost-card">
        <div>
          <strong><?= $n === 1 ? te('This area') : te('%d areas', $n) ?> <?= te('not pulled with these filters yet') ?></strong>
          <p class="small soft"><?= e(implode(' · ', array_column($needs, 'label'))) ?> · <?= te('%1$s needed, %2$d left this month.', plural($n, 'request'), $left) ?>
            <?php if (in_array('state', array_column($needs, 'type'), true)): ?><?= te('Counties in the same state share a statewide request. The provider may return only part of the listings.') ?><?php endif; ?></p>
        </div>
        <form method="post" action="/search/act" class="inline">
          <?= csrf_field() ?><input type="hidden" name="do" value="pull"><input type="hidden" name="params" value="<?= e(json_encode($query)) ?>">
          <button class="btn btn-primary" type="submit"<?= $left >= 1 ? '' : ' disabled' ?>><?= icon('download', 14) ?><?= te('Pull') ?> <?= $n === 1 ? 'it' : 'them' ?><span class="cost"><?= plural(min($n, $left), 'request') ?></span></button>
        </form>
      </div>
      <?php endif; ?>

      <?php
      // Listings alone give only asking prices; one request brings the area's recorded sales for real ARVs.
      $areaSales = $mode === 'sale' && $res['total'] > 0 && !in_array($loc['type'], ['address', 'point', 'building', 'saved'], true)
          ? PropertyRepo::search(['loc' => $loc, 'mode' => 'sold', 'sold_days' => 365, 'limit' => 1])['total'] : null;
      ?>
      <?php
      // Where a county publishes its sales, they come from its records for free.
      $ffxZip = $areaSales === 0 && $loc['type'] === 'zip' && App\Services\CountyRecords::zipSource($loc['zip']) !== null;
      ?>
      <?php if ($areaSales === 0 && Auth::can('edit')):
          $salesFilters = Search::fromRequest(['q' => $f['q'], 'mode' => 'sold', 'sold_days' => 365, 'types' => $f['types']]);
          $salesRequests = $ffxZip ? 0 : count(Search::plan($loc, $salesFilters)['needs']);
      ?>
      <div class="cost-card sales-card">
        <div>
          <strong><?= te('ARV and profit here come from asking prices.') ?></strong>
          <p class="small soft"><?= te('Pull the last 12 months of recorded sales in') ?> <?= e($loc['label']) ?> <?= te('and every home here is valued against what actually sold.') ?></p>
        </div>
        <form method="post" action="/search/act" class="inline">
          <?= csrf_field() ?><input type="hidden" name="do" value="area_sales"><input type="hidden" name="params" value="<?= e(json_encode($query)) ?>">
          <?php if ($ffxZip): ?>
          <button class="btn btn-primary" type="submit"><?= icon('download', 14) ?><?= te('Pull recorded sales') ?><span class="cost"><?= te('free · county records') ?></span></button>
          <?php else: ?>
          <button class="btn btn-primary" type="submit"<?= $provider && $salesRequests ? '' : ' disabled' ?>><?= icon('download', 14) ?><?= te('Pull recorded sales') ?><span class="cost"><?= e(plural($salesRequests, 'request')) ?></span></button>
          <?php endif; ?>
        </form>
      </div>
      <?php endif; ?>
      <?php $rcProblem = App\Providers\RentCast::problem(); ?>
      <?php if ($res['notice'] && $rcProblem && $res['notice'] === $rcProblem['why']): ?>
      <div class="callout callout-bad mt-s" role="alert"><?= icon('triangle-alert', 16) ?><div>
        <strong><?= te('No new homes could be pulled:') ?> <?= e($rcProblem['why']) ?></strong>
        <?php if (Auth::can('admin')): ?>
        <span class="small soft"><?= te('On the dashboard: API → choose the Developer plan (free, 50 requests a month). The key you already pasted starts working once the plan is on. Refused requests are not counted.') ?></span>
        <a class="btn btn-sm mt-s" href="<?= e(App\Providers\RentCast::DASHBOARD) ?>" target="_blank" rel="noopener noreferrer"><?= icon('external-link', 14) ?><?= te('Open the RentCast API dashboard') ?></a>
        <?php else: ?>
        <span class="small soft"><?= te('Ask whoever runs the site to check the RentCast plan in Settings.') ?></span>
        <?php endif; ?>
      </div></div>
      <?php elseif ($res['notice']): ?>
      <p class="note mt-s" role="status"><?= icon('info', 16) ?><span><?= te($res['notice']) ?></span></p>
      <?php endif; ?>

      <div class="results-tools">
        <form method="post" action="/search/act" class="inline">
          <?= csrf_field() ?><input type="hidden" name="do" value="save">
          <input type="hidden" name="params" value="<?= e(json_encode($query)) ?>">
          <button class="btn btn-sm" type="submit"><?= icon('bookmark', 14) ?><?= te('Save as profile') ?></button>
        </form>
        <?php if (!in_array($loc['type'],['building','saved'],true)): ?><a class="btn btn-sm" href="<?= e(url('/alerts/new', $query + ['kind' => $mode === 'sold' ? 'new_sale' : 'new_listing'])) ?>"><?= icon('bell', 14) ?><?= te('Alert me') ?></a><?php endif; ?>
        <?php if ($res['total'] > 0): ?>
        <a class="btn btn-sm" href="<?= e(url('/export.xlsx', $query)) ?>"><?= icon('file-spreadsheet', 14) ?><?= te('Excel') ?></a>
        <?php endif; ?>
        <?php if ($provider && !$needs && Auth::can('edit')): ?>
        <form method="post" action="/search/act" class="inline" data-confirm="<?= te('Pull these areas again? Each area uses one request.') ?>">
          <?= csrf_field() ?><input type="hidden" name="do" value="refresh">
          <input type="hidden" name="params" value="<?= e(json_encode($query)) ?>">
          <button class="btn btn-sm btn-ghost" type="submit"><?= icon('refresh-cw', 14) ?><?= te('Refresh') ?></button>
        </form>
        <?php endif; ?>
      </div>

      <?php if ($res['rows'] && Auth::can('edit')): $bulk = ['type' => 'homes', 'form' => 'bulk-homes', 'back' => $_SERVER['REQUEST_URI'] ?? '/search',
          'all' => 'Delete all homes in these results', 'params' => json_encode($query), 'count' => (int) $res['total']]; include APP_ROOT . '/pages/partials/bulk_bar.php'; endif; ?>

      <?php if (!$res['rows'] && $loc['type'] === 'building'): ?>
        <p class="blank"><?= te('No verified apartment listings on file for this building.') ?> <?= te('Only homes with a matching building name or verified building address are included. Search a full address and apartment number to open a particular home.') ?></p>
      <?php elseif (!$res['rows']): ?>
        <div class="empty">
          <?= View::sketch('search-none') ?>
          <?php
          $any = PropertyRepo::search(['loc' => $loc, 'scope'=>$f['scope'], 'view'=>$f['view'], 'score_min'=>$f['view']==='opportunities'?70:0, 'mode' => $mode, 'sold_days' => $f['sold_days'], 'limit' => 1])['total'];
          if ($any > 0): ?>
            <h2 class="display"><?= te('Nothing matches those filters.') ?></h2>
            <p><?= plural($any, 'home') ?> <?= te($mode === 'sold' ? 'sold in %s' : 'are for sale in %s', $loc['label']) ?> <?= te('without them. Condition and investment filters only match homes whose listing text was read or analysed.') ?></p>
            <p><a class="btn" href="<?= e(url('/search', array_intersect_key($query,array_flip(['q','scope','view','mode'])))) ?>"><?= te('Clear filters') ?></a></p>
          <?php elseif ($loc['type']==='saved'): ?>
            <h2 class="display"><?= te('No saved homes match these filters.') ?></h2>
            <p><a href="/search"><?= te('Search a new area') ?></a></p>
          <?php elseif (!$provider && !$snap): ?>
            <h2 class="display"><?= te('Nothing on file for') ?> <?= e($loc['label']) ?> <?= te('yet.') ?></h2>
            <p><?= te('Connect RentCast (free, 50 requests a month) and this search fills in. Or add homes from any site by pasting their link.') ?></p>
            <p class="actions"><?php if (Auth::can('admin')): ?><a class="btn btn-primary" href="/settings#sources"><?= te('Connect a source') ?></a><?php endif; ?><a class="btn" href="/add"><?= te('Add a home') ?></a></p>
          <?php elseif ($needs): ?>
            <h2 class="display"><?= te('Not pulled yet.') ?></h2>
            <p><?= te('Use the button above to pull') ?> <?= e($loc['label']) ?>.</p>
          <?php else: ?>
            <h2 class="display"><?= te($mode === 'sold' ? 'No recorded sales came back.' : 'No listings came back.') ?></h2>
            <p><?= te($mode === 'sold' ? 'Try a longer period. Virginia publishes sale prices, but some counties lag.' : 'Check the spelling, or try the ZIP code.') ?></p>
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
            $homesQuote = json_decode((string) ($r['homes_quote'] ?? ''), true);
            $homesLink = App\Providers\HomesCom::propertyUrl($homesQuote['url'] ?? null);
        ?>
          <article class="hrow hrow-x" data-pid="<?= (int) $r['id'] ?>">
            <?php if (str_starts_with((string) $r['photo_url'], 'https://')): ?>
            <img class="listing-thumb" src="<?= e($r['photo_url']) ?>" alt="<?= e($r['address']) ?>" loading="lazy" decoding="async" referrerpolicy="no-referrer" data-photo-trigger role="button" tabindex="0" aria-label="<?= te('Enlarge photo') ?>: <?= e($r['address']) ?>">
            <?php endif; ?>
            <div class="price"><?php if (Auth::can('edit')): ?><input class="bulk-pick" type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" form="bulk-homes" aria-label="<?= te('Select') ?> <?= e($r['address']) ?>"> <?php endif; ?><?= e(money($price)) ?>
              <?php if ($pp): ?><small>$<?= number_format($pp) ?><?= te('/sqft') ?></small><?php endif; ?></div>
            <div class="body">
              <a dir="auto" class="addr" href="/property/<?= (int) $r['id'] ?>"><?= e($r['address']) ?></a>
              <span class="where"><bdi><?= e($r['city']) ?>, <?= e($r['state']) ?> <?= e($r['zip']) ?></bdi><?php if ($loc['type'] === 'multi' && $r['county']): ?> · <bdi><?= e($r['county']) ?></bdi><?php endif; ?></span>
              <div class="facts"><?= (PropertyRepo::facts($r) !== '' ? e(PropertyRepo::facts($r)) : te('Details not on file')) ?><?= $r['property_type'] ? ' · ' . te($r['property_type']) : '' ?></div>
              <div class="chips">
                <?php if ($r['source'] === 'rentcast'): ?><span class="chip">RentCast</span><?php elseif ($r['source'] === 'openwebninja'): ?><span class="chip"><?= te('Zillow data') ?></span><?php endif; ?>
                <?php if ($homesLink): ?><a class="chip homes-source-link" href="<?= e($homesLink) ?>" target="_blank" rel="noopener noreferrer" title="<?= te('Source checked') ?> <?= e($homesQuote['observed_at'] ?? '') ?> UTC">Homes.com</a><?php endif; ?>
                <?php if ($mode === 'sale' && $r['est_profit'] !== null): ?>
                  <span class="chip <?= (int) $r['est_profit'] > 0 ? 'chip-good' : 'chip-bad' ?>"><?= te('Profit') ?> <?= e(money_short(abs((int) $r['est_profit']))) ?><?= (int) $r['est_profit'] < 0 ? ' ' . te('loss') : '' ?></span>
                  <?php if ($r['est_arv']): ?><span class="chip"><?= te('Value after renovation') ?> <?= e(money_short($r['est_arv'])) ?></span><?php endif; ?>
                <?php endif; ?>
                <?php if ($r['cond_overall'] && $r['cond_overall'] !== 'not_disclosed'): ?><span class="chip chip-cond"><?= te(Condition::OVERALL[$r['cond_overall']]) ?></span><?php endif; ?>
                <?php foreach ($flags as $fl): ?><span class="chip chip-warn"><?= te(Condition::FLAGS[$fl] ?? $fl) ?></span><?php endforeach; ?>
                <?php if ($r['has_basement']): ?><span class="chip"><?= te('Basement') ?></span><?php endif; ?>
                <?php if ($r['watch_stage']): ?><span class="chip chip-stage"><?= te(App\Services\Pipeline::STAGES[$r['watch_stage']] ?? $r['watch_stage']) ?><?= $r['owner_name'] ? ' · ' . e($r['owner_name']) : '' ?></span><?php endif; ?>
              </div>
            </div>
            <div class="side">
              <?php if ($mode === 'sale'): ?>
              <?php if ($r['score'] === null && empty($r['scored_at'])): ?>
              <span class="score-badge score-wait" title="<?= te('Being scored — ready within a few minutes') ?>">…</span>
              <?php else: ?>
              <span class="score-badge score-<?= $band ?>" title="<?= te('Opportunity score') ?><?= $r['risk'] !== null ? ' · risk ' . (int) $r['risk'] : '' ?>"><?= $r['score'] !== null ? (int) $r['score'] : '—' ?></span>
              <?php endif; ?>
              <?php endif; ?>
              <span class="side-meta">
                <?php if ($mode === 'sold'): ?><?= te('Sold') ?> <?= e(fmt_date($r['last_sale_date'])) ?>
                <?php elseif ($dom !== null): ?><?= $dom === 0 ? te('Listed today') : e(plural($dom, 'day')) ?><?php endif; ?>
              </span>
              <?php if (Auth::can('edit')): ?>
              <form method="post" action="/property/<?= (int) $r['id'] ?>/act">
                <?= csrf_field() ?><input type="hidden" name="do" value="<?= $r['watch_id'] ? 'unwatch' : 'watch' ?>"><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '/search') ?>">
                <button class="star<?= $r['watch_id'] ? ' is-on' : '' ?>" type="submit" title="<?= te($r['watch_id'] ? 'In favorites — remove' : 'Add to favorites') ?>"><?= icon('star', 17) ?><span class="sr"><?= te($r['watch_id'] ? 'Remove from favorites' : 'Add to favorites') ?></span></button>
              </form>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
        </div>
        <?= View::pager($res['total'], $per, $page, $query) ?>
      <?php endif; ?>

      <?php if ($elsewhere): ?>
      <p class="elsewhere"><span><?= te('Same area on') ?></span>
        <?php foreach ($elsewhere as $s): ?><a href="<?= e($s['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($s['name']) ?></a><?php endforeach; ?>
      </p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <aside class="results-map" aria-label="<?= te('Map of results') ?>">
    <div id="map"></div>
    <script type="application/json" id="map-data"><?= json_embed(['points' => $points, 'wheel' => true]) ?></script>
  </aside>
</div>
<?php
View::footer();

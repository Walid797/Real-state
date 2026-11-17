<?php
declare(strict_types=1);

use App\Address;
use App\Db;
use App\Geo;
use App\PropertyRepo;
use App\Providers\Census;
use App\Services\ExternalSites;
use App\Services\Search;
use App\View;

$f = Search::fromRequest($_GET);

/* ── A street address goes straight to that home's page ─────────────── */
if ($f['q'] !== '') {
    $loc = Address::parseLocation($f['q']);
    if ($loc && $loc['type'] === 'address') {
        $hash = Address::hash($loc['address'], $loc['city'], $loc['state'], $loc['zip']);
        $id = (int) Db::val('SELECT id FROM properties WHERE addr_hash = ?', [$hash]);
        if (!$id) {
            $geo = Census::geocode(Address::full($loc)); // free; gives coordinates, ZIP and county
            $id = PropertyRepo::upsert([
                'address' => $loc['address'], 'city' => $loc['city'], 'state' => $loc['state'],
                'zip' => $loc['zip'] ?: ($geo['zip'] ?? ''), 'lat' => $geo['lat'] ?? null, 'lng' => $geo['lng'] ?? null,
                'county' => $geo['county'] ?? null,
            ], 'manual')['id'];
            flash($geo ? 'New address. Nothing is known about it yet — pull its record below.' : 'Saved the address, but the Census geocoder couldn’t place it on the map. Check the spelling.', $geo ? 'ok' : 'warn');
        }
        redirect('/property/' . $id);
    }
}

/* ── Landing: no query yet ──────────────────────────────────────────── */
if ($f['q'] === '') {
    $saved = Db::all('SELECT * FROM saved_searches ORDER BY COALESCE(last_run_at, created_at) DESC LIMIT 8');
    $areas = Db::all(
        'SELECT s.loc_key, s.mode, MAX(s.fetched_at) AS fetched_at, MAX(s.result_count) AS n
         FROM snapshots s GROUP BY s.loc_key, s.mode ORDER BY fetched_at DESC LIMIT 6'
    );
    $moves = Db::all(
        "SELECT e.event, e.price, e.event_date, p.id, p.address, p.city, p.state
         FROM price_events e JOIN properties p ON p.id = e.property_id JOIN watchlist w ON w.property_id = p.id
         ORDER BY e.created_at DESC, e.id DESC LIMIT 6"
    );
    $count = (int) Db::val('SELECT COUNT(*) FROM properties');

    View::header('Search', 'search');
    ?>
    <section class="hero">
      <h1 class="display">Where are you looking?</h1>
      <p>A city and state, a ZIP code, or one street address.</p>
      <form class="bigsearch" method="get" action="/" role="search">
        <?= icon('search', 20) ?>
        <input type="search" name="q" placeholder="Austin, TX  ·  78704  ·  5500 Grand Lake Dr, San Antonio, TX 78244" aria-label="City, ZIP or address" autocomplete="off" autofocus required>
        <span class="seg" role="group" aria-label="Listing type">
          <label><input type="radio" name="mode" value="sale" checked><span>For sale</span></label>
          <label><input type="radio" name="mode" value="sold"><span>Sold</span></label>
        </span>
        <button class="btn btn-primary" type="submit">Search</button>
      </form>
      <p class="tries">
        <?php if ($count > 0): ?>
        <span><?= plural($count, 'home') ?> saved so far. Searching an area you’ve already pulled costs nothing.</span>
        <?php else: ?>
        <span>First search in an area uses one request. After that it’s saved for <?= (int) App\Settings::int('snapshot_hours', 24) ?> hours.</span>
        <?php endif; ?>
      </p>
    </section>

    <div class="home-grid">
      <section class="section">
        <div class="section-head"><h2>Saved searches</h2></div>
        <?php if ($saved): ?>
        <ul class="plain-list">
          <?php foreach ($saved as $s): $p = json_decode($s['params'], true) ?: []; ?>
          <li>
            <a class="main" href="<?= e(url('/', $p)) ?>"><?= e($s['name']) ?><span class="sub"><?= e(Search::describe($p)) ?></span></a>
            <form method="post" action="/search/act" class="end" data-confirm="Remove this saved search?">
              <?= csrf_field() ?><input type="hidden" name="do" value="unsave"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="linkbtn muted" type="submit">Remove</button>
            </form>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="blank">Run a search, set the filters you like, then press <strong>Save</strong>. It will wait for you here.</p>
        <?php endif; ?>
      </section>

      <section class="section">
        <div class="section-head"><h2>Areas on file</h2></div>
        <?php if ($areas): ?>
        <ul class="plain-list">
          <?php foreach ($areas as $a):
              [$type, $rest] = explode(':', $a['loc_key'], 2) + [1 => ''];
              if ($type === 'zip') { $label = $rest; }
              else { [$st, $city] = explode(':', $rest, 2) + [1 => '']; $label = Address::titleCity($city) . ', ' . $st; }
          ?>
          <li>
            <a class="main" href="<?= e(url('/', ['q' => $label, 'mode' => $a['mode'] === 'sold' ? 'sold' : null])) ?>"><?= e($label) ?>
              <span class="sub"><?= $a['mode'] === 'sold' ? 'Recent sales' : 'For sale' ?> · <?= plural((int) $a['n'], 'home') ?></span></a>
            <span class="end"><?= e(ago($a['fetched_at'])) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="blank">Every area you search is kept here with the date it was last pulled.</p>
        <?php endif; ?>
      </section>

      <section class="section">
        <div class="section-head"><h2>Watchlist activity</h2><a class="aside" href="/watchlist">All</a></div>
        <?php if ($moves): ?>
        <ul class="plain-list">
          <?php foreach ($moves as $m): ?>
          <li>
            <a class="main" href="/property/<?= (int) $m['id'] ?>"><?= e($m['address']) ?>
              <span class="sub"><?= e(['listed' => 'Listed', 'price_change' => 'Re-priced', 'sold' => 'Sold', 'removed' => 'Taken off the market', 'relisted' => 'Back on the market'][$m['event']] ?? $m['event']) ?> · <?= e(fmt_date($m['event_date'], 'M j')) ?></span></a>
            <span class="end num"><?= e(money($m['price'])) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="blank">Star a home and its price changes show up here.</p>
        <?php endif; ?>
      </section>
    </div>
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
    foreach (PropertyRepo::search($f + ['loc' => $loc, 'limit' => 400])['rows'] as $r) {
        if (!Geo::valid($r['lat'], $r['lng'])) {
            continue;
        }
        $price = $mode === 'sold' ? $r['last_sale_price'] : $r['list_price'];
        $points[] = [
            'id' => (int) $r['id'], 'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'],
            'label' => money_short($price), 'price' => money($price), 'title' => $r['address'],
            'sub' => PropertyRepo::facts($r), 'href' => '/property/' . (int) $r['id'], 'sold' => $mode === 'sold',
        ];
    }
}

// Area links work better with a city + ZIP; borrow them from the first result when the search was ZIP-only.
$areaLoc = $loc ?? [];
if ($res['rows'] && $loc) {
    $areaLoc += ['city' => $res['rows'][0]['city'], 'state' => $res['rows'][0]['state']];
}
$elsewhere = $loc ? ExternalSites::forArea($areaLoc) : [];
$snap = $res['snapshot'];
$activeMore = count(array_intersect_key($f, array_flip(['sqft_min', 'sqft_max', 'year_min', 'year_max', 'lot_min', 'hoa_max', 'dom_max', 'ppsf_max'])));
$provider = Search::provider();

View::header(($loc['label'] ?? 'Search') . ($mode === 'sold' ? ' — sold' : ''), 'search', ['wide' => true, 'map' => true]);
View::script('/assets/vendor/leaflet/leaflet.js');
?>
<form class="sbar" method="get" action="/" role="search">
  <div class="sbar-row">
    <label class="q"><span class="sr">City, ZIP or address</span><?= icon('search', 16) ?>
      <input class="input" type="search" name="q" value="<?= e($f['q']) ?>" autocomplete="off" required></label>
    <span class="seg" role="group" aria-label="Listing type">
      <label><input type="radio" name="mode" value="sale"<?= checked($mode === 'sale') ?> data-autosubmit><span>For sale</span></label>
      <label><input type="radio" name="mode" value="sold"<?= checked($mode === 'sold') ?> data-autosubmit><span>Sold</span></label>
    </span>
    <label><span class="sr">Lowest price</span><input class="input mini" name="price_min" inputmode="numeric" placeholder="Min $" value="<?= isset($f['price_min']) ? e(number_format($f['price_min'])) : '' ?>"></label>
    <span class="dash">–</span>
    <label><span class="sr">Highest price</span><input class="input mini" name="price_max" inputmode="numeric" placeholder="Max $" value="<?= isset($f['price_max']) ? e(number_format($f['price_max'])) : '' ?>"></label>
    <label><span class="sr">Bedrooms</span>
      <select class="select mini-s" name="beds_min">
        <option value="">Beds</option>
        <?php foreach ([1, 2, 3, 4, 5] as $n): ?><option value="<?= $n ?>"<?= selected($f['beds_min'] ?? '', $n) ?>><?= $n ?>+ bd</option><?php endforeach; ?>
      </select></label>
    <label><span class="sr">Bathrooms</span>
      <select class="select mini-s" name="baths_min">
        <option value="">Baths</option>
        <?php foreach ([1, 1.5, 2, 3, 4] as $n): ?><option value="<?= $n ?>"<?= selected($f['baths_min'] ?? '', $n) ?>><?= $n ?>+ ba</option><?php endforeach; ?>
      </select></label>

    <details class="drop">
      <summary class="btn">Type<?php if ($f['types']): ?><span class="count"><?= count($f['types']) ?></span><?php endif; ?><?= icon('chevron-down', 14) ?></summary>
      <div class="drop-panel">
        <div class="checks">
          <?php foreach (PropertyRepo::TYPES as $t): ?>
          <label class="check"><input type="checkbox" name="types[]" value="<?= e($t) ?>"<?= checked(in_array($t, $f['types'], true)) ?>><?= e($t) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
    </details>

    <details class="drop">
      <summary class="btn"><?= icon('filter', 14) ?>More<?php if ($activeMore): ?><span class="count"><?= $activeMore ?></span><?php endif; ?></summary>
      <div class="drop-panel">
        <div class="field"><span>Living area, sqft</span>
          <div class="pair"><input class="input" name="sqft_min" inputmode="numeric" placeholder="Min" value="<?= e($f['sqft_min'] ?? '') ?>" aria-label="Minimum sqft">
          <input class="input" name="sqft_max" inputmode="numeric" placeholder="Max" value="<?= e($f['sqft_max'] ?? '') ?>" aria-label="Maximum sqft"></div></div>
        <div class="field"><span>Year built</span>
          <div class="pair"><input class="input" name="year_min" inputmode="numeric" placeholder="From" value="<?= e($f['year_min'] ?? '') ?>" aria-label="Built from">
          <input class="input" name="year_max" inputmode="numeric" placeholder="To" value="<?= e($f['year_max'] ?? '') ?>" aria-label="Built to"></div></div>
        <div class="pair">
          <label class="field"><span>Lot, min sqft</span><input class="input" name="lot_min" inputmode="numeric" value="<?= e($f['lot_min'] ?? '') ?>"></label>
          <label class="field"><span>HOA, max $/mo</span><input class="input" name="hoa_max" inputmode="numeric" placeholder="0 = none" value="<?= e($f['hoa_max'] ?? '') ?>"></label>
        </div>
        <div class="pair">
          <label class="field"><span>Max $/sqft</span><input class="input" name="ppsf_max" inputmode="numeric" value="<?= e($f['ppsf_max'] ?? '') ?>"></label>
          <?php if ($mode === 'sold'): ?>
          <label class="field"><span>Sold within</span>
            <select class="select" name="sold_days">
              <?php foreach ([30, 90, 180, 365, 730] as $d): ?><option value="<?= $d ?>"<?= selected($f['sold_days'], $d) ?>><?= e(ucfirst(Search::daysLabel($d))) ?></option><?php endforeach; ?>
            </select></label>
          <?php else: ?>
          <label class="field"><span>Listed within, days</span><input class="input" name="dom_max" inputmode="numeric" value="<?= e($f['dom_max'] ?? '') ?>"></label>
          <?php endif; ?>
        </div>
      </div>
    </details>

    <input type="hidden" name="sort" value="<?= e($f['sort']) ?>">
    <button class="btn btn-primary" type="submit">Search</button>
    <?php if (count($query) > 1 + ($mode === 'sold' ? 1 : 0)): ?>
    <a class="btn btn-ghost" href="<?= e(url('/', ['q' => $f['q'], 'mode' => $mode === 'sold' ? 'sold' : null])) ?>">Clear</a>
    <?php endif; ?>
  </div>
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
        <h1 class="display"><?= number_format($res['total']) ?> <?= $mode === 'sold' ? ($res['total'] === 1 ? 'sale' : 'sales') : ($res['total'] === 1 ? 'home for sale' : 'homes for sale') ?> in <?= e($loc['label']) ?></h1>
        <p class="results-meta">
          <?php if ($snap && $res['partial']): ?>
            From what was saved when <strong><?= e(App\Quota::PROVIDERS[$snap['provider']] ?? $snap['provider']) ?></strong> was last asked about this area, <?= e(ago($snap['fetched_at'])) ?>.
            These exact filters weren’t part of that pull, so there may be more<?= $provider ? ' — “Pull these filters” fetches them.' : '.' ?>
          <?php elseif ($snap): ?>
            <?= $res['fetched'] ? 'Just pulled' : 'Pulled' ?> from <strong><?= e(App\Quota::PROVIDERS[$snap['provider']] ?? $snap['provider']) ?></strong>
            <?= $res['fetched'] ? '— one request used.' : e(ago($snap['fetched_at'])) . ' — this search cost nothing.' ?>
          <?php else: ?>
            From your saved data only.
          <?php endif; ?>
          <?php if ($mode === 'sold'): ?> Sale prices come from county records; a few states don’t publish them.<?php endif; ?>
        </p>
      </header>

      <?php if ($res['notice']): ?>
      <p class="note" role="status"><?= icon('info', 16) ?><span><?= e($res['notice']) ?></span></p>
      <?php endif; ?>

      <div class="results-tools">
        <form method="post" action="/search/act" class="inline">
          <?= csrf_field() ?><input type="hidden" name="do" value="save">
          <input type="hidden" name="params" value="<?= e(json_encode($query)) ?>">
          <button class="btn btn-sm" type="submit"><?= icon('bookmark', 14) ?>Save</button>
        </form>
        <a class="btn btn-sm" href="<?= e(url('/alerts/new', $query + ['kind' => $mode === 'sold' ? 'new_sale' : 'new_listing'])) ?>"><?= icon('bell', 14) ?>Alert me</a>
        <?php if ($res['total'] > 0): ?>
        <a class="btn btn-sm" href="<?= e(url('/export.csv', $query)) ?>"><?= icon('download', 14) ?>CSV</a>
        <?php endif; ?>
        <?php if ($provider): ?>
        <form method="post" action="/search/act" class="inline"<?= $res['partial'] ? '' : ' data-confirm="Pull this area again? It uses one request."' ?>>
          <?= csrf_field() ?><input type="hidden" name="do" value="refresh">
          <input type="hidden" name="params" value="<?= e(json_encode($query)) ?>">
          <button class="btn btn-sm<?= $res['partial'] ? ' btn-primary' : '' ?>" type="submit"><?= icon('refresh-cw', 14) ?><?= $res['partial'] ? 'Pull these filters' : 'Refresh' ?><span class="cost">1 request</span></button>
        </form>
        <?php endif; ?>
        <span class="grow"></span>
        <form method="get" action="/" class="inline">
          <?php foreach ($query as $k => $v): if ($k === 'sort') continue; foreach ((array) $v as $vv): ?>
          <input type="hidden" name="<?= e($k . (is_array($v) ? '[]' : '')) ?>" value="<?= e($vv) ?>">
          <?php endforeach; endforeach; ?>
          <label><span class="sr">Sort</span>
            <select class="select" name="sort" data-autosubmit>
              <?php foreach (PropertyRepo::SORTS as $k => $label): ?><option value="<?= e($k) ?>"<?= selected($f['sort'], $k) ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></label>
        </form>
      </div>

      <?php if (!$res['rows']): ?>
        <div class="empty">
          <?php
          $any = PropertyRepo::search(['loc' => $loc, 'mode' => $mode, 'sold_days' => $f['sold_days'], 'limit' => 1])['total'];
          if ($any > 0): ?>
            <h2 class="display">Nothing matches those filters.</h2>
            <p><?= plural($any, 'home') ?> <?= $mode === 'sold' ? 'sold' : 'are for sale' ?> in <?= e($loc['label']) ?> without them.</p>
            <p><a class="btn" href="<?= e(url('/', ['q' => $f['q'], 'mode' => $mode === 'sold' ? 'sold' : null])) ?>">Clear filters</a></p>
          <?php elseif (!$provider && !$snap): ?>
            <h2 class="display">Nothing on file for <?= e($loc['label']) ?> yet.</h2>
            <p>Connect RentCast (free, 50 requests a month) and this search fills in. Or add homes by hand from any site.</p>
            <p class="actions"><a class="btn btn-primary" href="/settings#sources">Connect a source</a><a class="btn" href="/add">Add a home</a></p>
          <?php else: ?>
            <h2 class="display">No <?= $mode === 'sold' ? 'recorded sales' : 'listings' ?> came back.</h2>
            <p><?= $mode === 'sold' ? 'Try a longer period, or check the spelling of the city.' : 'Check the spelling of the city, or try its ZIP code.' ?></p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="rows">
        <?php foreach ($res['rows'] as $r):
            $price = $mode === 'sold' ? $r['last_sale_price'] : $r['list_price'];
            $pp = ppsf($price, $r['sqft']);
            $dom = PropertyRepo::dom($r);
        ?>
          <article class="hrow" data-pid="<?= (int) $r['id'] ?>">
            <div class="price"><?= e(money($price)) ?>
              <?php if ($pp): ?><small>$<?= number_format($pp) ?>/sqft</small><?php endif; ?></div>
            <div class="body">
              <a class="addr" href="/property/<?= (int) $r['id'] ?>"><?= e($r['address']) ?></a>
              <span class="where"><?= e($r['city']) ?>, <?= e($r['state']) ?> <?= e($r['zip']) ?></span>
              <div class="facts"><?= e(PropertyRepo::facts($r) ?: 'Details not on file') ?><?= $r['property_type'] ? ' · ' . e($r['property_type']) : '' ?></div>
            </div>
            <div class="side">
              <?php if ($mode === 'sold'): ?>
                <span>Sold <?= e(fmt_date($r['last_sale_date'])) ?></span>
              <?php else: ?>
                <?php if ($r['status'] === 'pending' || ($r['listing_type'] && $r['listing_type'] !== 'Standard')): ?><?= View::statusTag($r) ?><?php endif; ?>
                <?php if ($dom !== null): ?><span><?= $dom === 0 ? 'Listed today' : ($dom === 1 ? 'Listed yesterday' : $dom . ' days listed') ?></span><?php endif; ?>
              <?php endif; ?>
              <form method="post" action="/property/<?= (int) $r['id'] ?>/act">
                <?= csrf_field() ?><input type="hidden" name="do" value="<?= $r['watch_id'] ? 'unwatch' : 'watch' ?>"><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '/') ?>">
                <button class="star<?= $r['watch_id'] ? ' is-on' : '' ?>" type="submit" title="<?= $r['watch_id'] ? 'On your watchlist — remove' : 'Add to watchlist' ?>"><?= icon('star', 17) ?><span class="sr"><?= $r['watch_id'] ? 'Remove from watchlist' : 'Add to watchlist' ?></span></button>
              </form>
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

<?php
declare(strict_types=1);

use App\Db;
use App\PropertyRepo;
use App\View;

$stages = ['considering' => 'Considering', 'visited' => 'Visited', 'offer' => 'Offer made', 'passed' => 'Passed', 'bought' => 'Bought'];

$rows = Db::all(
    "SELECT p.*, w.stage, w.source_url AS watch_url, w.created_at AS watched_at,
            (SELECT e.price FROM price_events e WHERE e.property_id = p.id AND e.event IN ('listed','relisted') AND e.price > 0 ORDER BY e.event_date ASC, e.id ASC LIMIT 1) AS first_price,
            (SELECT e.event_date FROM price_events e WHERE e.property_id = p.id AND e.event = 'price_change' ORDER BY e.event_date DESC, e.id DESC LIMIT 1) AS last_change
     FROM watchlist w JOIN properties p ON p.id = w.property_id
     ORDER BY FIELD(w.stage, 'offer','visited','considering','bought','passed'), w.updated_at DESC"
);

$active = array_filter($rows, static fn ($r) => !in_array($r['stage'], ['passed', 'bought'], true));
$asking = array_sum(array_map(static fn ($r) => $r['status'] === 'for_sale' ? (int) $r['list_price'] : 0, $active));
$nSale = count(array_filter($active, static fn ($r) => $r['status'] === 'for_sale'));

View::header('Watchlist', 'watchlist');
?>
<header class="page-head">
  <div>
    <h1 class="display">Watchlist</h1>
    <p><?php if ($rows): ?><?= plural(count($active), 'home') ?> in play<?= $nSale ? ', average asking ' . e(money($asking / $nSale)) : '' ?>.<?php else: ?>Homes you’re following, from this site or any other.<?php endif; ?></p>
  </div>
  <div class="actions">
    <a class="btn" href="/alerts/new?kind=price_drop"><?= icon('bell', 15) ?>Alert on price cuts</a>
    <a class="btn btn-primary" href="/add?watch=1"><?= icon('plus', 15) ?>Add a home</a>
  </div>
</header>

<?php if (!$rows): ?>
  <div class="empty">
    <h2 class="display">Nothing here yet.</h2>
    <p>Press the star on any search result, or paste in a listing you found on Zillow, Redfin or anywhere else. Each home keeps its own price history and notes.</p>
    <p class="actions"><a class="btn btn-primary" href="/add?watch=1">Add a home by hand</a><a class="btn" href="/">Search</a></p>
  </div>
<?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Home</th><th>Stage</th><th class="r">Price</th><th class="r">Since first listed</th><th class="r">$/sqft</th><th>Latest</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $sale = $r['status'] === 'for_sale' || $r['status'] === 'pending';
          $price = $sale ? (int) $r['list_price'] : (int) $r['last_sale_price'];
          $first = (int) ($r['first_price'] ?? 0);
          $delta = ($sale && $first > 0 && $price > 0 && $first !== $price) ? ($price - $first) / $first * 100 : null;
          $pp = ppsf($price, $r['sqft']);
      ?>
        <tr>
          <td><a class="main" href="/property/<?= (int) $r['id'] ?>"><?= e($r['address']) ?></a>
            <span class="sub"><?= e($r['city']) ?>, <?= e($r['state']) ?> · <?= e(PropertyRepo::facts($r) ?: 'details not on file') ?></span>
            <?php if ($r['notes']): ?><span class="sub">“<?= e(mb_strimwidth((string) $r['notes'], 0, 90, '…')) ?>”</span><?php endif; ?></td>
          <td>
            <form method="post" action="/property/<?= (int) $r['id'] ?>/act">
              <?= csrf_field() ?><input type="hidden" name="do" value="stage">
              <label class="sr" for="st<?= (int) $r['id'] ?>">Stage</label>
              <select class="select stage" id="st<?= (int) $r['id'] ?>" name="stage" data-autosubmit>
                <?php foreach ($stages as $k => $label): ?><option value="<?= $k ?>"<?= selected($r['stage'], $k) ?>><?= $label ?></option><?php endforeach; ?>
              </select>
            </form>
          </td>
          <td class="r"><?= e(money($price)) ?><span class="sub"><?= $sale ? ($r['status'] === 'pending' ? 'pending' : 'asking') : ($r['last_sale_date'] ? 'sold ' . e(fmt_date($r['last_sale_date'], 'M Y')) : 'off market') ?></span></td>
          <td class="r"><?php if ($delta !== null): ?><span class="<?= $delta < 0 ? 'down' : 'up' ?>"><?= e(pct($delta)) ?></span><span class="sub">from <?= e(money_short($first)) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
          <td class="r"><?= $pp ? '$' . number_format($pp) : '—' ?></td>
          <td class="nowrap"><?= $r['last_change'] ? 'Re-priced ' . e(fmt_date($r['last_change'], 'M j')) : 'Added ' . e(fmt_date($r['watched_at'], 'M j')) ?>
            <?php if ($r['watch_url']): ?><span class="sub"><a href="<?= e($r['watch_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e(App\Services\ExternalSites::host($r['watch_url'])) ?></a></span><?php endif; ?></td>
          <td class="r">
            <form method="post" action="/property/<?= (int) $r['id'] ?>/act" data-confirm="Take this home off your watchlist?">
              <?= csrf_field() ?><input type="hidden" name="do" value="unwatch"><input type="hidden" name="back" value="/watchlist">
              <button class="linkbtn muted" type="submit">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="section-note">Prices update whenever a search or an alert pulls the home’s area again. To record a change you saw elsewhere, open the home and choose “Edit facts &amp; price”.</p>
<?php endif; ?>
<?php
View::footer();

<?php
declare(strict_types=1);

use App\Address;
use App\Geo;
use App\PropertyRepo;
use App\Providers\OpenWebNinja;
use App\Providers\RentCast;
use App\Quota;
use App\Auth;
use App\Db;
use App\Services\Activity;
use App\Services\Comps;
use App\Services\Condition;
use App\Services\Deal;
use App\Services\ExternalSites;
use App\Services\Pipeline;
use App\Services\Score;
use App\View;

$p = PropertyRepo::find((int) $params['id']);
if (!$p) {
    http_response_code(404);
    View::header('Not found');
    echo '<div class="empty">' . View::sketch('home-missing') . '<h1 class="display">That home isn’t on file.</h1><p>It may have been deleted. Search for the address to add it again.</p><p><a class="btn" href="/search">Back to search</a></p></div>';
    View::footer();
    return;
}

$id = (int) $p['id'];
// Fairfax County public record: free and quick, so it is looked up the first time a home is opened.
if (App\Services\CountyRecords::wanted($p) && App\Services\CountyRecords::enrich($p) === 'found') {
    Score::refresh((int) $p['id']);
    $p = PropertyRepo::find((int) $p['id']);
}
$id = (int) $p['id'];
$d = $p['details'];
$photos = App\Services\PropertyPhotos::gallery($p);
$homesPhotos = App\Services\PropertyPhotos::homes($p);
$events = PropertyRepo::events($id);
$hasGeo = Geo::valid($p['lat'], $p['lng']);
$forSale = in_array($p['status'], ['for_sale', 'pending'], true) && (int) $p['list_price'] > 0;
$headline = $forSale ? (int) $p['list_price'] : (int) ($p['last_sale_price'] ?? 0);
$pp = ppsf($headline, $p['sqft']);
$dom = $forSale ? PropertyRepo::dom($p) : null;

/* Comparable sales, with the radius / period picked in the toolbar. */
$optR = isset($_GET['r']) && in_array((float) $_GET['r'], Comps::RADII, true) ? (float) $_GET['r'] : null;
$optM = isset($_GET['m']) && in_array((int) $_GET['m'], Comps::MONTHS, true) ? (int) $_GET['m'] : null;
$compOpt = array_filter(['radius' => $optR, 'months' => $optM], static fn ($v) => $v !== null);
$comps = Comps::find($p, $compOpt);
$stats = $comps['stats'];

$points = [];
if ($hasGeo) {
    $points[] = ['id' => $id, 'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'label' => $headline ? money_short($headline) : 'Here', 'subject' => true];
    foreach ($comps['comps'] as $c) {
        $points[] = ['id' => (int) $c['id'], 'lat' => (float) $c['lat'], 'lng' => (float) $c['lng'], 'label' => money_short($c['last_sale_price']),
            'price' => money($c['last_sale_price']), 'title' => $c['address'], 'sub' => 'Sold ' . fmt_date($c['last_sale_date']), 'href' => '/property/' . (int) $c['id'], 'sold' => true];
    }
}

/* Price history chart: every dated price we know, oldest first. */
$series = [];
foreach (array_reverse($events) as $ev) {
    if ((int) $ev['price'] > 0) {
        $series[] = ['x' => fmt_date($ev['event_date'], 'M Y'), 'y' => (int) $ev['price']];
    }
}

$links = ExternalSites::forProperty($p);
$rcReady = RentCast::enabled();
$ownReady = OpenWebNinja::enabled();
$rcLeft = $rcReady ? Quota::left('rentcast') : 0;
$ownLeft = $ownReady ? Quota::left('openwebninja') : 0;
$eventLabel = ['listed' => 'Listed', 'price_change' => 'Price changed', 'sold' => 'Sold', 'removed' => 'Taken off the market', 'relisted' => 'Listed again'];
$backQ = $p['city'] !== '' ? $p['city'] . ', ' . $p['state'] : $p['zip'];

/* Investment analysis. Stored figures are refreshed when the record changed since the last scoring. */
if ($p['scored_at'] === null || $p['scored_at'] < $p['updated_at'] || strtotime($p['scored_at'] . ' UTC') < time() - 86400) {
    $an = Score::refresh($id);
    $p = PropertyRepo::find($id);
} else {
    $cond = Condition::get($id);
    $deal = Deal::analyse($p, $cond);
    $an = ['deal' => $deal, 'condition' => $cond, 'score' => Score::compute($p, $deal, $cond)];
}
$deal = $an['deal'];
$cond = $an['condition'];
$sc = $an['score'];
App\Services\PropertyAnalysis::enqueue($id);
$canEdit = Auth::can('edit');
$team = Db::all("SELECT id, COALESCE(NULLIF(name, ''), email) AS name FROM users WHERE is_active = 1 AND role <> 'viewer' ORDER BY name");
$notes = Db::all('SELECT n.*, COALESCE(NULLIF(u.name, \'\'), u.email) AS author FROM notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.property_id = ? ORDER BY n.id DESC', [$id]);
$history = Activity::forProperty($id, 30);
$original = $p['watch_url'] ?: $p['source_url'];
$dataLabels = ['full' => 'Full details', 'limited' => 'Limited details', 'link_only' => 'Link only', 'link_dead' => 'Link no longer works', 'removed' => 'Listing removed or sold'];

$pull = static function (string $do, string $label, string $cost, bool $on, string $confirm = '') use ($id): string {
    return '<form method="post" action="/property/' . $id . '/act"' . ($confirm ? ' data-confirm="' . te($confirm) . '"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="do" value="' . e($do) . '">'
        . '<button class="btn" type="submit"' . ($on ? '' : ' disabled') . '>' . te($label) . '<span class="cost">' . te($cost) . '</span></button></form>';
};

if ($hasGeo) {
    // Nearby places are looked up by the browser (OpenStreetMap); the 3D view talks to Cesium ion and Google's tiles.
    $aerialOn = App\Settings::has('cesium_key');
    App\Kernel::csp($aerialOn, true);
    if ($aerialOn) {
        View::script('/assets/aerial.js');
    }
}
View::header($p['address'], '', ['map' => $hasGeo]);
if ($hasGeo) {
    View::script('/assets/vendor/leaflet/leaflet.js');
}
if (count($series) >= 2) {
    View::script('/assets/vendor/chart.umd.js');
}
?>
<nav class="crumbs" aria-label="<?= te('Back') ?>">
  <a href="<?= e(url('/search', ['q' => $backQ])) ?>"><?= icon('arrow-left', 14) ?><?= e($backQ) ?></a>
  <?php if ($p['watch_id']): ?><span>·</span><a href="/pipeline"><?= te('Pipeline') ?></a><?php endif; ?>
</nav>

<nav class="setting-nav no-print" aria-label="<?= te('Property sections') ?>">
  <a href="#property-facts"><?= te('Property Details') ?></a>
  <a href="#deal"><?= te('Financial') ?></a>
  <a href="#property-intelligence"><?= te('AI Analysis') ?></a>
</nav>
<header class="p-head" id="property-facts">
  <div>
    <div class="tags">
      <?= View::statusTag($p) ?>
      <?php if ($p['property_type']): ?><span class="tag"><?= te($p['property_type']) ?></span><?php endif; ?>
      <?php if ($p['source'] === 'manual'): ?><span class="tag"><?= te('Added by hand') ?></span><?php endif; ?>
      <span class="tag tag-data-<?= e($p['data_status']) ?>" title="<?= te('How much of the listing we have') ?>"><?= te($dataLabels[$p['data_status']] ?? $p['data_status']) ?></span>
      <?php foreach (array_filter(explode(',', (string) $p['flags'])) as $fl): ?><span class="tag tag-warn"><?= te(Condition::FLAGS[$fl] ?? $fl) ?></span><?php endforeach; ?>
    </div>
    <h1 class="display" dir="auto"><?= e($p['address']) ?></h1>
    <p class="where" dir="auto"><?= e(trim($p['city'] . ', ' . $p['state'] . ' ' . $p['zip'], ', ')) ?><?= $p['county'] ? ' · ' . e($p['county']) . ' County' : '' ?></p>
  </div>
  <div class="p-price">
    <?php if ($headline): ?>
      <div class="price"><?= e(money($headline)) ?></div>
      <p class="sub">
        <?php if ($forSale): ?>
          <?= $pp ? '$' . number_format($pp) . '/sqft' : 'Asking price' ?><?= $dom !== null ? ' · ' . ($dom === 0 ? 'listed today' : plural($dom, 'day') . ' on the market') : '' ?>
        <?php else: ?>
          <?= te('Last sold') ?> <?= e(fmt_date($p['last_sale_date'])) ?><?= $pp ? ' · $' . number_format($pp) . '/sqft' : '' ?>
        <?php endif; ?>
      </p>
    <?php else: ?>
      <p class="sub"><?= te('No price on file yet.') ?></p>
    <?php endif; ?>
    <div class="actions head-actions">
      <button class="btn btn-sm no-print" type="button" data-print><?= icon('printer', 15) ?><?= te('PDF / print') ?></button>
      <?php if ($original): ?>
      <a class="btn btn-sm" href="<?= e($original) ?>" target="_blank" rel="noopener noreferrer"><?= icon('external-link', 14) ?><?= te('View original listing') ?></a>
      <?php endif; ?>
      <?php if ($canEdit && !$p['watch_id']): ?>
      <form method="post" action="/property/<?= $id ?>/act" class="inline">
        <?= csrf_field() ?><input type="hidden" name="do" value="watch">
        <button class="btn btn-sm btn-primary" type="submit"><?= icon('star', 14) ?><?= te('Add to pipeline') ?></button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</header>

<?php if ($photos): ?>
<?php if ($homesPhotos): ?><p class="small photo-source"><?= te('Photos from Homes.com for this exact home') ?></p><?php endif; ?>
<div class="photos" aria-label="<?= te('Listing photos') ?>">
  <?php foreach (array_slice($photos, 0, 24) as $i => $src): ?>
  <img src="<?= e($src) ?>" alt="<?= te('Photo') ?> <?= $i + 1 ?> <?= te('of') ?> <?= e($p['address']) ?>" loading="lazy" referrerpolicy="no-referrer">
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($p['watch_id'] && in_array($p['watch_stage'], Pipeline::ACTIVE, true) && $p['owner_id'] && (int) $p['owner_id'] !== Auth::userId()): ?>
<p class="claim-bar"><?= icon('user-check', 16) ?><span><strong><?= e($p['owner_name']) ?></strong> <?= te('is handling this home —') ?> <?= te(Pipeline::STAGES[$p['watch_stage']]) ?> <?= te('since') ?> <?= e(fmt_date($p['stage_at'], 'M j')) ?><?= te('. Coordinate before contacting the agent.') ?></span></p>
<?php endif; ?>

<?php include APP_ROOT . '/pages/partials/scorebar.php'; ?>

<div class="facts-strip">
  <div><b><?= $p['beds'] !== null ? e(trim_num($p['beds'])) : '—' ?></b><span><?= te('Bedrooms') ?></span></div>
  <div><b><?= $p['baths'] !== null ? e(trim_num($p['baths'])) : '—' ?></b><span><?= te('Bathrooms') ?></span></div>
  <div><b><?= $p['sqft'] ? number_format((int) $p['sqft']) : '—' ?></b><span><?= te('Sqft living area') ?></span></div>
  <div><b><?= e(PropertyRepo::lot($p['lot_sqft'])) ?></b><span><?= te('Lot') ?></span></div>
  <div><b><?= $p['year_built'] ? (int) $p['year_built'] : '—' ?></b><span><?= te('Year built') ?></span></div>
  <div><b><?= $p['hoa_fee'] !== null ? ((float) $p['hoa_fee'] > 0 ? '$' . number_format((float) $p['hoa_fee']) : 'None') : '—' ?></b><span><?= te('HOA per month') ?></span></div>
</div>



<?php include APP_ROOT . '/pages/partials/aerial.php'; ?>

<div class="p-cols">
  <div>
    <?php if ($p['description']): ?>
    <section class="section">
      <?php $descLang = App\Locale::language(); $descTr = $descLang !== 'en' ? App\Services\Translate::stored($p, $descLang) : null; ?>
      <div class="section-head"><h2><?= te('From the listing') ?></h2></div>
      <?php if ($descLang === 'en'): ?>
      <p class="desc"><?= e($p['description']) ?></p>
      <?php else: ?>
      <div class="desc-tr"<?= $descTr === null ? ' data-translate="/api/translate/' . (int) $p['id'] . '" data-csrf="' . e(App\Auth::csrfToken()) . '"' : '' ?>>
        <p class="desc desc-translated" dir="auto"><?= $descTr !== null ? e($descTr) : '<span class="muted">' . te('Translating the listing text…') . '</span>' ?></p>
        <p class="desc desc-original" dir="ltr" lang="en"<?= $descTr !== null ? ' hidden' : '' ?>><?= e($p['description']) ?></p>
        <p class="small muted desc-tr-foot"><span class="desc-tr-note"><?= $descTr !== null ? te('Machine translation of the listing text.') : '' ?></span>
          <button type="button" class="desc-toggle"<?= $descTr === null ? ' hidden' : '' ?>><?= te('Show original') ?></button></p>
      </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php include APP_ROOT . '/pages/partials/listing_details.php'; ?>
    <?php include APP_ROOT . '/pages/partials/county_record.php'; ?>
    <?php include APP_ROOT . '/pages/partials/area_pace.php'; ?>

    <?php include APP_ROOT . '/pages/partials/deal.php'; ?>
    <?php include APP_ROOT . '/pages/partials/condition.php'; ?>

    <section class="section" id="comps">
      <div class="section-head"><h2><?= te('Comparable sales') ?></h2>
        <span class="aside"><?= te('within') ?> <?= e(trim_num($comps['radius'])) ?> <?= te('mi · last') ?> <?= (int) $comps['months'] ?> <?= te('months') ?><?= $comps['widened'] ? ' (widened)' : '' ?></span></div>

      <?php if (!$hasGeo): ?>
        <p class="blank"><?= te('This address has no map position, so nearby sales can’t be matched. Edit the address or pull its public record.') ?></p>
      <?php elseif (!$comps['comps']): ?>
        <p class="blank"><?= te('No recorded sales on file within') ?> <?= e(trim_num($comps['radius'])) ?> <?= te('mile') ?><?= $comps['radius'] == 1 ? '' : 's' ?><?= te('. Pull sales for this neighbourhood from the panel on the right, or add ones you find elsewhere by hand.') ?></p>
      <?php else: ?>
        <div class="comp-sum">
          <div><b><?= (int) $stats['count'] ?></b><span><?= te('Sales matched') ?></span></div>
          <div><b><?= e(money($stats['median_price'])) ?></b><span><?= te('Median price') ?></span></div>
          <div><b><?= $stats['median_ppsf'] ? '$' . number_format($stats['median_ppsf']) : '—' ?></b><span><?= te('Median $/sqft') ?></span><?php if ($pp && $stats['median_ppsf']): $gapP = ($pp - $stats['median_ppsf']) / $stats['median_ppsf'] * 100; ?><span class="<?= $gapP > 0 ? 'up' : 'down' ?>"><?= te('this home') ?> <?= e(pct($gapP, 0)) ?></span><?php endif; ?></div>
          <div><b><?= $stats['value'] ? e(money($stats['value'])) : '—' ?></b><span><?= te('Implied value') ?></span><span><?= $stats['value'] ? e(money_short($stats['value_low'])) . ' – ' . e(money_short($stats['value_high'])) : 'needs 3 sales with sqft' ?></span></div>
        </div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th><?= te('Address') ?></th><th><?= te('Sold') ?></th><th class="r"><?= te('Price') ?></th><th class="r"><?= te('$/sqft') ?></th><th class="r"><?= te('Size') ?></th><th class="r"><?= te('Away') ?></th><th class="r" title="<?= te('How alike: distance, size, age, recency') ?>"><?= te('Match') ?></th></tr></thead>
            <tbody>
            <?php foreach ($comps['comps'] as $c): $sc = (int) $c['score']; ?>
              <tr data-pid="<?= (int) $c['id'] ?>">
                <td><a class="main" href="/property/<?= (int) $c['id'] ?>"><?= e($c['address']) ?></a>
                  <span class="sub"><?= e(PropertyRepo::facts($c + ['year_built' => $c['year_built']]) ?: '—') ?></span></td>
                <td class="nowrap"><?= e(fmt_date($c['last_sale_date'], 'M j, Y')) ?></td>
                <td class="r"><?= e(money($c['last_sale_price'])) ?></td>
                <td class="r"><?= $c['ppsf'] ? '$' . number_format($c['ppsf']) : '—' ?></td>
                <td class="r"><?= $c['sqft'] ? number_format((int) $c['sqft']) : '—' ?></td>
                <td class="r"><?= number_format($c['distance'], 2) ?> <?= te('mi') ?></td>
                <td class="r"><span class="score<?= $sc >= 70 ? '' : ($sc >= 50 ? ' mid' : ' low') ?>"><?= $sc ?></span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if ($hasGeo): ?>
      <form class="toolbar" method="get" action="/property/<?= $id ?>#comps">
        <label class="small muted" for="r"><?= te('Radius') ?></label>
        <select class="select" id="r" name="r" data-autosubmit>
          <?php foreach (Comps::RADII as $r): ?><option value="<?= $r ?>"<?= selected($comps['radius'], $r) ?>><?= $r ?> <?= te('mi') ?></option><?php endforeach; ?>
        </select>
        <label class="small muted" for="m"><?= te('Sold in the last') ?></label>
        <select class="select" id="m" name="m" data-autosubmit>
          <?php foreach (Comps::MONTHS as $m): ?><option value="<?= $m ?>"<?= selected($comps['months'], $m) ?>><?= $m ?> <?= te('months') ?></option><?php endforeach; ?>
        </select>
        <a class="small" href="<?= e(url('/add', ['near' => $id])) ?>"><?= te('Add a sale by hand') ?></a>
      </form>
      <p class="section-note"><?= te('Same property type, living area within 35%. Sale prices are public-record figures; Texas, Utah and a few other states don’t disclose them.') ?></p>
      <?php endif; ?>
    </section>

    <?php if (!empty($d['rc_comps'])): ?>
    <section class="section">
      <div class="section-head"><h2><?= te('Comparable listings') ?></h2><span class="aside"><?= te('asking prices RentCast used for its estimate') ?></span></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th><?= te('Address') ?></th><th><?= te('Status') ?></th><th class="r"><?= te('Asking') ?></th><th class="r"><?= te('Size') ?></th><th class="r"><?= te('Away') ?></th><th class="r"><?= te('Match') ?></th></tr></thead>
          <tbody>
          <?php foreach (array_slice($d['rc_comps'], 0, 12) as $c): ?>
            <tr>
              <td><?php if (!empty($c['id'])): ?><a class="main" href="/property/<?= (int) $c['id'] ?>"><?= e($c['address']) ?></a><?php else: ?><?= e($c['address']) ?><?php endif; ?>
                <span class="sub"><?= e(trim(($c['beds'] !== null ? trim_num($c['beds']) . ' bd · ' : '') . ($c['baths'] !== null ? trim_num($c['baths']) . ' ba' : ''))) ?></span></td>
              <td><?= ($c['status'] ?? '') === 'for_sale' ? '<span class="tag tag-live">Active</span>' : '<span class="tag">Ended</span>' ?></td>
              <td class="r"><?= e(money($c['price'])) ?></td>
              <td class="r"><?= !empty($c['sqft']) ? number_format((int) $c['sqft']) : '—' ?></td>
              <td class="r"><?= isset($c['distance']) ? number_format((float) $c['distance'], 2) . ' mi' : '—' ?></td>
              <td class="r"><?= isset($c['correlation']) ? '<span class="score">' . (int) round($c['correlation'] * 100) . '</span>' : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>

    <section class="section">
      <div class="section-head"><h2><?= te('Price history') ?></h2></div>
      <?php if (!$events): ?>
        <p class="blank"><?= te('No listing or sale events on file. Pull the public record for past sales, or the Zillow-data history for every listing and price change.') ?></p>
      <?php else: ?>
        <?php if (count($series) >= 2): ?>
        <div class="chart-box short"><canvas data-chart="price-chart" aria-label="<?= te('Price history chart') ?>" role="img"></canvas></div>
        <script type="application/json" id="price-chart"><?= json_embed(['format' => 'usd', 'labels' => array_column($series, 'x'), 'series' => [['name' => 'Price', 'data' => array_column($series, 'y'), 'points' => true, 'stepped' => true]]]) ?></script>
        <?php endif; ?>
        <ol class="timeline">
          <?php foreach ($events as $i => $ev):
              $prev = null;
              for ($j = $i + 1; $j < count($events); $j++) { if ((int) $events[$j]['price'] > 0) { $prev = (int) $events[$j]['price']; break; } }
              $chg = ($ev['event'] === 'price_change' && $prev && (int) $ev['price'] > 0) ? ((int) $ev['price'] - $prev) / $prev * 100 : null;
          ?>
          <li>
            <span class="when"><?= e(fmt_date($ev['event_date'])) ?></span>
            <span><?= te($eventLabel[$ev['event']] ?? $ev['event']) ?><?php if ($chg !== null): ?> <span class="<?= $chg < 0 ? 'down' : 'up' ?> num"><?= e(pct($chg)) ?></span><?php endif; ?></span>
            <span class="amt"><?= e(money($ev['price'])) ?></span>
          </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
    </section>

    <?php
    $taxYears = [];
    foreach ((array) ($d['taxes'] ?? []) as $y => $t) { $taxYears[(int) $y]['tax'] = $t['total'] ?? null; }
    foreach ((array) ($d['assessments'] ?? []) as $y => $a) { $taxYears[(int) $y] = ($taxYears[(int) $y] ?? []) + ['value' => $a['value'] ?? null, 'land' => $a['land'] ?? null, 'impr' => $a['improvements'] ?? null]; }
    foreach ((array) ($d['zillow_taxes'] ?? []) as $y => $t) { $taxYears[(int) $y] = ($taxYears[(int) $y] ?? []) + ['tax' => $t['total'] ?? null, 'value' => $t['value'] ?? null]; }
    krsort($taxYears);
    ?>
    <?php if ($taxYears): ?>
    <section class="section">
      <div class="section-head"><h2><?= te('Taxes and assessed value') ?></h2>
        <?php if ($p['tax_annual'] && $headline): ?><span class="aside"><?= te('effective rate') ?> <?= number_format((int) $p['tax_annual'] / $headline * 100, 2) ?><?= te('% of price') ?></span><?php endif; ?></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th><?= te('Year') ?></th><th class="r"><?= te('Tax bill') ?></th><th class="r"><?= te('Assessed') ?></th><th class="r"><?= te('Land') ?></th><th class="r"><?= te('Building') ?></th></tr></thead>
          <tbody>
          <?php foreach (array_slice($taxYears, 0, 8, true) as $y => $t): ?>
            <tr><td><?= (int) $y ?></td><td class="r"><?= e(money($t['tax'] ?? null)) ?></td><td class="r"><?= e(money($t['value'] ?? null)) ?></td>
              <td class="r"><?= e(money($t['land'] ?? null)) ?></td><td class="r"><?= e(money($t['impr'] ?? null)) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>

    <?php
    $featureLabels = ['architectureType' => 'Style', 'floorCount' => 'Floors', 'roomCount' => 'Rooms', 'unitCount' => 'Units', 'exteriorType' => 'Exterior',
        'roofType' => 'Roof', 'foundationType' => 'Foundation', 'heatingType' => 'Heating', 'coolingType' => 'Cooling', 'garageType' => 'Parking',
        'garageSpaces' => 'Garage spaces', 'fireplaceType' => 'Fireplace', 'poolType' => 'Pool', 'viewType' => 'View'];
    $rec = [];
    foreach ($featureLabels as $k => $label) {
        if (isset($d['features'][$k]) && $d['features'][$k] !== '') { $rec[$label] = (string) $d['features'][$k]; }
    }
    foreach (['pool' => 'Pool', 'fireplace' => 'Fireplace', 'garage' => 'Parking'] as $k => $label) {
        if (!isset($rec[$label]) && isset($d['features'][$k])) { $rec[$label] = $d['features'][$k] ? 'Yes' : 'No'; }
    }
    foreach (['heating' => 'Heating', 'cooling' => 'Cooling', 'parking' => 'Parking'] as $k => $label) {
        if (!isset($rec[$label]) && !empty($d[$k])) { $rec[$label] = implode(', ', array_slice((array) $d[$k], 0, 3)); }
    }
    $record = array_filter([
        'Parcel number' => $d['assessor_id'] ?? ($d['parcel_id'] ?? null),
        'Subdivision' => $d['subdivision'] ?? null,
        'Zoning' => $d['zoning'] ?? null,
        'Owner' => !empty($d['owner']['names']) ? implode(', ', (array) $d['owner']['names']) : null,
        'Owner type' => $d['owner']['type'] ?? null,
        'Owner lives here' => isset($d['owner_occupied']) ? ($d['owner_occupied'] ? 'Yes' : 'No') : null,
        'Tax mailing address' => $d['owner']['mailing'] ?? null,
        'Legal description' => $d['legal'] ?? null,
    ]);
    ?>
    <?php if ($rec || $record): ?>
    <section class="section">
      <div class="section-head"><h2><?= te('Building and public record') ?></h2></div>
      <dl class="kv">
        <?php foreach ($rec + $record as $k => $v): ?><div><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd></div><?php endforeach; ?>
      </dl>
      <?php if (!empty($d['interior'])): ?><p class="section-note"><?= te('Interior:') ?> <?= e(implode(', ', (array) $d['interior'])) ?>.</p><?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($p['agent_name'] || $p['office_name'] || $p['mls_number']): ?>
    <section class="section">
      <div class="section-head"><h2><?= te('Listed by') ?></h2></div>
      <dl class="kv">
        <?php if ($p['agent_name']): ?><div><dt><?= te('Agent') ?></dt><dd><?= e($p['agent_name']) ?></dd></div><?php endif; ?>
        <?php if ($p['agent_phone']): ?><div><dt><?= te('Phone') ?></dt><dd><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $p['agent_phone'])) ?>"><?= e(fmt_phone($p['agent_phone'])) ?></a></dd></div><?php endif; ?>
        <?php if ($p['agent_email']): ?><div><dt><?= te('Email') ?></dt><dd><a href="mailto:<?= e($p['agent_email']) ?>"><?= e($p['agent_email']) ?></a></dd></div><?php endif; ?>
        <?php if ($p['office_name']): ?><div><dt><?= te('Brokerage') ?></dt><dd><?= e($p['office_name']) ?></dd></div><?php endif; ?>
        <?php if ($p['mls_number']): ?><div><dt><?= te('MLS #') ?></dt><dd><?= e($p['mls_number']) ?><?= $p['mls_name'] ? ' · ' . e($p['mls_name']) : '' ?></dd></div><?php endif; ?>
      </dl>
    </section>
    <?php endif; ?>

    <?php include APP_ROOT . '/pages/partials/team.php'; ?>
    <?php include APP_ROOT . '/pages/partials/verdict.php'; ?>
  </div>

  <aside class="rail">
    <?php if ($hasGeo): ?>
    <div class="mini-map"><div id="map"></div></div>
    <script type="application/json" id="map-data"><?= json_embed(['points' => $points, 'zoom' => 15, 'labels' => count($points) <= 20, 'circle' => $comps['comps'] ? ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'miles' => $comps['radius']] : null]) ?></script>
    <?php endif; ?>

    <?php include APP_ROOT . '/pages/partials/pipeline_card.php'; ?>

    <?php if ($p['est_value'] || $p['est_rent']): ?>
    <div class="card estimate">
      <span class="eyebrow"><?= $p['est_source'] === 'zestimate' ? 'Zestimate' : 'RentCast estimate' ?></span>
      <?php if ($p['est_value']): ?>
        <div class="price"><?= e(money($p['est_value'])) ?></div>
        <?php if ($p['est_value_low'] && $p['est_value_high']): ?>
          <?= View::rangeBar((float) $p['est_value_low'], (float) $p['est_value_high'], (float) ($forSale ? $p['list_price'] : $p['est_value'])) ?>
          <div class="range-ends"><span><?= e(money_short($p['est_value_low'])) ?></span><span><?= $forSale ? 'marker = asking price' : '' ?></span><span><?= e(money_short($p['est_value_high'])) ?></span></div>
        <?php endif; ?>
        <?php if ($forSale): $gap = ((int) $p['list_price'] - (int) $p['est_value']) / (int) $p['est_value'] * 100; ?>
          <p class="small soft"><?= te('Asking is') ?> <span class="<?= $gap > 0 ? 'up' : 'down' ?>"><?= e(pct($gap)) ?></span> <?= te('against the estimate.') ?></p>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($p['est_rent']): ?>
        <div class="ctx-row"><span><?= te('Rent estimate') ?></span><span class="num"><?= e(money($p['est_rent'])) ?><?= te('/mo') ?></span></div>
        <?php if ($headline): ?><div class="ctx-row"><span><?= te('Gross yield') ?></span><span class="num"><?= number_format((int) $p['est_rent'] * 12 / $headline * 100, 1) ?>%</span></div><?php endif; ?>
      <?php endif; ?>
      <p class="small muted"><?= te('An automated figure, checked') ?> <?= e(ago($p['est_at'])) ?><?= te('. Trust the sold comps more.') ?></p>
    </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <div class="card">
      <h3><?= te('Pull more on this home') ?></h3>
      <p class="small muted mb-m"><?= te('Each button spends one request and keeps the result for good.') ?></p>
      <div class="pulls">
        <?php if ($rcReady): ?>
          <?= $pull('record', 'Public record & past sales', '1 request', $rcLeft > 0) ?>
          <?= $pull('sales', 'Sales in this neighbourhood', '1 request', $rcLeft > 0 && $hasGeo) ?>
          <?= $pull('value', 'Value estimate', '1 request', $rcLeft > 0) ?>
          <?= $pull('rent', 'Rent estimate', '1 request', $rcLeft > 0) ?>
        <?php endif; ?>
        <?php if ($ownReady): ?>
          <?= $pull('zillow', 'Photos, history, Zillow comps', '1 request', $ownLeft > 0) ?>
        <?php endif; ?>
        <?php if (!$rcReady && !$ownReady): ?>
          <p class="small"><?= te('No data source connected.') ?> <a href="/settings#sources"><?= te('Add a free key') ?></a> <?= te('to enable these.') ?></p>
        <?php endif; ?>
      </div>
      <?php if ($rcReady || $ownReady): ?>
      <p class="small muted mt-m">
        <?= $rcReady ? plural($rcLeft, 'RentCast request') . ' left' : '' ?><?= $rcReady && $ownReady ? ' · ' : '' ?><?= $ownReady ? $ownLeft . ' Zillow-data left' : '' ?> <?= te('this month.') ?>
      </p>
      <?php endif; ?>
    </div>

    <?php endif; ?>

    <?php if ($links): ?>
    <div class="card">
      <h3><?= te('Look it up elsewhere') ?></h3>
      <div class="linklist">
        <?php foreach ($links as $l): ?>
        <a href="<?= e($l['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($l['name']) ?><?= icon('arrow-up-right', 14) ?></a>
        <?php endforeach; ?>
      </div>
      <?php if ($p['watch_url'] || $p['source_url']): ?>
      <p class="small mt-m"><a href="<?= e($p['watch_url'] ?: $p['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= te('Original listing link') ?></a></p>
      <?php endif; ?>
      <p class="small muted mt-s"><a href="/sites"><?= te('Edit this list') ?></a></p>
    </div>
    <?php endif; ?>

    <?php if ($hasGeo): ?>
    <div class="card" data-context-url="/api/context/<?= $id ?>">
      <h3><?= te('Flood risk') ?></h3>
      <div class="ctx" data-flood><div class="skeleton"></div><div class="skeleton"></div></div>
      <h3 class="mt-l"><?= te('Nearby, within a mile') ?></h3>
      <div class="ctx" data-nearby-url="/api/nearby/<?= $id ?>" data-csrf="<?= e(App\Auth::csrfToken()) ?>"><div class="skeleton"></div><div class="skeleton"></div></div>
      <h3 class="mt-l"><?= te('Neighbourhood') ?></h3>
      <div class="ctx" data-hood><div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div></div>
    </div>
    <?php endif; ?>

    <div class="card">
      <h3><?= te('This record') ?></h3>
      <p class="small muted"><?= te('Source:') ?> <?= e(['rentcast' => 'RentCast', 'openwebninja' => 'Zillow data', 'manual' => 'entered by hand', 'import' => 'CSV import'][$p['source']] ?? $p['source']) ?><?= $p['source_site'] ? ' · found on ' . e($p['source_site']) : '' ?><?= te('. First on file') ?> <?= e(fmt_date($p['first_seen_at'])) ?>.</p>
      <?php if ($canEdit): ?>
      <form method="post" action="/property/<?= $id ?>/act" class="mt-m">
        <?= csrf_field() ?><input type="hidden" name="do" value="data_status">
        <label class="field"><span><?= te('What we have from the listing') ?></span>
          <select class="select" name="data_status" data-autosubmit>
            <?php foreach ($dataLabels as $k => $label): ?><option value="<?= $k ?>"<?= selected($p['data_status'], $k) ?>><?= te($label) ?></option><?php endforeach; ?>
          </select></label>
      </form>
      <div class="actions mt-m">
        <a class="btn btn-sm" href="<?= e(url('/add', ['id' => $id])) ?>"><?= icon('pencil', 14) ?><?= te('Edit facts & price') ?></a>
        <?php if (Auth::can('admin')): ?>
        <form method="post" action="/property/<?= $id ?>/act" class="inline" data-confirm="<?= te('Delete this home, its deal numbers, notes and history? This can’t be undone.') ?>">
          <?= csrf_field() ?><input type="hidden" name="do" value="delete">
          <button class="btn btn-sm btn-danger" type="submit"><?= icon('trash-2', 14) ?><?= te('Delete') ?></button>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </aside>
</div>
<?php
View::footer();

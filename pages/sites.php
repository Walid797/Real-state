<?php
declare(strict_types=1);

use App\Db;
use App\Services\ExternalSites;
use App\View;

$categories = ['listings' => 'Listings', 'records' => 'Public records', 'maps' => 'Maps', 'other' => 'Other'];

/** Validates one site from the request; returns [data, errors]. */
$read = static function () use ($categories): array {
    $data = [
        'name' => input('name', '', 80),
        'category' => isset($categories[input('category')]) ? input('category') : 'listings',
        'property_url' => input('property_url', '', 500) ?: null,
        'area_url' => input('area_url', '', 500) ?: null,
        'sort_order' => max(0, min(9999, (int) input('sort_order', '100'))),
        'enabled' => isset($_POST['enabled']) ? 1 : 0,
    ];
    $errors = [];
    if ($data['name'] === '') {
        $errors[] = 'Give the site a name.';
    }
    if (!$data['property_url'] && !$data['area_url']) {
        $errors[] = 'Add at least one link — for a single home, for an area, or both.';
    }
    foreach (['property_url' => 'home link', 'area_url' => 'area link'] as $k => $label) {
        $tpl = (string) $data[$k];
        if ($tpl === '') {
            continue;
        }
        if (!ExternalSites::validTemplate($tpl)) {
            $errors[] = "The $label must start with https:// and a site name.";
        }
        preg_match_all('/\{[^}]*\}/', $tpl, $m);
        $unknown = array_diff($m[0], ExternalSites::PLACEHOLDERS);
        if ($unknown) {
            $errors[] = "The $label uses " . implode(', ', $unknown) . ', which isn’t one of the placeholders below.';
        }
    }
    return [$data, $errors];
};

if (is_post()) {
    $do = input('do');
    $id = (int) input('id');
    if ($do === 'delete' && $id) {
        Db::run('DELETE FROM external_sites WHERE id = ?', [$id]);
        flash('Site removed.');
    } elseif ($do === 'reset') {
        Db::run('DELETE FROM external_sites');
        ExternalSites::seedDefaults();
        flash('Back to the original list.');
    } elseif ($do === 'save' || $do === 'add') {
        [$data, $errors] = $read();
        if ($errors) {
            flash(implode(' ', $errors), 'err');
        } elseif ($do === 'add') {
            Db::insert('external_sites', $data);
            flash($data['name'] . ' added. It now appears on every home and search.');
        } else {
            Db::update('external_sites', $data, $id);
            flash($data['name'] . ' saved.');
        }
    }
    redirect('/sites');
}

$sites = ExternalSites::all();
$sample = ['address' => '139 E Del Rio Dr', 'city' => 'Tempe', 'state' => 'AZ', 'zip' => '85282', 'county' => 'Maricopa', 'lat' => 33.39724, 'lng' => -111.93654];

View::header('Sites', 'sites');
?>
<header class="page-head">
  <div>
    <h1 class="display">Sites</h1>
    <p>The other places you check. Each one becomes a link on every home and every search, already pointed at the same address or area.</p>
  </div>
</header>

<section class="section">
  <div class="section-head"><h2>Your list</h2><span class="aside"><?= plural(count($sites), 'site') ?> · lower order shows first</span></div>
  <?php foreach ($sites as $s): ?>
  <form class="site-row" method="post" action="/sites">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
    <label><span class="sr">Name</span><input class="input name" name="name" value="<?= e($s['name']) ?>" required></label>
    <label><span class="sr">Category</span>
      <select class="select" name="category">
        <?php foreach ($categories as $k => $label): ?><option value="<?= $k ?>"<?= selected($s['category'], $k) ?>><?= $label ?></option><?php endforeach; ?>
      </select></label>
    <div class="urls">
      <label><span class="sr">Link for one home</span><input class="input" name="property_url" value="<?= e($s['property_url']) ?>" placeholder="Link for one home — https://…{address_slug}…" spellcheck="false"></label>
      <label><span class="sr">Link for an area</span><input class="input" name="area_url" value="<?= e($s['area_url']) ?>" placeholder="Link for an area — https://…{area_slug}…" spellcheck="false"></label>
    </div>
    <label><span class="sr">Order</span><input class="input" name="sort_order" value="<?= (int) $s['sort_order'] ?>" inputmode="numeric" title="Order"></label>
    <div class="actions">
      <label class="check" title="Show this site"><input type="checkbox" name="enabled"<?= checked($s['enabled']) ?>>Show</label>
      <button class="btn btn-sm" type="submit" name="do" value="save">Save</button>
      <button class="btn btn-sm btn-ghost btn-danger" type="submit" name="do" value="delete" formnovalidate data-confirm="Remove <?= e($s['name']) ?> from your list?" title="Remove <?= e($s['name']) ?>"><?= icon('trash-2', 14) ?><span class="sr">Remove</span></button>
    </div>
  </form>
  <?php endforeach; ?>
  <?php if (!$sites): ?><p class="blank">The list is empty. Add a site below or restore the originals.</p><?php endif; ?>
</section>

<section class="section">
  <div class="section-head"><h2>Add a site</h2></div>
  <form class="site-row" method="post" action="/sites">
    <?= csrf_field() ?><input type="hidden" name="do" value="add">
    <label><span class="sr">Name</span><input class="input name" name="name" placeholder="Compass" required></label>
    <label><span class="sr">Category</span>
      <select class="select" name="category">
        <?php foreach ($categories as $k => $label): ?><option value="<?= $k ?>"><?= $label ?></option><?php endforeach; ?>
      </select></label>
    <div class="urls">
      <label><span class="sr">Link for one home</span><input class="input" name="property_url" placeholder="https://www.google.com/search?q={address_full}+site%3Acompass.com" spellcheck="false"></label>
      <label><span class="sr">Link for an area</span><input class="input" name="area_url" placeholder="https://www.compass.com/homes-for-sale/{city_slug}-{state_lower}/" spellcheck="false"></label>
    </div>
    <label><span class="sr">Order</span><input class="input" name="sort_order" value="<?= count($sites) * 10 + 10 ?>" inputmode="numeric" title="Order"></label>
    <div class="actions">
      <input type="hidden" name="enabled" value="1">
      <button class="btn btn-sm btn-primary" type="submit">Add</button>
    </div>
  </form>

  <h3 class="eyebrow mt-l">Placeholders</h3>
  <p class="section-note">Open the site, search for a home, and copy the address from your browser. Replace the part that changes with one of these. The example values are for <?= e(App\Address::full($sample)) ?>.</p>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Write</th><th>Becomes</th><th>Use it when the site wants</th></tr></thead>
      <tbody>
        <tr><td class="mono">{address_slug}</td><td class="mono">139-E-Del-Rio-Dr-Tempe-AZ-85282</td><td>the whole address with dashes (Zillow)</td></tr>
        <tr><td class="mono">{address_full}</td><td class="mono">139%20E%20Del%20Rio%20Dr%2C%20Tempe%2C%20AZ%2085282</td><td>the address inside a search box or <span class="mono">?q=</span></td></tr>
        <tr><td class="mono">{address}</td><td class="mono">139%20E%20Del%20Rio%20Dr</td><td>the street line alone</td></tr>
        <tr><td class="mono">{city} {state} {zip} {county}</td><td class="mono">Tempe · AZ · 85282 · Maricopa</td><td>each part on its own</td></tr>
        <tr><td class="mono">{city_slug} {city_dash} {city_under}</td><td class="mono">san-antonio · San-Antonio · San_Antonio</td><td>a city inside the path</td></tr>
        <tr><td class="mono">{state_lower}</td><td class="mono">az</td><td>a lower-case state (Homes.com, Movoto)</td></tr>
        <tr><td class="mono">{area} {area_slug} {area_under}</td><td class="mono">Tempe%2C%20AZ · Tempe-AZ · Tempe_AZ</td><td>the searched area; the ZIP when you searched a ZIP</td></tr>
        <tr><td class="mono">{lat} {lng}</td><td class="mono">33.39724 · -111.93654</td><td>map coordinates</td></tr>
      </tbody>
    </table>
  </div>
  <p class="section-note">A link that needs something the page doesn’t have — a ZIP on a city-wide search, say — is simply left out there. Sites such as Redfin and Realtor.com hide each home behind an internal number, so their home links go through a Google search limited to that site.</p>

  <form method="post" action="/sites" class="mt-l" data-confirm="Replace your list with the original ten sites?">
    <?= csrf_field() ?><input type="hidden" name="do" value="reset">
    <button class="linkbtn small" type="submit">Restore the original list</button>
  </form>
</section>
<?php
View::footer();

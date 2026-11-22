<?php
declare(strict_types=1);

use App\Address;
use App\Db;
use App\Geo;
use App\PropertyRepo;
use App\Providers\Census;
use App\Services\Activity;
use App\Services\LinkImport;
use App\Services\Pipeline;
use App\Services\Score;
use App\View;

/*
 * Add a home by hand, edit one that is on file, or import a CSV of sales.
 *   /add            new home
 *   /add?id=12      edit home 12 (address stays fixed — it identifies the record)
 *   /add?near=12    new sale next to home 12 (area prefilled, status "Sold")
 *   /add?watch=1    new home, watchlist box ticked
 */

$editId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$edit = $editId ? PropertyRepo::find($editId) : null;
if ($editId && !$edit) {
    redirect('/add');
}
$near = !$edit && isset($_GET['near']) ? PropertyRepo::find((int) $_GET['near']) : null;

$form = [
    'address' => $edit['address'] ?? '', 'city' => $edit['city'] ?? ($near['city'] ?? ''),
    'state' => $edit['state'] ?? ($near['state'] ?? ''), 'zip' => $edit['zip'] ?? ($near['zip'] ?? ''),
    'status' => $edit['status'] ?? ($near ? 'sold' : 'for_sale'),
    'list_price' => $edit['list_price'] ?? '', 'listed_date' => $edit['listed_date'] ?? '',
    'last_sale_price' => $edit['last_sale_price'] ?? '', 'last_sale_date' => $edit['last_sale_date'] ?? '',
    'beds' => $edit['beds'] ?? '', 'baths' => $edit['baths'] ?? '', 'sqft' => $edit['sqft'] ?? '',
    'lot_sqft' => $edit['lot_sqft'] ?? '', 'year_built' => $edit['year_built'] ?? '',
    'property_type' => $edit['property_type'] ?? ($near['property_type'] ?? ''), 'hoa_fee' => $edit['hoa_fee'] ?? '',
    'source_url' => $edit['watch_url'] ?? ($edit['source_url'] ?? ''), 'notes' => '',
    'watch' => $edit ? (bool) $edit['watch_id'] : isset($_GET['watch']),
];
$errors = [];
$importResult = null;

/* ── Add from a listing link ─────────────────────────────────────────── */
$linkError = null;
$linkUrl = '';
if (is_post() && input('do') === 'link') {
    $linkUrl = input('url', '', 500);
    $r = LinkImport::resolve($linkUrl);
    if (!$r['ok']) {
        $linkError = $r['error'];
        $form['source_url'] = preg_match('#^https://#i', $linkUrl) ? $linkUrl : '';
    } else {
        $price = parse_num(input('price', '', 30));
        $res = PropertyRepo::upsert([
            'address' => $r['address'], 'city' => $r['city'], 'state' => $r['state'], 'zip' => $r['zip'],
            'lat' => $r['lat'], 'lng' => $r['lng'], 'county' => $r['county'],
            'source_url' => $linkUrl, 'source_site' => $r['site'], 'data_status' => 'link_only',
            'status' => $price ? 'for_sale' : null, 'list_price' => $price, 'listed_date' => $price ? today() : null,
        ], 'manual');
        Pipeline::add($res['id'], $linkUrl);
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note !== '') {
            Db::insert('notes', ['property_id' => $res['id'], 'user_id' => App\Auth::userId(), 'body' => mb_substr($note, 0, 10000), 'created_at' => now()]);
        }
        Activity::log('added', $res['id'], 'From a ' . $r['site'] . ' link');
        Score::refresh($res['id']);
        flash(($res['created'] ? 'Added from ' . $r['site'] . '.' : 'That home was already saved — linked it and added it to favorites.')
            . ' Only the address came from the link: add the facts by hand or pull the public record.');
        redirect('/property/' . $res['id']);
    }
}

/* ── CSV import ─────────────────────────────────────────────────────── */
if (is_post() && input('do') === 'import') {
    $file = $_FILES['csv'] ?? null;
    if (!$file || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $errors[] = 'Choose a CSV file first.';
    } elseif ($file['size'] > 3 * 1024 * 1024) {
        $errors[] = 'That file is over 3 MB. Split it and import the parts one after another.';
    } else {
        $fh = fopen($file['tmp_name'], 'r');
        $head = fgetcsv($fh, 0, ',', '"', '');
        $cols = [];
        foreach ((array) $head as $i => $name) {
            $key = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $name)) ?? '', '_'));
            // Accept the common spellings found in exports from other tools.
            $key = ['street' => 'address', 'street_address' => 'address', 'zipcode' => 'zip', 'zip_code' => 'zip', 'postal_code' => 'zip',
                'price' => 'sale_price', 'sold_price' => 'sale_price', 'sold_date' => 'sale_date', 'date' => 'sale_date',
                'bedrooms' => 'beds', 'bed' => 'beds', 'bathrooms' => 'baths', 'bath' => 'baths', 'house_size' => 'sqft', 'square_feet' => 'sqft',
                'living_area' => 'sqft', 'lot_size' => 'lot_sqft', 'yearbuilt' => 'year_built', 'type' => 'property_type',
                'latitude' => 'lat', 'longitude' => 'lng', 'lon' => 'lng'][$key] ?? $key;
            $cols[$key] = $i;
        }
        if (!isset($cols['address']) || (!isset($cols['zip']) && !(isset($cols['city'], $cols['state'])))) {
            $errors[] = 'The first row needs an “address” column and either “zip” or both “city” and “state”.';
        } else {
            $ok = $skipped = $n = 0;
            Db::tx(static function () use ($fh, $cols, &$ok, &$skipped, &$n) {
                while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false && $n < 5000) {
                    $n++;
                    $get = static fn (string $k) => isset($cols[$k]) ? trim((string) ($row[$cols[$k]] ?? '')) : '';
                    $salePrice = parse_num($get('sale_price'));
                    $listPrice = parse_num($get('list_price'));
                    try {
                        PropertyRepo::upsert(array_filter([
                            'address' => $get('address'), 'city' => $get('city'), 'state' => Address::stateAbbr($get('state')) ?? '', 'zip' => $get('zip'),
                            'last_sale_price' => $salePrice, 'last_sale_date' => $salePrice ? ($get('sale_date') ?: null) : null,
                            'list_price' => $listPrice, 'status' => $listPrice && !$salePrice ? 'for_sale' : null,
                            'beds' => parse_num($get('beds')), 'baths' => parse_num($get('baths')), 'sqft' => parse_num($get('sqft')),
                            'lot_sqft' => parse_num($get('lot_sqft')), 'year_built' => parse_num($get('year_built')),
                            'property_type' => in_array($get('property_type'), PropertyRepo::TYPES, true) ? $get('property_type') : null,
                            'lat' => is_numeric($get('lat')) ? (float) $get('lat') : null, 'lng' => is_numeric($get('lng')) ? (float) $get('lng') : null,
                        ], static fn ($v) => $v !== null && $v !== ''), 'import');
                        $ok++;
                    } catch (InvalidArgumentException) {
                        $skipped++;
                    }
                }
            });
            $importResult = ['ok' => $ok, 'skipped' => $skipped, 'geo' => isset($cols['lat'], $cols['lng'])];
        }
        fclose($fh);
    }
}

/* ── Save one home ──────────────────────────────────────────────────── */
if (is_post() && !in_array(input('do'), ['import', 'link'], true)) {
    foreach (['address', 'city', 'zip', 'listed_date', 'last_sale_date', 'property_type'] as $k) {
        $form[$k] = input($k, '', 190);
    }
    $form['state'] = Address::stateAbbr(input('state', '', 30)) ?? '';
    $form['status'] = in_array(input('status'), ['for_sale', 'pending', 'sold', 'off_market'], true) ? input('status') : 'off_market';
    foreach (['list_price', 'last_sale_price', 'beds', 'baths', 'sqft', 'lot_sqft', 'year_built', 'hoa_fee'] as $k) {
        $form[$k] = parse_num(input($k, '', 30));
    }
    $form['source_url'] = input('source_url', '', 500);
    $form['notes'] = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 10000);
    $form['watch'] = isset($_POST['watch']);
    if ($edit) {
        // The address identifies the record; changing it would silently create a second home.
        foreach (['address', 'city', 'state', 'zip'] as $k) {
            $form[$k] = $edit[$k];
        }
    }

    if ($form['address'] === '' || !preg_match('/\d/', $form['address'])) {
        $errors[] = 'Enter the street address with its house number.';
    }
    if ($form['zip'] !== '' && !preg_match('/^\d{5}$/', $form['zip'])) {
        $errors[] = 'The ZIP code should be five digits.';
    }
    if ($form['zip'] === '' && ($form['city'] === '' || $form['state'] === '')) {
        $errors[] = 'Add a ZIP code, or both the city and the state.';
    }
    if (in_array($form['status'], ['for_sale', 'pending'], true) && !$form['list_price']) {
        $errors[] = 'A home that’s for sale needs its asking price.';
    }
    if ($form['status'] === 'sold' && (!$form['last_sale_price'] || !PropertyRepo::date($form['last_sale_date']))) {
        $errors[] = 'A sold home needs the sale price and the sale date.';
    }
    if ($form['source_url'] !== '' && !preg_match('#^https://[^\s]+$#i', $form['source_url'])) {
        $errors[] = 'The link should start with https://';
    }

    if (!$errors) {
        $geo = null;
        if (!$edit || !Geo::valid($edit['lat'], $edit['lng'])) {
            $geo = Census::geocode(Address::full($form)); // free; needed for the map and for comps
        }
        $data = [
            'address' => $form['address'], 'city' => $form['city'] ?: ($geo['city'] ?? ''), 'state' => $form['state'] ?: ($geo['state'] ?? ''),
            'zip' => $form['zip'] ?: ($geo['zip'] ?? ''), 'status' => $form['status'],
            'list_price' => $form['list_price'], 'listed_date' => $form['listed_date'] ?: (in_array($form['status'], ['for_sale', 'pending'], true) && !$edit ? today() : null),
            'last_sale_price' => $form['last_sale_price'], 'last_sale_date' => $form['last_sale_date'],
            'beds' => $form['beds'], 'baths' => $form['baths'], 'sqft' => $form['sqft'], 'lot_sqft' => $form['lot_sqft'],
            'year_built' => $form['year_built'], 'hoa_fee' => $form['hoa_fee'],
            'property_type' => in_array($form['property_type'], PropertyRepo::TYPES, true) ? $form['property_type'] : null,
            'source_url' => $form['source_url'] ?: null,
            'lat' => $geo['lat'] ?? null, 'lng' => $geo['lng'] ?? null, 'county' => $geo['county'] ?? null,
        ];
        if ($form['source_url'] !== '') {
            $data['source_site'] = LinkImport::site($form['source_url']);
        }
        $res = PropertyRepo::upsert($data, $edit ? $edit['source'] : 'manual');
        $pid = $res['id'];
        if ($form['notes'] !== '') {
            Db::insert('notes', ['property_id' => $pid, 'user_id' => App\Auth::userId(), 'body' => $form['notes'], 'created_at' => now()]);
        }
        Activity::log($edit ? 'edit' : 'added', $pid, $edit ? 'Facts or price edited' : 'Entered by hand');
        if ($form['watch']) {
            Pipeline::add($pid, $form['source_url'] ?: null);
        } elseif ($edit && $edit['watch_id']) {
            Pipeline::remove($pid);
        }
        Score::refresh($pid);
        $msg = $edit ? 'Saved.' : ($res['created'] ? 'Added.' : 'That address was already on file — updated it.');
        if ($res['old_price'] && $res['new_price']) {
            $msg .= ' Price change recorded: ' . money($res['old_price']) . ' → ' . money($res['new_price']) . '.';
        }
        if (!$edit && !$geo) {
            $msg .= ' The Census geocoder couldn’t place the address, so it won’t show on maps or in comps.';
        }
        flash($msg, (!$edit && !$geo) ? 'warn' : 'ok');
        redirect('/property/' . $pid);
    }
}

$val = static fn (string $k) => e(is_float($form[$k]) || is_int($form[$k]) ? rtrim(rtrim(number_format((float) $form[$k], 2, '.', ''), '0'), '.') : (string) $form[$k]);

View::header($edit ? t('Edit %s', $edit['address']) : 'Add a home', 'add');
?>
<?php if ($edit): ?><nav class="crumbs"><a href="/property/<?= $editId ?>"><?= icon('arrow-left', 14) ?><?= e($edit['address']) ?></a></nav><?php endif; ?>
<div class="narrow">
  <header class="page-head">
    <div>
      <h1 class="display"><?= te($edit ? 'Edit facts & price' : ($near ? 'Add a sale nearby' : 'Add a home')) ?></h1>
      <p><?php if ($edit): ?><?= te('Change the asking price here and the old one is kept in the price history.') ?><?php elseif ($near): ?><?= te('A sale you found elsewhere, close to') ?> <?= e($near['address']) ?><?= te('. It joins the comparable sales straight away.') ?><?php else: ?><?= te('Found something on Zillow, Redfin or a yard sign? Enter it once — it shows up in searches, comps and alerts like any other home.') ?><?php endif; ?></p>
    </div>
  </header>

  <?php foreach ($errors as $err): ?><p class="flash flash-err" role="alert"><?= te($err) ?></p><?php endforeach; ?>
  <?php if ($importResult): ?>
    <p class="flash"><?= plural($importResult['ok'], 'row') ?> <?= te('imported') ?><?= $importResult['skipped'] ? ', ' . $importResult['skipped'] . ' skipped for a missing address' : '' ?>.
      <?= $importResult['geo'] ? '' : 'The file had no latitude/longitude columns, so these rows are searchable but won’t be used as comps.' ?></p>
  <?php endif; ?>

  <?php if (!$edit && !$near): ?>
  <section class="card link-card">
    <h3><?= icon('link-2', 16) ?> <?= te('Add from a listing link') ?></h3>
    <p class="small soft mb-m"><?= te('Paste a home’s page from Homes.com, Zillow, Realtor.com, Redfin or Trulia. Its address is read from the link, and the home is added to favorites with a button to view the original listing.') ?></p>
    <?php if ($linkError): ?><p class="err" role="alert"><?= e($linkError) ?></p><?php endif; ?>
    <form method="post" action="/add">
      <?= csrf_field() ?><input type="hidden" name="do" value="link">
      <div class="form-grid">
        <label class="field c8"><span><?= te('Listing link') ?></span><input class="input" type="url" name="url" value="<?= e($linkUrl) ?>" placeholder="https://www.homes.com/property/…" required></label>
        <label class="field c4"><span><?= te('Asking price (optional)') ?></span><input class="input" name="price" inputmode="numeric" placeholder="$"></label>
        <label class="field c12"><span><?= te('Note for the team (optional)') ?></span><input class="input" name="note" placeholder="<?= te('Seen on Homes.com — price cut last week, vacant.') ?>"></label>
      </div>
      <div class="form-foot"><button class="btn btn-primary" type="submit"><?= icon('plus', 15) ?><?= te('Add from link') ?></button></div>
    </form>
  </section>
  <h2 class="eyebrow mt-l mb-m"><?= te('Or type it in') ?></h2>
  <?php endif; ?>

  <form method="post" action="<?= e(url('/add', ['id' => $editId ?: null])) ?>">
    <?= csrf_field() ?>
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= $editId ?>"><?php endif; ?>
    <div class="form-grid">
      <label class="field c12"><span><?= te('Street address') ?></span>
        <input class="input" name="address" value="<?= $val('address') ?>" placeholder="1204 Maple St" <?= $edit ? 'readonly' : 'required autofocus' ?> autocomplete="off"></label>
      <label class="field c6"><span><?= te('City') ?></span><input class="input" name="city" value="<?= $val('city') ?>"<?= $edit ? ' readonly' : '' ?>></label>
      <label class="field c3"><span><?= te('State') ?></span><input class="input" name="state" value="<?= $val('state') ?>" maxlength="20" placeholder="TX"<?= $edit ? ' readonly' : '' ?>></label>
      <label class="field c3"><span><?= te('ZIP') ?></span><input class="input" name="zip" value="<?= $val('zip') ?>" inputmode="numeric" maxlength="5"<?= $edit ? ' readonly' : '' ?>></label>

      <label class="field c4"><span><?= te('Status') ?></span>
        <select class="select" name="status">
          <?php foreach (['for_sale' => 'For sale', 'pending' => 'Pending', 'sold' => 'Sold', 'off_market' => 'Off market'] as $k => $label): ?><option value="<?= $k ?>"<?= selected($form['status'], $k) ?>><?= te($label) ?></option><?php endforeach; ?>
        </select></label>
      <label class="field c4"><span><?= te('Asking price') ?></span><input class="input" name="list_price" value="<?= $form['list_price'] ? e(number_format((float) $form['list_price'])) : '' ?>" inputmode="numeric" placeholder="$"></label>
      <label class="field c4"><span><?= te('Listed on') ?></span><input class="input" type="date" name="listed_date" value="<?= $val('listed_date') ?>"></label>
      <label class="field c4"><span><?= te('Last sale price') ?></span><input class="input" name="last_sale_price" value="<?= $form['last_sale_price'] ? e(number_format((float) $form['last_sale_price'])) : '' ?>" inputmode="numeric" placeholder="$"></label>
      <label class="field c4"><span><?= te('Sold on') ?></span><input class="input" type="date" name="last_sale_date" value="<?= $val('last_sale_date') ?>"></label>
      <label class="field c4"><span><?= te('Type') ?></span>
        <select class="select" name="property_type"><option value=""><?= te('Not sure') ?></option>
          <?php foreach (PropertyRepo::TYPES as $t): ?><option value="<?= e($t) ?>"<?= selected($form['property_type'], $t) ?>><?= te($t) ?></option><?php endforeach; ?>
        </select></label>

      <label class="field c2"><span><?= te('Beds') ?></span><input class="input" name="beds" value="<?= $val('beds') ?>" inputmode="decimal"></label>
      <label class="field c2"><span><?= te('Baths') ?></span><input class="input" name="baths" value="<?= $val('baths') ?>" inputmode="decimal"></label>
      <label class="field c3"><span><?= te('Living area, sqft') ?></span><input class="input" name="sqft" value="<?= $val('sqft') ?>" inputmode="numeric"></label>
      <label class="field c3"><span><?= te('Lot, sqft') ?></span><input class="input" name="lot_sqft" value="<?= $val('lot_sqft') ?>" inputmode="numeric"></label>
      <label class="field c2"><span><?= te('Built') ?></span><input class="input" name="year_built" value="<?= $val('year_built') ?>" inputmode="numeric" maxlength="4"></label>
      <label class="field c4"><span><?= te('HOA, $ per month') ?></span><input class="input" name="hoa_fee" value="<?= $val('hoa_fee') ?>" inputmode="decimal"></label>
      <label class="field c8"><span><?= te('Link to the listing') ?></span><input class="input" type="url" name="source_url" value="<?= $val('source_url') ?>" placeholder="https://www.zillow.com/homedetails/…"></label>

      <label class="field c12"><span><?= te($edit ? 'Add a note for the team' : 'Note for the team') ?></span>
        <textarea class="textarea" name="notes" rows="3" placeholder="<?= te('Corner lot, backs onto the greenbelt.') ?>"><?= e($form['notes']) ?></textarea></label>
      <div class="field c12"><label class="check"><input type="checkbox" name="watch"<?= checked($form['watch']) ?>><?= te('In favorites') ?></label></div>
    </div>
    <div class="form-foot">
      <button class="btn btn-primary" type="submit"><?= te($edit ? 'Save changes' : 'Add home') ?></button>
      <a class="btn btn-ghost" href="<?= $edit ? '/property/' . $editId : '/' ?>"><?= te('Cancel') ?></a>
      <?php if (!$edit): ?><span class="small muted"><?= te('The map position is looked up for you (U.S. Census geocoder).') ?></span><?php endif; ?>
    </div>
  </form>

  <?php if (!$edit): ?>
  <section class="section">
    <div class="section-head"><h2><?= te('Import a spreadsheet of sales') ?></h2></div>
    <p class="section-note"><?= te('A CSV with a header row. County recorder exports and the Kaggle “USA Real Estate” dataset both work once the columns are named like this:') ?></p>
    <code class="block">address,city,state,zip,sale_price,sale_date,beds,baths,sqft,lot_sqft,year_built,property_type,lat,lng</code>
    <p class="section-note"><?= te('Only') ?> <b>address</b> <?= te('and') ?> <b>zip</b> <?= te('(or city + state) are required. Rows need') ?> <b>lat</b> <?= te('and') ?> <b>lng</b> <?= te('to count as comparable sales. Up to 5,000 rows and 3 MB per file.') ?></p>
    <form method="post" action="/add" enctype="multipart/form-data" class="actions mt-m">
      <?= csrf_field() ?><input type="hidden" name="do" value="import">
      <label><span class="sr"><?= te('CSV file') ?></span><input type="file" name="csv" accept=".csv,text/csv" required></label>
      <button class="btn" type="submit"><?= icon('database', 15) ?><?= te('Import') ?></button>
    </form>
  </section>
  <?php endif; ?>
</div>
<?php
View::footer();

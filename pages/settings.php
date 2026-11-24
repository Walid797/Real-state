<?php
declare(strict_types=1);

use App\Auth;
use App\Cache;
use App\Config;
use App\Db;
use App\Jobs;
use App\Notify\Notifier;
use App\Quota;
use App\Settings;
use App\View;

$int = static fn (string $key, int $min, int $max, int $default) => (string) max($min, min($max, is_numeric($_POST[$key] ?? null) ? (int) $_POST[$key] : $default));

if (is_post()) {
    $do = input('do');
    $anchor = '';
    switch ($do) {
        case 'sources':
            Settings::set('rentcast_on', isset($_POST['rentcast_on']) ? '1' : '0');
            Settings::set('homes_on', isset($_POST['homes_on']) ? '1' : '0');
            Settings::set('rentcast_limit', $int('rentcast_limit', 0, 100000, 50));
            Settings::set('rentcast_reserve', $int('rentcast_reserve', 0, 100000, 15));
            Settings::set('openwebninja_on', isset($_POST['openwebninja_on']) ? '1' : '0');
            Settings::set('openwebninja_details_mode', input('openwebninja_details_mode') === 'manual' ? 'manual' : 'auto');
            Settings::set('openwebninja_limit', $int('openwebninja_limit', 0, 1000000, 100));
            Settings::set('openwebninja_reserve', $int('openwebninja_reserve', 0, 1000000, 20));
            Settings::set('openwebninja_auto', $int('openwebninja_auto', 0, 1000000, 60));
            Settings::set('search_provider', in_array(input('search_provider'), ['auto', 'rentcast', 'openwebninja'], true) ? input('search_provider') : 'auto');
            Settings::set('snapshot_hours', $int('snapshot_hours', 1, 720, 24));
            flash('Data sources saved.');
            $anchor = '#sources';
            break;

        case 'aerial':
            Settings::set('cesium_limit', $int('cesium_limit', 0, 100000, 900));
            if (Settings::has('cesium_key')) {
                $check = App\Providers\CesiumIon::check((string) Settings::get('cesium_key'));
                Settings::set('cesium_check', json_encode($check + ['at' => now()]));
            }
            flash('Saved.');
            $anchor = '#aerial';
            break;

        case 'free':
            Settings::set('mail_listings_on', isset($_POST['mail_listings_on']) ? '1' : '0');
            Settings::set('mail_senders_extra', mb_substr(strtolower(preg_replace('/[^a-z0-9.,\s-]/i', '', input('mail_senders_extra', '', 500)) ?? ''), 0, 500));
            $rate = parse_num(input('fairfax_tax_rate', '', 10));
            Settings::set('fairfax_tax_rate', $rate !== null && $rate >= 0 && $rate < 5 ? (string) $rate : '1.12');
            $arl = parse_num(input('arlington_tax_rate', '', 10));
            Settings::set('arlington_tax_rate', $arl !== null && $arl >= 0 && $arl < 5 ? (string) $arl : '1.053');
            flash('Free sources saved.');
            $anchor = '#free';
            break;

        case 'mail_now':
            @set_time_limit(280);
            $lines = App\Services\MailListings::run(200);
            flash($lines ? implode(' ', $lines) : 'No new listing e-mails since the last check.', Settings::get('mail_last_error') ? 'err' : 'ok');
            $anchor = '#free';
            break;

        case 'county_zip':
            @set_time_limit(280);
            $zip = preg_replace('/\D/', '', input('zip', '', 5)) ?? '';
            if (strlen($zip) !== 5) {
                flash('Type a 5-digit ZIP code.', 'err');
            } else {
                $r = App\Services\CountyRecords::areaSales($zip, 200);
                flash($r['message'] . ($r['ok'] ? ' Homes for sale there are re-scored within minutes.' : ''), $r['ok'] ? 'ok' : 'err');
            }
            $anchor = '#free';
            break;

        case 'notify':
            $email = input('notify_email', '', 190);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('That email address doesn’t look right.', 'err');
                redirect('/settings#notify');
            }
            Settings::set('notify_email', $email);
            flash('Delivery settings saved. Send yourself a test below.');
            $anchor = '#notify';
            break;

        case 'test':
            $ch = input('channel');
            if (isset(Notifier::CHANNELS[$ch])) {
                $site = (string) Settings::get('site_name', 'Plat');
                $me = Db::one('SELECT * FROM users WHERE id = ?', [App\Auth::userId()]);
                $r = Notifier::send(
                    "Alerts from $site are set up",
                    "This is the test you asked for from $site's settings. Alerts — new listings, price cuts and strong opportunities — will arrive here, with the price, the estimated profit and a link to the home.\n\nYour alerts: " . abs_url('/alerts'),
                    [$ch], null, Notifier::recipient($me)
                )[$ch] ?? ['ok' => false, 'detail' => 'Unknown channel'];
                flash(Notifier::CHANNELS[$ch] . ': ' . ($r['ok'] ? 'sent — check that it arrived.' : 'failed. ' . $r['detail']), $r['ok'] ? 'ok' : 'err');
            }
            $anchor = '#notify';
            break;

        case 'site':
            Settings::set('site_name', input('site_name', 'Plat', 30) ?: 'Plat');
            Settings::set('default_state', App\Address::stateAbbr(input('default_state', 'VA', 30)) ?? 'VA');
            foreach (App\Services\Deal::SETTINGS as $k => $def) {
                $v = parse_num(input($k, '', 20));
                Settings::set($k, $v === null ? null : (string) $v);
            }
            flash('Saved.');
            $anchor = '#site';
            break;

        case 'run':
            @set_time_limit(280);
            flash(implode(' ', array_map('trim', Jobs::run('auto', 'manual'))));
            $anchor = '#schedule';
            break;

        case 'cache':
            $n = Db::run('DELETE FROM api_cache')->rowCount();
            Db::run('DELETE FROM snapshots');
            flash("Cleared $n cached lookups and the area snapshots. The next search of each area will pull it again (one request each). Saved homes were kept.");
            $anchor = '#data';
            break;
    }
    redirect('/settings' . $anchor);
}

$ready = Notifier::ready(Notifier::recipient(Db::one('SELECT * FROM users WHERE id = ?', [Auth::userId()])));
$log = Db::all('SELECT * FROM api_log ORDER BY id DESC LIMIT 12');
$sent = Db::all('SELECT * FROM notify_log ORDER BY id DESC LIMIT 6');
$counts = [
    'homes' => (int) Db::val('SELECT COUNT(*) FROM properties'),
    'sales' => (int) Db::val('SELECT COUNT(*) FROM properties WHERE last_sale_price > 0'),
    'events' => (int) Db::val('SELECT COUNT(*) FROM price_events'),
    'cache' => (int) Db::val('SELECT COUNT(*) FROM api_cache'),
];
$lastRun = Settings::get('job_last_run');
$s = static fn (string $k) => e((string) (Settings::get($k) ?? ''));
View::header('Settings', 'settings');
?>
<header class="page-head"><div><h1 class="display"><?= te('Settings') ?></h1></div></header>
<nav class="setting-nav" aria-label="<?= te('Sections') ?>">
  <a href="#sources"><?= te('Data sources') ?></a><a href="#free"><?= te('Free sources') ?></a><a href="#aerial"><?= te('3D view') ?></a><a href="#usage"><?= te('Usage') ?></a><a href="#notify"><?= te('Delivery') ?></a><a href="#schedule"><?= te('Schedule') ?></a><a href="#site"><?= te('Site & sign-in') ?></a><a href="/users"><?= te('Team') ?></a><a href="#data"><?= te('Data') ?></a>
</nav>

<div class="narrow">
<section class="section" id="sources">
  <div class="section-head"><h2><?= te('Data sources') ?><?= tip_open() ?><?= te('Always free and already on: map position (U.S. Census geocoder), flood zones (FEMA), market trends (Zillow Research), maps (OpenStreetMap).') ?><?= tip_close() ?></h2></div>
  <form method="post" action="/settings" class="mt-m">
    <?= csrf_field() ?><input type="hidden" name="do" value="sources">
    <div class="card">
      <?php $rcOn = App\Providers\RentCast::switchedOn(); $rcProblem = $rcOn ? App\Providers\RentCast::problem() : null; ?>
      <h3>RentCast <?php if (!$rcOn): ?><span class="tag"><?= te('Off') ?></span><?php elseif ($rcProblem && Settings::has('rentcast_key')): ?><span class="tag tag-bad"><?= te('Key refused') ?></span><?php else: ?><span class="tag<?= Settings::has('rentcast_key') ? ' tag-live' : '' ?>"><?= te(Settings::has('rentcast_key') ? 'Connected' : 'Not connected') ?></span><?php endif; ?><?= tip_open() ?><?= te('Listings for sale, county sale records, value and rent estimates. The free Developer plan gives 50 requests a month.') ?><?= tip_close() ?></h3>
      <label class="check"><input type="checkbox" name="rentcast_on" value="1"<?= checked($rcOn) ?>><?= te('Allow new RentCast API requests') ?></label>
      <?php if ($rcProblem && Settings::has('rentcast_key')): ?>
      <div class="callout callout-bad mb-m" role="alert"><?= icon('triangle-alert', 16) ?><div><strong><?= e($rcProblem['why']) ?></strong>
        <span class="small soft"><?= te('Last refused') ?> <?= e(ago($rcProblem['at'])) ?><?= te('. Refused requests are not counted against your 50.') ?></span>
        <a class="btn btn-sm mt-s" href="<?= e(App\Providers\RentCast::DASHBOARD) ?>" target="_blank" rel="noopener noreferrer"><?= icon('external-link', 14) ?><?= te('Open the RentCast API dashboard') ?></a></div></div>
      <?php endif; ?>
      <div class="form-grid">
        <label class="field c4"><span><?= te('Requests per month') ?><?= tip_open(t('Requests per month')) ?><?= te('Keep at 50 on the free plan — RentCast bills past it.') ?><?= tip_close() ?></span><input class="input" name="rentcast_limit" value="<?= $s('rentcast_limit') ?>" inputmode="numeric"></label>
        <label class="field c4"><span><?= te('Kept for searching by hand') ?><?= tip_open(t('Kept for searching by hand')) ?><?= te('Alerts never touch these.') ?><?= tip_close() ?></span><input class="input" name="rentcast_reserve" value="<?= $s('rentcast_reserve') ?>" inputmode="numeric"></label>
      </div>
    </div>

    <div class="card">
      <?php $zillowOn = App\Providers\OpenWebNinja::switchedOn(); ?>
      <h3><?= te('Zillow data, through OpenWeb Ninja') ?> <span class="tag<?= App\Providers\OpenWebNinja::enabled() ? ' tag-live' : '' ?>"><?= te(!$zillowOn ? 'Off' : (Settings::has('openwebninja_key') ? 'Connected' : 'Optional')) ?></span></h3>
      <label class="check"><input type="checkbox" name="openwebninja_on" value="1"<?= checked($zillowOn) ?>><?= te('Allow new Zillow API requests') ?></label>
      <div class="form-grid">
        <?php $detailsMode = App\Services\ListingDetails::defaultMode(); ?>
        <label class="field c12"><span><?= te('Default Zillow listing details mode') ?></span>
          <select class="select" name="openwebninja_details_mode">
            <option value="auto"<?= selected($detailsMode, 'auto') ?>><?= te('Automatic on open or favorite') ?></option>
            <option value="manual"<?= selected($detailsMode, 'manual') ?>><?= te('Manual (only when requested)') ?></option>
          </select>
          <small><?= te('Each home can use a different mode. A cap of 0 stops all automatic Zillow details.') ?></small>
        </label>
        <label class="field c4"><span><?= te('Requests per month') ?></span><input class="input" name="openwebninja_limit" value="<?= $s('openwebninja_limit') ?>" inputmode="numeric"></label>
        <label class="field c4"><span><?= te('Kept for searching by hand') ?></span><input class="input" name="openwebninja_reserve" value="<?= $s('openwebninja_reserve') ?>" inputmode="numeric"></label>
        <label class="field c4"><span><?= te('Fetched by themselves, per month') ?><?= tip_open(t('Fetched by themselves, per month')) ?><?= te('Monthly cap when automatic Zillow details are enabled.') ?> <?= App\Services\ListingDetails::autoUsed() ?> <?= te('used this month.') ?><?= tip_close() ?></span><input class="input" name="openwebninja_auto" value="<?= $s('openwebninja_auto') ?>" inputmode="numeric"></label>
      </div>
    </div>

    <div class="card">
      <h3>Homes.com<?= tip_open() ?><?= te('Reads the first public search page for up to three areas within a short time limit. Pages are cached for one hour. No RentCast request is used for this source. Access and coverage depend on Homes.com.') ?><?= tip_close() ?></h3>
      <label class="check"><input type="checkbox" name="homes_on" value="1"<?= checked(App\Providers\HomesCom::enabled()) ?>><?= te('Allow new searches on Homes.com') ?></label>
    </div>

    <div class="card">
      <h3><?= te('How searches use them') ?></h3>
      <div class="form-grid mt-m">
        <label class="field c6"><span><?= te('Search with') ?></span>
          <select class="select" name="search_provider">
            <option value="auto"<?= selected(Settings::get('search_provider'), 'auto') ?>><?= te('RentCast first, Zillow data when it runs out') ?></option>
            <option value="rentcast"<?= selected(Settings::get('search_provider'), 'rentcast') ?>><?= te('RentCast only') ?></option>
            <option value="openwebninja"<?= selected(Settings::get('search_provider'), 'openwebninja') ?>><?= te('Zillow data only') ?></option>
          </select></label>
        <label class="field c6"><span><?= te('Reuse a pulled area for, hours') ?><?= tip_open(t('Reuse a pulled area for, hours')) ?><?= te('Within this time the same area is answered from your own database, free.') ?><?= tip_close() ?></span><input class="input" name="snapshot_hours" value="<?= $s('snapshot_hours') ?>" inputmode="numeric"></label>
      </div>
    </div>
    <div class="form-foot"><button class="btn btn-primary" type="submit"><?= te('Save data sources') ?></button></div>
  </form>
</section>

<?php
$mailOn = App\Services\MailListings::enabled();
$mailLog = Db::all('SELECT * FROM mail_log ORDER BY id DESC LIMIT 8');
$mailbox = (string) (Settings::get('smtp_user') ?? '');
$countyZips = Db::all("SELECT k, v FROM settings WHERE k LIKE 'county\\_sales\\_%' ORDER BY v DESC LIMIT 12");
?>
<section class="section" id="free">
  <div class="section-head"><h2><?= te('Free sources') ?><?= tip_open() ?><?= te('no keys, no monthly limit') ?><?= tip_close() ?></h2></div>
  <form method="post" action="/settings" class="mt-m" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="do" value="free">
    <div class="card">
      <h3><?= te('Listing e-mails') ?> <?= $mailOn ? '<span class="tag tag-live">' . te('On') . '</span>' : '<span class="tag">' . te('Off') . '</span>' ?><?= tip_open() ?><?= te('New homes from the saved-search e-mails you already get from Zillow, Redfin, Homes.com, Realtor.com and others — or from your agent’s MLS. They are sent to this site’s mailbox; every ten minutes the site reads the new ones, takes each home’s address from its link, and the price, beds, baths and size printed next to it. Then the home is scored and checked against your alerts like any other. Nothing is downloaded from those sites.') ?><?= tip_close() ?></h3>
      <?php if (Settings::get('mail_last_error')): ?>
      <div class="callout callout-bad mb-m" role="alert"><?= icon('triangle-alert', 16) ?><div><strong><?= e((string) Settings::get('mail_last_error')) ?></strong><span class="small soft"><?= te('Check the mailbox settings on the server.') ?></span></div></div>
      <?php endif; ?>
      <ol class="steps mb-m">
        <li><?= te('The site reads the mailbox it sends alerts from:') ?> <strong class="mono"><?= $mailbox !== '' ? e($mailbox) : te('set it up under Delivery first') ?></strong>.</li>
        <li><strong>Zillow:</strong> <?= te('search the area with your filters →') ?> <strong><?= te('Save search') ?></strong> <?= te('→ e-mail') ?> <strong><?= te('Instantly') ?></strong><?= te('. In Zillow’s account settings, set the e-mail address to the mailbox above (or forward Zillow’s e-mails to it from your own inbox).') ?></li>
        <li><strong>Redfin / Homes.com / Realtor.com:</strong> <?= te('the same — save the search with instant e-mail updates going to that mailbox.') ?></li>
        <li><strong><?= te('Agent with MLS access:') ?></strong> <?= te('ask them to set up an MLS auto-e-mail (“listing alert”) for your criteria to that mailbox; it carries the most complete data. Add the sender’s domain below if it isn’t listed.') ?></li>
        <li><?= te('Tick the box, save, and press') ?> <strong><?= te('Check now') ?></strong> <?= te('once to see it work.') ?></li>
      </ol>
      <div class="form-grid">
        <label class="check c12"><input type="checkbox" name="mail_listings_on"<?= Settings::get('mail_listings_on') === '1' ? ' checked' : '' ?>><?= te('Read listing e-mails from this mailbox') ?></label>
        <label class="field c12"><span><?= te('More sender domains to trust') ?><?= tip_open(t('More sender domains to trust')) ?><?= te('Already trusted:') ?> <?= e(implode(', ', array_keys(App\Services\MailListings::SENDERS))) ?><?= te('. Only mail the mail server confirms came from these domains is read; other mail is left alone.') ?><?= tip_close() ?></span><input class="input" name="mail_senders_extra" value="<?= $s('mail_senders_extra') ?>" placeholder="e.g. myagentsbrokerage.com"></label>
      </div>
      <?php if ($mailLog): ?>
      <ul class="plain-list mt-m">
        <?php foreach ($mailLog as $m): ?>
        <li><span><strong><?= e($m['sender']) ?></strong> · <?= e($m['subject']) ?><br><span class="small soft"><?= e($m['note']) ?></span></span><span class="end"><?= e(ago($m['created_at'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php elseif ($mailOn): ?>
      <p class="small muted mt-m"><?= Settings::get('mail_checked_at') ? 'Checked ' . e(ago(Settings::get('mail_checked_at'))) . ' — no listing e-mails yet.' : 'Not checked yet.' ?></p>
      <?php endif; ?>
    </div>

    <div class="card mt-l">
      <h3><?= te('County and state public records') ?> <span class="tag tag-live"><?= te('On') ?></span><?= tip_open() ?><?= te('Official open data from the assessors, for every home in the places below: assessed value, tax, year built, size, lot and the recorded sales (beds and baths where the assessor publishes them). Looked up the first time a home is opened, and hourly for homes for sale. Each ZIP’s fair-market sales of the last 12 months are brought in every two weeks, so ARV and profit rest on real sale prices. Elsewhere the paid sources and listing e-mails fill in; more places are added as their records are checked.') ?><br><br><?= te('DC uses the rate on each property’s own record. Maryland rates differ by county and town, so no tax is computed there.') ?><?= tip_close() ?></h3>
      <ul class="plain-list mb-m">
        <?php foreach (App\Services\CountyRecords::SOURCES as $src): ?>
        <li><span><strong><?= e($src::area()) ?></strong><span class="sub"><?= e($src::name()) ?></span></span><span class="end"><span class="tag tag-live"><?= te('Connected') ?></span></span></li>
        <?php endforeach; ?>
      </ul>
      <div class="form-grid">
        <label class="field c4"><span><?= te('Fairfax County tax rate, $ per $100') ?><?= tip_open(t('Fairfax County tax rate, $ per $100')) ?><?= te('FY 2027, adopted May 5, 2026: $1.12.') ?><?= tip_close() ?></span><input class="input" name="fairfax_tax_rate" value="<?= $s('fairfax_tax_rate') ?>" inputmode="decimal"></label>
        <label class="field c4"><span><?= te('Arlington County tax rate, $ per $100') ?><?= tip_open(t('Arlington County tax rate, $ per $100')) ?><?= te('2026, adopted April 22, 2026: $1.053.') ?><?= tip_close() ?></span><input class="input" name="arlington_tax_rate" value="<?= $s('arlington_tax_rate') ?>" inputmode="decimal"></label>
      </div>
      <?php $missingCounties = App\Services\CountyRecords::missing(); ?>
      <h3 class="mt-l"><?= te('Counties without a source yet') ?><?= tip_open() ?><?= te('Places with homes for sale on file whose assessor records aren’t connected yet, most homes first. Each week this list is checked: counties that publish their records openly are added after a live test; those that don’t are noted here.') ?><?= tip_close() ?></h3>
      <?php if ($missingCounties): ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th><?= te('County') ?></th><th><?= te('State') ?></th><th class="r"><?= te('Homes for sale') ?></th><th><?= te('ZIP codes') ?></th></tr></thead>
          <tbody>
          <?php foreach ($missingCounties as $mc): ?>
            <tr><td><?= e($mc['county'] ?: 'Unknown county') ?></td><td><?= e($mc['state']) ?></td><td class="r"><?= (int) $mc['homes'] ?></td><td class="mono small"><?= e($mc['zips']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <p class="small soft"><?= te('Every county with homes for sale on file has its records connected.') ?></p>
      <?php endif; ?>
      <?php if ($countyZips): ?>
      <p class="small soft mt-m"><?= te('Recorded sales brought in:') ?> <?php foreach ($countyZips as $i => $z): ?><?= $i ? ', ' : '' ?><span class="mono"><?= e(substr($z['k'], 13)) ?></span> (<?= e(ago($z['v'])) ?>)<?php endforeach; ?>.</p>
      <?php endif; ?>
    </div>
    <div class="form-foot"><button class="btn btn-primary" type="submit"><?= te('Save free sources') ?></button></div>
  </form>
  <div class="actions mt-m">
    <?php if ($mailOn): ?>
    <form method="post" action="/settings"><?= csrf_field() ?><input type="hidden" name="do" value="mail_now"><button class="btn btn-sm" type="submit"><?= icon('mail', 14) ?><?= te('Check listing e-mails now') ?></button></form>
    <?php endif; ?>
    <form method="post" action="/settings" class="actions"><?= csrf_field() ?><input type="hidden" name="do" value="county_zip">
      <input class="input input-zip" name="zip" placeholder="<?= te('ZIP, e.g. 22030') ?>" inputmode="numeric" maxlength="5" aria-label="<?= te('ZIP code') ?>">
      <button class="btn btn-sm" type="submit"><?= icon('download', 14) ?><?= te('Bring in that ZIP’s recorded sales') ?></button></form>
  </div>
</section>

<?php $aerialCheck = json_decode((string) Settings::get('cesium_check', ''), true) ?: null; ?>
<section class="section" id="aerial">
  <div class="section-head"><h2><?= te('3D view') ?></h2><span class="aside"><?= Settings::has('cesium_key') ? te('%1$d of %2$d used this month', Quota::used('cesium'), Quota::limit('cesium')) : te('off') ?></span></div>
  <form method="post" action="/settings" class="mt-m" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="do" value="aerial">
    <div class="card">
      <h3><?= te('Look around in 3D, through Cesium ion') ?><?= tip_open() ?><?= te('Each press of “Open 3D view” counts as one. The free Community plan is meant for personal and early-stage use; Cesium asks companies with more than $50K a year in revenue to use a paid plan. Imagery belongs to Google and can be months or years old.') ?><?= tip_close() ?>
        <?php if (!Settings::has('cesium_key')): ?><span class="tag"><?= te('Off') ?></span>
        <?php elseif ($aerialCheck && !$aerialCheck['ok'] && !empty($aerialCheck['unreachable'])): ?><span class="tag tag-warn"><?= te('Not checked') ?></span>
        <?php elseif ($aerialCheck && !$aerialCheck['ok']): ?><span class="tag tag-bad"><?= te('Token refused') ?></span>
        <?php else: ?><span class="tag tag-live"><?= te('On') ?></span><?php endif; ?><?= tip_open() ?><?= te('Every home’s page gets a “Look around” panel: a slow flight around the house over Google’s photorealistic 3D map — roof, yard, driveway, street and neighbours. Cesium ion passes Google’s 3D tiles through its free plan, so no Google account or bank card is needed here.') ?><?= tip_close() ?></h3>
      <?php if ($aerialCheck && !$aerialCheck['ok'] && Settings::has('cesium_key')): ?>
      <?php if (!empty($aerialCheck['unreachable'])): ?>
      <div class="callout mb-m" role="status"><?= icon('info', 16) ?><div><strong><?= e($aerialCheck['why']) ?></strong><span class="small soft"><?= te('The 3D view may well work — open a home to see. Save again later to re-check.') ?></span></div></div>
      <?php else: ?>
      <div class="callout callout-bad mb-m" role="alert"><?= icon('triangle-alert', 16) ?><div><strong><?= e($aerialCheck['why']) ?></strong><span class="small soft"><?= te('Checked') ?> <?= e(ago($aerialCheck['at'])) ?>. <?= te('Check the 3D service’s token on the server.') ?></span></div></div>
      <?php endif; ?>
      <?php endif; ?>
      <div class="form-grid">
        <label class="field c4"><span><?= te('3D views per month') ?><?= tip_open(t('3D views per month')) ?><?= te('The free plan allows 1,000 openings of Google’s 3D tiles a month; 900 leaves a margin.') ?><?= tip_close() ?></span><input class="input" name="cesium_limit" value="<?= $s('cesium_limit') ?>" inputmode="numeric"></label>
      </div>
    </div>
    <div class="form-foot"><button class="btn btn-primary" type="submit"><?= te('Save 3D view') ?></button></div>
  </form>
</section>

<section class="section" id="usage">
  <div class="section-head"><h2><?= te('Usage this month') ?><?= tip_open() ?><?= te('Every answered request is counted, including ones that find nothing, so the meter can only run ahead of the provider’s own count — never behind it.') ?><?= tip_close() ?></h2><span class="aside"><?= te('resets in') ?> <?= plural(Quota::resetsIn(), 'day') ?></span></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th><?= te('Source') ?></th><th class="r"><?= te('Used') ?></th><th class="r"><?= te('Allowance') ?></th><th class="r"><?= te('Left for you') ?></th><th class="r"><?= te('Left for alerts') ?></th></tr></thead>
      <tbody>
      <?php foreach (Quota::PROVIDERS as $pid => $label): ?>
        <tr><td><?= te($label) ?><?= Settings::has($pid . '_key') ? '' : ' <span class="muted small">— ' . te('not connected') . '</span>' ?></td>
          <td class="r"><?= Quota::used($pid) ?></td><td class="r"><?= Quota::limit($pid) ?></td>
          <td class="r"><?= Quota::left($pid) ?></td><td class="r"><?= Quota::left($pid, 'cron') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($log): ?>
  <h3 class="eyebrow mt-l"><?= te('Last requests') ?></h3>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th><?= te('When') ?></th><th><?= te('Source') ?></th><th><?= te('What') ?></th><th><?= te('By') ?></th><th class="r"><?= te('Result') ?></th><th class="r"><?= te('Time') ?></th></tr></thead>
      <tbody>
      <?php foreach ($log as $l): ?>
        <tr><td class="nowrap"><?= e(ago($l['created_at'])) ?></td><td><?= e($l['provider'] === 'homes' ? 'Homes.com' : (Quota::PROVIDERS[$l['provider']] ?? $l['provider'])) ?></td>
          <td class="mono"><?= e($l['endpoint']) ?><?php if ($l['note']): ?><span class="sub"><?= e($l['note']) ?></span><?php endif; ?></td>
          <td><?= te($l['context'] === 'cron' ? 'alert' : 'you') ?></td>
          <td class="r"><?= in_array((int) $l['status'], [200, 404], true) ? '<span class="tag tag-live">' . (int) $l['status'] . '</span>' : '<span class="tag tag-bad">' . ((int) $l['status'] ?: 'no reply') . '</span>' ?></td>
          <td class="r"><?= number_format((int) $l['ms']) ?> <?= te('ms') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="section" id="notify">
  <div class="section-head"><h2><?= te('Where alerts are delivered') ?></h2></div>
  <form method="post" action="/settings" class="mt-m">
    <?= csrf_field() ?><input type="hidden" name="do" value="notify">
    <div class="card">
      <h3><?= te('Email') ?> <span class="tag<?= $ready['email'] ? ' tag-live' : '' ?>"><?= te($ready['email'] ? 'Ready' : 'Not set') ?></span><?= tip_open() ?><?= te('Sent through a mailbox on your own domain, so it lands in the inbox instead of spam.') ?><?= tip_close() ?></h3>
      <div class="form-grid">
        <label class="field c6"><span><?= te('Send alerts to') ?></span><input class="input" type="email" name="notify_email" value="<?= $s('notify_email') ?>" placeholder="you@gmail.com"></label>
      </div>
    </div>

    <div class="card">
      <h3><?= te('Telegram') ?> <span class="tag<?= $ready['telegram'] ? ' tag-live' : '' ?>"><?= te($ready['telegram'] ? 'Ready' : 'Not set') ?></span><?= tip_open() ?><?= te('Free, instant and the most dependable of the three.') ?><?= tip_close() ?></h3>
    </div>

    <div class="card">
      <h3><?= te('WhatsApp') ?> <span class="tag<?= $ready['whatsapp'] ? ' tag-live' : '' ?>"><?= te($ready['whatsapp'] ? 'Yours is ready' : 'Per person') ?></span><?= tip_open() ?><?= te('CallMeBot can only message the number that asked it for a key, so each person turns WhatsApp on for themselves under') ?> <a href="/account#whatsapp"><?= te('My account → WhatsApp') ?></a><?= te('. It takes about three minutes.') ?><?= tip_close() ?></h3>
    </div>
    <div class="form-foot"><button class="btn btn-primary" type="submit"><?= te('Save delivery settings') ?></button></div>
  </form>

  <div class="actions mt-m">
    <span class="small muted"><?= te('Send a test:') ?></span>
    <?php foreach (Notifier::CHANNELS as $ch => $label): ?>
    <form method="post" action="/settings" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="test"><input type="hidden" name="channel" value="<?= $ch ?>">
      <button class="btn btn-sm" type="submit"<?= $ready[$ch] ? '' : ' disabled' ?>><?= icon('send', 14) ?><?= te($label) ?></button></form>
    <?php endforeach; ?>
  </div>
  <?php if ($sent): ?>
  <ul class="plain-list mt-m">
    <?php foreach ($sent as $n): ?>
    <li><span><?= te(Notifier::CHANNELS[$n['channel']] ?? $n['channel']) ?> — <?= e($n['subject']) ?><span class="sub"><?= e(t((string) $n['detail'])) ?></span></span>
      <span class="end"><?= $n['ok'] ? '<span class="tag tag-live">' . te('Sent') . '</span>' : '<span class="tag tag-bad">' . te('Failed') . '</span>' ?><br><?= e(ago($n['created_at'])) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<section class="section" id="schedule">
  <div class="section-head"><h2><?= te('Schedule') ?><?= tip_open() ?><?= te('Alerts, county records and market data are refreshed on the server every hour.') ?><?= tip_close() ?></h2><span class="aside"><?= $lastRun ? 'last ran ' . e(ago($lastRun)) : 'has not run yet' ?></span></div>
  <form method="post" action="/settings" class="actions mt-m"><?= csrf_field() ?><input type="hidden" name="do" value="run">
    <button class="btn btn-sm" type="submit"><?= icon('refresh-cw', 14) ?><?= te('Run it once now') ?></button>
    <?= tip_open() ?><?= te('Run by hand it may use your own requests rather than the alerts’ share.') ?><?= tip_close() ?></form>
</section>

<section class="section" id="site">
  <div class="section-head"><h2><?= te('Site, sign-in and deal defaults') ?></h2></div>
  <form method="post" action="/settings" class="mt-m" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="do" value="site">
    <div class="form-grid">
      <label class="field c4"><span><?= te('Site name') ?></span><input class="input" name="site_name" value="<?= $s('site_name') ?>" maxlength="30"></label>
      <label class="field c4"><span><?= te('Home state') ?><?= tip_open(t('Home state')) ?><?= te('“Entire state” searches and county names use this.') ?><?= tip_close() ?></span><input class="input" name="default_state" value="<?= $s('default_state') ?>" maxlength="20"></label>
    </div>

    <div class="card">
      <h3><?= te('Deal calculator defaults') ?><?= tip_open() ?><?= te('Used until someone types the real figure for a home. Renovation is estimated from the home’s condition × its square footage.') ?><?= tip_close() ?></h3>
      <div class="form-grid">
        <?php foreach (App\Services\Deal::SETTINGS as $k => [$label, $def, $unit]): ?>
        <label class="field c4"><span><?= te($label) ?></span><input class="input" name="<?= $k ?>" value="<?= e((string) (Settings::get($k) ?? '')) ?>" placeholder="<?= e($def . ' ' . t($unit)) ?>" inputmode="decimal"></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="form-foot"><button class="btn btn-primary" type="submit"><?= te('Save') ?></button><?= tip_open() ?><?= te('Your own name, password and Telegram are on') ?> <a href="/account"><?= te('My account') ?></a>.<?= tip_close() ?></div>
  </form>
</section>

<section class="section" id="data">
  <div class="section-head"><h2><?= te('What’s stored') ?></h2></div>
  <dl class="kv">
    <div><dt><?= te('Homes on file') ?></dt><dd><?= number_format($counts['homes']) ?></dd></div>
    <div><dt><?= te('With a recorded sale') ?></dt><dd><?= number_format($counts['sales']) ?></dd></div>
    <div><dt><?= te('Price events') ?></dt><dd><?= number_format($counts['events']) ?></dd></div>
    <div><dt><?= te('Cached free lookups') ?></dt><dd><?= number_format($counts['cache']) ?></dd></div>
  </dl>
  <form method="post" action="/settings" class="mt-m" data-confirm="<?= te('Clear cached lookups and area snapshots? Saved homes stay, but each area’s next search will use a request.') ?>">
    <?= csrf_field() ?><input type="hidden" name="do" value="cache">
    <button class="linkbtn small" type="submit"><?= te('Clear caches and force fresh pulls') ?></button>
  </form>
  <h3 class="eyebrow mt-l"><?= te('Recent log') ?><?= tip_open() ?><?= te('Newest first. Errors are marked red, slow pages amber; each data pull writes one line with how long each step took.') ?><?= tip_close() ?></h3>
  <?php $tail = App\Diagnostics::logTail(20); ?>
  <?php if ($tail): ?>
  <pre class="logtail"><?php foreach (array_reverse($tail) as $line): ?><span class="<?= preg_match('/\[(fatal|uncaught)\]|PHP (Fatal|Warning)/', $line) ? 'is-bad' : (str_contains($line, '[slow]') ? 'is-warn' : '') ?>"><?= e(mb_substr($line, 0, 500)) ?></span>
<?php endforeach; ?></pre>
  <?php else: ?>
  <p class="blank"><?= te('Nothing logged — no errors so far.') ?></p>
  <?php endif; ?>
  <h3 class="eyebrow mt-l"><?= te('Backups') ?><?= tip_open() ?><?= te('A backup is written daily by the scheduler (the last 14 are kept on the server, closed to the web). Download one now and then and keep it somewhere else too. It holds no API keys or passwords: those stay only on the server.') ?><?= tip_close() ?></h3>
  <?php $backups = App\Backup::list(); ?>
  <?php if ($backups): ?>
  <ul class="plain-list">
    <?php foreach (array_slice($backups, 0, 7) as $b): ?>
    <li><a class="main" href="/backup/<?= e($b['name']) ?>"><?= e($b['name']) ?></a><span class="end"><?= number_format($b['size'] / 1024, 0) ?> <?= te('KB ·') ?> <?= e(ago(gmdate('Y-m-d H:i:s', $b['time']))) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
  <p class="blank"><?= te('The scheduler writes one every day and keeps the last 14.') ?></p>
  <?php endif; ?>
</section>
</div>
<?php
View::footer();

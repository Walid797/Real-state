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

/** Saves a secret field: empty keeps the stored value, the "remove" box clears it. */
$secret = static function (string $key): void {
    if (isset($_POST[$key . '_remove'])) {
        Settings::set($key, null);
        return;
    }
    $v = trim((string) ($_POST[$key] ?? ''));
    if ($v !== '') {
        Settings::set($key, mb_substr($v, 0, 400));
    }
};
$int = static fn (string $key, int $min, int $max, int $default) => (string) max($min, min($max, is_numeric($_POST[$key] ?? null) ? (int) $_POST[$key] : $default));

if (is_post()) {
    $do = input('do');
    $anchor = '';
    switch ($do) {
        case 'sources':
            if (trim((string) ($_POST['rentcast_key'] ?? '')) !== '') {
                Settings::set('rentcast_key_problem', null); // a new key gets a fresh start
            }
            $secret('rentcast_key');
            $secret('openwebninja_key');
            $secret('census_key');
            Settings::set('rentcast_limit', $int('rentcast_limit', 0, 100000, 50));
            Settings::set('rentcast_reserve', $int('rentcast_reserve', 0, 100000, 15));
            Settings::set('openwebninja_limit', $int('openwebninja_limit', 0, 1000000, 100));
            Settings::set('openwebninja_reserve', $int('openwebninja_reserve', 0, 1000000, 20));
            Settings::set('openwebninja_auto', $int('openwebninja_auto', 0, 1000000, 60));
            Settings::set('search_provider', in_array(input('search_provider'), ['auto', 'rentcast', 'openwebninja'], true) ? input('search_provider') : 'auto');
            Settings::set('snapshot_hours', $int('snapshot_hours', 1, 720, 24));
            flash('Data sources saved.');
            $anchor = '#sources';
            break;

        case 'aerial':
            if (!empty($_POST['cesium_key_remove'])) {
                Settings::set('cesium_key', null);
                Settings::set('cesium_check', null);
                flash('3D view switched off.');
            } else {
                $secret('cesium_key');
                Settings::set('cesium_limit', $int('cesium_limit', 0, 100000, 900));
                if (Settings::has('cesium_key')) {
                    $check = App\Providers\CesiumIon::check((string) Settings::get('cesium_key'));
                    Settings::set('cesium_check', json_encode($check + ['at' => now()]));
                    flash($check['ok'] ? '3D view is on. Open any home and press “Open 3D view”.' : (!empty($check['unreachable']) ? 'Token saved. ' . $check['why'] : 'Saved, but Cesium said: ' . $check['why']), $check['ok'] || !empty($check['unreachable']) ? 'ok' : 'err');
                } else {
                    flash('Saved.');
                }
            }
            $anchor = '#aerial';
            break;

        case 'free':
            Settings::set('mail_listings_on', isset($_POST['mail_listings_on']) ? '1' : '0');
            Settings::set('imap_host', input('imap_host', 'imap.hostinger.com', 120) ?: 'imap.hostinger.com');
            Settings::set('imap_port', $int('imap_port', 1, 65535, 993));
            Settings::set('mail_senders_extra', mb_substr(strtolower(preg_replace('/[^a-z0-9.,\s-]/i', '', input('mail_senders_extra', '', 500)) ?? ''), 0, 500));
            $rate = parse_num(input('fairfax_tax_rate', '', 10));
            Settings::set('fairfax_tax_rate', $rate !== null && $rate >= 0 && $rate < 5 ? (string) $rate : '1.12');
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
            Settings::set('smtp_host', input('smtp_host', '', 120));
            Settings::set('smtp_port', $int('smtp_port', 1, 65535, 465));
            Settings::set('smtp_secure', in_array(input('smtp_secure'), ['ssl', 'tls', 'none'], true) ? input('smtp_secure') : 'ssl');
            Settings::set('smtp_user', input('smtp_user', '', 190));
            $secret('smtp_pass');
            $secret('telegram_token');
            Settings::set('telegram_chat', preg_replace('/[^0-9@a-zA-Z_\-]/', '', input('telegram_chat', '', 64)));
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
            Settings::set('google_client_id', input('google_client_id', '', 200));
            $secret('google_client_secret');
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
$cronCmd = 'php ' . APP_ROOT . '/cron/run.php';
$cronUrl = abs_url('/cron', ['key' => (string) Config::get('cron_key', '')]);
$s = static fn (string $k) => e((string) (Settings::get($k) ?? ''));
$secretField = static function (string $key, string $label, string $hint = '') : string {
    $has = Settings::has($key);
    return '<label class="field c8"><span>' . e($label) . '</span>'
        . '<input class="input mono" type="password" name="' . $key . '" autocomplete="off" spellcheck="false" placeholder="' . ($has ? e(Settings::masked($key)) . ' — saved; type to replace' : 'Paste it here') . '">'
        . ($hint ? '<small>' . $hint . '</small>' : '') . '</label>'
        . '<div class="field c4"><span>&nbsp;</span>' . ($has ? '<label class="check"><input type="checkbox" name="' . $key . '_remove">Remove this key</label>' : '<span class="small muted">Not connected</span>') . '</div>';
};

View::header('Settings', 'settings');
?>
<header class="page-head"><div><h1 class="display">Settings</h1></div></header>
<nav class="setting-nav" aria-label="Sections">
  <a href="#sources">Data sources</a><a href="#free">Free sources</a><a href="#aerial">3D view</a><a href="#usage">Usage</a><a href="#notify">Delivery</a><a href="#schedule">Schedule</a><a href="#site">Site &amp; sign-in</a><a href="/users">Team</a><a href="#data">Data</a>
</nav>

<div class="narrow">
<section class="section" id="sources">
  <div class="section-head"><h2>Data sources</h2></div>
  <form method="post" action="/settings" class="mt-m">
    <?= csrf_field() ?><input type="hidden" name="do" value="sources">
    <div class="card">
      <?php $rcProblem = App\Providers\RentCast::problem(); ?>
      <h3>RentCast <?php if ($rcProblem && Settings::has('rentcast_key')): ?><span class="tag tag-bad">Key refused</span><?php else: ?><span class="tag<?= Settings::has('rentcast_key') ? ' tag-live' : '' ?>"><?= Settings::has('rentcast_key') ? 'Connected' : 'Not connected' ?></span><?php endif; ?></h3>
      <?php if ($rcProblem && Settings::has('rentcast_key')): ?>
      <div class="callout callout-bad mb-m" role="alert"><?= icon('triangle-alert', 16) ?><div><strong><?= e($rcProblem['why']) ?></strong>
        <span class="small soft">Last refused <?= e(ago($rcProblem['at'])) ?>. Refused requests are not counted against your 50.</span>
        <a class="btn btn-sm mt-s" href="<?= e(App\Providers\RentCast::DASHBOARD) ?>" target="_blank" rel="noopener noreferrer"><?= icon('external-link', 14) ?>Open the RentCast API dashboard</a></div></div>
      <?php endif; ?>
      <p class="small soft mb-m">Listings for sale, county sale records, value and rent estimates. The free Developer plan gives 50 requests a month.</p>
      <ol class="steps mb-m">
        <li>Create an account at <a href="https://app.rentcast.io/app/api" target="_blank" rel="noopener noreferrer">app.rentcast.io → API</a> and choose the free Developer plan.</li>
        <li>Press “Create API key” and paste it below.</li>
      </ol>
      <div class="form-grid">
        <?= $secretField('rentcast_key', 'API key') ?>
        <label class="field c4"><span>Requests per month</span><input class="input" name="rentcast_limit" value="<?= $s('rentcast_limit') ?>" inputmode="numeric">
          <small>Keep at 50 on the free plan — RentCast bills past it.</small></label>
        <label class="field c4"><span>Kept for searching by hand</span><input class="input" name="rentcast_reserve" value="<?= $s('rentcast_reserve') ?>" inputmode="numeric">
          <small>Alerts never touch these.</small></label>
      </div>
    </div>

    <div class="card">
      <h3>Zillow data, through OpenWeb Ninja <span class="tag<?= Settings::has('openwebninja_key') ? ' tag-live' : '' ?>"><?= Settings::has('openwebninja_key') ? 'Connected' : 'Optional' ?></span></h3>
      <p class="small soft mb-m">Adds photos, the listing description, full price and tax history, Zestimates and Zillow’s own sold comparables. Free plan: 100 requests a month, hard-capped.</p>
      <ol class="steps mb-m">
        <li>Sign up at <a href="https://www.openwebninja.com/api/real-time-zillow-data" target="_blank" rel="noopener noreferrer">openwebninja.com → Real-Time Zillow Data</a> and subscribe to the Free plan.</li>
        <li>Copy the key from the developer portal and paste it below.</li>
      </ol>
      <div class="form-grid">
        <?= $secretField('openwebninja_key', 'API key', 'A third-party service that reads Zillow for you; Zillow itself offers no public API.') ?>
        <label class="field c4"><span>Requests per month</span><input class="input" name="openwebninja_limit" value="<?= $s('openwebninja_limit') ?>" inputmode="numeric"></label>
        <label class="field c4"><span>Kept for searching by hand</span><input class="input" name="openwebninja_reserve" value="<?= $s('openwebninja_reserve') ?>" inputmode="numeric"></label>
        <label class="field c4"><span>Fetched by themselves, per month</span><input class="input" name="openwebninja_auto" value="<?= $s('openwebninja_auto') ?>" inputmode="numeric">
          <small>The first time a home is opened or added to the pipeline, its full listing is fetched once. 0 turns this off. <?= App\Services\ListingDetails::autoUsed() ?> used this month.</small></label>
      </div>
    </div>

    <div class="card">
      <h3>How searches use them</h3>
      <div class="form-grid mt-m">
        <label class="field c6"><span>Search with</span>
          <select class="select" name="search_provider">
            <option value="auto"<?= selected(Settings::get('search_provider'), 'auto') ?>>RentCast first, Zillow data when it runs out</option>
            <option value="rentcast"<?= selected(Settings::get('search_provider'), 'rentcast') ?>>RentCast only</option>
            <option value="openwebninja"<?= selected(Settings::get('search_provider'), 'openwebninja') ?>>Zillow data only</option>
          </select></label>
        <label class="field c6"><span>Reuse a pulled area for, hours</span><input class="input" name="snapshot_hours" value="<?= $s('snapshot_hours') ?>" inputmode="numeric">
          <small>Within this time the same area is answered from your own database, free.</small></label>
        <?= $secretField('census_key', 'Census API key', 'Free, and needed for the neighbourhood panel (income, home values, rents). Request one at <a href="https://api.census.gov/data/key_signup.html" target="_blank" rel="noopener noreferrer">api.census.gov/data/key_signup.html</a> — it arrives by email.') ?>
      </div>
    </div>
    <div class="form-foot"><button class="btn btn-primary" type="submit">Save data sources</button></div>
  </form>
  <p class="section-note">Always free and already on: map position (U.S. Census geocoder), flood zones (FEMA), market trends (Zillow Research), maps (OpenStreetMap).</p>
</section>

<?php
$mailOn = App\Services\MailListings::enabled();
$mailLog = Db::all('SELECT * FROM mail_log ORDER BY id DESC LIMIT 8');
$mailbox = (string) (Settings::get('smtp_user') ?? '');
$countyZips = Db::all("SELECT k, v FROM settings WHERE k LIKE 'county\\_sales\\_%' ORDER BY v DESC LIMIT 12");
?>
<section class="section" id="free">
  <div class="section-head"><h2>Free sources</h2><span class="aside">no keys, no monthly limit</span></div>
  <form method="post" action="/settings" class="mt-m" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="do" value="free">
    <div class="card">
      <h3>Listing e-mails <?= $mailOn ? '<span class="tag tag-live">On</span>' : '<span class="tag">Off</span>' ?></h3>
      <p class="small soft mb-m">New homes from the saved-search e-mails you already get from Zillow, Redfin, Homes.com, Realtor.com and others — or from your agent’s MLS. They are sent to this site’s mailbox; every ten minutes the site reads the new ones, takes each home’s address from its link, and the price, beds, baths and size printed next to it. Then the home is scored and checked against your alerts like any other. Nothing is downloaded from those sites.</p>
      <?php if (Settings::get('mail_last_error')): ?>
      <div class="callout callout-bad mb-m" role="alert"><?= icon('triangle-alert', 16) ?><div><strong><?= e((string) Settings::get('mail_last_error')) ?></strong><span class="small soft">The mailbox is the one under Delivery below; check its address and password.</span></div></div>
      <?php endif; ?>
      <ol class="steps mb-m">
        <li>The site reads the mailbox it sends alerts from: <strong class="mono"><?= $mailbox !== '' ? e($mailbox) : 'set it up under Delivery first' ?></strong>.</li>
        <li><strong>Zillow:</strong> search the area with your filters → <strong>Save search</strong> → e-mail <strong>Instantly</strong>. In Zillow’s account settings, set the e-mail address to the mailbox above (or forward Zillow’s e-mails to it from your own inbox).</li>
        <li><strong>Redfin / Homes.com / Realtor.com:</strong> the same — save the search with instant e-mail updates going to that mailbox.</li>
        <li><strong>Agent with MLS access:</strong> ask them to set up an MLS auto-e-mail (“listing alert”) for your criteria to that mailbox; it carries the most complete data. Add the sender’s domain below if it isn’t listed.</li>
        <li>Tick the box, save, and press <strong>Check now</strong> once to see it work.</li>
      </ol>
      <div class="form-grid">
        <label class="check c12"><input type="checkbox" name="mail_listings_on"<?= Settings::get('mail_listings_on') === '1' ? ' checked' : '' ?>>Read listing e-mails from this mailbox</label>
        <label class="field c6"><span>Mail server (IMAP)</span><input class="input" name="imap_host" value="<?= $s('imap_host') ?>"></label>
        <label class="field c2"><span>Port</span><input class="input" name="imap_port" value="<?= $s('imap_port') ?>" inputmode="numeric"></label>
        <label class="field c12"><span>More sender domains to trust</span><input class="input" name="mail_senders_extra" value="<?= $s('mail_senders_extra') ?>" placeholder="e.g. myagentsbrokerage.com">
          <small>Already trusted: <?= e(implode(', ', array_keys(App\Services\MailListings::SENDERS))) ?>. Only mail the mail server confirms came from these domains is read; other mail is left alone.</small></label>
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
      <h3>Fairfax County public records <span class="tag tag-live">On</span></h3>
      <p class="small soft mb-m">The county’s own open data, for every home in Fairfax County: assessed value, real estate tax, year built and remodelled, bedrooms, baths, living area, basement, condition, heating, roof, lot size, zoning — and every recorded sale. Looked up the first time a home is opened, and hourly for homes for sale. The county’s sales of the last 12 months in each ZIP with homes for sale are brought in every two weeks, so ARV and profit rest on real sale prices. Fairfax City and Falls Church keep separate records and are not included.</p>
      <div class="form-grid">
        <label class="field c4"><span>Real estate tax rate, $ per $100</span><input class="input" name="fairfax_tax_rate" value="<?= $s('fairfax_tax_rate') ?>" inputmode="decimal">
          <small>Fairfax County FY 2027, adopted May 5, 2026: $1.12. Change it when the county adopts a new rate.</small></label>
      </div>
      <?php if ($countyZips): ?>
      <p class="small soft mt-m">Recorded sales brought in: <?php foreach ($countyZips as $i => $z): ?><?= $i ? ', ' : '' ?><span class="mono"><?= e(substr($z['k'], 13)) ?></span> (<?= e(ago($z['v'])) ?>)<?php endforeach; ?>.</p>
      <?php endif; ?>
    </div>
    <div class="form-foot"><button class="btn btn-primary" type="submit">Save free sources</button></div>
  </form>
  <div class="actions mt-m">
    <?php if ($mailOn): ?>
    <form method="post" action="/settings"><?= csrf_field() ?><input type="hidden" name="do" value="mail_now"><button class="btn btn-sm" type="submit"><?= icon('mail', 14) ?>Check listing e-mails now</button></form>
    <?php endif; ?>
    <form method="post" action="/settings" class="actions"><?= csrf_field() ?><input type="hidden" name="do" value="county_zip">
      <input class="input input-zip" name="zip" placeholder="ZIP, e.g. 22030" inputmode="numeric" maxlength="5" aria-label="Fairfax County ZIP code">
      <button class="btn btn-sm" type="submit"><?= icon('download', 14) ?>Bring in that ZIP’s recorded sales</button></form>
  </div>
</section>

<?php $aerialCheck = json_decode((string) Settings::get('cesium_check', ''), true) ?: null; ?>
<section class="section" id="aerial">
  <div class="section-head"><h2>3D view</h2><span class="aside"><?= Settings::has('cesium_key') ? Quota::used('cesium') . ' of ' . Quota::limit('cesium') . ' used this month' : 'off' ?></span></div>
  <form method="post" action="/settings" class="mt-m" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="do" value="aerial">
    <div class="card">
      <h3>Look around in 3D, through Cesium ion
        <?php if (!Settings::has('cesium_key')): ?><span class="tag">Off</span>
        <?php elseif ($aerialCheck && !$aerialCheck['ok'] && !empty($aerialCheck['unreachable'])): ?><span class="tag tag-warn">Not checked</span>
        <?php elseif ($aerialCheck && !$aerialCheck['ok']): ?><span class="tag tag-bad">Token refused</span>
        <?php else: ?><span class="tag tag-live">On</span><?php endif; ?></h3>
      <p class="small soft mb-m">Every home’s page gets a “Look around” panel: a slow flight around the house over Google’s photorealistic 3D map — roof, yard, driveway, street and neighbours. Cesium ion passes Google’s 3D tiles through its free plan, so no Google account or bank card is needed here.</p>
      <?php if ($aerialCheck && !$aerialCheck['ok'] && Settings::has('cesium_key')): ?>
      <?php if (!empty($aerialCheck['unreachable'])): ?>
      <div class="callout mb-m" role="status"><?= icon('info', 16) ?><div><strong><?= e($aerialCheck['why']) ?></strong><span class="small soft">The token is saved and the 3D view may well work — open a home to see. Save again later to re-check.</span></div></div>
      <?php else: ?>
      <div class="callout callout-bad mb-m" role="alert"><?= icon('triangle-alert', 16) ?><div><strong><?= e($aerialCheck['why']) ?></strong><span class="small soft">Checked <?= e(ago($aerialCheck['at'])) ?>. Follow steps 2 and 3 again, then save.</span></div></div>
      <?php endif; ?>
      <?php endif; ?>
      <ol class="steps mb-m">
        <li>Create a free account at <a href="https://ion.cesium.com/signup" target="_blank" rel="noopener noreferrer">ion.cesium.com/signup</a> and pick the free <strong>Community</strong> plan. An email address is enough to sign up.</li>
        <li>In ion, open <strong>Asset Depot</strong>, search for <strong>Google Photorealistic 3D Tiles</strong> and press <strong>Add to my assets</strong>.</li>
        <li>Open <strong>Access Tokens</strong> → <strong>Create token</strong>. Name it “<?= e((string) Settings::get('site_name', 'Plat')) ?> 3D view”, keep the scope <span class="mono">assets:read</span>, and under <strong>Allowed URLs</strong> add <span class="mono"><?= e(rtrim(abs_url('/'), '/')) ?></span> so the token works on this site only.</li>
        <li>Copy the token (a long text starting with <span class="mono">eyJ</span>), paste it below and save. The site checks it with Cesium straight away.</li>
        <li>Open any home and press <strong>Open 3D view</strong> under “Look around”.</li>
      </ol>
      <div class="form-grid">
        <?= $secretField('cesium_key', 'Cesium ion access token') ?>
        <label class="field c4"><span>3D views per month</span><input class="input" name="cesium_limit" value="<?= $s('cesium_limit') ?>" inputmode="numeric">
          <small>The free plan allows 1,000 openings of Google’s 3D tiles a month; 900 leaves a margin.</small></label>
      </div>
      <p class="small muted mt-m">Each press of “Open 3D view” counts as one. The free Community plan is meant for personal and early-stage use; Cesium asks companies with more than $50K a year in revenue to use a paid plan. Imagery belongs to Google and can be months or years old.</p>
    </div>
    <div class="form-foot"><button class="btn btn-primary" type="submit">Save 3D view</button></div>
  </form>
</section>

<section class="section" id="usage">
  <div class="section-head"><h2>Usage this month</h2><span class="aside">resets in <?= plural(Quota::resetsIn(), 'day') ?></span></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Source</th><th class="r">Used</th><th class="r">Allowance</th><th class="r">Left for you</th><th class="r">Left for alerts</th></tr></thead>
      <tbody>
      <?php foreach (Quota::PROVIDERS as $pid => $label): ?>
        <tr><td><?= e($label) ?><?= Settings::has($pid . '_key') ? '' : ' <span class="muted small">— not connected</span>' ?></td>
          <td class="r"><?= Quota::used($pid) ?></td><td class="r"><?= Quota::limit($pid) ?></td>
          <td class="r"><?= Quota::left($pid) ?></td><td class="r"><?= Quota::left($pid, 'cron') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($log): ?>
  <h3 class="eyebrow mt-l">Last requests</h3>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>When</th><th>Source</th><th>What</th><th>By</th><th class="r">Result</th><th class="r">Time</th></tr></thead>
      <tbody>
      <?php foreach ($log as $l): ?>
        <tr><td class="nowrap"><?= e(ago($l['created_at'])) ?></td><td><?= e(Quota::PROVIDERS[$l['provider']] ?? $l['provider']) ?></td>
          <td class="mono"><?= e($l['endpoint']) ?><?php if ($l['note']): ?><span class="sub"><?= e($l['note']) ?></span><?php endif; ?></td>
          <td><?= $l['context'] === 'cron' ? 'alert' : 'you' ?></td>
          <td class="r"><?= in_array((int) $l['status'], [200, 404], true) ? '<span class="tag tag-live">' . (int) $l['status'] . '</span>' : '<span class="tag tag-bad">' . ((int) $l['status'] ?: 'no reply') . '</span>' ?></td>
          <td class="r"><?= number_format((int) $l['ms']) ?> ms</td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <p class="section-note">Every answered request is counted, including ones that find nothing, so the meter can only run ahead of the provider’s own count — never behind it.</p>
</section>

<section class="section" id="notify">
  <div class="section-head"><h2>Where alerts are delivered</h2></div>
  <form method="post" action="/settings" class="mt-m">
    <?= csrf_field() ?><input type="hidden" name="do" value="notify">
    <div class="card">
      <h3>Email <span class="tag<?= $ready['email'] ? ' tag-live' : '' ?>"><?= $ready['email'] ? 'Ready' : 'Not set' ?></span></h3>
      <p class="small soft mb-m">Sent through a mailbox on your own domain, so it lands in the inbox instead of spam. Create one in hPanel → Emails if you don’t have it yet.</p>
      <div class="form-grid">
        <label class="field c6"><span>Send alerts to</span><input class="input" type="email" name="notify_email" value="<?= $s('notify_email') ?>" placeholder="you@gmail.com"></label>
        <label class="field c6"><span>Mailbox that sends them</span><input class="input" name="smtp_user" value="<?= $s('smtp_user') ?>" placeholder="alerts@hoshmand.net" autocomplete="off"></label>
        <?= $secretField('smtp_pass', 'That mailbox’s password') ?>
        <label class="field c6"><span>Mail server</span><input class="input" name="smtp_host" value="<?= $s('smtp_host') ?>"></label>
        <label class="field c3"><span>Port</span><input class="input" name="smtp_port" value="<?= $s('smtp_port') ?>" inputmode="numeric"></label>
        <label class="field c3"><span>Security</span>
          <select class="select" name="smtp_secure">
            <option value="ssl"<?= selected(Settings::get('smtp_secure'), 'ssl') ?>>SSL (465)</option>
            <option value="tls"<?= selected(Settings::get('smtp_secure'), 'tls') ?>>STARTTLS (587)</option>
            <option value="none"<?= selected(Settings::get('smtp_secure'), 'none') ?>>None</option>
          </select></label>
      </div>
    </div>

    <div class="card">
      <h3>Telegram <span class="tag<?= $ready['telegram'] ? ' tag-live' : '' ?>"><?= $ready['telegram'] ? 'Ready' : 'Not set' ?></span></h3>
      <p class="small soft">Free, instant and the most dependable of the three.</p>
      <ol class="steps mb-m">
        <li>In Telegram, message <b>@BotFather</b>, send <span class="mono">/newbot</span> and follow it. Copy the token it gives you.</li>
        <li>Open your new bot and press Start.</li>
        <li>Message <b>@userinfobot</b> to see your numeric ID. That’s the chat ID.</li>
      </ol>
      <div class="form-grid">
        <?= $secretField('telegram_token', 'Bot token') ?>
        <label class="field c6"><span>Chat ID</span><input class="input mono" name="telegram_chat" value="<?= $s('telegram_chat') ?>" placeholder="123456789"></label>
      </div>
    </div>

    <div class="card">
      <h3>WhatsApp <span class="tag<?= $ready['whatsapp'] ? ' tag-live' : '' ?>"><?= $ready['whatsapp'] ? 'Yours is ready' : 'Per person' ?></span></h3>
      <p class="small soft">CallMeBot can only message the number that asked it for a key, so each person turns WhatsApp on for themselves under <a href="/account#whatsapp">My account → WhatsApp</a>. It takes about three minutes.</p>
    </div>
    <div class="form-foot"><button class="btn btn-primary" type="submit">Save delivery settings</button></div>
  </form>

  <div class="actions mt-m">
    <span class="small muted">Send a test:</span>
    <?php foreach (Notifier::CHANNELS as $ch => $label): ?>
    <form method="post" action="/settings" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="test"><input type="hidden" name="channel" value="<?= $ch ?>">
      <button class="btn btn-sm" type="submit"<?= $ready[$ch] ? '' : ' disabled' ?>><?= icon('send', 14) ?><?= $label ?></button></form>
    <?php endforeach; ?>
  </div>
  <?php if ($sent): ?>
  <ul class="plain-list mt-m">
    <?php foreach ($sent as $n): ?>
    <li><span><?= e(Notifier::CHANNELS[$n['channel']] ?? $n['channel']) ?> — <?= e($n['subject']) ?><span class="sub"><?= e($n['detail']) ?></span></span>
      <span class="end"><?= $n['ok'] ? '<span class="tag tag-live">Sent</span>' : '<span class="tag tag-bad">Failed</span>' ?><br><?= e(ago($n['created_at'])) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<section class="section" id="schedule">
  <div class="section-head"><h2>Schedule</h2><span class="aside"><?= $lastRun ? 'last ran ' . e(ago($lastRun)) : 'has not run yet' ?></span></div>
  <p class="section-note">Alerts are checked, and market data refreshed, by one command that should run every hour. In hPanel go to <b>Advanced → Cron Jobs</b>, choose “Custom”, set it to every hour, and paste:</p>
  <code class="block"><?= e($cronCmd) ?></code>
  <p class="section-note">If your plan only offers URL-based cron, call this address hourly instead. Treat it like a password.</p>
  <code class="block"><?= e($cronUrl) ?></code>
  <form method="post" action="/settings" class="actions mt-m"><?= csrf_field() ?><input type="hidden" name="do" value="run">
    <button class="btn btn-sm" type="submit"><?= icon('refresh-cw', 14) ?>Run it once now</button>
    <span class="small muted">Run by hand it may use your own requests rather than the alerts’ share.</span></form>
</section>

<section class="section" id="site">
  <div class="section-head"><h2>Site, sign-in and deal defaults</h2></div>
  <form method="post" action="/settings" class="mt-m" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="do" value="site">
    <div class="form-grid">
      <label class="field c4"><span>Site name</span><input class="input" name="site_name" value="<?= $s('site_name') ?>" maxlength="30"></label>
      <label class="field c4"><span>Home state</span><input class="input" name="default_state" value="<?= $s('default_state') ?>" maxlength="20">
        <small>“Entire state” searches and county names use this.</small></label>
    </div>

    <div class="card mt-l">
      <h3>Sign in with Google <span class="tag<?= Settings::has('google_client_id') && Settings::has('google_client_secret') ? ' tag-live' : '' ?>"><?= Settings::has('google_client_id') && Settings::has('google_client_secret') ? 'On' : 'Off' ?></span></h3>
      <ol class="steps mb-m">
        <li>Open <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">Google Cloud → APIs &amp; Services → Credentials</a> (any Google account, free).</li>
        <li>Configure the consent screen (External, app name, your email), then Create credentials → OAuth client ID → Web application.</li>
        <li>Under Authorized redirect URIs add <span class="mono"><?= e(abs_url('/auth/google/callback')) ?></span></li>
        <li>Paste the client ID and secret here. Only people on the Team page can sign in.</li>
      </ol>
      <div class="form-grid">
        <label class="field c12"><span>Client ID</span><input class="input mono" name="google_client_id" value="<?= $s('google_client_id') ?>" spellcheck="false"></label>
        <?= $secretField('google_client_secret', 'Client secret') ?>
      </div>
    </div>

    <div class="card">
      <h3>Deal calculator defaults</h3>
      <p class="small soft mb-m">Used until someone types the real figure for a home. Renovation is estimated from the home’s condition × its square footage.</p>
      <div class="form-grid">
        <?php foreach (App\Services\Deal::SETTINGS as $k => [$label, $def, $unit]): ?>
        <label class="field c4"><span><?= e($label) ?></span><input class="input" name="<?= $k ?>" value="<?= e((string) (Settings::get($k) ?? '')) ?>" placeholder="<?= e($def . ' ' . $unit) ?>" inputmode="decimal"></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="form-foot"><button class="btn btn-primary" type="submit">Save</button><span class="small muted">Your own name, password and Telegram are on <a href="/account">My account</a>.</span></div>
  </form>
</section>

<section class="section" id="data">
  <div class="section-head"><h2>What’s stored</h2></div>
  <dl class="kv">
    <div><dt>Homes on file</dt><dd><?= number_format($counts['homes']) ?></dd></div>
    <div><dt>With a recorded sale</dt><dd><?= number_format($counts['sales']) ?></dd></div>
    <div><dt>Price events</dt><dd><?= number_format($counts['events']) ?></dd></div>
    <div><dt>Cached free lookups</dt><dd><?= number_format($counts['cache']) ?></dd></div>
  </dl>
  <form method="post" action="/settings" class="mt-m" data-confirm="Clear cached lookups and area snapshots? Saved homes stay, but each area’s next search will use a request.">
    <?= csrf_field() ?><input type="hidden" name="do" value="cache">
    <button class="linkbtn small" type="submit">Clear caches and force fresh pulls</button>
  </form>
  <h3 class="eyebrow mt-l">Recent log</h3>
  <?php $tail = App\Diagnostics::logTail(20); ?>
  <?php if ($tail): ?>
  <pre class="logtail"><?php foreach (array_reverse($tail) as $line): ?><span class="<?= preg_match('/\[(fatal|uncaught)\]|PHP (Fatal|Warning)/', $line) ? 'is-bad' : (str_contains($line, '[slow]') ? 'is-warn' : '') ?>"><?= e(mb_substr($line, 0, 500)) ?></span>
<?php endforeach; ?></pre>
  <p class="section-note">Newest first. Errors are marked red, slow pages amber; each data pull writes one line with how long each step took.</p>
  <?php else: ?>
  <p class="blank">Nothing logged — no errors so far.</p>
  <?php endif; ?>
  <h3 class="eyebrow mt-l">Backups</h3>
  <?php $backups = App\Backup::list(); ?>
  <?php if ($backups): ?>
  <ul class="plain-list">
    <?php foreach (array_slice($backups, 0, 7) as $b): ?>
    <li><a class="main" href="/backup/<?= e($b['name']) ?>"><?= e($b['name']) ?></a><span class="end"><?= number_format($b['size'] / 1024, 0) ?> KB · <?= e(ago(gmdate('Y-m-d H:i:s', $b['time']))) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
  <p class="blank">The scheduler writes one every day and keeps the last 14.</p>
  <?php endif; ?>
  <p class="section-note">A backup is written daily by the scheduler (the last 14 are kept on the server, closed to the web). Download one now and then and keep it somewhere else too. API keys and passwords inside are encrypted with a key that lives only in the server’s config file.</p>
</section>
</div>
<?php
View::footer();

<?php
declare(strict_types=1);

use App\Auth;
use App\Settings;
use App\View;

/*
 * The front door: what the workspace does, told by a house that builds itself as you scroll.
 * Public, but noindex like every page here. Signed-in people get a link back to the dashboard.
 */

Auth::startSession();
try {
    $site = (string) Settings::get('site_name', 'Plat');
} catch (Throwable) {
    $site = 'Plat';
}
$in = Auth::check();
$cta = $in ? ['/', 'Open the dashboard'] : ['/login', 'Sign in'];

$chapters = [
    ['01', 'Every listing, one search', 'Search all of Virginia, one county, a city, or a handful of ZIP codes. Narrow it by price, size, year built, lot, days on market, foreclosures and price cuts.'],
    ['02', 'What the listing doesn’t say out loud', 'Lines like “original kitchen”, “needs TLC” or “new roof 2023” are picked up and turned into a condition for the kitchen, baths, roof and HVAC. If a listing says nothing, it stays not disclosed — never assumed fine.'],
    ['03', 'The numbers before the phone call', 'After-repair value from recent sales nearby, renovation by condition and size, holding, loan and selling costs. Net profit, ROI and the highest offer that still works — in a good, a typical and a bad case.'],
    ['04', 'One list for the whole team', 'Mark a home under review, inspection needed or offer sent. Notes carry a name and a date, so two people never end up chasing the same house.'],
    ['05', 'Hear about it first', 'Save a search as an alert. When a new home fits, it arrives by email, Telegram or WhatsApp with the price, the estimate and a link straight to it.'],
];

View::header('Welcome', '', ['bare' => true, 'main' => 'welcome', 'body' => 'welcome-body']);
?>
<header class="wl-top">
  <a class="brand" href="/welcome"><?= e($site) ?></a>
  <a class="btn btn-primary btn-sm" href="<?= $cta[0] ?>"><?= e($cta[1]) ?></a>
</header>

<section class="wl-hero">
  <p class="eyebrow">Virginia · buy, fix, sell</p>
  <h1 class="display">Find the houses worth fixing.</h1>
  <p class="wl-lede"><?= e($site) ?> pulls listings from across the state, reads what each one says about its condition, and works out what it could sell for once it’s done. You decide which ones to chase.</p>
  <p class="wl-cue" aria-hidden="true"><span>Scroll</span><?= icon('chevron-down', 18) ?></p>
</section>

<section class="wl-scroll" aria-label="How it works">
  <div class="wl-stage">
    <div class="wl-art">
      <div class="wl-sky" aria-hidden="true"></div>
      <?php readfile(APP_ROOT . '/pages/partials/welcome_house.svg'); ?>
      <div class="wl-cards" aria-hidden="true">
        <div class="wl-card c1"><span>After-repair value</span><b>$412,000</b></div>
        <div class="wl-card c2"><span>Renovation</span><b>$46,500</b></div>
        <div class="wl-card c3 good"><span>Net profit</span><b>$58,900</b></div>
        <div class="wl-card c4 wl-score"><span>Score</span><b>82</b></div>
        <p class="wl-cards-note">Example figures</p>
      </div>
    </div>
    <ol class="wl-chapters">
      <?php foreach ($chapters as $i => [$n, $title, $text]): ?>
      <li class="wl-ch" data-ch="<?= $i ?>">
        <span class="wl-n"><?= $n ?></span>
        <h2><?= e($title) ?></h2>
        <p><?= e($text) ?></p>
      </li>
      <?php endforeach; ?>
    </ol>
    <div class="wl-dots" aria-hidden="true"><?php foreach ($chapters as $i => $_): ?><i data-dot="<?= $i ?>"></i><?php endforeach; ?></div>
  </div>
</section>

<section class="wl-end">
  <h2 class="display">Ready when you are.</h2>
  <p>A private workspace for the team. Accounts are by invitation.</p>
  <p><a class="btn btn-primary" href="<?= $cta[0] ?>"><?= e($cta[1]) ?></a></p>
  <p class="small muted">Estimates are estimates: ARV, renovation and profit come from public records and nearby sales, not an appraisal.</p>
</section>
<?php
View::footer();

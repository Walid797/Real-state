<?php
declare(strict_types=1);

use App\Auth;
use App\Settings;
use App\View;

/*
 * The front door: a drone flight around a real house that plays as you scroll,
 * with what the workspace does told over it. Public, but noindex like every page here.
 *
 * Footage: "Addison, VT Home (aerial drone footage)" by Herrick Spencer, CC BY 3.0,
 * from Wikimedia Commons — 120 frames from 0:44–1:18, in public/assets/flyover/{lg,sm}.
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
    ['01', 'Every listing, one search', 'All of Virginia, one county, a city, or a handful of ZIP codes. Narrow it by price, size, year built, lot, days on market, foreclosures and price cuts.'],
    ['02', 'What the listing doesn’t say out loud', 'Lines like “original kitchen”, “needs TLC” or “new roof 2023” become a condition for the kitchen, baths, roof and HVAC. If a listing says nothing, it stays not disclosed — never assumed fine.'],
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

<section class="wl-scroll" aria-label="What <?= e($site) ?> does">
  <div class="wl-media" data-frames="/assets/flyover" data-count="120">
    <img src="/assets/flyover/lg/001.webp" alt="Aerial view of a white farmhouse with a red barn among green fields" width="1280" height="720" fetchpriority="high">
    <canvas aria-hidden="true"></canvas>
    <div class="wl-shade"></div>
    <p class="wl-credit">Footage: <a href="https://commons.wikimedia.org/wiki/File:Addison,_VT_Home_(aerial_drone_footage).webm" rel="noopener noreferrer" target="_blank">Herrick Spencer</a>, <a href="https://creativecommons.org/licenses/by/3.0/" rel="noopener noreferrer" target="_blank">CC BY 3.0</a></p>
    <div class="wl-progress" aria-hidden="true"><i></i></div>
  </div>

  <div class="wl-text">
    <ol class="wl-chapters">
      <li class="wl-ch" data-ch="0">
        <span class="wl-n">VIRGINIA · BUY, FIX, SELL</span>
        <h1>Find the houses worth fixing.</h1>
        <p><?= e($site) ?> pulls listings from across the state, reads what each one says about its condition, and works out what it could sell for once it’s done. You decide which ones to chase.</p>
        <p class="wl-cue" aria-hidden="true"><span>Scroll</span><?= icon('chevron-down', 18) ?></p>
      </li>
      <?php foreach ($chapters as $i => [$n, $title, $text]): ?>
      <li class="wl-ch" data-ch="<?= $i + 1 ?>">
        <span class="wl-n"><?= $n ?></span>
        <h2><?= e($title) ?></h2>
        <p><?= e($text) ?></p>
      </li>
      <?php endforeach; ?>
    </ol>
    <div class="wl-cards" aria-hidden="true">
      <div class="wl-card c1"><span>After-repair value</span><b>$412,000</b></div>
      <div class="wl-card c2"><span>Renovation</span><b>$46,500</b></div>
      <div class="wl-card c3 good"><span>Net profit</span><b>$58,900</b></div>
      <div class="wl-card c4 wl-score"><span>Opportunity score</span><b>82</b></div>
      <p class="wl-cards-note">Example figures</p>
    </div>
    <div class="wl-dots" aria-hidden="true"><?php for ($i = 0; $i <= count($chapters); $i++): ?><i data-dot="<?= $i ?>"></i><?php endfor; ?></div>
  </div>
</section>

<section class="wl-end">
  <h2 class="display">Ready when you are.</h2>
  <p>A private workspace for the team. Accounts are by invitation.</p>
  <p><a class="btn btn-primary" href="<?= $cta[0] ?>"><?= e($cta[1]) ?></a></p>
  <p class="small">ARV, renovation and profit are estimates from public records and nearby sales, not an appraisal.</p>
</section>
<?php
View::footer();

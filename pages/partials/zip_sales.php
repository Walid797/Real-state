<?php
declare(strict_types=1);

use App\Auth;
use App\PropertyRepo;
use App\Providers\RentCast;
use App\Quota;

$zipRecords = $official['rows'];
$zipPageSize = 25;
$zipPages = max(1, (int) ceil(count($zipRecords) / $zipPageSize));
$zipPage = min($zipPages, max(1, (int) input('zip_page', '1')));
$zipUrl = static fn (int $p): string => url('/search', $query + ['zip_page' => $p > 1 ? $p : null]) . '#zip-records';
$rentCastReady = RentCast::enabled();
$rentCastLeft = $rentCastReady ? Quota::left(RentCast::ID) : 0;
?>
<section class="section" id="zip-records">
  <div class="section-head">
    <h2><?= te('Official ZIP records') ?> <bdi><?= e($loc['zip']) ?></bdi></h2>
    <?php if ($official['source'] !== null): ?><span class="aside"><?= e($official['source']) ?></span><?php endif; ?>
  </div>
  <?php if (Auth::can('edit')): ?>
  <form class="actions mt-s mb-m" method="post" action="/search/act"
        data-confirm="<?= te('Search this ZIP now with RentCast? This uses one RentCast request.') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="zip_rentcast">
    <input type="hidden" name="params" value="<?= e(json_encode($query)) ?>">
    <button class="btn btn-sm" type="submit"<?= $rentCastLeft > 0 ? '' : ' disabled' ?>>
      <?= icon('search', 15) ?><?= te('Search now with RentCast') ?><span class="cost"><?= te('1 request') ?></span>
    </button>
    <?php if ($rentCastReady && $rentCastLeft === 0): ?>
    <span class="small soft"><?= te('No RentCast requests remain this month.') ?></span>
    <?php elseif (!$rentCastReady): ?>
    <span class="small soft"><?= te('Connect RentCast to use instant search.') ?></span>
    <?php endif; ?>
  </form>
  <?php endif; ?>
  <?php if ($official['state'] === 'unavailable'): ?>
    <p class="blank"><?= te('No official sales source is connected for this ZIP.') ?></p>
  <?php elseif ($official['state'] === 'pending'): ?>
    <p role="status"><?= te('The next scheduled run will start fetching official sales for this ZIP. Check again shortly.') ?></p>
    <p><a class="btn btn-sm" href="<?= e($zipUrl(1)) ?>"><?= te('Check now') ?></a></p>
  <?php elseif ($official['state'] === 'error'): ?>
    <p class="blank"><?= te('The official source did not respond. Search this ZIP again in 30 minutes to retry.') ?></p>
  <?php else: ?>
    <p class="small muted"><?= te('Retrieved %s. These published residential sales cover up to the last 12 months and are kept for 48 hours. Coverage varies by source.', fmt_date($official['fetched_at'], 'M j, Y g:i a')) ?></p>
    <?php if (!$zipRecords): ?>
      <p class="blank"><?= te('No verified residential sales were returned for this exact ZIP.') ?></p>
    <?php else: ?>
      <p class="small"><?= te('%d recorded properties · the search filters apply to saved properties below.', count($zipRecords)) ?></p>
      <div class="rows">
        <?php foreach (array_slice($zipRecords, ($zipPage - 1) * $zipPageSize, $zipPageSize) as $record): ?>
        <article class="hrow hrow-x">
          <div class="price"><bdi><?= e(money($record['last_sale_price'])) ?></bdi></div>
          <div class="body">
            <form class="inline" method="post" action="/search/act">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="open_zip_property">
              <input type="hidden" name="params" value="<?= e(json_encode($query)) ?>">
              <input type="hidden" name="source_id" value="<?= e($record['source_id']) ?>">
              <button type="submit" class="addr linkbtn"><bdi><?= e($record['address']) ?></bdi></button>
            </form>
            <span class="where"><bdi><?= e(implode(' ', array_filter([$record['city'] ?? '', $record['state'] ?? '', $record['zip']]))) ?></bdi></span>
            <div class="facts"><?= e(PropertyRepo::facts($record + ['beds' => null, 'baths' => null])) ?></div>
          </div>
          <div class="side"><span class="side-meta"><?= te('Sold') ?> <?= e(fmt_date($record['last_sale_date'])) ?></span></div>
        </article>
        <?php endforeach; ?>
      </div>
      <?php if ($zipPages > 1): ?>
      <nav class="pager" aria-label="<?= te('Official ZIP records') ?>">
        <?php if ($zipPage > 1): ?><a class="btn btn-ghost" href="<?= e($zipUrl($zipPage - 1)) ?>"><?= te('Previous') ?></a><?php endif; ?>
        <span class="pager-at"><?= te('Page %1$d of %2$d', $zipPage, $zipPages) ?></span>
        <?php if ($zipPage < $zipPages): ?><a class="btn btn-ghost" href="<?= e($zipUrl($zipPage + 1)) ?>"><?= te('Next') ?></a><?php endif; ?>
      </nav>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php
declare(strict_types=1);

$zipRecords = $official['rows'];
$zipPageSize = 25;
$zipPages = max(1, (int) ceil(count($zipRecords) / $zipPageSize));
$zipPage = min($zipPages, max(1, (int) input('zip_page', '1')));
$zipUrl = static fn (int $p): string => url('/search', $query + ['zip_page' => $p > 1 ? $p : null]) . '#zip-records';
$specs = [
    'property_type' => 'Property type', 'beds' => 'Bedrooms', 'baths' => 'Bathrooms',
    'sqft' => 'Sqft living area', 'lot_sqft' => 'Lot, sqft', 'year_built' => 'Year built',
    'assessed_value' => 'Assessed', 'tax_annual' => 'Real estate tax a year', 'has_basement' => 'Basement',
    'garage_spaces' => 'Garage spaces', 'lat' => 'Latitude', 'lng' => 'Longitude',
];
?>
<section class="section" id="zip-records">
  <div class="section-head">
    <h2><?= te('Official ZIP records') ?> <bdi><?= e($loc['zip']) ?></bdi></h2>
    <?php if ($official['source'] !== null): ?><span class="aside"><?= e($official['source']) ?></span><?php endif; ?>
  </div>
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
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th><?= te('Address') ?></th><th><?= te('Sold on') ?></th><th class="r"><?= te('Price') ?></th><th><?= te('Property details') ?></th></tr></thead>
          <tbody>
          <?php foreach (array_slice($zipRecords, ($zipPage - 1) * $zipPageSize, $zipPageSize) as $record): ?>
          <tr>
            <td><strong><bdi><?= e($record['address']) ?></bdi></strong><span class="sub"><bdi><?= e(implode(' ', array_filter([$record['city'] ?? '', $record['state'] ?? '', $record['zip']]))) ?></bdi></span></td>
            <td><?= e(fmt_date($record['last_sale_date'])) ?></td>
            <td class="r"><bdi><?= e(money($record['last_sale_price'])) ?></bdi></td>
            <td>
              <details>
                <summary><?= te('Property details') ?></summary>
                <dl class="kv">
                <?php foreach ($specs as $key => $label): ?>
                  <?php if (!isset($record[$key]) || $record[$key] === '') { continue; } ?>
                  <div><dt><?= te($label) ?></dt><dd><?php
                    echo match ($key) {
                        'assessed_value', 'tax_annual' => e(money($record[$key])),
                        'has_basement' => te($record[$key] ? 'Yes' : 'No'),
                        'property_type' => te($record[$key]),
                        default => e($record[$key]),
                    };
                  ?></dd></div>
                <?php endforeach; ?>
                </dl>
              </details>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
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

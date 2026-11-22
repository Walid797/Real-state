<?php
$packet = $report['evidence'] ?? null;
$language = App\Locale::language();
$ai = $report['narrative'][$language] ?? null;
$status = $report['status'] ?? 'pending';
$stateLabels = [
    'pending' => 'Queued for automatic analysis', 'collecting' => 'Collecting property evidence',
    'awaiting_key' => 'Evidence ready; OpenRouter key required', 'evidence_ready' => 'Evidence ready; AI interpretation queued',
    'retry' => 'Source limit or temporary failure; automatic retry scheduled', 'failed' => 'AI response could not be verified',
    'ready' => 'Individual property report ready',
];
$scoreLabels = [
    'profitability' => 'Property profitability', 'housing' => 'Housing market strength', 'economic' => 'Economic conditions',
    'social' => 'Demographic demand', 'infrastructure' => 'Infrastructure and access', 'risk_safety' => 'Regional risk score',
];
$sectionLabels = ['social' => 'Social and demographic analysis', 'economic' => 'Economic analysis', 'housing' => 'Housing market analysis',
    'location' => 'Location and road access', 'development' => 'Future development', 'risk' => 'Investment risk assessment'];
?>
<section class="property-intelligence mt-l" id="property-intelligence" data-analysis-status="<?= e($status) ?>">
  <div class="section-head">
    <h2>Virginia Property Intelligence</h2>
    <?php if (App\Auth::can('edit')): ?>
    <form method="post" action="/property/<?= (int) $p['id'] ?>/act" class="no-print">
      <?= csrf_field() ?><input type="hidden" name="do" value="analysis_refresh">
      <button class="btn btn-sm" type="submit"><?= te('Refresh analysis') ?></button>
    </form>
    <?php endif; ?>
  </div>
  <p class="small analysis-status" role="status"><?= te($stateLabels[$status] ?? $stateLabels['pending']) ?></p>
  <?php if (!$packet): ?><p class="small soft mt-s"><?= te('Opening this home starts its individual analysis automatically. Source and free-model limits may delay completion.') ?></p><?php endif; ?>
  <?php if ($status === 'awaiting_key' && App\Auth::can('admin')): ?><p class="note mt-s"><?= te('Set OPENROUTER_API_KEY in the private server .env file to enable AI interpretation. Public evidence continues to be collected automatically.') ?></p><?php endif; ?>
  <?php if (!empty($report['stale'])): ?><p class="note mt-s"><?= te('This snapshot predates a property or calculator change. A fresh report has been requested.') ?></p><?php endif; ?>
  <?php if ($packet): ?>
  <p class="small mt-s"><bdi><?= e($packet['property']['address']) ?>, <?= e($packet['property']['city']) ?> <?= e($packet['property']['state']) ?> <?= e($packet['property']['zip']) ?></bdi></p>
  <p class="small muted"><?= te('Evidence collected') ?> <time><?= e($packet['collected_at']) ?> UTC</time><?= $report['model'] ? ' · ' . e($report['model']) : '' ?></p>
  <div class="analysis-scores">
    <?php foreach ($scoreLabels as $key => $label): ?>
    <div><span><?= te($label) ?></span><strong><?= $packet['scores'][$key] === null ? '—' : (int) $packet['scores'][$key] . '/100' ?></strong><small><?= te('Weight %d', $packet['scores']['weights'][$key]) ?></small></div>
    <?php endforeach; ?>
    <div><span><?= te('Final investment score') ?></span><strong><?= $packet['scores']['final_score'] === null ? '—' : (int) $packet['scores']['final_score'] . '/100' ?></strong><small><?= te('Evidence coverage %d%%', $packet['scores']['coverage']) ?></small></div>
  </div>
  <p class="section-note"><?= te('The final score requires all six components. Higher risk-score values mean lower measured risk. Age, family composition and education are descriptive and excluded from scoring.') ?></p>
  <p class="analysis-recommendation mt-m"><strong><?= te($packet['recommendation'] ?? 'More verified evidence is needed') ?></strong></p>
  <p class="small mt-s"><?= te('Property financial verdict') ?> · <?= te(App\Services\Verdict::VERDICTS[$packet['financial_verdict']] ?? 'Not enough data to decide') ?></p>
  <?php if ($ai): ?><p class="small mt-s"><?= e($ai['summary']) ?></p><?php endif; ?>
  <div class="analysis-sections">
    <?php foreach ($sectionLabels as $section => $label): $facts = array_filter($packet['facts'], static fn ($fact) => $fact['section'] === $section); ?>
    <article>
      <h3><?= te($label) ?></h3>
      <?php if (isset($ai['sections'][$section])): ?><p class="small mt-s"><?= e($ai['sections'][$section]['text']) ?></p><?php endif; ?>
      <?php if (!$facts): ?><p class="small muted"><?= te('Verified figures are unavailable for this item.') ?></p><?php endif; ?>
      <dl class="analysis-facts">
        <?php foreach ($facts as $fact): ?>
        <div id="analysis-<?= e($fact['id']) ?>"><dt><?= te($fact['label']) ?></dt><dd>
          <?php if ($fact['label'] === 'Nearby schools and source ratings'): ?>
          <div class="analysis-schools">
            <?php foreach ($fact['value'] as $school): ?>
            <article class="analysis-school">
              <strong><bdi><?= e($school['name']) ?></bdi></strong>
              <?php if (!empty($school['level'])): ?><span class="small"><?= te($school['level']) ?></span><?php endif; ?>
              <p>
                <?php if ($school['rating'] !== null): ?>
                  <span class="school-rating" aria-label="<?= te('School source rating %s of 10', trim_num($school['rating'])) ?>"><span aria-hidden="true">★</span> <bdi><?= e(trim_num($school['rating'])) ?>/10</bdi></span>
                  <?php if ($school['rating'] >= 8): ?><span class="tag tag-live"><?= te('Highly rated by this source') ?></span><?php endif; ?>
                  <small><?= te($school['rating_source']) ?></small>
                <?php else: ?><span class="small muted"><?= te('School rating unavailable') ?></span><?php endif; ?>
              </p>
              <?php if ($school['distance_miles'] !== null): ?><p class="small"><?= te('%s miles away', trim_num($school['distance_miles'])) ?> · <?= te($school['distance_method'] ?? 'Unknown') ?></p><?php else: ?><p class="small muted"><?= te('Distance unverified') ?></p><?php endif; ?>
              <?php if (isset($school['road_miles'])): ?><p class="small"><?= te('%1$s road miles, approximately %2$s minutes driving', trim_num($school['road_miles']), trim_num($school['driving_minutes'])) ?></p><?php endif; ?>
              <p class="small"><?= te($school['assigned'] === true ? 'Assigned according to the listing source; verify with the district' : 'Attendance eligibility needs district verification') ?></p>
              <?php if (!empty($school['source_url'])): ?><a class="small" href="<?= e($school['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= te('School data source') ?></a><?php endif; ?>
            </article>
            <?php endforeach; ?>
          </div>
          <?php elseif ($fact['section'] === 'location' && is_array($fact['value'])): $place = $fact['value']; ?>
            <bdi><?= e($place['name']) ?></bdi>
            <p><?= isset($place['road_miles']) ? te('%1$s road miles, approximately %2$s minutes driving', trim_num($place['road_miles']), trim_num($place['driving_minutes'])) : te('Road routing unavailable') ?></p>
            <small><?= te('%s miles in a straight line', trim_num($place['straight_miles'])) ?></small>
          <?php elseif ($fact['label'] === 'FEMA flood assessment'): ?>
            <?= te($fact['value']['label'] ?? 'Unknown') ?> <bdi><?= e($fact['value']['zone'] ?? '') ?></bdi>
          <?php elseif ($fact['label'] === 'Nearby VDOT approved-program projects'): ?>
            <?php foreach ($fact['value'] as $project): ?>
            <p><bdi><?= e($project['name']) ?></bdi> · <bdi><?= e($project['status']) ?></bdi></p>
            <small><?= te('VDOT project %s', $project['project_id']) ?> · <?= te('Planned completion %s', $project['planned_end_date'] ?? t('Unknown')) ?></small>
            <?php endforeach; ?>
          <?php elseif (is_array($fact['value'])): ?>
            <?php foreach ($fact['value'] as $project): ?>
            <p><bdi><?= e($project['name'] ?? '') ?></bdi> · <?= te('Approval and completion date unknown') ?></p>
            <?php endforeach; ?>
          <?php else: ?><bdi><?= is_numeric($fact['value']) ? e(trim_num((float) $fact['value'])) : e($fact['value']) ?></bdi><?php endif; ?>
          <small><?= str_starts_with($fact['scope'], 'property:') ? te('This home') : (str_starts_with($fact['scope'], 'tract:') ? te('Census tract %s', substr($fact['scope'], 6)) : te($fact['scope'])) ?> · <?= e($fact['period'] ?? $packet['collected_at']) ?> · <?= te($fact['source']) ?></small>
        </dd></div>
        <?php endforeach; ?>
      </dl>
      <?php if (!empty($ai['sections'][$section]['evidence_ids'])): ?>
      <p class="small analysis-citations"><?= te('Evidence used') ?>
        <?php foreach ($ai['sections'][$section]['evidence_ids'] as $factId): ?><a href="#analysis-<?= e($factId) ?>"><?= e($factId) ?></a><?php endforeach; ?>
      </p>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
  <details class="mt-m"><summary><?= te('Missing data and scoring method') ?></summary>
    <ul class="small mt-s"><?php foreach ($packet['missing_data'] as $missing): ?><li><?= te($missing) ?></li><?php endforeach; ?></ul>
    <p class="small mt-m"><?= te('Profitability averages profit versus the target and ROI versus 25%. Housing uses at least eight matched nearby sales and their closing time and sale-to-asking ratio. Economic and demographic scores use observed regional evidence. Infrastructure measures road access. Risk combines verified FEMA risk and property tax.') ?></p>
    <p class="small mt-s"><?= te('Prices are sampled from similar homes within one mile and 35% of this home’s size. Price changes compare six-month sale-price-per-square-foot samples, with at least eight sales in each period. Construction observations require official approval and completion verification.') ?></p>
  </details>
  <?php endif; ?>
</section>

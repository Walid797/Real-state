<?php
/** "Look around": a 3D flight around this home (Cesium + Google's 3D tiles). Loaded only when asked. */
use App\Auth;
use App\Quota;
use App\Settings;

$aerialReady = Settings::has('cesium_key');
$aerialLeft = $aerialReady ? Quota::left('cesium') : 0;
?>
<section class="section aerial-sec" id="look-around">
  <div class="section-head"><h2><?= te('Look around') ?><?= tip_open() ?><?= te('Imagery can be months or years old. Rural areas may only have flat satellite images, not 3D buildings.') ?><?= tip_close() ?></h2><span class="aside"><?= te('3D aerial view') ?></span></div>
  <?php if (!$hasGeo): ?>
    <p class="blank"><?= te('This address has no map position yet, so there is nothing to fly around.') ?></p>
  <?php elseif (!$aerialReady): ?>
    <div class="aerial-off">
      <p><?= te('See the roof, the yard, the street and the neighbours in 3D — the same data as Google Earth.') ?></p>
      <?php if (Auth::can('admin')): ?>
      <p><a class="btn btn-sm" href="/settings#aerial"><?= icon('settings', 14) ?><?= te('Set it up — free, about 3 minutes') ?></a></p>
      <?php else: ?>
      <p class="small muted"><?= te('Not switched on yet. An admin can turn it on under Settings → 3D view.') ?></p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="aerial" data-aerial="/api/aerial/<?= (int) $id ?>" data-csrf="<?= e(Auth::csrfToken()) ?>">
      <div class="aerial-stage" aria-label="<?= te('3D view of the area around') ?> <?= e($p['address']) ?>"></div>
      <div class="aerial-cover">
        <p class="aerial-title"><?= te('Fly around this home') ?></p>
        <p class="small"><?= te('Roof, yard, street and neighbours in 3D. Drag to turn, scroll to zoom.') ?></p>
        <button class="btn btn-primary" type="button" data-aerial-open<?= $aerialLeft > 0 ? '' : ' disabled' ?>><?= icon('eye', 15) ?><?= te('Open 3D view') ?></button>
        <p class="small aerial-cost"><?= $aerialLeft > 0 ? te('Uses one of the %d 3D views left this month.', $aerialLeft) : te('This month’s free 3D views are used up. They reset in %s.', plural(Quota::resetsIn(), 'day')) ?></p>
      </div>
      <div class="aerial-bar" hidden>
        <button class="btn btn-sm" type="button" data-aerial-orbit><?= icon('refresh-cw', 14) ?><span><?= te('Pause') ?></span></button>
        <button class="btn btn-sm" type="button" data-aerial-reset><?= icon('map-pin', 14) ?><?= te('House') ?></button>
        <button class="btn btn-sm" type="button" data-aerial-full><?= icon('arrow-up-right', 14) ?><span><?= te('Full screen') ?></span></button>
      </div>
      <p class="aerial-msg small" role="status" hidden></p>
    </div>
  <?php endif; ?>
</section>

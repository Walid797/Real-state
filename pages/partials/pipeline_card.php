<?php
/* Team status and owner. Expects $p, $id, $team, $canEdit. */
use App\Services\Pipeline;
?>
<div class="card pipe-card">
  <h3>Team status</h3>
  <?php if (!$p['watch_id']): ?>
    <p class="small muted mb-m">Not in the pipeline. Add it so the team can track it.</p>
    <?php if ($canEdit): ?>
    <form method="post" action="/property/<?= $id ?>/act"><?= csrf_field() ?><input type="hidden" name="do" value="watch">
      <button class="btn btn-sm btn-primary" type="submit"><?= icon('star', 14) ?>Add to pipeline</button></form>
    <?php endif; ?>
  <?php else: ?>
    <?php if ($canEdit): ?>
    <form method="post" action="/property/<?= $id ?>/act" class="pipe-form">
      <?= csrf_field() ?><input type="hidden" name="do" value="stage">
      <label class="field"><span>Status</span>
        <select class="select" name="stage" data-autosubmit>
          <?php foreach (Pipeline::STAGES as $k => $l): ?><option value="<?= $k ?>"<?= selected($p['watch_stage'], $k) ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></label>
    </form>
    <form method="post" action="/property/<?= $id ?>/act" class="pipe-form">
      <?= csrf_field() ?><input type="hidden" name="do" value="owner">
      <label class="field"><span>Handled by</span>
        <select class="select" name="owner_id" data-autosubmit>
          <option value="">No one yet</option>
          <?php foreach ($team as $m): ?><option value="<?= (int) $m['id'] ?>"<?= selected($p['owner_id'] ?? '', $m['id']) ?>><?= e($m['name']) ?></option><?php endforeach; ?>
        </select></label>
    </form>
    <?php else: ?>
    <div class="ctx-row"><span>Status</span><span><?= e(Pipeline::STAGES[$p['watch_stage']] ?? $p['watch_stage']) ?></span></div>
    <div class="ctx-row"><span>Handled by</span><span><?= e($p['owner_name'] ?: 'No one yet') ?></span></div>
    <?php endif; ?>
    <p class="small muted mt-s">Changed <?= e(ago($p['stage_at'])) ?><?= $p['stage_by'] ? ' by ' . e($p['stage_by']) : '' ?>.</p>
    <?php if ($canEdit): ?>
    <form method="post" action="/property/<?= $id ?>/act" class="mt-s" data-confirm="Take this home out of the team pipeline? Notes and deal numbers stay.">
      <?= csrf_field() ?><input type="hidden" name="do" value="unwatch">
      <button class="linkbtn small muted" type="submit">Remove from pipeline</button></form>
    <?php endif; ?>
  <?php endif; ?>
</div>

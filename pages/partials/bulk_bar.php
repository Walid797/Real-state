<?php
/**
 * Delete bar for a list: "select all", "delete selected (n)" and "delete all".
 * Expects $bulk = ['type' => …, 'form' => form id, 'back' => URL, 'all' => label for "all", 'params' => ?json, 'count' => total].
 * Each row carries <input type="checkbox" class="bulk-pick" name="ids[]" value="…" form="<form id>">.
 */
$bf = (string) $bulk['form'];
?>
<form method="post" action="/bulk" id="<?= e($bf) ?>" class="bulk-bar" data-bulk>
  <?= csrf_field() ?><input type="hidden" name="type" value="<?= e($bulk['type']) ?>"><input type="hidden" name="back" value="<?= e($bulk['back']) ?>">
  <?php if (!empty($bulk['params'])): ?><input type="hidden" name="params" value="<?= e($bulk['params']) ?>"><?php endif; ?>
  <label class="check bulk-all"><input type="checkbox" data-bulk-all="<?= e($bf) ?>"><?= te('Select all') ?></label>
  <button class="btn btn-sm btn-danger" type="submit" name="scope" value="selected" data-bulk-selected disabled
    data-confirm="<?= te('Delete the selected items? This can’t be undone.') ?>"><?= icon('trash', 14) ?><?= te('Delete selected') ?> <span class="count" data-bulk-count>0</span></button>
  <button class="btn btn-sm btn-ghost" type="submit" name="scope" value="all"
    data-confirm="<?= te('%s? This can’t be undone.', t((string) $bulk['all'])) ?>"><?= te((string) $bulk['all']) ?><?= isset($bulk['count']) ? ' (' . (int) $bulk['count'] . ')' : '' ?></button>
</form>

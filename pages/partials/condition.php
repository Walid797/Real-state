<?php
/* Condition & renovation. Expects $cond, $id, $canEdit, $p. */
use App\Services\Condition;

$stateClass = static fn (string $s) => match ($s) { 'new', 'updated' => 'chip-good', 'needs_work', 'original' => 'chip-bad', default => '' };
?>
<section class="section" id="condition">
  <div class="section-head"><h2><?= te('Condition & renovation') ?></h2>
    <span class="aside"><?= te($cond['source'] === 'manual' ? 'set by the team' : 'read from the listing text') ?><?= $cond['renovated_year'] ? ' · ' . te('renovated %d', (int) $cond['renovated_year']) : '' ?></span></div>
  <div class="cond-head">
    <span class="cond-overall cond-<?= e($cond['overall']) ?>"><?= te(Condition::OVERALL[$cond['overall']]) ?></span>
    <?php if ($cond['overall'] === 'not_disclosed'): ?><?= tip_open() ?><?= te('The listing doesn’t say. Treat it as unknown until someone sees the house.') ?><?= tip_close() ?><?php endif; ?>
  </div>
  <div class="cond-grid">
    <?php foreach (Condition::PARTS as $k => $label): ?>
    <div class="cond-cell"><span><?= te($label) ?></span><span class="chip <?= $stateClass($cond[$k]) ?>"><?= te(Condition::STATES[$cond[$k]]) ?></span></div>
    <?php endforeach; ?>
  </div>
  <?php if ($cond['evidence']): ?>
  <p class="section-note"><?= te('Found in the listing:') ?> <?php foreach ($cond['evidence'] as $ev): ?><q><?= e($ev) ?></q> <?php endforeach; ?></p>
  <?php endif; ?>
  <?php if ($canEdit): ?>
  <details class="deal-edit">
    <summary><?= icon('wrench', 15) ?><?= te('Correct the condition') ?></summary>
    <form method="post" action="/property/<?= $id ?>/act#condition">
      <?= csrf_field() ?><input type="hidden" name="do" value="condition">
      <div class="deal-grid">
        <label class="field"><span><?= te('Overall') ?></span><select class="select" name="overall">
          <?php foreach (Condition::OVERALL as $k => $l): ?><option value="<?= $k ?>"<?= selected($cond['overall'], $k) ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></label>
        <?php foreach (Condition::PARTS as $k => $label): ?>
        <label class="field"><span><?= te($label) ?></span><select class="select" name="<?= $k ?>">
          <?php foreach (Condition::STATES as $sk => $sl): ?><option value="<?= $sk ?>"<?= selected($cond[$k], $sk) ?>><?= e($sl) ?></option><?php endforeach; ?>
        </select></label>
        <?php endforeach; ?>
        <label class="field"><span><?= te('Renovated in') ?></span><input class="input" name="renovated_year" inputmode="numeric" maxlength="4" value="<?= e((string) ($cond['renovated_year'] ?? '')) ?>"></label>
      </div>
      <div class="form-foot"><button class="btn btn-primary" type="submit"><?= te('Save condition') ?></button>
        <?php if ($cond['source'] === 'manual'): ?>
        <button class="btn btn-ghost" type="submit" name="reset_auto" value="1"><?= te('Go back to reading the listing') ?></button>
        <?php endif; ?>
        <?= tip_open() ?><?= te('Saved by hand, it is no longer overwritten by the listing text.') ?><?= tip_close() ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>

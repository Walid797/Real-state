<?php
/* Team notes and the home's history. Expects $notes, $history, $id, $canEdit. */
use App\Auth;
use App\Services\Activity;

// What people did. Market events (listings, cuts, sales) are in Price history above.
$timeline = [];
foreach ($history as $h) {
    $timeline[] = ['t' => $h['created_at'], 'who' => Activity::who($h), 'what' => (Activity::LABELS[$h['action']] ?? ucfirst($h['action'])) . ': ' . $h['detail']];
}
usort($timeline, static fn ($a, $b) => strcmp($b['t'], $a['t']));
?>
<section class="section" id="notes">
  <div class="section-head"><h2><?= te('Team notes') ?></h2><span class="aside"><?= te('everyone on the team sees these') ?></span></div>
  <?php if ($canEdit): ?>
  <form method="post" action="/property/<?= $id ?>/act#notes" class="mt-m">
    <?= csrf_field() ?><input type="hidden" name="do" value="note">
    <label class="sr" for="notes-box"><?= te('New note') ?></label>
    <textarea class="textarea" id="notes-box" name="note" rows="3" placeholder="<?= te('Drove by — roof looks new, side yard overgrown. Agent says seller wants to close in 30 days.') ?>" required></textarea>
    <div class="actions mt-m"><button class="btn btn-sm btn-primary" type="submit"><?= te('Add note') ?></button></div>
  </form>
  <?php endif; ?>
  <?php if ($notes): ?>
  <ol class="notes">
    <?php foreach ($notes as $n): ?>
    <li>
      <div class="note-meta"><span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $n['author'], 0, 1))) ?></span>
        <strong><?= e($n['author'] ?? 'Former member') ?></strong><span class="muted"><?= e(fmt_date($n['created_at'], 'M j, Y g:i a')) ?></span>
        <?php if ((int) $n['user_id'] === Auth::userId() || Auth::can('admin')): ?>
        <form method="post" action="/property/<?= $id ?>/act#notes" class="inline" data-confirm="<?= te('Delete this note?') ?>">
          <?= csrf_field() ?><input type="hidden" name="do" value="note_delete"><input type="hidden" name="note_id" value="<?= (int) $n['id'] ?>">
          <button class="linkbtn muted small" type="submit"><?= te('Delete') ?></button></form>
        <?php endif; ?>
      </div>
      <p><?= nl2br(e($n['body'])) ?></p>
    </li>
    <?php endforeach; ?>
  </ol>
  <?php else: ?>
  <p class="blank"><?= te('No notes yet.') ?></p>
  <?php endif; ?>
</section>

<section class="section" id="history">
  <div class="section-head"><h2><?= te('History') ?></h2><span class="aside"><?= te('who changed what, newest first') ?></span></div>
  <?php if ($timeline): ?>
  <ol class="timeline">
    <?php foreach (array_slice($timeline, 0, 40) as $t): ?>
    <li><span class="when"><?= e(fmt_date(substr($t['t'], 0, empty($t['day']) ? 19 : 10), 'M j, Y')) ?></span><span><?= e($t['what']) ?></span><span class="amt muted"><?= e($t['who']) ?></span></li>
    <?php endforeach; ?>
  </ol>
  <?php else: ?>
  <p class="blank"><?= te('Nothing has happened to this home yet.') ?></p>
  <?php endif; ?>
</section>

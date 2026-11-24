<?php
declare(strict_types=1);

use App\Auth;
use App\Db;
use App\Notify\Notifier;
use App\Services\Activity;
use App\Settings;
use App\View;

/* Team members: invite, change role, disable, sign out everywhere. Admin only (see Kernel). */

$me = Auth::userId();
$newLink = null;

if (is_post()) {
    $do = input('do');
    $id = (int) input('id');
    $target = $id ? Db::one('SELECT * FROM users WHERE id = ?', [$id]) : null;
    $admins = (int) Db::val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");

    if ($do === 'invite') {
        $email = mb_strtolower(input('email', '', 190));
        $name = input('name', '', 80);
        $role = isset(Auth::ROLES[input('role')]) ? input('role') : 'partner';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('That email address doesn’t look right.', 'err');
            redirect('/users');
        }
        $existing = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
        if ($existing && $existing['password_hash'] !== '') {
            flash($email . ' already has an account.', 'warn');
            redirect('/users');
        }
        $uid = $existing ? (int) $existing['id'] : Db::insert('users', [
            'email' => $email, 'name' => $name, 'role' => $role, 'password_hash' => '', 'is_active' => 1,
            'invited_by' => $me, 'created_at' => now(),
        ]);
        $token = Auth::issueToken($uid, 'invite', 7 * 86400);
        $link = abs_url('/invite', ['token' => $token]);
        Activity::log('user', null, 'Invited ' . $email . ' as ' . Auth::ROLES[$role]);
        $site = (string) Settings::get('site_name', 'Plat');
        $sent = Notifier::send(
            "You’re invited to $site",
            Auth::name() . " added you to $site, the team’s property research workspace.\n\nSet your password here (link works for 7 days):\n$link\n\nIf you didn’t expect this, ignore the message.",
            ['email'], null, ['email' => $email, 'telegram' => '']
        )['email'] ?? ['ok' => false];
        $_SESSION['invite_link'] = ['email' => $email, 'link' => $link, 'mailed' => $sent['ok']];
        redirect('/users');
    }

    if (!$target) {
        redirect('/users');
    }
    $isLastAdmin = $target['role'] === 'admin' && (int) $target['is_active'] === 1 && $admins <= 1;

    switch ($do) {
        case 'role':
            $role = input('role');
            if (!isset(Auth::ROLES[$role]) || $role === $target['role']) {
                break;
            }
            if ($isLastAdmin) {
                flash('Keep at least one admin. Make someone else admin first.', 'err');
                break;
            }
            Db::run('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
            Activity::log('user', null, ($target['name'] ?: $target['email']) . ' is now ' . Auth::ROLES[$role]);
            flash(($target['name'] ?: $target['email']) . ' is now ' . Auth::ROLES[$role] . '.');
            break;

        case 'toggle':
            if ($id === $me || $isLastAdmin) {
                flash('You can’t disable yourself or the last admin.', 'err');
                break;
            }
            Db::run('UPDATE users SET is_active = 1 - is_active, session_epoch = session_epoch + 1 WHERE id = ?', [$id]);
            Activity::log('user', null, ($target['is_active'] ? 'Disabled ' : 'Re-enabled ') . $target['email']);
            flash($target['is_active'] ? 'Account disabled and signed out.' : 'Account enabled again.');
            break;

        case 'signout':
            Auth::signOutEverywhere($id);
            flash(($target['name'] ?: $target['email']) . ' was signed out on every device.');
            break;

        case 'relink':
            $token = Auth::issueToken($id, $target['password_hash'] === '' ? 'invite' : 'reset', $target['password_hash'] === '' ? 7 * 86400 : 3600);
            $path = $target['password_hash'] === '' ? '/invite' : '/reset';
            $_SESSION['invite_link'] = ['email' => $target['email'], 'link' => abs_url($path, ['token' => $token]), 'mailed' => false];
            break;
    }
    redirect('/users');
}

$link = $_SESSION['invite_link'] ?? null;
unset($_SESSION['invite_link']);
$users = Db::all(
    "SELECT u.*, (SELECT COUNT(*) FROM activity_log a WHERE a.user_id = u.id) AS actions FROM users u ORDER BY u.is_active DESC, FIELD(u.role,'admin','partner','viewer'), u.created_at"
);

$presence = App\Services\Presence::statuses();
View::header('Team', 'users');
?>
<header class="page-head">
  <div>
    <h1 class="display"><?= te('Team') ?><?= tip_open() ?><?= te('Everyone here shares the same homes, statuses, deal numbers and notes. Each person keeps their own alerts.') ?><?= tip_close() ?></h1>
  </div>
</header>

<?php if ($link): ?>
<div class="card">
  <h3><?= te('Invitation link for') ?> <?= e($link['email']) ?><?= tip(te('Anyone with the link can claim the account, so send it only to them.'), t('Invitation link for')) ?></h3>
  <p class="small soft mb-m"><?= te($link['mailed'] ? 'It was also emailed to them.' : 'Email isn’t set up, so send this link yourself (WhatsApp, text, email).') ?></p>
  <code class="block"><?= e($link['link']) ?></code>
  <button class="btn btn-sm mt-m" type="button" data-copy="<?= e($link['link']) ?>"><?= icon('copy', 14) ?><?= te('Copy link') ?></button>
</div>
<?php endif; ?>

<section class="section">
  <div class="section-head"><h2><?= te('Members') ?><?= tip_open() ?><?= te('Online means activity within the last 90 seconds. Last activity updates when someone visits or uses a visible page.') ?><?= tip_close() ?></h2><span class="aside"><?= plural(count($users), 'person', 'people') ?></span></div>
  <?php $bulk = ['type' => 'users', 'form' => 'bulk-users', 'back' => '/users', 'all' => 'Delete everyone but you', 'count' => max(0, count($users) - 1)]; include APP_ROOT . '/pages/partials/bulk_bar.php'; ?>
  <div class="table-wrap">
    <table class="table" data-presence-users>
      <thead><tr><th><?= te('Person') ?></th><th><?= te('Role') ?></th><th><?= te('Sign-in') ?></th><th><?= te('Last activity') ?></th><th class="r"><?= te('Actions taken') ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): $pending = $u['password_hash'] === '' && !$u['google_sub']; ?>
        <tr<?= $u['is_active'] ? '' : ' class="muted"' ?>>
          <td><?php if ((int) $u['id'] !== $me): ?><input class="bulk-pick" type="checkbox" name="ids[]" value="<?= (int) $u['id'] ?>" form="bulk-users" aria-label="<?= te('Select') ?> <?= e($u['email']) ?>"> <?php endif; ?><strong><?= e($u['name'] ?: '—') ?></strong><?= (int) $u['id'] === $me ? ' <span class="tag">' . te('You') . '</span>' : '' ?><span class="sub"><?= e($u['email']) ?></span></td>
          <td>
            <form method="post" action="/users"><?= csrf_field() ?><input type="hidden" name="do" value="role"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <label class="sr" for="r<?= (int) $u['id'] ?>"><?= te('Role') ?></label>
              <select class="select stage" id="r<?= (int) $u['id'] ?>" name="role" data-autosubmit<?= $u['is_active'] ? '' : ' disabled' ?>>
                <?php foreach (Auth::ROLES as $k => $label): ?><option value="<?= $k ?>"<?= selected($u['role'], $k) ?>><?= te($label) ?></option><?php endforeach; ?>
              </select></form></td>
          <td><?= $pending ? '<span class="tag tag-warn">' . te('Invite not accepted') . '</span>' : te($u['google_sub'] ? 'Password · Google' : 'Password') ?>
            <?= $u['is_active'] ? '' : '<span class="tag tag-bad">Disabled</span>' ?></td>
          <td class="nowrap"><span data-user-presence="<?= (int)$u['id'] ?>" data-presence-at="<?= e($presence[$u['id']]['last_active_at'] ?? '') ?>" data-presence-online="<?= $presence[$u['id']]['online'] ? '1' : '0' ?>" class="<?= $presence[$u['id']]['online'] ? 'tag tag-live' : 'muted' ?>" title="<?= e($presence[$u['id']]['title']) ?>"><?= e($presence[$u['id']]['label']) ?></span></td>
          <td class="r"><?= (int) $u['actions'] ?></td>
          <td class="r nowrap">
            <?php if ($pending || (int) $u['id'] !== $me): ?>
            <form method="post" action="/users" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="relink"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button class="linkbtn" type="submit"><?= te($pending ? 'New invite link' : 'Password reset link') ?></button></form>
            <?php endif; ?>
            <?php if ((int) $u['id'] !== $me): ?>
            · <form method="post" action="/users" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="signout"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button class="linkbtn" type="submit"><?= te('Sign out everywhere') ?></button></form>
            · <form method="post" action="/users" class="inline" data-confirm="<?= te($u['is_active'] ? 'Disable this account? They are signed out at once.' : 'Enable this account again?') ?>"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button class="linkbtn<?= $u['is_active'] ? ' danger' : '' ?>" type="submit"><?= te($u['is_active'] ? 'Disable' : 'Enable') ?></button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="section narrow">
  <div class="section-head"><h2><?= te('Invite someone') ?></h2></div>
  <form method="post" action="/users" class="mt-m">
    <?= csrf_field() ?><input type="hidden" name="do" value="invite">
    <div class="form-grid">
      <label class="field c4"><span><?= te('Name') ?></span><input class="input" name="name" placeholder="<?= te('Full name') ?>" required></label>
      <label class="field c5"><span><?= te('Email') ?></span><input class="input" type="email" name="email" required></label>
      <label class="field c3"><span><?= te('Role') ?></span>
        <select class="select" name="role">
          <option value="partner"><?= te('Partner') ?></option><option value="viewer"><?= te('Viewer') ?></option><option value="admin"><?= te('Admin') ?></option>
        </select></label>
    </div>
    <div class="form-foot"><button class="btn btn-primary" type="submit"><?= icon('send', 15) ?><?= te('Create invite link') ?></button>
      <?= tip_open() ?><?= te('They set their own password, or sign in with Google using this same email.') ?><?= tip_close() ?></div>
  </form>
  <dl class="kv mt-l">
    <div><dt><?= te('Admin') ?></dt><dd><?= te('Everything, including team, data sources and settings') ?></dd></div>
    <div><dt><?= te('Partner') ?></dt><dd><?= te('Search, statuses, notes, deal numbers, adding homes') ?></dd></div>
    <div><dt><?= te('Viewer') ?></dt><dd><?= te('Reads homes, deals and reports; changes nothing') ?></dd></div>
  </dl>
</section>
<?php
View::footer();

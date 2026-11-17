<?php
declare(strict_types=1);

use App\Auth;
use App\Db;
use App\Notify\Notifier;
use App\Settings;
use App\View;

/* Your own account: name, password, Telegram for your alerts, sign out everywhere. Every role. */

$me = Db::one('SELECT * FROM users WHERE id = ?', [Auth::userId()]);

if (is_post()) {
    $do = input('do');
    if ($do === 'profile') {
        $name = input('name', '', 80);
        $chat = preg_replace('/[^0-9@a-zA-Z_\-]/', '', input('telegram_chat', '', 64));
        Db::run('UPDATE users SET name = ?, telegram_chat = ? WHERE id = ?', [$name ?: $me['name'], $chat ?: null, $me['id']]);
        flash('Saved.');
    } elseif ($do === 'password') {
        $new = (string) ($_POST['new_password'] ?? '');
        $problems = Auth::passwordProblems($new);
        if ($me['password_hash'] !== '' && !password_verify((string) ($_POST['current_password'] ?? ''), $me['password_hash'])) {
            $problems[] = 'The current password is wrong.';
        }
        if ($new !== (string) ($_POST['new_password2'] ?? '')) {
            $problems[] = 'The two new passwords are different.';
        }
        if ($problems) {
            flash(implode(' ', $problems), 'err');
        } else {
            Db::run('UPDATE users SET password_hash = ?, session_epoch = session_epoch + 1 WHERE id = ?', [Auth::hash($new), $me['id']]);
            Auth::signIn(Db::one('SELECT * FROM users WHERE id = ?', [$me['id']]));
            flash('Password changed. Your other devices were signed out.');
        }
    } elseif ($do === 'everywhere') {
        Auth::signOutEverywhere((int) $me['id']);
        flash('Signed out on every device, including this one.');
        redirect('/login');
    } elseif ($do === 'test') {
        $ch = input('channel');
        $r = Notifier::send((string) Settings::get('site_name', 'Plat') . ' · test', 'Your alerts will arrive here.', [$ch], null, Notifier::recipient($me))[$ch] ?? null;
        if ($r) {
            flash(($r['ok'] ? 'Sent — check that it arrived. ' : 'Failed: ') . $r['detail'], $r['ok'] ? 'ok' : 'err');
        }
    }
    redirect('/account');
}

$ready = Notifier::ready(Notifier::recipient($me));
View::header('My account', 'account');
?>
<div class="narrow">
<header class="page-head"><div><h1 class="display">My account</h1><p><?= e($me['email']) ?> · <?= e(Auth::ROLES[$me['role']]) ?></p></div></header>

<section class="section">
  <div class="section-head"><h2>Profile and alerts</h2></div>
  <form method="post" action="/account" class="mt-m">
    <?= csrf_field() ?><input type="hidden" name="do" value="profile">
    <div class="form-grid">
      <label class="field c6"><span>Name the team sees</span><input class="input" name="name" value="<?= e($me['name']) ?>" maxlength="80"></label>
      <label class="field c6"><span>Your Telegram chat ID</span><input class="input mono" name="telegram_chat" value="<?= e($me['telegram_chat']) ?>" placeholder="123456789">
        <small>Your alerts go here. Open the team’s bot in Telegram and press Start, then get the number from @userinfobot. A group ID (starts with −) works too.</small></label>
    </div>
    <div class="form-foot"><button class="btn btn-primary" type="submit">Save</button>
      <span class="small muted">Email alerts go to <?= e($me['email']) ?>.</span></div>
  </form>
  <div class="actions mt-m">
    <span class="small muted">Send yourself a test:</span>
    <?php foreach (['email' => 'Email', 'telegram' => 'Telegram'] as $ch => $label): ?>
    <form method="post" action="/account" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="test"><input type="hidden" name="channel" value="<?= $ch ?>">
      <button class="btn btn-sm" type="submit"<?= $ready[$ch] ? '' : ' disabled' ?>><?= icon('send', 14) ?><?= $label ?></button></form>
    <?php endforeach; ?>
  </div>
</section>

<section class="section">
  <div class="section-head"><h2>Password</h2></div>
  <form method="post" action="/account" class="mt-m" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="do" value="password">
    <div class="form-grid">
      <?php if ($me['password_hash'] !== ''): ?>
      <label class="field c4"><span>Current password</span><input class="input" type="password" name="current_password" autocomplete="current-password" required></label>
      <?php endif; ?>
      <label class="field c4"><span>New password</span><input class="input" type="password" name="new_password" autocomplete="new-password" minlength="12" required></label>
      <label class="field c4"><span>Again</span><input class="input" type="password" name="new_password2" autocomplete="new-password" minlength="12" required></label>
    </div>
    <div class="form-foot"><button class="btn" type="submit">Change password</button></div>
  </form>
</section>

<section class="section">
  <div class="section-head"><h2>Devices</h2></div>
  <form method="post" action="/account" class="mt-m" data-confirm="Sign out on every device, including this one?">
    <?= csrf_field() ?><input type="hidden" name="do" value="everywhere">
    <button class="btn btn-danger" type="submit"><?= icon('log-out', 15) ?>Sign out everywhere</button>
    <span class="small muted">Use this if you signed in on a computer that isn’t yours.</span>
  </form>
</section>
</div>
<?php
View::footer();

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
    } elseif ($do === 'whatsapp') {
        $phone = preg_replace('/[^0-9]/', '', input('whatsapp_phone', '', 24));
        $key = trim((string) ($_POST['callmebot_key'] ?? ''));
        if ($phone === '') {
            Db::run('UPDATE users SET whatsapp_phone = NULL, callmebot_key = NULL WHERE id = ?', [$me['id']]);
            flash('WhatsApp turned off for your alerts.');
        } elseif (strlen($phone) < 8 || strlen($phone) > 15) {
            flash('Write the number with its country code, e.g. +1 804 555 0123.', 'err');
        } elseif ($key === '' && empty($me['callmebot_key'])) {
            flash('Paste the API key CallMeBot sent you.', 'err');
        } else {
            Db::run('UPDATE users SET whatsapp_phone = ?, callmebot_key = COALESCE(?, callmebot_key) WHERE id = ?',
                ['+' . $phone, $key !== '' ? Settings::seal(mb_substr(preg_replace('/\s+/', '', $key), 0, 64)) : null, $me['id']]);
            flash('WhatsApp saved. Send yourself a test below.');
        }
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
        $site = (string) Settings::get('site_name', 'Plat');
        $r = isset(Notifier::CHANNELS[$ch]) ? (Notifier::send(
            "Alerts from $site are set up",
            "Hi " . ($me['name'] ?: 'there') . ",\n\nThis is the test you asked for from $site. Alerts you create — new listings, price cuts and strong opportunities — will arrive here, with the price, the estimated profit and a link to the home.\n\nYour alerts: " . abs_url('/alerts'),
            [$ch], null, Notifier::recipient($me)
        )[$ch] ?? null) : null;
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
<header class="page-head"><div><h1 class="display"><?= te('My account') ?></h1><p><?= e($me['email']) ?> · <?= te(Auth::ROLES[$me['role']]) ?></p></div></header>

<section class="section">
  <div class="section-head"><h2><?= te('Profile and alerts') ?></h2></div>
  <form method="post" action="/account" class="mt-m">
    <?= csrf_field() ?><input type="hidden" name="do" value="profile">
    <div class="form-grid">
      <label class="field c6"><span><?= te('Name the team sees') ?></span><input class="input" name="name" value="<?= e($me['name']) ?>" maxlength="80"></label>
      <label class="field c6"><span><?= te('Your Telegram chat ID') ?></span><input class="input mono" name="telegram_chat" value="<?= e($me['telegram_chat']) ?>" placeholder="123456789">
        <small><?= te('Your alerts go here. Open the team’s bot in Telegram and press Start, then get the number from @userinfobot. A group ID (starts with −) works too.') ?></small></label>
    </div>
    <div class="form-foot"><button class="btn btn-primary" type="submit"><?= te('Save') ?></button>
      <span class="small muted"><?= te('Email alerts go to') ?> <?= e($me['email']) ?>.</span></div>
  </form>
  <div class="actions mt-m">
    <span class="small muted"><?= te('Send yourself a test:') ?></span>
    <?php foreach (Notifier::CHANNELS as $ch => $label): ?>
    <form method="post" action="/account" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="test"><input type="hidden" name="channel" value="<?= $ch ?>">
      <button class="btn btn-sm" type="submit"<?= $ready[$ch] ? '' : ' disabled' ?>><?= icon('send', 14) ?><?= te($label) ?></button></form>
    <?php endforeach; ?>
  </div>
</section>

<section class="section" id="whatsapp">
  <div class="section-head"><h2><?= te('WhatsApp') ?></h2><span class="aside"><?= te($ready['whatsapp'] ? 'on for your alerts' : 'not set') ?></span></div>
  <p class="small soft mt-s"><?= te('Free, through CallMeBot. It can only message the number that asked it for a key, so each person sets up their own.') ?></p>
  <ol class="steps mt-s mb-m">
    <li><?= te('Open') ?> <a href="https://www.callmebot.com/blog/free-api-whatsapp-messages/" target="_blank" rel="noopener noreferrer"><?= te('CallMeBot’s WhatsApp page') ?></a> <?= te('and save the bot’s number shown there in your phone’s contacts.') ?></li>
    <li><?= te('From your WhatsApp, send it exactly:') ?> <span class="mono">I allow callmebot to send me messages</span></li>
    <li><?= te('It replies with an API key, usually within a few minutes. Enter your number and that key here.') ?></li>
  </ol>
  <form method="post" action="/account" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="do" value="whatsapp">
    <div class="form-grid">
      <label class="field c6"><span><?= te('Your WhatsApp number') ?></span><input class="input mono" name="whatsapp_phone" value="<?= e($me['whatsapp_phone'] ?? '') ?>" placeholder="+1 804 555 0123" inputmode="tel">
        <small><?= te('With the country code. Leave empty to stop WhatsApp alerts.') ?></small></label>
      <label class="field c6"><span><?= te('CallMeBot API key') ?></span><input class="input mono" name="callmebot_key" value="" placeholder="<?= !empty($me['callmebot_key']) ? 'saved — leave empty to keep' : '123456' ?>">
        <small><?= te('Stored encrypted.') ?></small></label>
    </div>
    <div class="form-foot"><button class="btn btn-primary" type="submit"><?= te('Save WhatsApp') ?></button>
      <span class="small muted"><?= te('CallMeBot is free and sometimes slow; Telegram is the most reliable.') ?></span></div>
  </form>
</section>

<section class="section">
  <div class="section-head"><h2><?= te('Password') ?></h2></div>
  <form method="post" action="/account" class="mt-m" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="do" value="password">
    <div class="form-grid">
      <?php if ($me['password_hash'] !== ''): ?>
      <label class="field c4"><span><?= te('Current password') ?></span><input class="input" type="password" name="current_password" autocomplete="current-password" required></label>
      <?php endif; ?>
      <label class="field c4"><span><?= te('New password') ?></span><input class="input" type="password" name="new_password" autocomplete="new-password" minlength="12" required></label>
      <label class="field c4"><span><?= te('Again') ?></span><input class="input" type="password" name="new_password2" autocomplete="new-password" minlength="12" required></label>
    </div>
    <div class="form-foot"><button class="btn" type="submit"><?= te('Change password') ?></button></div>
  </form>
</section>

<section class="section">
  <div class="section-head"><h2><?= te('Devices') ?></h2></div>
  <form method="post" action="/account" class="mt-m" data-confirm="<?= te('Sign out on every device, including this one?') ?>">
    <?= csrf_field() ?><input type="hidden" name="do" value="everywhere">
    <button class="btn btn-danger" type="submit"><?= icon('log-out', 15) ?><?= te('Sign out everywhere') ?></button>
    <span class="small muted"><?= te('Use this if you signed in on a computer that isn’t yours.') ?></span>
  </form>
</section>
</div>
<?php
View::footer();

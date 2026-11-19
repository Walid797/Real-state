<?php
declare(strict_types=1);

use App\Auth;
use App\Db;
use App\Notify\Notifier;
use App\Settings;
use App\View;

/* Request a password reset link by email. The answer is the same whether or not the address exists. */

$sent = false;
if (is_post()) {
    $ip = client_ip();
    if (Auth::throttled($ip)) {
        App\Kernel::plain(429, 'Too many tries', 'Wait 15 minutes and try again.');
    }
    Db::insert('login_attempts', ['ip' => $ip, 'success' => 0, 'attempted_at' => now()]); // counts toward the same limit
    $email = mb_strtolower(input('email', '', 190));
    $user = Db::one('SELECT * FROM users WHERE email = ? AND is_active = 1', [$email]);
    if ($user && $user['password_hash'] !== '') {
        $token = Auth::issueToken((int) $user['id'], 'reset', 3600);
        $site = (string) Settings::get('site_name', 'Plat');
        Notifier::send(
            "$site · reset your password",
            "Someone asked to reset the password for $email.\n\nChoose a new one here (works once, for one hour):\n" . abs_url('/reset', ['token' => $token]) . "\n\nIf it wasn’t you, ignore this message — nothing changes.",
            ['email'], null, ['email' => $email, 'telegram' => '']
        );
    }
    $sent = true;
}

View::header('Reset password', '', ['bare' => true]);
?>
<section class="bare-side">
  <a class="brand" href="/login">Plat</a>
  <form class="bare-form" method="post" action="/forgot">
    <?= csrf_field() ?>
    <h1 class="display"><?= te('Reset password') ?></h1>
    <?php if ($sent): ?>
      <p class="soft"><?= te('If that address has an account, a reset link is on its way. It works for one hour.') ?></p>
      <p class="small muted"><?= te('Nothing arrived? Email must be set up by an admin, or an admin can give you a reset link from the Team page.') ?></p>
      <a class="btn" href="/login"><?= te('Back to sign in') ?></a>
    <?php else: ?>
      <p class="soft"><?= te('We’ll email you a link to choose a new one.') ?></p>
      <label class="field"><span><?= te('Email') ?></span><input class="input" type="email" name="email" autocomplete="username" required autofocus></label>
      <button class="btn btn-primary" type="submit"><?= te('Send reset link') ?></button>
    <?php endif; ?>
  </form>
  <p class="small muted"><a href="/login"><?= te('Back to sign in') ?></a></p>
</section>
<aside class="bare-art" aria-hidden="true"><blockquote><?= te('Links expire after an hour and work once.') ?></blockquote><cite><?= te('Security') ?></cite></aside>
<?php
View::footer();

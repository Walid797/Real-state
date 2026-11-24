<?php
declare(strict_types=1);

use App\Auth;
use App\Db;
use App\Services\Activity;
use App\View;

/* /invite?token=… — an invited person picks a name and password. Also serves /reset (see reset.php). */

$kind = $kind ?? 'invite';
$token = input('token', '', 120);
$user = Auth::peekToken($token, $kind);
if (!$user) {
    App\Kernel::plain(410, 'This link has expired', $kind === 'invite'
        ? 'Invitation links work once and for 7 days. Ask an admin for a new one.'
        : 'Reset links work once and for one hour. Request a new one from the sign-in page.');
}

$errors = [];
$name = (string) $user['name'];
if (is_post()) {
    $name = input('name', '', 80);
    $pass = (string) ($_POST['password'] ?? '');
    $errors = Auth::passwordProblems($pass);
    if ($pass !== (string) ($_POST['password2'] ?? '')) {
        $errors[] = 'The two passwords are different.';
    }
    if ($kind === 'invite' && $name === '') {
        $errors[] = 'Add your name so the team knows who wrote what.';
    }
    if (!$errors) {
        Db::run('UPDATE users SET password_hash = ?, name = ?, session_epoch = session_epoch + 1 WHERE id = ?',
            [Auth::hash($pass), $kind === 'invite' ? $name : $user['name'], $user['id']]);
        Auth::useToken((int) $user['token_id']);
        $fresh = Db::one('SELECT * FROM users WHERE id = ?', [$user['id']]);
        Auth::signIn($fresh);
        Activity::log('user', null, $kind === 'invite' ? 'Joined the team' : 'Changed their password');
        flash($kind === 'invite' ? 'Welcome, ' . $name . '.' : 'Password changed. Other devices were signed out.');
        redirect('/');
    }
}

View::header($kind === 'invite' ? 'Join' : 'New password', '', ['bare' => true]);
?>
<section class="bare-side">
  <span class="brand">Plat</span>
  <form class="bare-form" method="post" action="/<?= $kind ?>?token=<?= e(rawurlencode($token)) ?>">
    <?= csrf_field() ?>
    <h1 class="display"><?= te($kind === 'invite' ? 'Join the team' : 'New password') ?><?= tip_open() ?><?= te('This link works once.') ?><?= tip_close() ?></h1>
    <p class="soft"><?= e($user['email']) ?></p>
    <?php foreach ($errors as $err): ?><p class="err" role="alert"><?= te($err) ?></p><?php endforeach; ?>
    <?php if ($kind === 'invite'): ?>
    <label class="field"><span><?= te('Your name') ?></span><input class="input" name="name" value="<?= e($name) ?>" autocomplete="name" required autofocus></label>
    <?php endif; ?>
    <label class="field"><span><?= te('Password') ?><?= tip_open(t('Password')) ?><?= te('At least 12 characters.') ?><?= tip_close() ?></span>
      <input class="input" type="password" name="password" autocomplete="new-password" minlength="12" required<?= $kind === 'invite' ? '' : ' autofocus' ?>></label>
    <label class="field"><span><?= te('Password again') ?></span><input class="input" type="password" name="password2" autocomplete="new-password" minlength="12" required></label>
    <button class="btn btn-primary" type="submit"><?= te($kind === 'invite' ? 'Create my sign-in' : 'Save password') ?></button>
  </form>
</section>
<aside class="bare-art" aria-hidden="true">
  <blockquote><?= te('Every home, status and note is shared with the team. Alerts stay yours.') ?></blockquote>
  <cite><?= te('Team workspace') ?></cite>
</aside>
<?php
View::footer();

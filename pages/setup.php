<?php
declare(strict_types=1);

use App\Auth;
use App\Config;
use App\Db;
use App\Services\ExternalSites;
use App\View;

/*
 * One-time setup: creates the tables and the single account.
 * Needs ?token=<setup_token from config.php>. Once an account exists this page is closed for good.
 */

try {
    $hasUser = (int) Db::val('SELECT COUNT(*) FROM users') > 0;
} catch (PDOException) {
    $hasUser = false;
}
if ($hasUser) {
    App\Kernel::plain(404, 'Setup is finished', 'An account already exists. Sign in instead.');
}

$token = (string) Config::get('setup_token', '');
$given = input('token', '', 200);
if ($token === '' || strlen($token) < 16 || !hash_equals($token, $given)) {
    App\Kernel::plain(403, 'Setup link needed', 'Open this page with the setup token from your config file: /setup?token=…');
}

$errors = [];
$email = '';
if (is_post()) {
    $email = mb_strtolower(input('email', '', 190));
    $pass = (string) ($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'That email address doesn’t look right.';
    }
    $errors = array_merge($errors, Auth::passwordProblems($pass));
    if ($pass !== (string) ($_POST['password2'] ?? '')) {
        $errors[] = 'The two passwords are different.';
    }
    if (!$errors) {
        Config::appKey(); // fail early if app_key is malformed
        Db::runFile(APP_ROOT . '/sql/schema.sql');
        App\Migrator::run();
        Db::insert('users', ['email' => $email, 'name' => input('name', '', 80) ?: strstr($email, '@', true), 'role' => 'admin',
            'password_hash' => Auth::hash($pass), 'created_at' => now()]);
        ExternalSites::seedDefaults();
        App\Settings::set('notify_email', $email);
        flash('Account created. Sign in to start.');
        redirect('/login');
    }
}

View::header('Set up', '', ['bare' => true]);
?>
<section class="bare-side">
  <span class="brand">Plat</span>
  <form class="bare-form" method="post" action="/setup?token=<?= e(rawurlencode($given)) ?>">
    <?= csrf_field() ?>
    <h1 class="display"><?= te('Set up') ?></h1>
    <p class="soft"><?= te('Creates the database tables and your sign-in. This page closes afterwards.') ?></p>
    <?php foreach ($errors as $err): ?><p class="err" role="alert"><?= te($err) ?></p><?php endforeach; ?>
    <label class="field"><span><?= te('Your name') ?></span>
      <input class="input" name="name" autocomplete="name" required></label>
    <label class="field"><span><?= te('Your email') ?></span>
      <input class="input" type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required></label>
    <label class="field"><span><?= te('Password') ?></span>
      <input class="input" type="password" name="password" autocomplete="new-password" minlength="12" required>
      <small><?= te('At least 12 characters. A sentence works well.') ?></small></label>
    <label class="field"><span><?= te('Password again') ?></span>
      <input class="input" type="password" name="password2" autocomplete="new-password" minlength="12" required></label>
    <button class="btn btn-primary" type="submit"><?= te('Create my account') ?></button>
  </form>
  <p class="small muted"><?= te('Alerts go to this email until you change it in Settings.') ?></p>
</section>
<aside class="bare-art" aria-hidden="true">
  <blockquote><?= te('Three steps left: sign in, paste a free API key, run your first search.') ?></blockquote>
  <cite><?= te('Setup') ?></cite>
</aside>
<?php
View::footer();

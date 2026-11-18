<?php
declare(strict_types=1);

use App\Auth;
use App\Db;
use App\View;

if (Auth::check()) {
    redirect('/');
}

try {
    $hasUser = (int) Db::val('SELECT COUNT(*) FROM users') > 0;
} catch (PDOException) {
    $hasUser = false; // tables not created yet
}
if (!$hasUser) {
    redirect('/setup');
}

$error = null;
$email = '';
if (is_post()) {
    $email = input('email', '', 190);
    $result = Auth::attempt($email, (string) ($_POST['password'] ?? ''));
    if ($result === true) {
        $next = input('next', '/', 300);
        redirect(str_starts_with($next, '/') && !str_starts_with($next, '/login') ? $next : '/');
    }
    $error = $result;
}

$note = flash();
View::header('Sign in', '', ['bare' => true]);
?>
<section class="bare-side">
  <a class="brand" href="/login">Plat</a>
  <form class="bare-form" method="post" action="/login" autocomplete="on">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e(input('next', '/', 300)) ?>">
    <h1 class="display">Sign in</h1>
    <p class="soft">Private to your team.</p>
    <?php if ($note): ?><p class="small down" role="status"><?= e($note['msg']) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="err" role="alert"><?= e($error) ?></p><?php endif; ?>
    <label class="field">
      <span>Email</span>
      <input class="input" type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
    </label>
    <label class="field">
      <span>Password</span>
      <input class="input" type="password" name="password" autocomplete="current-password" required>
    </label>
    <button class="btn btn-primary" type="submit">Sign in</button>
    <p class="small mt-m"><a href="/forgot">Forgot your password?</a></p>
    <?php if (App\Settings::has('google_client_id') && App\Settings::has('google_client_secret')): ?>
    <div class="or"><span>or</span></div>
    <a class="btn btn-google" href="<?= e(url('/auth/google', ['next' => input('next', '', 300) ?: null])) ?>"><?= icon('google', 16) ?>Continue with Google</a>
    <?php endif; ?>
  </form>
  <p class="small muted">Not indexed by search engines. Sessions end after 14 days. No account? Ask an admin for an invitation.</p>
</section>
<aside class="bare-art" aria-hidden="true">
  <svg class="plat" viewBox="0 0 600 800" preserveAspectRatio="xMidYMid slice" fill="none" stroke="#3d4b47" stroke-width="1">
    <path d="M-20 120 L640 60 M-20 300 L640 250 M-20 480 L640 450 M-20 660 L640 650"/>
    <path d="M90 -20 L60 820 M250 -20 L235 820 M410 -20 L400 820 M545 -20 L548 820"/>
    <path d="M250 280 L405 268 L402 462 L240 470 Z" stroke="#14b8a6" stroke-width="1.5"/>
    <path d="M90 113 L250 98 M235 300 L60 310" stroke-dasharray="4 6"/>
    <circle cx="322" cy="368" r="3" fill="#14b8a6" stroke="none"/>
  </svg>
  <blockquote>Know what the house next door sold for before you make the call.</blockquote>
  <cite>Lot 14 · Block 3</cite>
</aside>
<?php
View::footer();

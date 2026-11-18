<?php
declare(strict_types=1);

use App\Auth;
use App\Db;
use App\Http;
use App\Services\Activity;
use App\Settings;

/*
 * Sign in with Google (OpenID Connect, authorization code + PKCE).
 *   /auth/google           → redirect to Google
 *   /auth/google/callback  → Google sends the user back here
 * Only people already on the Team page can get in; there is no self-sign-up.
 */

$clientId = (string) Settings::get('google_client_id', '');
$secret = (string) Settings::get('google_client_secret', '');
if ($clientId === '' || $secret === '') {
    App\Kernel::plain(404, 'Google sign-in is off', 'An admin can turn it on under Settings → Sign-in.');
}
$redirectUri = abs_url('/auth/google/callback');

if (empty($params['cb'])) {
    $state = bin2hex(random_bytes(16));
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $_SESSION['g_state'] = $state;
    $_SESSION['g_verifier'] = $verifier;
    $_SESSION['g_next'] = input('next', '/', 300);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
        'prompt' => 'select_account',
    ]), true, 303);
    exit;
}

$fail = static function (string $why): never {
    flash($why, 'err');
    redirect('/login');
};

$state = (string) ($_SESSION['g_state'] ?? '');
$verifier = (string) ($_SESSION['g_verifier'] ?? '');
$next = (string) ($_SESSION['g_next'] ?? '/');
unset($_SESSION['g_state'], $_SESSION['g_verifier'], $_SESSION['g_next']);

if (isset($_GET['error'])) {
    $fail('Google sign-in was cancelled.');
}
if ($state === '' || !hash_equals($state, (string) ($_GET['state'] ?? ''))) {
    $fail('That sign-in attempt expired. Try again.');
}
if (Auth::throttled(client_ip())) {
    $fail('Too many tries. Wait 15 minutes and try again.');
}

// The ID token comes straight from Google over TLS, so its claims can be trusted after these checks.
$res = Http::postForm('https://oauth2.googleapis.com/token', [
    'code' => (string) ($_GET['code'] ?? ''), 'client_id' => $clientId, 'client_secret' => $secret,
    'redirect_uri' => $redirectUri, 'grant_type' => 'authorization_code', 'code_verifier' => $verifier,
], 20);
$tok = json_decode($res['body'], true);
$parts = explode('.', (string) ($tok['id_token'] ?? ''));
$claims = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
if ($res['status'] !== 200 || !is_array($claims)) {
    $fail('Google didn’t confirm the sign-in. Check the client ID and secret in Settings.');
}
$okIssuer = in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true);
if (!$okIssuer || ($claims['aud'] ?? '') !== $clientId || (int) ($claims['exp'] ?? 0) < time() || empty($claims['email_verified'])) {
    $fail('Google’s answer didn’t pass the checks. Try again.');
}

$email = mb_strtolower((string) $claims['email']);
$sub = (string) $claims['sub'];
$user = Db::one('SELECT * FROM users WHERE google_sub = ?', [$sub]) ?? Db::one('SELECT * FROM users WHERE email = ?', [$email]);
if (!$user || !(int) $user['is_active']) {
    Db::insert('login_attempts', ['ip' => client_ip(), 'success' => 0, 'attempted_at' => now()]);
    $fail($email . ' isn’t on the team. Ask an admin to invite this address first.');
}
if ($user['google_sub'] === null || $user['name'] === '') {
    Db::run('UPDATE users SET google_sub = ?, name = IF(name = \'\', ?, name) WHERE id = ?', [$sub, mb_substr((string) ($claims['name'] ?? ''), 0, 80), $user['id']]);
    if ($user['password_hash'] === '') {
        Db::run('UPDATE user_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now(), $user['id']]);
        Activity::log('user', null, 'Joined the team with Google', (int) $user['id']);
    }
}
Db::insert('login_attempts', ['ip' => client_ip(), 'success' => 1, 'attempted_at' => now()]);
Auth::signIn(Db::one('SELECT * FROM users WHERE id = ?', [$user['id']]));
redirect(str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : '/');

<?php
declare(strict_types=1);

namespace App;

/** Single-user session auth, CSRF tokens and login throttling. */
final class Auth
{
    private const SESSION_DAYS = 14;
    private const MAX_FAILURES = 6;      // per IP …
    private const WINDOW_MINUTES = 15;   // … inside this window

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }
        $dir = APP_ROOT . '/storage/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            // Own directory: the shared host's 24-minute session GC must not log us out.
            session_save_path($dir);
        }
        $life = self::SESSION_DAYS * 86400;
        ini_set('session.gc_maxlifetime', (string) $life);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('plat_sid');
        session_set_cookie_params([
            'lifetime' => $life,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function isHttps(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['uid']);
    }

    public static function userId(): int
    {
        return (int) ($_SESSION['uid'] ?? 0);
    }

    public static function email(): string
    {
        return (string) ($_SESSION['email'] ?? '');
    }

    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }
        $next = $_SERVER['REQUEST_URI'] ?? '/';
        if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
            json_out(['error' => 'signed_out'], 401);
        }
        redirect(url('/login', ['next' => $next === '/' ? null : $next]));
    }

    public static function throttled(string $ip): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::WINDOW_MINUTES * 60);
        $n = (int) Db::val(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at > ?',
            [$ip, $since]
        );
        return $n >= self::MAX_FAILURES;
    }

    /** @return true|string true on success, otherwise a message safe to show. */
    public static function attempt(string $email, string $password): bool|string
    {
        $ip = client_ip();
        if (self::throttled($ip)) {
            return 'Too many tries. Wait ' . self::WINDOW_MINUTES . ' minutes and try again.';
        }
        $user = Db::one('SELECT id, email, password_hash FROM users WHERE email = ?', [mb_strtolower($email)]);
        // Verify against a dummy hash when the user is unknown so timing does not reveal it.
        $hash = $user['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21lc2FsdA$Zm9vYmFyYmF6cXV4Zm9vYmFyYmF6cXV4Zm9vYmFyYmE';
        $ok = password_verify($password, $hash) && $user !== null;
        Db::insert('login_attempts', ['ip' => $ip, 'success' => $ok ? 1 : 0, 'attempted_at' => now()]);
        if (!$ok) {
            return 'That email and password don’t match.';
        }
        if (password_needs_rehash($user['password_hash'], self::algo())) {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [self::hash($password), $user['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        Db::run('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $user['id']]);
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
                'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }

    private static function algo(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algo());
    }

    /** @return string[] problems; empty means acceptable */
    public static function passwordProblems(string $password): array
    {
        $out = [];
        if (mb_strlen($password) < 12) {
            $out[] = 'Use at least 12 characters.';
        }
        if (mb_strlen($password) > 200) {
            $out[] = 'Keep it under 200 characters.';
        }
        return $out;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function verifyCsrf(): void
    {
        $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($sent) || $sent === '' || !hash_equals(self::csrfToken(), $sent)) {
            http_response_code(419);
            if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
                json_out(['error' => 'expired'], 419);
            }
            exit('This form expired. Go back, reload the page and try again.');
        }
    }
}

<?php
declare(strict_types=1);

namespace App;

/** Session auth for a small team: roles, CSRF tokens, login throttling, sign-out everywhere. */
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
        ini_set('session.gc_probability', '1'); // some hosts disable GC and clean only their own directory
        ini_set('session.gc_divisor', '100');
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

    public const ROLES = [
        'admin' => 'Admin',
        'partner' => 'Partner',
        'viewer' => 'Viewer',
    ];

    /** What each role may do. Viewers read; partners work deals; admins also run the system. */
    private const PERMS = [
        'view' => ['admin', 'partner', 'viewer'],
        'edit' => ['admin', 'partner'],
        'admin' => ['admin'],
    ];

    private static ?array $user = null;

    /** Loads the signed-in user once per request; drops the session if the account was disabled or signed out everywhere. */
    public static function user(): ?array
    {
        if (self::$user !== null || empty($_SESSION['uid'])) {
            return self::$user;
        }
        $u = Db::one('SELECT id, email, name, role, is_active, session_epoch, telegram_chat FROM users WHERE id = ?', [(int) $_SESSION['uid']]);
        if (!$u || !(int) $u['is_active'] || (int) $u['session_epoch'] !== (int) ($_SESSION['epoch'] ?? 0)) {
            self::logout();
            return null;
        }
        return self::$user = $u;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function userId(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function email(): string
    {
        return (string) (self::user()['email'] ?? '');
    }

    public static function name(): string
    {
        $u = self::user();
        return (string) ($u['name'] ?: ($u['email'] ?? ''));
    }

    public static function role(): string
    {
        return (string) (self::user()['role'] ?? '');
    }

    public static function can(string $perm): bool
    {
        return in_array(self::role(), self::PERMS[$perm] ?? [], true);
    }

    public static function require(string $perm): void
    {
        if (self::can($perm)) {
            return;
        }
        http_response_code(403);
        if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
            json_out(['error' => 'forbidden'], 403);
        }
        Kernel::plain(403, 'Not allowed', self::role() === 'viewer'
            ? 'Your account can view homes and reports but not change them. Ask an admin if you need more.'
            : 'Only an admin can open this page.');
    }

    /** Starts a session for a verified user (password, invite or Google). */
    public static function signIn(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['epoch'] = (int) $user['session_epoch'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        self::$user = null;
        Db::run('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $user['id']]);
    }

    /** Ends every session of a user on every device. */
    public static function signOutEverywhere(int $userId): void
    {
        Db::run('UPDATE users SET session_epoch = session_epoch + 1 WHERE id = ?', [$userId]);
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
        $user = Db::one('SELECT id, email, password_hash, session_epoch, is_active FROM users WHERE email = ?', [mb_strtolower($email)]);
        // Verify against a dummy hash when the user is unknown so timing does not reveal it.
        $hash = $user['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21lc2FsdA$Zm9vYmFyYmF6cXV4Zm9vYmFyYmF6cXV4Zm9vYmFyYmE';
        $ok = password_verify($password, $hash) && $user !== null && (int) $user['is_active'] === 1 && $user['password_hash'] !== '';
        Db::insert('login_attempts', ['ip' => $ip, 'success' => $ok ? 1 : 0, 'attempted_at' => now()]);
        if (!$ok) {
            return 'That email and password don’t match.';
        }
        if (password_needs_rehash($user['password_hash'], self::algo())) {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [self::hash($password), $user['id']]);
        }
        self::signIn($user);
        return true;
    }

    public static function logout(): void
    {
        self::$user = null;
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
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

    /**
     * One-time link token (invite or password reset). Only its hash is stored.
     */
    public static function issueToken(int $userId, string $kind, int $ttlSeconds): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Db::run('UPDATE user_tokens SET used_at = ? WHERE user_id = ? AND kind = ? AND used_at IS NULL', [now(), $userId, $kind]);
        Db::insert('user_tokens', [
            'user_id' => $userId, 'kind' => $kind, 'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlSeconds), 'created_at' => now(),
        ]);
        return $token;
    }

    /** @return array|null the user the token belongs to, if valid and unused */
    public static function peekToken(string $token, string $kind): ?array
    {
        if (strlen($token) < 20 || strlen($token) > 100) {
            return null;
        }
        return Db::one(
            'SELECT u.*, t.id AS token_id FROM user_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.kind = ? AND t.used_at IS NULL AND t.expires_at > ? AND u.is_active = 1',
            [hash('sha256', $token), $kind, now()]
        );
    }

    public static function useToken(int $tokenId): void
    {
        Db::run('UPDATE user_tokens SET used_at = ? WHERE id = ?', [now(), $tokenId]);
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

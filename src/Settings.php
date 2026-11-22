<?php
declare(strict_types=1);

namespace App;

/**
 * Key/value settings in MySQL. Secrets (API keys, SMTP password, bot tokens)
 * are encrypted with AES-256-GCM using app_key from the private config file,
 * so a database dump alone does not expose them.
 */
final class Settings
{
    /** Settings that must be encrypted at rest. */
    public const SECRETS = [
        'rentcast_key', 'openwebninja_key', 'smtp_pass', 'telegram_token', 'callmebot_key', 'census_key', 'google_client_secret', 'cesium_key',
    ];

    /**
     * Settings kept in the private .env file instead of the database: keys, passwords and
     * connection details. They are read from there and not shown or editable in Settings.
     */
    public const ENV = [
        'rentcast_key' => 'RENTCAST_API_KEY',
        'openwebninja_key' => 'OPENWEBNINJA_API_KEY',
        'census_key' => 'CENSUS_API_KEY',
        'cesium_key' => 'CESIUM_ION_TOKEN',
        'smtp_host' => 'SMTP_HOST',
        'smtp_port' => 'SMTP_PORT',
        'smtp_secure' => 'SMTP_SECURE',
        'smtp_user' => 'SMTP_USER',
        'smtp_pass' => 'SMTP_PASSWORD',
        'imap_host' => 'IMAP_HOST',
        'imap_port' => 'IMAP_PORT',
        'telegram_token' => 'TELEGRAM_BOT_TOKEN',
        'telegram_chat' => 'TELEGRAM_CHAT_ID',
        'google_client_id' => 'GOOGLE_CLIENT_ID',
        'google_client_secret' => 'GOOGLE_CLIENT_SECRET',
    ];

    public const DEFAULTS = [
        'site_name' => 'Plat',
        'default_state' => 'VA',
        'rentcast_limit' => '50',
        'rentcast_reserve' => '15',
        'openwebninja_limit' => '100',
        'openwebninja_reserve' => '20',
        'openwebninja_auto' => '60',
        'cesium_limit' => '900',
        'cesium_reserve' => '0',
        'search_provider' => 'auto',
        'homes_on' => '1',
        'snapshot_hours' => '24',
        'smtp_host' => 'smtp.hostinger.com',
        'smtp_port' => '465',
        'smtp_secure' => 'ssl',
        'imap_host' => 'imap.hostinger.com',
        'imap_port' => '993',
        'fairfax_tax_rate' => '1.12',
        'arlington_tax_rate' => '1.053',
    ];

    private static ?array $cache = null;

    private static function loadAll(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Db::all('SELECT k, v, is_secret FROM settings') as $r) {
                self::$cache[$r['k']] = $r;
            }
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (isset(self::ENV[$key])) {
            $env = Env::get(self::ENV[$key]);
            if ($env !== null) {
                return $env;
            }
        }
        $row = self::loadAll()[$key] ?? null;
        if ($row === null || $row['v'] === null || $row['v'] === '') {
            return $default ?? (self::DEFAULTS[$key] ?? null);
        }
        if ((int) $row['is_secret'] === 1) {
            return self::decrypt((string) $row['v']) ?? $default;
        }
        return (string) $row['v'];
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === null || !is_numeric($v) ? $default : (int) $v;
    }

    public static function has(string $key): bool
    {
        $v = self::get($key);
        return $v !== null && $v !== '';
    }

    /** Is this setting kept in .env (so the Settings page neither shows nor saves it)? */
    public static function fromEnv(string $key): bool
    {
        return isset(self::ENV[$key]);
    }

    /** Is it actually set in .env? */
    public static function inEnv(string $key): bool
    {
        return isset(self::ENV[$key]) && Env::get(self::ENV[$key]) !== null;
    }

    public static function set(string $key, ?string $value): void
    {
        if (isset(self::ENV[$key]) && self::inEnv($key)) {
            return; // .env is the source; a form cannot overwrite it
        }
        $secret = in_array($key, self::SECRETS, true);
        $stored = ($value === null || $value === '') ? null : ($secret ? self::encrypt($value) : $value);
        Db::run(
            'INSERT INTO settings (k, v, is_secret, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE v = VALUES(v), is_secret = VALUES(is_secret), updated_at = VALUES(updated_at)',
            [$key, $stored, $secret ? 1 : 0, now()]
        );
        self::$cache = null;
    }

    /** "••••••3f9a" — enough to recognise a key without revealing it. */
    public static function masked(string $key): string
    {
        $v = self::get($key);
        if ($v === null || $v === '') {
            return '';
        }
        return str_repeat('•', 8) . substr($v, -4);
    }

    /** Encrypt a value for storage outside this table (e.g. a person's own API key). */
    public static function seal(string $plain): string
    {
        return self::encrypt($plain);
    }

    public static function unseal(?string $stored): ?string
    {
        return $stored === null || $stored === '' ? null : self::decrypt($stored);
    }

    private static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', Config::appKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('Could not encrypt the setting.');
        }
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    private static function decrypt(string $stored): ?string
    {
        if (!str_starts_with($stored, 'v1:')) {
            return null;
        }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(
            substr($raw, 28), 'aes-256-gcm', Config::appKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)
        );
        return $plain === false ? null : $plain;
    }
}

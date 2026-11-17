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
        'rentcast_key', 'openwebninja_key', 'smtp_pass', 'telegram_token', 'callmebot_key', 'census_key', 'google_client_secret',
    ];

    public const DEFAULTS = [
        'site_name' => 'Plat',
        'default_state' => 'VA',
        'rentcast_limit' => '50',
        'rentcast_reserve' => '15',
        'openwebninja_limit' => '100',
        'openwebninja_reserve' => '20',
        'search_provider' => 'auto',
        'snapshot_hours' => '24',
        'smtp_host' => 'smtp.hostinger.com',
        'smtp_port' => '465',
        'smtp_secure' => 'ssl',
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

    public static function set(string $key, ?string $value): void
    {
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

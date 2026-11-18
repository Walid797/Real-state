<?php
declare(strict_types=1);

namespace App;

/**
 * Loads the private config file. It is never in Git and never under /public.
 *
 * Lookup order:
 *   1. path in the REALESTATE_CONFIG environment variable
 *   2. <app>/config.php                      (blocked from the web by the root .htaccess)
 *   3. two / three levels above <app>        (outside public_html on Hostinger)
 */
final class Config
{
    private static array $data = [];
    private static ?string $path = null;

    public static function load(): void
    {
        $candidates = array_filter([
            getenv('REALESTATE_CONFIG') ?: null,
            APP_ROOT . '/config.php',
            dirname(APP_ROOT, 2) . '/realestate-config.php',
            dirname(APP_ROOT, 3) . '/realestate-config.php',
        ]);
        foreach ($candidates as $file) {
            if (is_file($file) && is_readable($file)) {
                $cfg = require $file;
                if (is_array($cfg)) {
                    self::$data = $cfg;
                    self::$path = $file;
                    return;
                }
            }
        }
    }

    public static function loaded(): bool
    {
        return self::$path !== null;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $v = self::$data;
        foreach (explode('.', $key) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) {
                return $default;
            }
            $v = $v[$k];
        }
        return $v;
    }

    /** 32-byte key for encrypting stored API keys. */
    public static function appKey(): string
    {
        $hex = (string) self::get('app_key', '');
        if (!preg_match('/^[0-9a-f]{64}$/i', $hex)) {
            throw new \RuntimeException('app_key in config.php must be 64 hex characters.');
        }
        return hex2bin($hex);
    }
}

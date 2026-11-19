<?php
declare(strict_types=1);

namespace App;

final class Locale
{
    private const COOKIE = 'plat_language';

    public static function language(): string
    {
        return ($_COOKIE[self::COOKIE] ?? null) === 'fa' ? 'fa' : 'en';
    }

    public static function direction(): string
    {
        return self::language() === 'fa' ? 'rtl' : 'ltr';
    }

    public static function text(string $message): string
    {
        if (self::language() !== 'fa') {
            return $message;
        }
        static $persian = null;
        $persian ??= require APP_ROOT . '/src/locales/fa.php';
        return $persian[$message] ?? $message;
    }

    public static function change(): never
    {
        $language = input('language');
        if ($language === 'en' || $language === 'fa') {
            setcookie(self::COOKIE, $language, [
                'expires' => time() + 365 * 86400,
                'path' => '/',
                'secure' => Auth::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $return = $_POST['return'] ?? '/';
        if (!is_string($return) || !str_starts_with($return, '/') || str_starts_with($return, '//')
            || str_contains($return, '\\') || preg_match('/[\x00-\x20\x7f]/', $return)) {
            $return = '/';
        }
        redirect($return);
    }
}

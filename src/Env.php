<?php
declare(strict_types=1);

namespace App;

/**
 * The private .env file next to the app (never in Git, closed to the web by the root .htaccess):
 * API keys, mail and bot credentials. Lines are KEY=value; quotes and # comments are allowed.
 * A real environment variable of the same name wins over the file.
 */
final class Env
{
    private static ?array $vars = null;

    public static function path(): string
    {
        return (getenv('REALESTATE_ENV') ?: '') !== '' ? (string) getenv('REALESTATE_ENV') : APP_ROOT . '/.env';
    }

    public static function get(string $name): ?string
    {
        $real = getenv($name);
        if ($real !== false && $real !== '') {
            return $real;
        }
        $v = self::all()[$name] ?? null;
        return $v === null || $v === '' ? null : $v;
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$vars === null) {
            self::$vars = is_file(self::path()) && is_readable(self::path()) ? self::parse((string) file_get_contents(self::path())) : [];
        }
        return self::$vars;
    }

    /** @return array<string,string> */
    public static function parse(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !preg_match('/^(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
                continue;
            }
            $v = $m[2];
            if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $v, $q)) {
                $v = stripcslashes($q[1]);
            } elseif (preg_match("/^'([^']*)'/", $v, $q)) {
                $v = $q[1];
            } else {
                $v = trim((string) preg_replace('/\s+#.*$/', '', $v));
            }
            $out[$m[1]] = $v;
        }
        return $out;
    }

    /** A value written so parse() reads it back exactly. */
    public static function quote(string $v): string
    {
        return preg_match('/^[A-Za-z0-9_.@:\/+-]*$/', $v) ? $v : '"' . addcslashes($v, "\"\\\n\r\t\$") . '"';
    }

    /** For tests: forget the parsed file. */
    public static function reset(): void
    {
        self::$vars = null;
    }
}

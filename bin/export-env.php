<?php
declare(strict_types=1);

/*
 * Command-line only. Moves the keys, passwords and connection details saved under Settings
 * into the private .env file next to the app, then (with --clear) removes them from the
 * database. Keys already in .env are left as they are. Prints names only, never values.
 *
 *   php bin/export-env.php            write .env (keeps the database copy)
 *   php bin/export-env.php --clear    write .env and remove the moved values from the database
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Db;
use App\Env;
use App\Settings;

$path = Env::path();
$existing = Env::all();
$lines = [];
$moved = [];
foreach (Settings::ENV as $key => $name) {
    if (isset($existing[$name]) && $existing[$name] !== '') {
        echo "  $name: already in .env\n";
        continue;
    }
    $v = (string) (Settings::get($key) ?? ''); // saved value, or the built-in default
    $lines[] = $name . '=' . Env::quote((string) $v);
    $moved[$key] = $name;
    echo "  $name: " . ($v === '' ? 'empty (fill it in)' : 'moved') . "\n";
}
if ($lines) {
    $text = (is_file($path) ? rtrim((string) file_get_contents($path)) . "\n\n" : "# Plat — private settings. Never commit this file.\n")
        . '# Moved from Settings on ' . gmdate('Y-m-d H:i') . " UTC\n" . implode("\n", $lines) . "\n";
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $text, LOCK_EX) === false || !chmod($tmp, 0600) || !rename($tmp, $path)) {
        fwrite(STDERR, "Could not write $path\n");
        exit(1);
    }
    Env::reset();
}
echo ".env: $path (" . count(Env::all()) . " values, permissions " . substr(sprintf('%o', fileperms($path)), -4) . ")\n";

if (in_array('--clear', $argv, true)) {
    $n = 0;
    foreach (Settings::ENV as $key => $name) {
        if (Env::get($name) !== null || array_key_exists($key, $moved)) {
            $n += Db::run('DELETE FROM settings WHERE k = ?', [$key])->rowCount();
        }
    }
    echo "Removed $n values from the database; they are read from .env now.\n";
}

<?php
declare(strict_types=1);

/*
 * Command-line setup — the same thing /setup does in the browser.
 *
 *   php bin/install.php                         create / update the tables
 *   php bin/install.php you@example.com         …and create the account (asks for a password)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Config;
use App\Db;
use App\Services\ExternalSites;

if (!Config::loaded()) {
    fwrite(STDERR, "config.php not found. Copy config.sample.php to config.php and fill it in.\n");
    exit(1);
}
Config::appKey();

$n = Db::runFile(dirname(__DIR__) . '/sql/schema.sql');
ExternalSites::seedDefaults();
echo "Schema ready ($n statements).\n";

$email = strtolower(trim($argv[1] ?? ''));
if ($email === '') {
    exit(0);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "That is not an email address.\n");
    exit(1);
}
if ((int) Db::val('SELECT COUNT(*) FROM users') > 0) {
    fwrite(STDERR, "An account already exists. Change its password under Settings.\n");
    exit(1);
}
$pass = getenv('PLAT_PASSWORD') ?: null;
if ($pass === null) {
    fwrite(STDOUT, 'Password (12+ characters): ');
    $pass = rtrim((string) fgets(STDIN), "\r\n");
}
$problems = Auth::passwordProblems($pass);
if ($problems) {
    fwrite(STDERR, implode(' ', $problems) . "\n");
    exit(1);
}
Db::insert('users', ['email' => $email, 'password_hash' => Auth::hash($pass), 'created_at' => now()]);
App\Settings::set('notify_email', $email);
echo "Account created for $email.\n";

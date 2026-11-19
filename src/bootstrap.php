<?php
declare(strict_types=1);

/*
 * Application bootstrap: config, autoload, error handling, session, security headers.
 * Included by public/index.php and every CLI script in /cron and /bin.
 */

define('APP_ROOT', dirname(__DIR__));
define('APP_START', microtime(true));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = APP_ROOT . '/src/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_ROOT . '/src/helpers.php';

App\Config::load();

date_default_timezone_set((string) App\Config::get('timezone', 'America/New_York'));
mb_internal_encoding('UTF-8');

$debug = (bool) App\Config::get('debug', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
$logDir = APP_ROOT . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}
ini_set('error_log', $logDir . '/php-error.log');

/** A plain error page that still looks like the site (no inline styles: the CSP forbids them). */
function fail_page(string $detail = ''): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $again = e($_SERVER['REQUEST_URI'] ?? '/');
    echo '<!doctype html><html lang="' . App\Locale::language() . '" dir="' . App\Locale::direction() . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . te('Something went wrong') . '</title><link rel="stylesheet" href="/assets/app.css">'
        . '<body class="bare"><main class="page"><div class="empty"><h1 class="display">' . te('That didn’t work.') . '</h1>'
        . '<p>' . te('The page stopped part-way. It has been logged, so it can be looked into.') . '</p>'
        . ($detail !== '' ? '<pre class="small">' . e($detail) . '</pre>' : '')
        . '<p><a class="btn btn-primary" href="' . $again . '">' . te('Try again') . '</a> <a class="btn" href="/">' . te('Dashboard') . '</a></p></div></main></body></html>';
}

set_exception_handler(static function (Throwable $e) use ($debug): void {
    error_log('[uncaught] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
        . (PHP_SAPI === 'cli' ? '' : ' · ' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '')));
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    fail_page($debug ? $e->getMessage() . "\n" . $e->getTraceAsString() : '');
});

// Out of memory or out of time never reaches the exception handler: catch those here so the
// visitor gets a page instead of a blank screen, and the log says which address it was.
if (PHP_SAPI !== 'cli') {
    ob_start(); // lets the fatal handler replace a half-drawn page
}
register_shutdown_function(static function () use ($debug): void {
    $err = error_get_last();
    if ($err === null || !in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR], true)) {
        if (PHP_SAPI !== 'cli' && ($t = microtime(true) - APP_START) > 20) {
            error_log(sprintf('[slow] %.1fs · %s %s', $t, $_SERVER['REQUEST_METHOD'] ?? '', $_SERVER['REQUEST_URI'] ?? ''));
        }
        return;
    }
    error_log('[fatal] ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']
        . (PHP_SAPI === 'cli' ? '' : ' · ' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '')));
    if (PHP_SAPI !== 'cli') {
        fail_page($debug ? $err['message'] : '');
    }
});

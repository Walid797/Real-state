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

set_exception_handler(static function (Throwable $e) use ($debug): void {
    error_log('[uncaught] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><body style="font-family:sans-serif;padding:2rem">';
    echo '<h1>Something went wrong</h1>';
    echo $debug ? '<pre>' . e($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>' : '<p>The error was logged.</p>';
    echo '</body>';
});

<?php
// Router for PHP's built-in server (local testing only):
//   php -S 127.0.0.1:8088 -t public tests/router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__ . '/../public' . $path)) {
    return false;
}
require __DIR__ . '/../public/index.php';

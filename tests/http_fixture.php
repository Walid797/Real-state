<?php
// Transport-only fixture; never supplies property evidence. Run with multiple workers:
// PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8097 tests/http_fixture.php
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
usleep($path === '/slow' ? 3000000 : 400000);
http_response_code($path === '/fail' ? 503 : 200);
echo 'transport fixture';

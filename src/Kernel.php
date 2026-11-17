<?php
declare(strict_types=1);

namespace App;

/** Front controller: security headers, session, auth gate, CSRF, routing. */
final class Kernel
{
    /** method, pattern, page file, public? */
    private const ROUTES = [
        ['GET|POST', '#^/login$#', 'login', true],
        ['GET|POST', '#^/setup$#', 'setup', true],
        ['GET', '#^/cron$#', 'cron', true],
        ['POST', '#^/logout$#', 'logout', false],
        ['GET', '#^/$#', 'search', false],
        ['POST', '#^/search/act$#', 'search_act', false],
        ['GET', '#^/export\.csv$#', 'export', false],
        ['GET', '#^/property/(?<id>\d+)$#', 'property', false],
        ['POST', '#^/property/(?<id>\d+)/act$#', 'property_act', false],
        ['GET', '#^/api/context/(?<id>\d+)$#', 'context', false],
        ['GET|POST', '#^/watchlist$#', 'watchlist', false],
        ['GET', '#^/alerts$#', 'alerts', false],
        ['GET|POST', '#^/alerts/new$#', 'alert_edit', false],
        ['GET|POST', '#^/alerts/(?<id>\d+)$#', 'alert_edit', false],
        ['POST', '#^/alerts/(?<id>\d+)/act$#', 'alert_act', false],
        ['GET|POST', '#^/market$#', 'market', false],
        ['GET', '#^/market/(?<id>\d+)$#', 'market', false],
        ['GET|POST', '#^/add$#', 'add', false],
        ['GET|POST', '#^/sites$#', 'sites', false],
        ['GET|POST', '#^/settings$#', 'settings', false],
    ];

    public static function handle(): void
    {
        self::headers();

        if (!Config::loaded()) {
            self::plain(503, 'Not configured', 'Copy config.sample.php to config.php next to the “public” folder (never inside it), fill in the database details, then reload.');
        }
        // Answer only on the configured hostname: the same files are also reachable
        // under the parent domain's folder on shared hosting, and must not be served there.
        $want = strtolower((string) parse_url((string) Config::get('base_url', ''), PHP_URL_HOST));
        $have = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]);
        if ($want !== '' && $have !== $want) {
            self::plain(404, 'Not found', 'Nothing here.');
        }

        $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        $path = $path === '' ? '/' : (strlen($path) > 1 ? rtrim($path, '/') : $path);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        foreach (self::ROUTES as [$methods, $pattern, $page, $public]) {
            if (!preg_match($pattern, $path, $m)) {
                continue;
            }
            if (!in_array($method, explode('|', $methods), true)) {
                header('Allow: ' . str_replace('|', ', ', $methods));
                self::plain(405, 'Method not allowed', 'That address doesn’t accept this kind of request.');
            }
            Auth::startSession();
            if (!$public) {
                Auth::requireLogin();
            }
            if ($method === 'POST') {
                Auth::verifyCsrf();
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            (static function (string $__file, array $params): void {
                require $__file;
            })(APP_ROOT . '/pages/' . $page . '.php', $params);
            return;
        }

        Auth::startSession();
        if (!Auth::check()) {
            redirect('/login');
        }
        http_response_code(404);
        View::header('Not found');
        echo '<div class="empty"><h1 class="display">That page isn’t here.</h1><p>The link may be old, or the record was deleted.</p><p><a class="btn" href="/">Back to search</a></p></div>';
        View::footer();
    }

    private static function headers(): void
    {
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        // No inline scripts or styles anywhere. Images: own files, map tiles, listing photos.
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self'; script-src 'self'; "
            . "connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        if (Auth::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
        header('Cache-Control: no-store, private');
    }

    public static function plain(int $status, string $title, string $message): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . e($title) . '</title><link rel="stylesheet" href="/assets/app.css">'
            . '<body class="bare"><main class="bare-box"><h1 class="display">' . e($title) . '</h1><p>' . e($message) . '</p></main></body></html>';
        exit;
    }
}

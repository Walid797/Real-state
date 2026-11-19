<?php
declare(strict_types=1);

namespace App;

/** Front controller: security headers, session, auth gate, CSRF, routing. */
final class Kernel
{
    /**
     * method, pattern, page file, permission for GET, permission for POST.
     * Permissions: public | view (any signed-in role) | edit (admin, partner) | admin.
     */
    private const ROUTES = [
        ['GET', '#^/welcome$#', 'welcome', 'public', 'public'],
        ['GET|POST', '#^/login$#', 'login', 'public', 'public'],
        ['POST', '#^/language$#', 'language', 'public', 'public'],
        ['GET|POST', '#^/setup$#', 'setup', 'public', 'public'],
        ['GET|POST', '#^/invite$#', 'invite', 'public', 'public'],
        ['GET|POST', '#^/forgot$#', 'forgot', 'public', 'public'],
        ['GET|POST', '#^/reset$#', 'reset', 'public', 'public'],
        ['GET', '#^/auth/google(?<cb>/callback)?$#', 'auth_google', 'public', 'public'],
        ['GET', '#^/cron$#', 'cron', 'public', 'public'],
        ['POST', '#^/logout$#', 'logout', 'view', 'view'],
        ['GET', '#^/$#', 'dashboard', 'view', 'view'],
        ['GET', '#^/search$#', 'search', 'view', 'view'],
        ['POST', '#^/search/act$#', 'search_act', 'view', 'view'],
        ['GET', '#^/export\.(?<fmt>csv|xlsx)$#', 'export', 'view', 'view'],
        ['GET', '#^/property/(?<id>\d+)$#', 'property', 'view', 'view'],
        ['POST', '#^/property/(?<id>\d+)/act$#', 'property_act', 'edit', 'edit'],
        ['GET', '#^/api/context/(?<id>\d+)$#', 'context', 'view', 'view'],
        ['GET|POST', '#^/api/nearby/(?<id>\d+)$#', 'nearby', 'view', 'view'],
        ['POST', '#^/api/aerial/(?<id>\d+)$#', 'aerial', 'view', 'view'],
        ['POST', '#^/api/details/(?<id>\d+)$#', 'details_auto', 'view', 'view'],
        ['GET', '#^/(pipeline|watchlist)$#', 'pipeline', 'view', 'edit'],
        ['GET', '#^/alerts$#', 'alerts', 'view', 'view'],
        ['GET|POST', '#^/alerts/new$#', 'alert_edit', 'view', 'view'],
        ['GET|POST', '#^/alerts/(?<id>\d+)$#', 'alert_edit', 'view', 'view'],
        ['POST', '#^/alerts/(?<id>\d+)/act$#', 'alert_act', 'view', 'view'],
        ['GET|POST', '#^/market$#', 'market', 'view', 'edit'],
        ['GET', '#^/market/(?<id>\d+)$#', 'market', 'view', 'edit'],
        ['GET', '#^/reports$#', 'reports', 'view', 'view'],
        ['GET|POST', '#^/add$#', 'add', 'edit', 'edit'],
        ['GET|POST', '#^/sites$#', 'sites', 'admin', 'admin'],
        ['GET|POST', '#^/settings$#', 'settings', 'admin', 'admin'],
        ['GET|POST', '#^/users$#', 'users', 'admin', 'admin'],
        ['GET|POST', '#^/account$#', 'account', 'view', 'view'],
        ['GET', '#^/backup/(?<file>[a-z0-9_.-]+\.sql\.gz)$#', 'backup', 'admin', 'admin'],
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

        // Database updates ship with the code: apply them on the first request after a deploy.
        if (Migrator::pending() && (new Migrator())->hasTable('users')) {
            Migrator::run();
        }

        $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        $path = $path === '' ? '/' : (strlen($path) > 1 ? rtrim($path, '/') : $path);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        foreach (self::ROUTES as [$methods, $pattern, $page, $getPerm, $postPerm]) {
            if (!preg_match($pattern, $path, $m)) {
                continue;
            }
            if (!in_array($method, explode('|', $methods), true)) {
                header('Allow: ' . str_replace('|', ', ', $methods));
                self::plain(405, 'Method not allowed', 'That address doesn’t accept this kind of request.');
            }
            Auth::startSession();
            $perm = $method === 'POST' ? $postPerm : $getPerm;
            if ($perm !== 'public') {
                Auth::requireLogin();
                Auth::require($perm);
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
        echo '<div class="empty"><h1 class="display">' . te('That page isn’t here.') . '</h1><p>' . te('The link may be old, or the record was deleted.') . '</p>'
            . '<p><a class="btn" href="/">' . te('Back to the dashboard') . '</a></p></div>';
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
        self::csp();
        if (Auth::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
        header('Cache-Control: no-store, private');
    }

    /**
     * Content-Security-Policy. No inline scripts or styles anywhere. Images: own files, map tiles, listing photos.
     * A page can widen it for itself (the 3D view needs Cesium ion, Google's 3D tiles, workers and WebAssembly).
     */
    public static function csp(bool $aerial = false, bool $nearby = false): void
    {
        $script = "'self'" . ($aerial ? " 'wasm-unsafe-eval'" : '');
        $connect = "'self'" . ($aerial ? ' https://api.cesium.com https://assets.ion.cesium.com https://tile.googleapis.com' : '')
            . ($nearby ? ' ' . implode(' ', array_map(static fn ($u) => 'https://' . parse_url($u, PHP_URL_HOST), Providers\Overpass::URLS)) : '');
        $style = "'self'" . ($aerial ? " 'unsafe-inline'" : ''); // Cesium's credit line sets inline styles; scripts stay locked
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https:; style-src $style; script-src $script; "
            . "connect-src $connect; worker-src 'self' blob:; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'", true);
        if ($aerial) {
            // Cesium ion checks a token's "Allowed URLs" against the page's origin, so send the origin
            // (never the path) on cross-site requests from this page. Everywhere else: same-origin only.
            header('Referrer-Policy: strict-origin-when-cross-origin', true);
        }
    }

    public static function plain(int $status, string $title, string $message): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="' . Locale::language() . '" dir="' . Locale::direction() . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . te($title) . '</title><link rel="stylesheet" href="/assets/app.css">'
            . '<body class="bare"><main class="bare-box"><h1 class="display">' . te($title) . '</h1><p>' . te($message) . '</p></main></body></html>';
        exit;
    }
}

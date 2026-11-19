<?php
declare(strict_types=1);

/* Small global helpers used by every page. Kept free of business logic. */

function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Translate interface copy. Use e() directly for user and provider data. */
function t(string $message, mixed ...$values): string
{
    $translation = App\Locale::text($message);
    return $values ? sprintf($translation, ...$values) : $translation;
}

function te(string $message, mixed ...$values): string
{
    return e(t($message, ...$values));
}

/** UTC timestamp for DB writes. The connection runs in UTC as well. */
function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function today(): string
{
    return gmdate('Y-m-d');
}

function url(string $path = '/', array $query = []): string
{
    $query = array_filter($query, static fn ($v) => $v !== null && $v !== '' && $v !== []);
    return $path . ($query ? '?' . http_build_query($query) : '');
}

function abs_url(string $path = '/', array $query = []): string
{
    return rtrim((string) App\Config::get('base_url', ''), '/') . url($path, $query);
}

function redirect(string $to): never
{
    // Only same-site redirects.
    if ($to === '' || $to[0] !== '/' || str_starts_with($to, '//')) {
        $to = '/';
    }
    header('Location: ' . $to, true, 303);
    exit;
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Trimmed string from GET/POST, length-capped. */
function input(string $key, string $default = '', int $max = 500): string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    if (!is_scalar($v)) {
        return $default;
    }
    return mb_substr(trim((string) $v), 0, $max);
}

/** Nullable positive number from a request field ("" → null). Accepts "$450,000" or "450k". */
function input_num(string $key, ?array $src = null): ?float
{
    $src ??= $_POST + $_GET;
    $v = $src[$key] ?? null;
    return is_scalar($v) ? parse_num((string) $v) : null;
}

function parse_num(string $v): ?float
{
    $v = strtolower(trim($v));
    if ($v === '') {
        return null;
    }
    $mult = 1;
    if (str_ends_with($v, 'k')) {
        $mult = 1000;
        $v = substr($v, 0, -1);
    } elseif (str_ends_with($v, 'm')) {
        $mult = 1000000;
        $v = substr($v, 0, -1);
    }
    $v = preg_replace('/[^0-9.\-]/', '', $v) ?? '';
    if ($v === '' || !is_numeric($v)) {
        return null;
    }
    return (float) $v * $mult;
}

function money(mixed $v, string $dash = '—'): string
{
    if ($v === null || $v === '' || (float) $v <= 0) {
        return $dash;
    }
    return '$' . number_format((float) $v);
}

/** $1.25M / $485K — for map pins and tight spots. */
function money_short(mixed $v): string
{
    $n = (float) $v;
    if ($n <= 0) {
        return '—';
    }
    if ($n >= 1000000) {
        return '$' . rtrim(rtrim(number_format($n / 1000000, 2), '0'), '.') . 'M';
    }
    if ($n >= 1000) {
        return '$' . number_format($n / 1000) . 'K';
    }
    return '$' . number_format($n);
}

function num(mixed $v, int $dec = 0, string $dash = '—'): string
{
    if ($v === null || $v === '') {
        return $dash;
    }
    return number_format((float) $v, $dec);
}

/** 2 → "2", 2.5 → "2.5" */
function trim_num(mixed $v, string $dash = '—'): string
{
    if ($v === null || $v === '') {
        return $dash;
    }
    return rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
}

/** 5125553695 → (512) 555-3695; anything else is returned as typed. */
function fmt_phone(?string $v): string
{
    $d = preg_replace('/\D/', '', (string) $v) ?? '';
    if (strlen($d) === 11 && $d[0] === '1') {
        $d = substr($d, 1);
    }
    return strlen($d) === 10 ? '(' . substr($d, 0, 3) . ') ' . substr($d, 3, 3) . '-' . substr($d, 6) : (string) $v;
}

function ppsf(mixed $price, mixed $sqft): ?float
{
    $p = (float) $price;
    $s = (float) $sqft;
    return ($p > 0 && $s > 0) ? $p / $s : null;
}

function pct(float $v, int $dec = 1): string
{
    return ($v > 0 ? '+' : ($v < 0 ? '−' : '')) . number_format(abs($v), $dec) . '%';
}

/** "Mar 4, 2026" from a DATE / DATETIME (stored UTC). */
function fmt_date(?string $v, string $format = 'M j, Y', string $dash = '—'): string
{
    if (!$v || str_starts_with($v, '0000')) {
        return $dash;
    }
    try {
        $hasTime = strlen($v) > 10;
        $d = new DateTimeImmutable($v, new DateTimeZone('UTC'));
        if ($hasTime) {
            $d = $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
        }
        return $d->format($format);
    } catch (Throwable) {
        return $dash;
    }
}

/** "3h ago", "yesterday", "12 days ago" */
function ago(?string $utc): string
{
    if (!$utc) {
        return t('never');
    }
    $t = strtotime($utc . ' UTC');
    if ($t === false) {
        return t('never');
    }
    $s = max(0, time() - $t);
    if ($s < 90) {
        return t('just now');
    }
    if ($s < 3600) {
        return t('%d min ago', (int) round($s / 60));
    }
    if ($s < 86400) {
        return t('%dh ago', (int) round($s / 3600));
    }
    if ($s < 172800) {
        return t('yesterday');
    }
    if ($s < 86400 * 60) {
        return t('%d days ago', (int) round($s / 86400));
    }
    return fmt_date($utc);
}

function days_since(?string $date): ?int
{
    if (!$date) {
        return null;
    }
    $t = strtotime(substr($date, 0, 10) . ' 00:00:00 UTC');
    return $t === false ? null : max(0, (int) floor((time() - $t) / 86400));
}

function plural(int|float $n, string $one, ?string $many = null): string
{
    $label = t($one);
    if ($label !== $one) {
        return number_format($n) . ' ' . $label;
    }
    return number_format($n) . ' ' . ($n == 1 ? $one : ($many ?? $one . 's'));
}

function icon(string $name, int $size = 16, string $class = ''): string
{
    static $set = null;
    $set ??= require APP_ROOT . '/src/icons.php';
    $inner = $set[$name] ?? '';
    return '<svg class="icon ' . e($class) . '" width="' . $size . '" height="' . $size
        . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $inner . '</svg>';
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(App\Auth::csrfToken()) . '">';
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Safe JSON for embedding inside <script type="application/json">. */
function json_embed(mixed $data): string
{
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null';
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function checked(mixed $v): string
{
    return $v ? ' checked' : '';
}

function array_get(array $a, string $path, mixed $default = null): mixed
{
    foreach (explode('.', $path) as $k) {
        if (!is_array($a) || !array_key_exists($k, $a)) {
            return $default;
        }
        $a = $a[$k];
    }
    return $a ?? $default;
}

function median(array $values): ?float
{
    $values = array_values(array_filter($values, static fn ($v) => $v !== null));
    $n = count($values);
    if ($n === 0) {
        return null;
    }
    sort($values, SORT_NUMERIC);
    $mid = intdiv($n, 2);
    return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
}

/** Linear-interpolated percentile, $p in 0..1. */
function percentile(array $values, float $p): ?float
{
    $values = array_values(array_filter($values, static fn ($v) => $v !== null));
    $n = count($values);
    if ($n === 0) {
        return null;
    }
    sort($values, SORT_NUMERIC);
    $pos = ($n - 1) * $p;
    $lo = (int) floor($pos);
    $hi = (int) ceil($pos);
    return $values[$lo] + ($values[$hi] - $values[$lo]) * ($pos - $lo);
}

/** Before streaming a file: drop the page buffer so large downloads go straight out. */
function end_buffers(): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

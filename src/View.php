<?php
declare(strict_types=1);

namespace App;

/** Page chrome and the handful of fragments shared by more than one page. */
final class View
{
    /** key => [href, label, permission] */
    private const NAV = [
        'dashboard' => ['/', 'Dashboard', 'view', 'layout-dashboard'],
        'search' => ['/search', 'Search', 'view', 'search'],
        'pipeline' => ['/pipeline', 'Favorites', 'view', 'star'],
        'alerts' => ['/alerts', 'Alerts', 'view', 'bell'],
        'reports' => ['/reports', 'Reports', 'view', 'file-spreadsheet'],
        'market' => ['/market', 'Market', 'view', 'trending-up'],
        'add' => ['/add', 'Add a home', 'edit', 'plus'],
    ];

    private static array $scripts = [];

    public static function script(string $src): void
    {
        self::$scripts[$src] = true;
    }

    public static function asset(string $path): string
    {
        $file = APP_ROOT . '/public' . $path;
        return $path . (is_file($file) ? '?v=' . substr(md5((string) filemtime($file)), 0, 8) : '');
    }

    public static function header(string $title, string $active = '', array $opt = []): void
    {
        $site = Auth::check() ? (string) Settings::get('site_name', 'Plat') : 'Plat';
        $bare = !empty($opt['bare']);
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!doctype html>
<html lang="<?= Locale::language() ?>" dir="<?= Locale::direction() ?>"<?= self::browserLabels() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
<title><?= te($title) ?> · <?= e($site) ?></title>
<?php if (Locale::language() === 'fa'): ?>
<link rel="preload" href="/assets/fonts/vazirmatn-medium.woff2" as="font" type="font/woff2" crossorigin>
<?php else: ?>
<link rel="preload" href="/assets/fonts/public-sans-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/newsreader-latin-opsz-normal.woff2" as="font" type="font/woff2" crossorigin>
<?php endif; ?>
<?php if (!empty($opt['map'])): ?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<?php endif; ?>
<link rel="stylesheet" href="<?= e(self::asset('/assets/app.css')) ?>">
<link rel="stylesheet" href="<?= e(self::asset('/assets/glass.css')) ?>" media="screen">
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
</head>
<body class="<?= $bare ? 'bare' : '' ?><?= !empty($opt['wide']) ? ' wide' : '' ?><?= !empty($opt['body']) ? ' ' . e($opt['body']) : '' ?>">
<a class="skip" href="#main"><?= te('Skip to content') ?></a>
<?php if (!$bare): ?>
<header class="top">
  <div class="top-in">
    <button class="menu-btn" type="button" aria-controls="main-nav" aria-expanded="false" data-menu>
      <span class="menu-open"><?= icon('menu', 22) ?></span><span class="menu-close"><?= icon('x', 22) ?></span><span class="sr"><?= te('Menu') ?></span>
    </button>
    <a class="brand" href="/" aria-label="<?= e($site) ?> <?= te('— dashboard') ?>"><?= e($site) ?></a>
    <nav class="nav" id="main-nav" aria-label="<?= te('Main') ?>">
      <?php foreach (self::NAV as $key => [$href, $label, $perm, $ico]): if (!Auth::can($perm)) { continue; } ?>
      <a href="<?= $href ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><span class="nav-ico" aria-hidden="true"><?= icon($ico, 18) ?></span><?= te($label) ?></a>
      <?php endforeach; ?>
      <div class="nav-more">
        <a href="/account"<?= $active === 'account' ? ' aria-current="page"' : '' ?>><span class="nav-ico" aria-hidden="true"><?= icon('user', 18) ?></span><?= te('My account') ?><span class="nav-who"><?= e(Auth::name()) ?></span></a>
        <?php if (Auth::can('admin')): ?>
        <a href="/settings"<?= in_array($active, ['settings', 'users', 'sites'], true) ? ' aria-current="page"' : '' ?>><span class="nav-ico" aria-hidden="true"><?= icon('settings', 18) ?></span><?= te('Settings, team & sites') ?></a>
        <div class="nav-quota"><?= self::quotaChip() ?></div>
        <?php endif; ?>
        <form method="post" action="/logout">
          <?= csrf_field() ?>
          <button type="submit"><span class="nav-ico" aria-hidden="true"><?= icon('log-out', 18) ?></span><?= te('Sign out') ?></button>
        </form>
      </div>
    </nav>
    <div class="top-end">
      <?php self::languageSwitch(); ?>
      <?= Auth::can('admin') ? self::quotaChip() : '' ?>
      <?php if (Auth::can('admin')): ?>
      <a class="top-link<?= in_array($active, ['settings', 'users', 'sites'], true) ? ' is-on' : '' ?>" href="/settings" title="<?= te('Settings, team and sites') ?>"><?= icon('settings', 17) ?><span class="sr"><?= te('Settings') ?></span></a>
      <?php endif; ?>
      <a class="who<?= $active === 'account' ? ' is-on' : '' ?>" href="/account" title="<?= te('My account') ?>"><span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(Auth::name() ?: '?', 0, 1))) ?></span><span class="who-name"><?= e(Auth::name()) ?></span></a>
      <form method="post" action="/logout" class="inline">
        <?= csrf_field() ?>
        <button class="top-link" type="submit" title="<?= te('Sign out') ?>"><?= icon('log-out', 17) ?><span class="sr"><?= te('Sign out') ?></span></button>
      </form>
    </div>
  </div>
</header>
<?php elseif (($opt['body'] ?? '') !== 'welcome-body'): ?>
<div class="bare-language"><?php self::languageSwitch(); ?></div>
<?php endif; ?>
<main id="main" class="<?= e($opt['main'] ?? ($bare ? 'bare-main' : 'page')) ?>">
<?php
        $flash = flash();
        if ($flash) {
            echo '<div class="flash flash-' . e($flash['type']) . '" role="status">' . te($flash['msg']) . '</div>';
        }
    }

    /** Text needed after the browser makes an asynchronous request. */
    private static function browserLabels(): string
    {
        if (Locale::language() === 'en') {
            return '';
        }
        $messages = [
            'Copied',
            'Listing details found — updating the page…',
            'No further details from the listing.',
            'Couldn’t fetch the listing details. Reload to try again.',
            'OpenStreetMap didn’t answer. Reload to try again.',
            'From OpenStreetMap. Distance is a straight line; it says nothing about quality.',
            'FEMA didn’t answer. Reload to try again.',
            'FEMA zone',
            'Zone',
            'No census figures for this spot.',
            'Median household income',
            'Median home value',
            'Median rent',
            'Couldn’t load this. Reload to try again.',
            'Owner-occupied',
            'Vacant homes',
            'Typical year built',
            'Residents',
            'Income, home values and rents for this neighbourhood need a free Census key.',
            'Add it in Settings',
            'Pause',
            'Orbit',
            'Could not load the 3D viewer.',
            'Loading the 3D viewer…',
            'Show original',
            'Hide original',
            'Machine translation of the listing text.',
            'Couldn’t translate this.',
            'Zoom in',
            'Zoom out',
        ];
        $labels = array_combine($messages, array_map('t', $messages));
        return ' data-ui-labels="' . e(json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) . '"';
    }

    public static function languageSwitch(): void
    {
        $persian = Locale::language() === 'fa';
        ?>
<form class="language-switch" method="post" action="/language">
  <?= csrf_field() ?>
  <input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI'] ?? '/') ?>">
  <button class="btn btn-ghost btn-sm" type="submit" name="language" value="<?= $persian ? 'en' : 'fa' ?>"
          lang="<?= $persian ? 'en' : 'fa' ?>" dir="<?= $persian ? 'ltr' : 'rtl' ?>">
    <?= $persian ? 'English' : 'فارسی' ?>
  </button>
</form>
<?php
    }

    public static function footer(): void
    {
        ?>
</main>
<?php foreach (array_keys(self::$scripts) as $src): ?>
<script src="<?= e(self::asset($src)) ?>" defer></script>
<?php endforeach; ?>
<script src="<?= e(self::asset('/assets/app.js')) ?>" defer></script>
</body>
</html>
<?php
    }

    /**
     * A hand-drawn empty-state sketch (pages/partials/sketches, drawn by bin/make_sketches.py
     * in the style of the "Ian Xiaohei Illustrations" skill). Inline, so the Persian labels use the page's font.
     */
    public static function sketch(string $name): string
    {
        if (!preg_match('/^[a-z-]+$/', $name)) {
            return '';
        }
        $file = APP_ROOT . '/pages/partials/sketches/' . $name . '.svg';
        if (!is_file($file)) {
            return '';
        }
        $svg = str_replace(['id="t"', 'aria-labelledby="t"'], ['id="sk-' . $name . '"', 'aria-labelledby="sk-' . $name . '"'], (string) file_get_contents($file));
        return '<figure class="sketch" lang="fa">' . $svg . '</figure>';
    }

    /** "RentCast 13 / 50" with a thin bar — the one number worth watching on a free plan. */
    public static function quotaChip(): string
    {
        $out = '';
        foreach (Quota::PROVIDERS as $id => $label) {
            if (!Settings::has($id . '_key')) {
                continue;
            }
            $limit = Quota::limit($id);
            $used = min($limit, Quota::used($id));
            $share = $limit > 0 ? (int) round($used / $limit * 100) : 100;
            $state = $share >= 100 ? 'out' : ($share >= 80 ? 'low' : 'ok');
            $out .= '<a class="quota quota-' . $state . '" href="/settings#usage" title="' . e(t($label) . ': ' . t('%1$d of %2$d requests used this month. Resets in %3$s.', $used, $limit, plural(Quota::resetsIn(), 'day'))) . '">'
                . '<span class="quota-label">' . te($label) . '</span>'
                . '<span class="quota-num">' . $used . '<i>/' . $limit . '</i></span>'
                . '<svg class="quota-bar" viewBox="0 0 100 3" preserveAspectRatio="none" aria-hidden="true"><rect class="t" width="100" height="3"/><rect class="f" width="' . $share . '" height="3"/></svg>'
                . '</a>';
        }
        if ($out === '') {
            $out = '<a class="quota quota-none" href="/settings#sources">' . te('Connect a data source') . '</a>';
        }
        return $out;
    }

    public static function statusTag(array $p, string $mode = 'sale'): string
    {
        if ($mode === 'sold') {
            return '<span class="tag tag-sold">' . te('Sold') . '</span>';
        }
        return match ($p['status'] ?? '') {
            'for_sale' => (($p['listing_type'] ?? '') && $p['listing_type'] !== 'Standard')
                ? '<span class="tag tag-warn">' . te($p['listing_type']) . '</span>'
                : '<span class="tag tag-live">' . te('For sale') . '</span>',
            'pending' => '<span class="tag tag-warn">' . te('Pending') . '</span>',
            'sold' => '<span class="tag tag-sold">' . te('Sold') . '</span>',
            default => '<span class="tag">' . te('Off market') . '</span>',
        };
    }

    /** A compact horizontal bar: where $value sits between $low and $high. */
    public static function rangeBar(float $low, float $high, float $value): string
    {
        $span = max(1.0, $high - $low);
        $pos = max(0, min(100, ($value - $low) / $span * 100));
        return '<svg class="range" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">'
            . '<rect class="t" y="4" width="100" height="2"/><rect class="m" x="' . round($pos, 1) . '" y="0" width="1.4" height="10"/></svg>';
    }

    public static function pager(int $total, int $perPage, int $page, array $query): string
    {
        $pages = (int) ceil($total / $perPage);
        if ($pages <= 1) {
            return '';
        }
        $link = static fn (int $p, string $label, bool $on = true) => $on
            ? '<a class="btn btn-ghost" href="' . e(url('/search', $query + ['page' => $p > 1 ? $p : null])) . '">' . $label . '</a>'
            : '<span class="btn btn-ghost is-off">' . $label . '</span>';
        return '<nav class="pager" aria-label="' . te('Pages') . '">' . $link($page - 1, te('Previous'), $page > 1)
            . '<span class="pager-at">' . te('Page %1$d of %2$d', $page, $pages) . '</span>'
            . $link($page + 1, te('Next'), $page < $pages) . '</nav>';
    }
}

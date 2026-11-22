<?php
declare(strict_types=1);

use App\Address;
use App\Db;
use App\PropertyRepo;
use App\Services\Bulk;
use App\Services\Search;

/* Delete the ticked items of a list, or all of it. POST type, ids[] | scope=all, back. CSRF-checked by the Kernel. */

$type = input('type');
$back = (string) ($_POST['back'] ?? '/');
if (!str_starts_with($back, '/') || str_starts_with($back, '//') || preg_match('/[\x00-\x20\x7f\\\\]/', $back)) {
    $back = '/';
}
if (!in_array($type, Bulk::TYPES, true) || !Bulk::allowed($type)) {
    flash(t('You can’t delete these.'), 'err');
    redirect($back);
}
$all = input('scope') === 'all';
$ids = array_map('intval', (array) ($_POST['ids'] ?? []));
$allIds = [];
if ($all && $type === 'homes') {
    // "All" in a result list means every home those results hold, not every home on file.
    $raw = json_decode((string) ($_POST['params'] ?? ''), true);
    $f = Search::fromRequest(is_array($raw) ? $raw : []);
    $loc = Search::location($f);
    if ($loc && $loc['type'] !== 'address') {
        $allIds = array_map('intval', array_column(Search::run($f, ['fetch' => false, 'limit' => 5000])['rows'], 'id'));
    }
    if (!$allIds) {
        flash(t('No homes match this search.'), 'warn');
        redirect($back);
    }
} elseif ($all && $type === 'users') {
    $allIds = array_map('intval', array_column(Db::all('SELECT id FROM users'), 'id'));
}
if (!$all && !$ids) {
    flash(t('Tick at least one first.'), 'warn');
    redirect($back);
}
$r = Bulk::delete($type, $ids, $all, $allIds);
[$one, $many] = ['users' => ['team member', 'team members'], 'homes' => ['home', 'homes'], 'pipeline' => ['favorite home', 'favorite homes'],
    'alerts' => ['alert', 'alerts'], 'profiles' => ['search profile', 'search profiles']][$type];
flash(t('Deleted: %s.', plural($r['count'], $one, $many)) . ($r['skipped'] ? ' ' . t('Kept %d (you, or the last admin).', $r['skipped']) : ''), $r['count'] ? 'ok' : 'warn');
redirect($back);

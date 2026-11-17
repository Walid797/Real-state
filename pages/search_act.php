<?php
declare(strict_types=1);

use App\Address;
use App\Db;
use App\Services\Search;

$do = input('do');

if ($do === 'unsave') {
    Db::run('DELETE FROM saved_searches WHERE id = ?', [(int) input('id')]);
    flash('Saved search removed.');
    redirect('/');
}

$raw = json_decode((string) ($_POST['params'] ?? ''), true);
$f = Search::fromRequest(is_array($raw) ? $raw : []);
$query = Search::compact($f);
$back = url('/', $query);
$loc = Address::parseLocation($f['q']);
if (!$loc || $loc['type'] === 'address') {
    redirect('/');
}

if ($do === 'save') {
    $name = $loc['label'] . ($f['mode'] === 'sold' ? ' — sold' : '');
    $json = json_encode($query);
    $existing = Db::val('SELECT id FROM saved_searches WHERE params = ?', [$json]);
    if ($existing) {
        flash('That search is already saved.');
    } else {
        Db::insert('saved_searches', ['name' => mb_substr($name, 0, 120), 'params' => $json, 'created_at' => now(), 'last_run_at' => now()]);
        flash('Saved. It’s on the search page whenever you want it.');
    }
    redirect($back);
}

if ($do === 'refresh') {
    $r = Search::ensureSnapshot($loc, $f, 'manual', true);
    if ($r['fetched']) {
        flash('Pulled a fresh copy of ' . $loc['label'] . '.' . ($r['notice'] ? ' ' . $r['notice'] : ''));
    } else {
        flash($r['notice'] ?: 'Could not refresh right now.', 'warn');
    }
    redirect($back);
}

redirect('/');

<?php
declare(strict_types=1);

use App\Address;
use App\Auth;
use App\Db;
use App\Services\Search;

$do = input('do');
$me = Auth::userId();

if ($do === 'unsave') {
    Db::run('DELETE FROM saved_searches WHERE id = ? AND user_id = ?', [(int) input('id'), $me]);
    flash('Profile deleted.');
    redirect('/search');
}

$raw = json_decode((string) ($_POST['params'] ?? ''), true);
$f = Search::fromRequest(is_array($raw) ? $raw : []);
$query = Search::compact($f);
$back = url('/search', $query);
$loc = Address::parseLocation($f['q']);
if (!$loc || $loc['type'] === 'address') {
    redirect('/search');
}

if ($do === 'save') {
    $name = mb_substr(input('name', '', 120) ?: Search::describe($f), 0, 120);
    $json = json_encode($query);
    if (Db::val('SELECT id FROM saved_searches WHERE params = ? AND user_id = ?', [$json, $me])) {
        flash('You already have that profile.');
    } else {
        Db::insert('saved_searches', ['user_id' => $me, 'name' => $name, 'params' => $json, 'created_at' => now(), 'last_run_at' => now()]);
        flash('Saved as a profile. Find it under Search, or turn it into an alert.');
    }
    redirect($back);
}

if ($do === 'pull' || $do === 'refresh') {
    Auth::require('edit');
    @set_time_limit(180);
    $r = Search::run($f, $do === 'pull' ? ['confirm' => true] : ['force' => true]);
    if ($r['fetched']) {
        flash('Pulled ' . $loc['label'] . '.' . ($r['notice'] ? ' ' . $r['notice'] : ''));
    } else {
        flash($r['notice'] ?: 'Nothing new was pulled — it was already on file.', $r['notice'] ? 'warn' : 'ok');
    }
    redirect($back);
}

redirect('/search');

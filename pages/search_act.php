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

if ($do === 'area_sales') {
    // One request: the last 12 months of recorded sales for the whole area — real comparables for every listing in it.
    Auth::require('edit');
    @set_time_limit(180);
    if ($loc['type'] === 'zip' && App\Services\CountyRecords::zipSource($loc['zip']) !== null) {
        // The county publishes its sales: free, and no request used.
        $r = App\Services\CountyRecords::areaSales($loc['zip'], 150);
        $ids = array_map('intval', array_column(App\PropertyRepo::search(['loc' => $loc, 'mode' => 'sale', 'limit' => 500])['rows'], 'id'));
        if ($r['ok'] && $ids) {
            App\Services\Score::refreshMany(Search::scoreFirst($ids, 500), 6);
        }
        flash($r['message'] . ($r['ok'] ? ' ARV and profit now come from real sales; the rest of the area is re-scored within minutes.' : ''), $r['ok'] && $r['sales'] ? 'ok' : 'warn');
        redirect($back);
    }
    $fs = Search::fromRequest(['q' => $f['q'], 'mode' => 'sold', 'sold_days' => 365, 'types' => $f['types']]);
    $r = Search::run($fs, ['confirm' => true]);
    $sold = App\PropertyRepo::search(['loc' => $loc, 'mode' => 'sold', 'sold_days' => 365, 'limit' => 1])['total'];
    // Re-score the area's listings against the new sales: the cheapest per sqft now, the rest by the scheduler.
    $ids = array_map('intval', array_column(App\PropertyRepo::search(['loc' => $loc, 'mode' => 'sale', 'limit' => 500])['rows'], 'id'));
    if ($ids) {
        Db::run('UPDATE properties SET scored_at = NULL WHERE id IN (' . implode(',', $ids) . ')');
        App\Services\Score::refreshMany(Search::scoreFirst($ids, 500), 6);
    }
    flash($r['fetched'] || $sold
        ? plural($sold, 'recorded sale') . ' from the last 12 months on file for ' . $loc['label'] . '. ARV and profit now come from real sales; the rest of the area is re-scored within minutes.'
        : ($r['notice'] ?: 'No recorded sales came back. This county may not publish sale prices.'), $sold ? 'ok' : 'warn');
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

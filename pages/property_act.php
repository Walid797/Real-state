<?php
declare(strict_types=1);

use App\Address;
use App\Db;
use App\Geo;
use App\PropertyRepo;
use App\Providers\OpenWebNinja;
use App\Providers\RentCast;

$id = (int) $params['id'];
$p = PropertyRepo::find($id);
if (!$p) {
    redirect('/');
}
$do = input('do');
$back = input('back', '', 600);
$home = '/property/' . $id;
$full = Address::full($p);

switch ($do) {
    case 'watch':
        Db::run('INSERT IGNORE INTO watchlist (property_id, stage, created_at, updated_at) VALUES (?, ?, ?, ?)', [$id, 'considering', now(), now()]);
        flash('Added to your watchlist.');
        break;

    case 'unwatch':
        Db::run('DELETE FROM watchlist WHERE property_id = ?', [$id]);
        flash('Removed from your watchlist.');
        break;

    case 'stage':
        $stage = input('stage');
        if (in_array($stage, ['considering', 'visited', 'offer', 'passed', 'bought'], true)) {
            Db::run('UPDATE watchlist SET stage = ?, updated_at = ? WHERE property_id = ?', [$stage, now(), $id]);
        }
        $back = $back ?: '/watchlist';
        break;

    case 'note':
        Db::run('UPDATE properties SET notes = ?, updated_at = ? WHERE id = ?', [mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 10000) ?: null, now(), $id]);
        flash('Note saved.');
        $home .= '#notes';
        break;

    case 'delete':
        Db::run('DELETE FROM properties WHERE id = ?', [$id]);
        flash('Deleted ' . $p['address'] . '.');
        redirect('/');

    case 'record':
        $r = RentCast::record($full);
        if (!$r['ok']) {
            flash($r['error'], 'err');
        } elseif (!$r['item']) {
            flash('RentCast has no public record for this address.', 'warn');
        } else {
            // Keep our address spelling so the record merges into this row rather than creating a twin.
            PropertyRepo::upsert(['address' => $p['address'], 'city' => $p['city'], 'state' => $p['state'], 'zip' => $p['zip']] + $r['item'], 'rentcast');
            flash('Public record added.');
        }
        break;

    case 'value':
        $r = RentCast::value($p);
        if (!$r['ok']) {
            flash($r['error'], 'err');
            break;
        }
        $list = [];
        foreach ($r['comps'] as $c) {
            try {
                $cid = PropertyRepo::upsert($c, 'rentcast')['id'];
            } catch (InvalidArgumentException) {
                $cid = null;
            }
            $list[] = ['id' => $cid, 'address' => $c['address'], 'price' => $c['list_price'] ?? null, 'status' => $c['status'] ?? null,
                'beds' => $c['beds'] ?? null, 'baths' => $c['baths'] ?? null, 'sqft' => $c['sqft'] ?? null,
                'distance' => $c['distance'] ?? null, 'correlation' => $c['correlation'] ?? null];
        }
        PropertyRepo::upsert([
            'address' => $p['address'], 'city' => $p['city'], 'state' => $p['state'], 'zip' => $p['zip'],
            'est_value' => $r['value'], 'est_value_low' => $r['low'], 'est_value_high' => $r['high'],
            'est_source' => 'rentcast', 'est_at' => now(), 'details' => ['rc_comps' => $list],
        ], $p['source']);
        flash('Estimate: ' . money($r['value']) . ', based on ' . plural(count($list), 'comparable listing') . '.');
        break;

    case 'rent':
        $r = RentCast::rent($p);
        if (!$r['ok']) {
            flash($r['error'], 'err');
        } else {
            Db::run('UPDATE properties SET est_rent = ?, est_at = COALESCE(est_at, ?), est_source = COALESCE(est_source, ?), updated_at = ? WHERE id = ?',
                [$r['rent'], now(), 'rentcast', now(), $id]);
            flash('Rent estimate: ' . money($r['rent']) . ' a month (' . money($r['low']) . '–' . money($r['high']) . ').');
        }
        break;

    case 'sales':
        if (!Geo::valid($p['lat'], $p['lng'])) {
            flash('This address has no map position yet.', 'warn');
            break;
        }
        $r = RentCast::soldRecords(['type' => 'point', 'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'radius' => 1.5], 365, [], 'manual');
        if (!$r['ok']) {
            flash($r['error'], 'err');
            break;
        }
        $n = 0;
        Db::tx(static function () use ($r, &$n) {
            foreach ($r['items'] as $item) {
                try {
                    PropertyRepo::upsert($item, 'rentcast');
                    $n++;
                } catch (InvalidArgumentException) {
                }
            }
        });
        flash($n ? plural($n, 'sale') . ' from the last 12 months within 1.5 miles added.' : 'No recorded sales nearby in the last 12 months. This county may not publish sale prices.', $n ? 'ok' : 'warn');
        $home .= '#comps';
        break;

    case 'zillow':
        $r = OpenWebNinja::details($full);
        if (!$r['ok']) {
            flash($r['error'], 'err');
            break;
        }
        if (!$r['item']) {
            flash('No Zillow page was found for this address.', 'warn');
            break;
        }
        PropertyRepo::upsert(['address' => $p['address'], 'city' => $p['city'], 'state' => $p['state'], 'zip' => $p['zip']] + $r['item'], 'openwebninja');
        $n = 0;
        foreach ($r['comps'] as $c) {
            try {
                PropertyRepo::upsert($c, 'openwebninja');
                $n++;
            } catch (InvalidArgumentException) {
            }
        }
        flash('Listing details added' . ($n ? ', plus ' . plural($n, 'nearby sale') : '') . '.');
        break;
}

redirect($back !== '' && str_starts_with($back, '/') ? $back : $home);

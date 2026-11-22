<?php
declare(strict_types=1);

use App\Address;
use App\Auth;
use App\Db;
use App\Services\Activity;
use App\Services\Condition;
use App\Services\Deal;
use App\Services\Pipeline;
use App\Services\Score;
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
    case 'regional':
        @set_time_limit(75);
        $regional = App\Services\RegionalDemand::for($p, true);
        flash($regional['county_data_complete']
            ? 'Regional evidence loaded. Review dates, coverage and remaining checks below.'
            : 'Some regional sources are unavailable. Available figures are shown with their dates. Try again later.', $regional['county_data_complete'] ? 'ok' : 'warn');
        $home .= '#regional-demand';
        break;

    case 'watch':
        Pipeline::add($id);
        $auto = App\Services\ListingDetails::wanted($p) ? App\Services\ListingDetails::fetch($p, 'auto') : null;
        flash('Added to the team pipeline.' . ($auto && $auto['status'] === 'fetched' ? ' ' . $auto['message'] : ''));
        break;

    case 'unwatch':
        Pipeline::remove($id);
        flash('Removed from the pipeline.');
        break;

    case 'stage':
        Pipeline::setStage($id, input('stage'));
        $back = $back ?: '';
        break;

    case 'owner':
        $owner = (int) input('owner_id');
        $valid = $owner ? Db::val("SELECT id FROM users WHERE id = ? AND is_active = 1 AND role <> 'viewer'", [$owner]) : null;
        Pipeline::setOwner($id, $valid ? $owner : null);
        break;

    case 'note':
        $body = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 10000);
        if ($body !== '') {
            Db::insert('notes', ['property_id' => $id, 'user_id' => Auth::userId(), 'body' => $body, 'created_at' => now()]);
            Activity::log('note', $id, mb_strimwidth($body, 0, 120, '…'));
        }
        $home .= '#notes';
        break;

    case 'note_delete':
        $note = Db::one('SELECT * FROM notes WHERE id = ? AND property_id = ?', [(int) input('note_id'), $id]);
        if ($note && ((int) $note['user_id'] === Auth::userId() || Auth::can('admin'))) {
            Db::run('DELETE FROM notes WHERE id = ?', [$note['id']]);
        }
        $home .= '#notes';
        break;

    case 'deal':
        Deal::save($id, $_POST);
        Score::refresh($id);
        flash('Numbers saved and the analysis recalculated.');
        $home .= '#deal';
        break;

    case 'condition':
        if (input('reset_auto') === '1') {
            Db::run("UPDATE property_condition SET source = 'auto' WHERE property_id = ?", [$id]);
            Activity::log('condition', $id, 'Back to reading the listing text');
        } else {
            Condition::setByHand($id, $_POST);
        }
        Score::refresh($id);
        flash('Condition saved; renovation estimate and score updated.');
        $home .= '#condition';
        break;

    case 'data_status':
        $ds = input('data_status');
        if (in_array($ds, ['full', 'limited', 'link_only', 'link_dead', 'removed'], true)) {
            Db::run('UPDATE properties SET data_status = ? WHERE id = ?', [$ds, $id]);
            Activity::log('edit', $id, 'Listing data marked “' . str_replace('_', ' ', $ds) . '”');
        }
        break;

    case 'delete':
        Auth::require('admin');
        Db::run('DELETE FROM properties WHERE id = ?', [$id]);
        Activity::log('edit', null, 'Deleted ' . $p['address']);
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
        $r = App\Services\ListingDetails::fetch($p, 'manual');
        flash($r['message'], ['fetched' => 'ok', 'none' => 'warn', 'error' => 'err'][$r['status']]);
        break;
}

if (in_array($do, ['record', 'value', 'rent', 'sales'], true)) {
    Activity::log('pull', $id, ['record' => 'Public record', 'value' => 'Value estimate', 'rent' => 'Rent estimate', 'sales' => 'Nearby sales'][$do]);
    Score::refresh($id);
}

redirect($back !== '' && str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : $home);

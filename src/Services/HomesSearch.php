<?php
declare(strict_types=1);

namespace App\Services;

use App\Address;
use App\Geo;
use App\PropertyRepo;
use App\Providers\HomesCom;

/** Adds a bounded set of verified Homes.com observations alongside the primary search provider. */
final class HomesSearch
{
    public static function run(array $loc, string $ctx = 'manual'): ?array
    {
        if (!HomesCom::enabled() || $ctx === 'cron') {
            return null;
        }
        $started = microtime(true);
        $scopes = self::scopes($loc);
        $out = ['count' => 0, 'added' => 0, 'checked' => 0, 'available' => false, 'cached' => true,
            'limited' => false, 'errors' => [], 'observed_at' => null, 'retry_at'=>null, 'source_pages'=>[]];
        $seen = $score = [];
        foreach (array_slice($scopes, 0, 3) as $scope) {
            $remaining = 12 - (microtime(true) - $started);
            if ($remaining < 1) {
                break;
            }
            $r = HomesCom::search($scope, $ctx, $remaining);
            $out['checked']++;
            $out['cached'] = $out['cached'] && $r['cached'];
            if (!$r['ok']) {
                $out['errors'][] = $r['error'];
                $page = $r['url'] ?? HomesCom::browserPage($scope);
                if ($page) $out['source_pages'][$page] = $scope['label'];
                if (!empty($r['retry_at'])) $out['retry_at']=max($out['retry_at']??'', $r['retry_at']);
                continue;
            }
            $out['available'] = true;
            $out['observed_at'] = $out['observed_at'] === null ? $r['observed_at'] : min($out['observed_at'], $r['observed_at']);
            foreach ($r['items'] as $item) {
                if (!self::matches($item, $loc)) {
                    continue;
                }
                $key = Address::hash($item['address'], $item['city'], $item['state'], $item['zip']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                try {
                    $saved = self::save($item);
                } catch (\InvalidArgumentException) {
                    continue;
                }
                $out['count']++;
                $out['added'] += $saved['created'] ? 1 : 0;
                if ($saved['changed']) {
                    $score[] = $saved['id'];
                }
            }
        }
        $out['limited'] = $out['checked'] < count($scopes);
        $out['errors'] = array_values(array_unique(array_filter($out['errors'])));
        if ($score) {
            Score::refreshMany(Search::scoreFirst($score, 60), 2);
        }
        return $out;
    }

    public static function scopes(array $loc): array
    {
        $out = [];
        foreach (Address::areas($loc) as $area) {
            if ($area['type'] === 'building') {
                $buildings = $area['buildings'] ?? [];
                foreach ($buildings as $building) {
                    if (!empty($building['zip'])) {
                        $zip = $building['zip'];
                        $out['zip:' . $zip] = ['type' => 'zip', 'zip' => $zip, 'label' => $zip, 'key' => 'zip:' . $zip];
                    }
                }
            } elseif ($area['type'] === 'address' || $area['type'] === 'street') {
                $scope = !empty($area['zip']) ? Address::parseLocation($area['zip'])
                    : (($area['city'] ?? '') !== '' ? Address::parseLocation($area['city'] . ', ' . $area['state']) : null);
                if ($scope && in_array($scope['type'], ['zip', 'city'], true)) {
                    $out[$scope['key']] = $scope;
                }
            } elseif (in_array($area['type'], ['zip','city','county','state'], true)) {
                $out[$area['key']] = $area;
            }
        }
        return array_values($out);
    }

    public static function matches(array $item, array $loc): bool
    {
        foreach (Address::areas($loc) as $area) {
            $match = match ($area['type']) {
                'address' => Address::sameProperty($item, $area) || (empty($area['partial']) && Address::sameProperty($item, $area, true) && !preg_match('/\b(?:apt|apartment|unit|suite|ste)\b|#/i', (string) $area['address'])),
                'street' => $item['state'] === $area['state'] && (($area['city'] ?? '') === '' || strcasecmp($item['city'], $area['city']) === 0)
                    && preg_match('/' . str_replace('/', '\/', Address::streetRegex($area['street'])) . '/i', strtolower((string) $item['address'])) === 1,
                'building' => BuildingSearch::matches($item, $area),
                'zip' => $item['zip'] === $area['zip'],
                'city' => $item['state'] === $area['state'] && strcasecmp($item['city'], $area['city']) === 0,
                'county' => $item['state'] === $area['state'] && strcasecmp((string) ($item['county'] ?? ''), $area['county']) === 0,
                'state' => $item['state'] === $area['state'], default => false,
            };
            if ($match) {
                return true;
            }
        }
        return false;
    }

    /** Keep separate provider quotes. Fill blanks without overwriting RentCast/Zillow facts. */
    public static function save(array $item): array
    {
        $hash = Address::hash($item['address'],$item['city'],$item['state'],$item['zip']);
        if (($item['details']['homes']['address_hash']??'') !== $hash) {
            throw new \InvalidArgumentException('Homes.com observation belongs to a different address or unit.');
        }
        $id = PropertyRepo::findAddress($item);
        $existing = $id ? PropertyRepo::find($id) : null;
        $evidence = $item['details']['homes'];
        if ($existing) {
            $prior = $existing['details']['homes'] ?? [];
            $newPhotos = ($evidence['observed_at']??'') >= ($prior['observed_at']??'') && !empty($evidence['photos'])
                && (($evidence['photos']??[]) !== ($prior['photos']??[]) || empty($prior['address_hash']));
            $fillFields = ['property_type','beds','baths','sqft','description','photo_url','agent_name','office_name','county'];
            $missing = false;
            foreach ($fillFields as $field) {
                if (isset($item[$field]) && $item[$field] !== '' && ($existing[$field] === null || $existing[$field] === '')) {
                    $missing = true;
                }
            }
            if (($prior['observed_at'] ?? '') >= $evidence['observed_at'] && ($prior['url'] ?? '') === $evidence['url'] && !$missing && !$newPhotos) {
                return ['id' => $id, 'created' => false, 'changed' => false];
            }
            $older = ($prior['observed_at'] ?? '') > $evidence['observed_at'];
            if ($older) {
                $evidence = $prior;
            }
            $data = array_intersect_key($existing, array_flip(['address','city','state','zip']));
            foreach (['city','state'] as $field) {
                if (!$data[$field] && !empty($item[$field])) {
                    $data[$field] = $item[$field];
                }
            }
            $evidence['address_hash'] = Address::hash($data['address'],$data['city'],$data['state'],$data['zip']);
            $data['details'] = ['homes' => $evidence];
            $ownsPrimary = !$older && $existing['source'] === HomesCom::ID && empty($existing['details']['zillow']) && !in_array($existing['data_status'], ['removed','link_dead'], true);
            foreach ($fillFields as $field) {
                if (isset($item[$field]) && ($ownsPrimary || $existing[$field] === null || $existing[$field] === '')) {
                    $data[$field] = $item[$field];
                }
            }
            if (!Geo::valid($existing['lat'], $existing['lng']) && Geo::valid($item['lat'] ?? null, $item['lng'] ?? null)) {
                $data['lat'] = $item['lat'];
                $data['lng'] = $item['lng'];
            }
            if ($ownsPrimary || (!$existing['list_price'] && $existing['source'] === 'manual' && !in_array($existing['data_status'], ['removed','link_dead'], true))) {
                foreach (['status','list_price','source_id','source_url','source_site','data_status'] as $field) {
                    $data[$field] = $item[$field];
                }
            }
            $origin = $existing['source'] === 'manual' && $existing['list_price'] ? 'manual' : HomesCom::ID;
        } else {
            $data = $item;
            $origin = HomesCom::ID;
        }
        $r = PropertyRepo::upsert($data, $origin);
        return ['id' => $r['id'], 'created' => $r['created'], 'changed' => true];
    }
}

<?php
declare(strict_types=1);

namespace App\Services;

use App\Address;
use App\Cache;
use App\Db;
use App\Http;

/** Named-building lookup. Map addresses are location evidence, never apartment listing facts. */
final class BuildingSearch
{
    public static function location(string $query): ?array
    {
        $query = trim(preg_replace('/\s+/', ' ', str_replace('،', ',', $query)) ?? '');
        $parts = array_map('trim', explode(',', $query));
        $name = array_shift($parts);
        if (!$name || mb_strlen($name) < 3 || !preg_match('/[a-z]/i', $name)) {
            return null;
        }
        $state = Address::homeState();
        $city = $zip = '';
        if ($parts) {
            $tail = implode(', ', $parts);
            if (preg_match('/^(?:(.+?)[,\s]+)?([A-Za-z]{2})(?:\s+(\d{5}))?$/', $tail, $m) && ($st = Address::stateAbbr($m[2]))) {
                $state = $st;
                $city = Address::titleCity(trim($m[1], ', '));
                $zip = $m[3] ?? '';
            } elseif (($area = Address::parseLocation($tail)) && in_array($area['type'], ['city','state','zip'], true)) {
                $state = $area['state'] ?? $state;
                $city = $area['city'] ?? '';
                $zip = $area['zip'] ?? '';
            } else {
                return null;
            }
        }
        $label = implode(', ', array_filter([$name, $city, trim($state . ' ' . $zip)]));
        return ['type' => 'building', 'name' => $name, 'city' => $city, 'state' => $state, 'zip' => $zip,
            'label' => $label, 'key' => 'building:' . sha1(mb_strtolower($label))];
    }

    public static function resolve(array $loc, bool $fetch = true): array
    {
        $key = Cache::key('building_map', 'photon-v1', ['q' => $loc['label']]);
        $kept = Cache::get($key);
        if (is_array($kept)) {
            return $kept;
        }
        if (!$fetch) {
            return [];
        }
        $response = Http::get('https://photon.komoot.io/api/?' . http_build_query([
            'q' => $loc['label'], 'limit' => 8, 'lang' => 'en',
        ]), [], 8);
        $data = json_decode($response['body'], true);
        $buildings = $response['status'] === 200 ? self::map((array) ($data['features'] ?? []), $loc) : [];
        Cache::put($key, 'building_map', $buildings, $buildings ? 86400 * 7 : 900);
        return $buildings;
    }

    private static function nameKey(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/i', ' ', mb_strtolower($name)) ?? '');
    }

    public static function nameMatches(string $query, string $name): bool
    {
        $query = self::nameKey($query);
        $name = self::nameKey($name);
        return $query !== '' && ($name === $query || str_starts_with($name, $query . ' '));
    }

    public static function map(array $features, array $loc): array
    {
        $out = [];
        foreach ($features as $feature) {
            $p = $feature['properties'] ?? [];
            if (!is_array($p) || strtoupper((string) ($p['countrycode'] ?? '')) !== 'US'
                || !self::nameMatches($loc['name'], (string) ($p['name'] ?? ''))
                || empty($p['housenumber']) || empty($p['street']) || empty($p['city'])) {
                continue;
            }
            $state = Address::stateAbbr((string) ($p['state'] ?? ''));
            if ($state !== $loc['state'] || ($loc['zip'] && $loc['zip'] !== ($p['postcode'] ?? ''))
                || ($loc['city'] && strcasecmp($loc['city'], (string) $p['city']) !== 0)) {
                continue;
            }
            $address = ['address' => trim($p['housenumber'] . ' ' . $p['street']), 'city' => $p['city'],
                'state' => $state, 'zip' => preg_match('/^\d{5}$/', (string) ($p['postcode'] ?? '')) ? $p['postcode'] : ''];
            $type = ['N' => 'node', 'W' => 'way', 'R' => 'relation'][$p['osm_type'] ?? ''] ?? null;
            $out[Address::hash(...array_values($address))] = $address + [
                'name' => mb_substr(strip_tags($p['name']), 0, 160),
                'source_url' => $type && is_numeric($p['osm_id'] ?? null) ? 'https://www.openstreetmap.org/' . $type . '/' . (int) $p['osm_id'] : null,
            ];
        }
        return array_slice(array_values($out), 0, 8);
    }

    /** A provider returning a whole city must not make unrelated homes look like building matches. */
    public static function matches(array $property, array $loc): bool
    {
        if (strtoupper((string) ($property['state'] ?? '')) !== $loc['state']
            || ($loc['zip'] && ($property['zip'] ?? '') !== $loc['zip'])
            || ($loc['city'] && strcasecmp($loc['city'], (string) ($property['city'] ?? '')) !== 0)) {
            return false;
        }
        $details = $property['details'] ?? [];
        if (is_string($details)) {
            $details = json_decode($details, true) ?: [];
        }
        if (self::nameMatches($loc['name'], (string) ($details['building_name'] ?? ''))) {
            return true;
        }
        foreach ($loc['buildings'] ?? [] as $building) {
            if (Address::sameProperty($property, $building, true)) {
                return true;
            }
        }
        return false;
    }

    public static function savedIds(array $loc): array
    {
        $args = [$loc['state']];
        $where = 'state = ?';
        if ($loc['zip']) {
            $where .= ' AND zip = ?';
            $args[] = $loc['zip'];
        } elseif ($loc['city']) {
            $where .= ' AND city = ?';
            $args[] = $loc['city'];
        }
        // Narrow candidates to explicit provider names or verified map street numbers before PHP comparison.
        $or = ["JSON_UNQUOTE(JSON_EXTRACT(details, '$.building_name')) LIKE ? ESCAPE '!'" ];
        $args[] = strtr($loc['name'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        foreach ($loc['buildings'] ?? [] as $building) {
            if (preg_match('/^(\d+[a-z]?)\s/i', $building['address'], $m)) {
                $or[] = 'address LIKE ?';
                $args[] = $m[1] . ' %';
            }
        }
        $rows = Db::all('SELECT id, address, city, state, zip, details FROM properties WHERE ' . $where . ' AND (' . implode(' OR ', $or) . ')', $args);
        return array_map('intval', array_column(array_filter($rows, static fn ($p) => self::matches($p, $loc)), 'id'));
    }
}

<?php
declare(strict_types=1);

namespace App\Providers;

use App\Cache;
use App\Geo;
use App\Http;

/**
 * What is near a home, from OpenStreetMap (Overpass API — free, no key).
 * Cached for 90 days per location. Being near something says nothing about its quality.
 */
final class Overpass
{
    private const URL = 'https://overpass-api.de/api/interpreter';

    public const KINDS = [
        'school' => 'Schools',
        'health' => 'Hospitals & clinics',
        'grocery' => 'Groceries',
        'transit' => 'Bus & rail stops',
        'highway' => 'Highway on-ramps',
        'park' => 'Parks',
    ];

    /** @return array<string, array{count:int, nearest:?float, name:?string}>|null null when OSM didn't answer */
    public static function around(float $lat, float $lng): ?array
    {
        $key = ['lat' => round($lat, 4), 'lng' => round($lng, 4)];
        return Cache::remember('osm', 'nearby', $key, 86400 * 90, static function () use ($lat, $lng) {
            $a = '(around:1609,' . $lat . ',' . $lng . ')';
            $h = '(around:4828,' . $lat . ',' . $lng . ')'; // on-ramps: within 3 miles
            $q = '[out:json][timeout:25];('
                . 'nwr' . $a . '[amenity~"^(school|kindergarten)$"];'
                . 'nwr' . $a . '[amenity~"^(hospital|clinic|doctors|urgent_care)$"];'
                . 'nwr' . $a . '[shop~"^(supermarket|grocery|greengrocer|wholesale)$"];'
                . 'node' . $a . '[highway=bus_stop];node' . $a . '[railway~"^(station|halt|tram_stop|subway_entrance)$"];'
                . 'node' . $h . '[highway=motorway_junction];'
                . 'nwr' . $a . '[leisure=park][name];'
                . ');out center 400;';
            $res = Http::postForm(self::URL, ['data' => $q], 30);
            $json = json_decode($res['body'], true);
            if ($res['status'] !== 200 || !isset($json['elements'])) {
                return null;
            }
            $out = array_fill_keys(array_keys(self::KINDS), ['count' => 0, 'nearest' => null, 'name' => null]);
            foreach ($json['elements'] as $el) {
                $t = $el['tags'] ?? [];
                $kind = match (true) {
                    in_array($t['amenity'] ?? '', ['school', 'kindergarten'], true) => 'school',
                    in_array($t['amenity'] ?? '', ['hospital', 'clinic', 'doctors', 'urgent_care'], true) => 'health',
                    isset($t['shop']) => 'grocery',
                    ($t['highway'] ?? '') === 'bus_stop' || isset($t['railway']) => 'transit',
                    ($t['highway'] ?? '') === 'motorway_junction' => 'highway',
                    ($t['leisure'] ?? '') === 'park' => 'park',
                    default => null,
                };
                $plat = $el['lat'] ?? ($el['center']['lat'] ?? null);
                $plng = $el['lon'] ?? ($el['center']['lon'] ?? null);
                if ($kind === null || !Geo::valid($plat, $plng)) {
                    continue;
                }
                $d = Geo::miles($lat, $lng, (float) $plat, (float) $plng);
                $out[$kind]['count']++;
                if ($out[$kind]['nearest'] === null || $d < $out[$kind]['nearest']) {
                    $out[$kind]['nearest'] = round($d, 2);
                    $out[$kind]['name'] = isset($t['name']) ? mb_substr((string) $t['name'], 0, 80) : ($kind === 'highway' && isset($t['ref']) ? 'Exit ' . $t['ref'] : null);
                }
            }
            return $out;
        });
    }
}

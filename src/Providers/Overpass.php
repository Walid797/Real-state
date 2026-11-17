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
    /** Public OpenStreetMap query servers, tried in turn: the main one is often busy for shared-hosting addresses. */
    private const URLS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
    ];

    public const KINDS = [
        'school' => 'Schools',
        'health' => 'Hospitals & clinics',
        'grocery' => 'Groceries',
        'transit' => 'Bus & rail stops',
        'highway' => 'Highway on-ramps',
        'park' => 'Parks',
    ];

    /** One tiny query to each server: which ones answer from here, and how fast. @return string[] */
    public static function probe(): array
    {
        $out = [];
        foreach (self::URLS as $url) {
            $res = Http::postForm($url, ['data' => '[out:json][timeout:5];node(around:200,37.5407,-77.4360)[highway=bus_stop];out 1;'], 10);
            $out[] = parse_url($url, PHP_URL_HOST) . ': ' . ($res['status'] ?: 'no answer' . ($res['error'] ? ' (' . $res['error'] . ')' : '')) . ' in ' . $res['ms'] . 'ms';
        }
        return $out;
    }

    /** @return array<string, array{count:int, nearest:?float, name:?string}>|null null when OSM didn't answer */
    public static function around(float $lat, float $lng): ?array
    {
        $key = ['lat' => round($lat, 4), 'lng' => round($lng, 4)];
        // After every server failed, don't make the next visitor wait again for a while.
        $failKey = Cache::key('osm', 'nearby-failed', $key);
        if (Cache::get($failKey) !== null) {
            return null;
        }
        $res = Cache::remember('osm', 'nearby', $key, 86400 * 90, static function () use ($lat, $lng) {
            $a = '(around:1609,' . $lat . ',' . $lng . ')';
            $h = '(around:4828,' . $lat . ',' . $lng . ')'; // on-ramps: within 3 miles
            $q = '[out:json][timeout:25];('
                . 'nwr' . $a . '[amenity~"^(school|kindergarten)$"];'
                . 'nwr' . $a . '[amenity~"^(hospital|clinic|doctors|urgent_care)$"];'
                . 'nwr' . $a . '[shop~"^(supermarket|grocery|greengrocer|wholesale)$"];'
                . 'node' . $a . '[highway=bus_stop];node' . $a . '[railway~"^(station|halt|tram_stop|subway_entrance)$"];'
                . 'node' . $h . '[highway=motorway_junction];'
                . 'nwr' . $a . '[leisure=park][name];'
                . ');out center qt 400;';
            $json = null;
            $started = microtime(true);
            foreach (self::URLS as $url) {
                $left = 26 - (int) (microtime(true) - $started); // stay well under the page's own patience
                if ($left < 5) {
                    break;
                }
                $res = Http::postForm($url, ['data' => str_replace('[timeout:25]', '[timeout:' . min(12, $left) . ']', $q)], min(14, $left));
                $try = json_decode($res['body'], true);
                if ($res['status'] === 200 && isset($try['elements'])) {
                    $json = $try;
                    break;
                }
                error_log('[info] OpenStreetMap ' . parse_url($url, PHP_URL_HOST) . ' answered ' . ($res['status'] ?: 'nothing') . ' in ' . $res['ms'] . 'ms');
            }
            if ($json === null) {
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
        if ($res === null) {
            Cache::put($failKey, 'osm', true, 900);
        }
        return $res;
    }
}

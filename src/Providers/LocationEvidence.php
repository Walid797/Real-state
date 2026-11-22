<?php
declare(strict_types=1);

namespace App\Providers;

use App\Cache;
use App\Geo;
use App\Http;

final class LocationEvidence
{
    public static function at(float $lat, float $lng): array
    {
        $result = Cache::remember('location', 'routes-v1', ['lat' => round($lat, 5), 'lng' => round($lng, 5)], 86400 * 30,
            static function () use ($lat, $lng): array {
                $around = sprintf('(around:4828,%.6f,%.6f)', $lat, $lng);
                $query = '[out:json][timeout:8];('
                    . 'nwr' . $around . '[amenity~"^(school|hospital|clinic|doctors)$"];'
                    . 'nwr' . $around . '[shop=supermarket];node' . $around . '[highway~"^(bus_stop|motorway_junction)$"];'
                    . 'nwr' . $around . '[railway=station];nwr' . $around . '[leisure=park];'
                    . 'nwr' . $around . '[office];nwr' . $around . '[construction];);out center qt 500;';
                $r = Http::postForm(Overpass::URLS[0], ['data' => $query], 12);
                $data = json_decode($r['body'], true);
                if ($r['status'] !== 200 || !is_array($data['elements'] ?? null)) {
                    return ['places' => [], 'construction' => [], 'unavailable' => true, 'observed_at' => now()];
                }
                $out = self::parse($data['elements'], $lat, $lng);
                foreach ($out['places'] as &$place) {
                    $route = self::route($lat, $lng, $place['lat'], $place['lng']);
                    $place['road_miles'] = $route['miles'] ?? null;
                    $place['driving_minutes'] = $route['minutes'] ?? null;
                }
                unset($place);
                return $out + ['unavailable' => false, 'observed_at' => now()];
            });
        if (!empty($result['unavailable'])) {
            Cache::put(Cache::key('location', 'routes-v1', ['lat' => round($lat, 5), 'lng' => round($lng, 5)]), 'location', $result, 900);
        }
        return $result;
    }

    public static function parse(array $elements, float $lat, float $lng): array
    {
        $places = $construction = [];
        foreach ($elements as $el) {
            $y = $el['lat'] ?? $el['center']['lat'] ?? null;
            $x = $el['lon'] ?? $el['center']['lon'] ?? null;
            if (!Geo::valid($y, $x)) {
                continue;
            }
            $miles = Geo::miles($lat, $lng, (float) $y, (float) $x);
            if ($miles > 3) {
                continue;
            }
            $tags = $el['tags'] ?? [];
            $kind = match (true) {
                ($tags['amenity'] ?? '') === 'school' => 'school',
                in_array($tags['amenity'] ?? '', ['hospital','clinic','doctors'], true) => 'health',
                ($tags['shop'] ?? '') === 'supermarket' => 'grocery',
                ($tags['highway'] ?? '') === 'motorway_junction' => 'highway',
                ($tags['highway'] ?? '') === 'bus_stop', ($tags['railway'] ?? '') === 'station' => 'transit',
                ($tags['leisure'] ?? '') === 'park' => 'park',
                isset($tags['office']) => 'workplace',
                default => null,
            };
            $row = [
                'name' => mb_substr(strip_tags((string) ($tags['name'] ?? $tags['operator'] ?? 'Mapped place')), 0, 120),
                'lat' => (float) $y, 'lng' => (float) $x, 'straight_miles' => round($miles, 2),
                'osm_id' => (int) ($el['id'] ?? 0),
            ];
            if ($kind !== null && (!isset($places[$kind]) || $miles < $places[$kind]['straight_miles'])) {
                $places[$kind] = $row;
            }
            if (isset($tags['construction']) && count($construction) < 8) {
                $construction[] = $row + ['type' => mb_substr((string) $tags['construction'], 0, 80), 'approval' => null, 'completion_date' => null];
            }
        }
        return ['places' => $places, 'construction' => $construction];
    }

    private static function route(float $lat, float $lng, float $destLat, float $destLng): ?array
    {
        $url = sprintf('https://router.project-osrm.org/route/v1/driving/%.6f,%.6f;%.6f,%.6f?overview=false', $lng, $lat, $destLng, $destLat);
        $r = Http::get($url, [], 4);
        $data = json_decode($r['body'], true);
        $route = $data['routes'][0] ?? [];
        if ($r['status'] !== 200 || ($data['code'] ?? '') !== 'Ok' || !is_numeric($route['distance'] ?? null) || !is_numeric($route['duration'] ?? null)) {
            return null;
        }
        return ['miles' => round($route['distance'] / 1609.344, 2), 'minutes' => round($route['duration'] / 60, 1)];
    }
}

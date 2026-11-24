<?php
declare(strict_types=1);

namespace App\Providers;

use App\Cache;
use App\Geo;
use App\Http;

final class LocationEvidence
{
    public static function cached(float $lat,float $lng): array
    {
        return Cache::get(Cache::key('location','routes-v3',['lat'=>round($lat,5),'lng'=>round($lng,5)]))??[];
    }
    public static function at(float $lat, float $lng): array
    {
        $result = Cache::remember('location', 'routes-v3', ['lat' => round($lat, 5), 'lng' => round($lng, 5)], 86400 * 30,
            static function () use ($lat, $lng): array {
                $around = sprintf('(around:4828,%.6f,%.6f)', $lat, $lng);
                $query = '[out:json][timeout:8];('
                    . 'nwr' . $around . '[amenity~"^(school|hospital|clinic|doctors|pharmacy|dentist|kindergarten|library)$"];'
                    . 'nwr' . $around . '[shop=supermarket];node' . $around . '[highway~"^(bus_stop|motorway_junction)$"];'
                    . 'nwr' . $around . '[railway=station];nwr' . $around . '[leisure=park];'
                    . 'nwr' . $around . '[office];nwr' . $around . '[construction];);out center qt 500;';
                $r = Http::get(Overpass::URLS[0] . '?' . http_build_query(['data'=>$query]), [], 8);
                $data = json_decode($r['body'], true);
                if ($r['status'] !== 200 || !is_array($data['elements'] ?? null)) {
                    foreach (array_slice(Overpass::URLS, 1) as $server) {
                        $r = Http::get($server . '?' . http_build_query(['data' => $query]), [], 6);
                        $data = json_decode($r['body'], true);
                        if ($r['status'] === 200 && is_array($data['elements'] ?? null)) {
                            break;
                        }
                    }
                }
                if ($r['status'] !== 200 || !is_array($data['elements'] ?? null)) {
                    return ['places' => [], 'construction' => [], 'unavailable' => true, 'observed_at' => now()];
                }
                $out = self::parse($data['elements'], $lat, $lng);
                $routingStarted = microtime(true);
                $routes = [];
                $withRoute = static function (array $place) use ($lat, $lng, $routingStarted, &$routes): array {
                    $key = $place['lat'] . ',' . $place['lng'];
                    if (!array_key_exists($key, $routes)) {
                        $routes[$key] = microtime(true) - $routingStarted < 8 ? self::route($lat, $lng, $place['lat'], $place['lng']) : null;
                    }
                    return $place + ['road_miles' => $routes[$key]['miles'] ?? null, 'driving_minutes' => $routes[$key]['minutes'] ?? null];
                };
                $out['places'] = array_map($withRoute, $out['places']);
                $out['schools'] = array_map($withRoute, $out['schools']);
                return $out + ['unavailable' => false, 'observed_at' => now()];
            });
        if (!empty($result['unavailable'])) {
            Cache::put(Cache::key('location', 'routes-v3', ['lat' => round($lat, 5), 'lng' => round($lng, 5)]), 'location', $result, 900);
        }
        return $result;
    }

    public static function parse(array $elements, float $lat, float $lng): array
    {
        $places = $construction = $schools = [];
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
                ($tags['amenity'] ?? '') === 'hospital' => 'hospital',
                in_array($tags['amenity'] ?? '', ['clinic','doctors'], true) => 'clinic',
                ($tags['amenity'] ?? '') === 'pharmacy' => 'pharmacy',
                ($tags['amenity'] ?? '') === 'dentist' => 'dentist',
                ($tags['amenity'] ?? '') === 'kindergarten' => 'childcare',
                ($tags['amenity'] ?? '') === 'library' => 'library',
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
                'osm_type' => in_array($el['type'] ?? '', ['node','way','relation'], true) ? $el['type'] : 'node',
                'level' => mb_substr(strip_tags((string) ($tags['school:level'] ?? $tags['isced:level'] ?? '')), 0, 60),
            ];
            if ($kind === 'school') {
                $schools[$row['osm_type'] . ':' . $row['osm_id']] = $row;
            }
            if ($kind !== null && (!isset($places[$kind]) || $miles < $places[$kind]['straight_miles'])) {
                $places[$kind] = $row;
            }
            if (isset($tags['construction']) && count($construction) < 8) {
                $construction[] = $row + ['type' => mb_substr((string) $tags['construction'], 0, 80), 'approval' => null, 'completion_date' => null];
            }
        }
        usort($schools, static fn ($a, $b) => $a['straight_miles'] <=> $b['straight_miles']);
        return ['places' => $places, 'construction' => $construction, 'schools' => array_slice($schools, 0, 5)];
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

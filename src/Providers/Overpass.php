<?php
declare(strict_types=1);

namespace App\Providers;

use App\Cache;
use App\Http;

/**
 * What is near a home, from OpenStreetMap (Overpass API — free, no key).
 *
 * The public Overpass servers rarely answer requests from shared hosting, so the visitor's
 * browser runs the query (app.js) and sends back the tally, which is kept here for 90 days
 * so the next visit is instant. Being near something says nothing about its quality.
 */
final class Overpass
{
    /** Public OpenStreetMap query servers, tried in turn. Also allowed in the property page's CSP. */
    public const URLS = [
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

    /** Miles searched for each kind. */
    public const RADIUS = ['highway' => 3];

    private const KEEP = 86400 * 90;

    private static function key(float $lat, float $lng): string
    {
        return Cache::key('osm', 'nearby', ['lat' => round($lat, 4), 'lng' => round($lng, 4)]);
    }

    /** Overpass QL for everything the panel counts around one point. */
    public static function query(float $lat, float $lng): string
    {
        $a = sprintf('(around:1609,%.6f,%.6f)', $lat, $lng);
        $h = sprintf('(around:4828,%.6f,%.6f)', $lat, $lng); // on-ramps: within 3 miles
        return '[out:json][timeout:12];('
            . 'nwr' . $a . '[amenity~"^(school|kindergarten)$"];'
            . 'nwr' . $a . '[amenity~"^(hospital|clinic|doctors|urgent_care)$"];'
            . 'nwr' . $a . '[shop~"^(supermarket|grocery|greengrocer|wholesale)$"];'
            . 'node' . $a . '[highway=bus_stop];node' . $a . '[railway~"^(station|halt|tram_stop|subway_entrance)$"];'
            . 'node' . $h . '[highway=motorway_junction];'
            . 'nwr' . $a . '[leisure=park][name];'
            . ');out center qt 400;';
    }

    /** @return array<string, array{count:int, nearest:?float, name:?string}>|null */
    public static function cached(float $lat, float $lng): ?array
    {
        $hit = Cache::get(self::key($lat, $lng));
        return is_array($hit) ? $hit : null;
    }

    /**
     * Keeps a tally the browser worked out — after checking every field, since it comes from a client.
     * @return bool whether it was valid and stored
     */
    public static function store(float $lat, float $lng, mixed $places): bool
    {
        if (!is_array($places)) {
            return false;
        }
        $clean = [];
        foreach (array_keys(self::KINDS) as $k) {
            $row = $places[$k] ?? null;
            if (!is_array($row) || !is_int($row['count'] ?? null) || $row['count'] < 0 || $row['count'] > 5000) {
                return false;
            }
            $near = $row['nearest'] ?? null;
            if ($near !== null && (!is_numeric($near) || $near < 0 || $near > 10)) {
                return false;
            }
            $name = $row['name'] ?? null;
            $clean[$k] = [
                'count' => $row['count'],
                'nearest' => $near === null ? null : round((float) $near, 2),
                'name' => is_string($name) && $name !== '' ? mb_substr(strip_tags($name), 0, 80) : null,
            ];
        }
        Cache::put(self::key($lat, $lng), 'osm', $clean, self::KEEP);
        return true;
    }

    /** One tiny query to each server: which ones answer from this host, and how fast. @return string[] */
    public static function probe(): array
    {
        $out = [];
        foreach (self::URLS as $url) {
            $res = Http::postForm($url, ['data' => '[out:json][timeout:5];node(around:200,37.5407,-77.4360)[highway=bus_stop];out 1;'], 10);
            $out[] = parse_url($url, PHP_URL_HOST) . ': ' . ($res['status'] ?: 'no answer' . ($res['error'] ? ' (' . $res['error'] . ')' : '')) . ' in ' . $res['ms'] . 'ms';
        }
        return $out;
    }
}

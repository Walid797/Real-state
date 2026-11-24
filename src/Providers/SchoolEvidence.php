<?php
declare(strict_types=1);

namespace App\Providers;

use App\Address;
use App\Cache;

final class SchoolEvidence
{
    /** Zillow's school rating uses its original ten-point scale. No conversion to review stars. */
    public static function normalize(array $rows): array
    {
        $schools = [];
        foreach (array_slice($rows, 0, 30) as $row) {
            if (!is_array($row) || !is_string($row['name'] ?? null) || trim($row['name']) === '') {
                continue;
            }
            $rating = $row['rating'] ?? null;
            $rating = is_numeric($rating) && $rating >= 1 && $rating <= 10 ? (float) $rating : null;
            $distance = $row['distance'] ?? null;
            $distance = is_numeric($distance) && $distance >= 0 && $distance <= 100 ? (float) $distance : null;
            $url = is_string($row['link'] ?? null) ? $row['link'] : '';
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $safe = str_starts_with($url, 'https://') && ($host === 'greatschools.org' || str_ends_with($host, '.greatschools.org')
                || $host === 'zillow.com' || str_ends_with($host, '.zillow.com'));
            $key = mb_strtolower(trim($row['name'])) . '|' . ($safe ? $url : '');
            $schools[$key] = [
                'name' => mb_substr(strip_tags(trim($row['name'])), 0, 160),
                'level' => mb_substr(strip_tags((string) ($row['level'] ?? $row['grades'] ?? '')), 0, 80),
                'rating' => $rating, 'rating_scale' => $rating === null ? null : 10,
                'rating_source' => $rating === null ? null : ($host === 'greatschools.org' || str_ends_with($host, '.greatschools.org') ? 'GreatSchools via Zillow' : 'Zillow school data'),
                'distance_miles' => $distance, 'distance_method' => $distance === null ? null : 'Listing provider distance',
                'assigned' => is_bool($row['isAssigned'] ?? null) ? $row['isAssigned'] : null,
                'source_url' => $safe ? $url : null,
            ];
        }
        return array_values($schools);
    }

    public static function forProperty(array $p, array $location): array
    {
        $schools = $p['details']['schools'] ?? $p['details']['zillow_search_schools'] ?? null;
        if (!is_array($schools)) {
            $raw = Cache::get(Cache::key(OpenWebNinja::ID, '/property-details-address', ['address' => Address::full($p)]));
            $raw = is_array($raw) && array_is_list($raw) ? ($raw[0] ?? []) : $raw;
            $schools = self::normalize((array) ($raw['schools'] ?? []));
        }
        $stored = [];
        foreach ($schools as $school) {
            if (!is_array($school)) {
                continue;
            }
            $stored[] = [
                'name' => $school['name'] ?? null, 'level' => $school['level'] ?? $school['grades'] ?? '',
                'rating' => isset($school['rating_scale']) && $school['rating_scale'] !== 10 ? null : ($school['rating'] ?? null),
                'distance' => $school['distance_miles'] ?? $school['distance'] ?? null,
                'link' => $school['source_url'] ?? $school['link'] ?? null,
                'isAssigned' => $school['assigned'] ?? $school['isAssigned'] ?? null,
            ];
        }
        $schools = self::normalize($stored);
        $out = [];
        foreach ($schools as $school) {
            if (!is_array($school) || empty($school['name'])) {
                continue;
            }
            $distance = $school['distance_miles'] ?? null;
            if ($distance !== null && $distance > 3) {
                continue;
            }
            $out[] = $school + ['rating' => null, 'rating_scale' => null, 'rating_source' => null, 'distance_miles' => null, 'assigned' => null, 'source_url' => null];
        }
        $names = array_map(static fn ($school) => mb_strtolower(trim($school['name'])), $out);
        foreach ($location['schools'] ?? [] as $mapped) {
            if (in_array(mb_strtolower(trim($mapped['name'])), $names, true)) {
                continue;
            }
            $out[] = [
                'name' => $mapped['name'], 'level' => $mapped['level'] ?? '',
                'rating' => null, 'rating_scale' => null, 'rating_source' => null,
                'distance_miles' => $mapped['straight_miles'], 'distance_method' => 'Straight-line distance',
                'road_miles' => $mapped['road_miles'] ?? null, 'driving_minutes' => $mapped['driving_minutes'] ?? null,
                'assigned' => null, 'source_url' => 'https://www.openstreetmap.org/' . ($mapped['osm_type'] ?? 'node') . '/' . $mapped['osm_id'],
            ];
        }
        usort($out, static fn ($a, $b) => ($a['distance_miles'] ?? INF) <=> ($b['distance_miles'] ?? INF));
        return array_slice($out, 0, 8);
    }
}

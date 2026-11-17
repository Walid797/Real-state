<?php
declare(strict_types=1);

namespace App\Providers;

use App\Cache;
use App\Http;
use App\Settings;

/**
 * U.S. Census Bureau — free, no key required.
 *  - Geocoder: address → coordinates + census tract
 *  - ACS 5-year: income, home value, rent, tenure for that tract
 */
final class Census
{
    private const GEOCODER = 'https://geocoding.geo.census.gov/geocoder';
    private const ACS_YEARS = [2024, 2023];
    private const VARS = [
        'B01003_001E' => 'population',
        'B19013_001E' => 'median_income',
        'B25077_001E' => 'median_home_value',
        'B25064_001E' => 'median_rent',
        'B25003_001E' => 'occupied_units',
        'B25003_002E' => 'owner_occupied',
        'B25002_001E' => 'housing_units',
        'B25002_003E' => 'vacant_units',
        'B25035_001E' => 'median_year_built',
    ];

    /**
     * @return array{lat:float, lng:float, matched:string, zip:?string, city:?string, state:?string, county:?string, tract:?array}|null
     */
    public static function geocode(string $oneLine): ?array
    {
        return Cache::remember('census', 'geocode', ['a' => mb_strtolower($oneLine)], 86400 * 365, static function () use ($oneLine) {
            $res = Http::get(self::GEOCODER . '/geographies/onelineaddress?' . http_build_query([
                'address' => $oneLine, 'benchmark' => 'Public_AR_Current', 'vintage' => 'Current_Current', 'format' => 'json',
            ]), [], 20);
            $m = json_decode($res['body'], true)['result']['addressMatches'][0] ?? null;
            if ($res['status'] !== 200 || !$m || !isset($m['coordinates']['x'], $m['coordinates']['y'])) {
                return null;
            }
            $tract = $m['geographies']['Census Tracts'][0] ?? null;
            $county = $m['geographies']['Counties'][0]['BASENAME'] ?? null;
            return [
                'lat' => (float) $m['coordinates']['y'],
                'lng' => (float) $m['coordinates']['x'],
                'matched' => (string) ($m['matchedAddress'] ?? $oneLine),
                'zip' => $m['addressComponents']['zip'] ?? null,
                'city' => $m['addressComponents']['city'] ?? null,
                'state' => $m['addressComponents']['state'] ?? null,
                'county' => $county,
                'tract' => $tract ? ['state' => $tract['STATE'], 'county' => $tract['COUNTY'], 'tract' => $tract['TRACT'], 'name' => $tract['NAME'] ?? ''] : null,
            ];
        });
    }

    /** @return array{state:string, county:string, tract:string, name:string, county_name:?string}|null */
    public static function tractAt(float $lat, float $lng): ?array
    {
        $key = ['lat' => round($lat, 4), 'lng' => round($lng, 4)];
        return Cache::remember('census', 'tract', $key, 86400 * 365, static function () use ($lat, $lng) {
            $res = Http::get(self::GEOCODER . '/geographies/coordinates?' . http_build_query([
                'x' => $lng, 'y' => $lat, 'benchmark' => 'Public_AR_Current', 'vintage' => 'Current_Current', 'format' => 'json',
            ]), [], 20);
            $g = json_decode($res['body'], true)['result']['geographies'] ?? null;
            $t = $g['Census Tracts'][0] ?? null;
            if ($res['status'] !== 200 || !$t) {
                return null;
            }
            return ['state' => $t['STATE'], 'county' => $t['COUNTY'], 'tract' => $t['TRACT'], 'name' => $t['NAME'] ?? '',
                'county_name' => $g['Counties'][0]['BASENAME'] ?? null];
        });
    }

    /** Neighbourhood numbers for a census tract. Null values mean the Census suppressed them. */
    public static function tractStats(array $tract): ?array
    {
        $id = ['s' => $tract['state'], 'c' => $tract['county'], 't' => $tract['tract']];
        return Cache::remember('census', 'acs', $id, 86400 * 120, static function () use ($tract) {
            foreach (self::ACS_YEARS as $year) {
                $q = [
                    'get' => 'NAME,' . implode(',', array_keys(self::VARS)),
                    'for' => 'tract:' . $tract['tract'],
                    'in' => 'state:' . $tract['state'] . ' county:' . $tract['county'],
                ];
                if (Settings::has('census_key')) {
                    $q['key'] = Settings::get('census_key');
                }
                $res = Http::get("https://api.census.gov/data/$year/acs/acs5?" . http_build_query($q), [], 20);
                $rows = json_decode($res['body'], true);
                if ($res['status'] !== 200 || !is_array($rows) || count($rows) < 2) {
                    continue;
                }
                $row = array_combine($rows[0], $rows[1]);
                $out = ['year' => $year, 'name' => $row['NAME'] ?? ''];
                foreach (self::VARS as $var => $name) {
                    $v = $row[$var] ?? null;
                    // The Census uses large negative numbers as "not available".
                    $out[$name] = is_numeric($v) && (float) $v >= 0 ? (float) $v : null;
                }
                $out['owner_share'] = !empty($out['occupied_units']) && $out['owner_occupied'] !== null
                    ? round($out['owner_occupied'] / $out['occupied_units'] * 100, 1) : null;
                $out['vacancy'] = !empty($out['housing_units']) && $out['vacant_units'] !== null
                    ? round($out['vacant_units'] / $out['housing_units'] * 100, 1) : null;
                return $out;
            }
            return null;
        });
    }
}

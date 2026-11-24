<?php
declare(strict_types=1);

namespace App\Providers;

use App\Cache;
use App\Providers\County\ArcGis;

/**
 * Where a county is and how big it is, from the Census Bureau's TIGERweb service (free, no key):
 * its internal point and its land area. A listing provider with no county filter can then be asked
 * for the county's own circle instead of the whole state — so one 500-home answer is about that
 * county, not spread across the state.
 */
final class CountyArea
{
    /** Tests switch the network off. */
    public static bool $network = true;

    private const LAYER = 'https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/State_County/MapServer/1';

    /** Above this radius the circle would hold more than the county; the state pull is used. */
    public const MAX_RADIUS_MI = 50.0;

    public const FIPS = [
        'AL' => '01', 'AK' => '02', 'AZ' => '04', 'AR' => '05', 'CA' => '06', 'CO' => '08', 'CT' => '09', 'DE' => '10', 'DC' => '11',
        'FL' => '12', 'GA' => '13', 'HI' => '15', 'ID' => '16', 'IL' => '17', 'IN' => '18', 'IA' => '19', 'KS' => '20', 'KY' => '21',
        'LA' => '22', 'ME' => '23', 'MD' => '24', 'MA' => '25', 'MI' => '26', 'MN' => '27', 'MS' => '28', 'MO' => '29', 'MT' => '30',
        'NE' => '31', 'NV' => '32', 'NH' => '33', 'NJ' => '34', 'NM' => '35', 'NY' => '36', 'NC' => '37', 'ND' => '38', 'OH' => '39',
        'OK' => '40', 'OR' => '41', 'PA' => '42', 'RI' => '44', 'SC' => '45', 'SD' => '46', 'TN' => '47', 'TX' => '48', 'UT' => '49',
        'VT' => '50', 'VA' => '51', 'WA' => '53', 'WV' => '54', 'WI' => '55', 'WY' => '56',
    ];

    /**
     * @return array{lat:float, lng:float, radius:float, name:string}|null  null = unknown or too large
     */
    public static function find(string $state, string $county): ?array
    {
        $state = strtoupper($state);
        $fips = self::FIPS[$state] ?? null;
        $isCity = (bool) preg_match('/\s+city$/i', trim($county));
        $base = trim(preg_replace('/\s+(?:county|parish|borough|city)$/i', '', trim($county)) ?? $county);
        if ($fips === null || $base === '') {
            return null;
        }
        $key = Cache::key('tigerweb', 'county_area_v2', ['s' => $state, 'c' => mb_strtolower($base), 'city' => $isCity]);
        $kept = Cache::get($key);
        if (is_array($kept)) {
            return $kept['area'];
        }
        if (!self::$network) {
            return null;
        }
        try {
            $rows = ArcGis::query(self::LAYER, [
                'where' => 'STATE = ' . ArcGis::str($fips) . ' AND BASENAME = ' . ArcGis::str($base),
                'outFields' => 'NAME,BASENAME,AREALAND,CENTLAT,CENTLON,INTPTLAT,INTPTLON',
            ], false, false, 'The Census map service');
        } catch (\RuntimeException) {
            return null; // not cached: tried again next time
        }
        $area = self::pick($rows, $isCity);
        Cache::put($key, 'tigerweb', ['area' => $area], $area ? 86400 * 365 : 86400);
        return $area;
    }

    /** The county (or, for "X City", the independent city) among same-named rows, as a circle. */
    public static function pick(array $rows, bool $isCity): ?array
    {
        foreach ($rows as $r) {
            $name = (string) ($r['NAME'] ?? '');
            if ($isCity !== (bool) preg_match('/\bcity$/i', $name)) {
                continue;
            }
            $lat = (float) ($r['INTPTLAT'] ?? $r['CENTLAT'] ?? 0);
            $lng = (float) ($r['INTPTLON'] ?? $r['CENTLON'] ?? 0);
            $land = (float) ($r['AREALAND'] ?? 0);
            if ($lat == 0.0 || $lng == 0.0 || $land <= 0) {
                continue;
            }
            // A circle of the same area, widened by a third for counties that are not round.
            $radius = round(sqrt($land / M_PI) / 1609.344 * 1.35, 1);
            if ($radius > self::MAX_RADIUS_MI) {
                return null;
            }
            return ['lat' => round($lat, 5), 'lng' => round($lng, 5), 'radius' => max(2.0, $radius), 'name' => $name];
        }
        return null;
    }
}

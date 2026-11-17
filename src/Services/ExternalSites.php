<?php
declare(strict_types=1);

namespace App\Services;

use App\Address;
use App\Db;

/**
 * Links out to other real estate sites for the same house or the same area.
 * Nothing is fetched from those sites — these are ordinary links you open yourself.
 *
 * Each site has two optional URL templates:
 *   property_url — for one address          area_url — for a city or ZIP search
 *
 * Placeholders:
 *   {address} {city} {state} {zip} {county}  URL-encoded values
 *   {address_full}                           "139 E Del Rio Dr, Tempe, AZ 85282", URL-encoded
 *   {address_slug}                           139-E-Del-Rio-Dr-Tempe-AZ-85282
 *   {city_slug}  {city_dash}  {city_under}   san-antonio / San-Antonio / San_Antonio
 *   {state_lower}                            tx
 *   {area}                                   ZIP when searching a ZIP, otherwise "City, ST" (encoded)
 *   {area_slug}  {area_under}                78704  or  San-Antonio-TX / San-Antonio_TX
 *   {lat} {lng}
 */
final class ExternalSites
{
    public const PLACEHOLDERS = [
        '{address}', '{address_full}', '{address_slug}', '{city}', '{city_slug}', '{city_dash}', '{city_under}',
        '{state}', '{state_lower}', '{zip}', '{county}', '{area}', '{area_slug}', '{area_under}', '{lat}', '{lng}',
    ];

    public const DEFAULTS = [
        ['Zillow', 'listings', 'https://www.zillow.com/homes/{address_slug}_rb/', 'https://www.zillow.com/homes/{area_slug}_rb/'],
        ['Zillow — recently sold', 'listings', null, 'https://www.zillow.com/homes/recently_sold/{area_slug}_rb/'],
        ['Redfin', 'listings', 'https://www.google.com/search?q={address_full}+site%3Aredfin.com', 'https://www.redfin.com/zipcode/{zip}'],
        ['Realtor.com', 'listings', 'https://www.google.com/search?q={address_full}+site%3Arealtor.com', 'https://www.realtor.com/realestateandhomes-search/{area_under}'],
        ['Trulia', 'listings', 'https://www.google.com/search?q={address_full}+site%3Atrulia.com', 'https://www.trulia.com/{state}/{city_under}/'],
        ['Homes.com', 'listings', 'https://www.google.com/search?q={address_full}+site%3Ahomes.com', 'https://www.homes.com/{city_slug}-{state_lower}/'],
        ['Movoto', 'listings', 'https://www.google.com/search?q={address_full}+site%3Amovoto.com', 'https://www.movoto.com/{city_slug}-{state_lower}/'],
        ['County assessor', 'records', 'https://www.google.com/search?q={county}+County+{state}+property+assessor+{address}', 'https://www.google.com/search?q={county}+County+{state}+property+assessor+search'],
        ['Google Maps', 'maps', 'https://www.google.com/maps/search/?api=1&query={address_full}', 'https://www.google.com/maps/search/?api=1&query={area}'],
        ['Street View', 'maps', 'https://www.google.com/maps/@?api=1&map_action=pano&viewpoint={lat},{lng}', null],
    ];

    public static function seedDefaults(): void
    {
        if ((int) Db::val('SELECT COUNT(*) FROM external_sites') > 0) {
            return;
        }
        foreach (self::DEFAULTS as $i => [$name, $cat, $prop, $area]) {
            Db::insert('external_sites', [
                'name' => $name, 'category' => $cat, 'property_url' => $prop, 'area_url' => $area,
                'sort_order' => ($i + 1) * 10, 'enabled' => 1,
            ]);
        }
    }

    public static function all(bool $enabledOnly = false): array
    {
        return Db::all('SELECT * FROM external_sites ' . ($enabledOnly ? 'WHERE enabled = 1 ' : '') . 'ORDER BY sort_order, id');
    }

    /** @return array<int, array{name:string, category:string, url:string}> */
    public static function forProperty(array $p): array
    {
        $vars = self::vars($p, null);
        $out = [];
        foreach (self::all(true) as $s) {
            $url = self::fill((string) $s['property_url'], $vars);
            if ($url !== null) {
                $out[] = ['name' => $s['name'], 'category' => $s['category'], 'url' => $url];
            }
        }
        return $out;
    }

    /** @return array<int, array{name:string, category:string, url:string}> */
    public static function forArea(array $loc): array
    {
        $vars = self::vars(['city' => $loc['city'] ?? '', 'state' => $loc['state'] ?? '', 'zip' => $loc['zip'] ?? ''], $loc);
        $out = [];
        foreach (self::all(true) as $s) {
            $url = self::fill((string) $s['area_url'], $vars);
            if ($url !== null) {
                $out[] = ['name' => $s['name'], 'category' => $s['category'], 'url' => $url];
            }
        }
        return $out;
    }

    private static function vars(array $p, ?array $loc): array
    {
        $city = (string) ($p['city'] ?? '');
        $state = (string) ($p['state'] ?? '');
        $zip = (string) ($p['zip'] ?? '');
        $full = Address::full($p);
        $isZipArea = $loc !== null && ($loc['type'] ?? '') === 'zip';
        $areaText = $isZipArea || $city === '' ? $zip : $city . ', ' . $state;
        $hasGeo = \App\Geo::valid($p['lat'] ?? null, $p['lng'] ?? null);
        return [
            '{address}' => rawurlencode((string) ($p['address'] ?? '')),
            '{address_full}' => rawurlencode($full),
            '{address_slug}' => Address::slug($full),
            '{city}' => rawurlencode($city),
            '{city_slug}' => Address::slug($city, '-', true),
            '{city_dash}' => Address::slug($city),
            '{city_under}' => Address::slug($city, '_'),
            '{state}' => rawurlencode($state),
            '{state_lower}' => strtolower($state),
            '{zip}' => rawurlencode($zip),
            '{county}' => rawurlencode((string) ($p['county'] ?? '')),
            '{area}' => rawurlencode($areaText),
            '{area_slug}' => $isZipArea || $city === '' ? $zip : Address::slug($city . ' ' . $state),
            '{area_under}' => $isZipArea || $city === '' ? $zip : Address::slug($city) . '_' . $state,
            '{lat}' => $hasGeo ? (string) (float) $p['lat'] : '',
            '{lng}' => $hasGeo ? (string) (float) $p['lng'] : '',
        ];
    }

    /** Returns null when the template needs something we don't have (e.g. {zip} for a city-only search). */
    private static function fill(string $tpl, array $vars): ?string
    {
        $tpl = trim($tpl);
        if ($tpl === '') {
            return null;
        }
        foreach ($vars as $k => $v) {
            if (str_contains($tpl, $k)) {
                if ($v === '') {
                    return null;
                }
                $tpl = str_replace($k, $v, $tpl);
            }
        }
        return self::validTemplate($tpl) && !str_contains($tpl, '{') ? $tpl : null;
    }

    public static function validTemplate(string $tpl): bool
    {
        return $tpl === '' || (bool) preg_match('#^https://[a-z0-9.-]+\.[a-z]{2,}(/|\?|$)#i', $tpl);
    }

    public static function host(string $url): string
    {
        $h = (string) parse_url($url, PHP_URL_HOST);
        return preg_replace('/^www\./', '', $h) ?? $h;
    }
}

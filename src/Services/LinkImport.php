<?php
declare(strict_types=1);

namespace App\Services;

use App\Address;
use App\Providers\Census;

/**
 * Turns a listing link from another site into an address — by reading the link
 * itself, never by downloading the page (those sites don't allow automated reading).
 * The U.S. Census geocoder then confirms the address and places it on the map.
 */
final class LinkImport
{
    private const SITES = [
        'zillow.com' => 'Zillow', 'homes.com' => 'Homes.com', 'realtor.com' => 'Realtor.com', 'redfin.com' => 'Redfin',
        'trulia.com' => 'Trulia', 'movoto.com' => 'Movoto', 'compass.com' => 'Compass', 'auction.com' => 'Auction.com',
        'hubzu.com' => 'Hubzu', 'xome.com' => 'Xome', 'brightmls.com' => 'Bright MLS', 'loopnet.com' => 'LoopNet',
    ];

    public static function site(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (self::SITES as $domain => $name) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $name;
            }
        }
        return preg_replace('/^www\./', '', $host) ?: 'Other site';
    }

    /**
     * The address text a link carries, or null if it carries none we can read.
     * Zillow   /homedetails/139-E-Del-Rio-Dr-Tempe-AZ-85282/7594920_zpid/
     * Realtor  /realestateandhomes-detail/1234-Main-St_Richmond_VA_23220_M12345-67890
     * Redfin   /VA/Richmond/1234-Main-St-23220/home/12345
     * Homes    /property/1234-main-st-richmond-va/abc123/
     * Trulia   /home/1234-main-st-richmond-va-23220-12345
     */
    public static function addressText(string $url): ?string
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $segs = array_values(array_filter(explode('/', $path), 'strlen'));
        if (!$segs) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (str_contains($host, 'redfin.com') && count($segs) >= 3 && strlen($segs[0]) === 2) {
            // state / city / street-zip
            $street = str_replace('-', ' ', $segs[2]);
            $zip = preg_match('/\s(\d{5})$/', $street, $z) ? $z[1] : '';
            return trim(preg_replace('/\s\d{5}$/', '', $street) ?? $street) . ', ' . str_replace('-', ' ', $segs[1]) . ', ' . strtoupper($segs[0]) . ($zip ? ' ' . $zip : '');
        }
        if (str_contains($host, 'realtor.com')) {
            foreach ($segs as $s) {
                if (preg_match('/^(\d[^_]*)_([^_]+)_([A-Za-z]{2})_(\d{5})/', $s, $m)) {
                    return str_replace('-', ' ', $m[1]) . ', ' . str_replace('-', ' ', $m[2]) . ', ' . strtoupper($m[3]) . ' ' . $m[4];
                }
            }
        }
        // Everything else: the first path segment that starts with a house number.
        foreach ($segs as $s) {
            if (preg_match('/^\d+[a-z]?[-_ ][a-z]/i', $s)) {
                $s = preg_replace('/[-_](\d+_zpid|[a-z]*\d{6,}[a-z0-9]*)$/i', '', $s) ?? $s; // trailing listing ids (ZIPs have 5 digits)
                $t = trim(preg_replace('/[-_]+/', ' ', $s) ?? '');
                // "... Tempe AZ 85282" → "... Tempe, AZ 85282"
                $t = preg_replace('/\s+([A-Za-z]{2})\s+(\d{5})(?:\s+\d+)?$/', ', $1 $2', $t) ?? $t;
                $t = preg_replace('/\s+([A-Za-z]{2})$/', ', $1', $t) ?? $t;
                return $t;
            }
        }
        return null;
    }

    /**
     * @return array{ok:bool, error?:string, address?:string, city?:string, state?:string, zip?:string, lat?:float, lng?:float, county?:?string, site:string}
     */
    public static function resolve(string $url): array
    {
        $site = self::site($url);
        if (!preg_match('#^https?://[^\s]+$#i', $url)) {
            return ['ok' => false, 'error' => 'That doesn’t look like a web link.', 'site' => $site];
        }
        $text = self::addressText($url);
        if ($text === null) {
            return ['ok' => false, 'error' => 'This link doesn’t contain the address. Type the address below instead — the link is still saved with the home.', 'site' => $site];
        }
        $geo = Census::geocode($text);
        if (!$geo) {
            return ['ok' => false, 'error' => 'Read “' . $text . '” from the link, but the Census geocoder couldn’t find it. Type the address below.', 'site' => $site];
        }
        // Keep the street as the link spells it (the geocoder drops suffixes like "Dr"); take city / state / ZIP from the geocoder.
        $street = preg_split('/,/', $text)[0];
        $matchedStreet = trim(explode(',', $geo['matched'])[0]);
        $street = self::streetFrom($street, $matchedStreet);
        return [
            'ok' => true, 'site' => $site,
            'address' => $street, 'city' => Address::titleCity((string) $geo['city']), 'state' => (string) $geo['state'],
            'zip' => (string) $geo['zip'], 'lat' => $geo['lat'], 'lng' => $geo['lng'], 'county' => $geo['county'],
        ];
    }

    /**
     * The link text may carry the city glued to the street ("139 E Del Rio Dr Tempe").
     * Cut it after the last word the geocoder's street shares, and keep a suffix such as Dr / St.
     */
    private static function streetFrom(string $fromLink, string $matched): string
    {
        $words = preg_split('/\s+/', trim($fromLink)) ?: [];
        $m = array_map('strtolower', preg_split('/\s+/', $matched) ?: []);
        $lastShared = -1;
        foreach ($words as $i => $w) {
            if (in_array(strtolower($w), $m, true)) {
                $lastShared = $i;
            }
        }
        if ($lastShared < 0) {
            return ucwords(strtolower($matched));
        }
        $keep = array_slice($words, 0, $lastShared + 1);
        $next = strtolower($words[$lastShared + 1] ?? '');
        $suffixes = ['st', 'street', 'ave', 'avenue', 'dr', 'drive', 'rd', 'road', 'ln', 'lane', 'ct', 'court', 'pl', 'place', 'blvd', 'way',
            'cir', 'circle', 'ter', 'terrace', 'trl', 'trail', 'pkwy', 'hwy', 'sq', 'loop', 'run', 'pike', 'xing', 'cv', 'pt', 'row', 'aly'];
        if (in_array($next, $suffixes, true)) {
            $keep[] = $words[$lastShared + 1];
            $unit = array_slice($words, $lastShared + 2, 2);
            if ($unit && in_array(strtolower($unit[0]), ['unit', 'apt', '#', 'ste'], true)) {
                $keep = array_merge($keep, $unit);
            }
        }
        return implode(' ', array_map(static fn ($w) => preg_match('/^[nsew]{1,2}$/i', $w) ? strtoupper($w) : ucfirst(strtolower($w)), $keep));
    }
}

<?php
declare(strict_types=1);

namespace App\Services;

use App\Address;
use App\Cache;
use App\Db;
use App\Http;

/** Automatic query classification, with verified map evidence for ambiguous names and incomplete addresses. */
final class SmartLocation
{
    /** Offline checks disable transport; production lookups remain bounded and cached. */
    public static bool $network = true;

    public static function normalize(string $q): string
    {
        return trim(preg_replace('/\s+/', ' ', strtr($q, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','،'=>',','؛'=>';',
        ])) ?? '');
    }

    public static function key(string $q): string
    {
        return Cache::key('smart_location', 'v1', ['q'=>mb_strtolower(self::normalize($q)), 'state'=>Address::homeState()]);
    }

    public static function resolve(string $query, bool $fetch = false): ?array
    {
        $q = self::normalize($query);
        $parsed = Address::parseLocation($q);
        if ($q === '' || ($parsed && !self::ambiguous($parsed))) {
            return $parsed;
        }
        $cached = Cache::get(self::key($q));
        if (is_array($cached)) {
            return $cached['location'];
        }
        if ($parsed && $parsed['type'] === 'city' && Db::val('SELECT id FROM properties WHERE state=? AND city=? LIMIT 1', [$parsed['state'],$parsed['city']])) {
            return $parsed;
        }
        $building = BuildingSearch::location($q);
        if ($building) {
            $mapped = BuildingSearch::resolve($building, false);
            if ($mapped || Db::val("SELECT id FROM properties WHERE state=? AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(details,'$.building_name'))) = ? LIMIT 1", [$building['state'],mb_strtolower($building['name'])])) {
                return $building + ['buildings'=>$mapped];
            }
        }
        if (!$fetch || !self::$network) {
            return $parsed;
        }
        [$state] = self::stateAndHead($q);
        $lookup = $q;
        // Unit identifiers are retained for the subject but omitted from the building's map lookup.
        if (preg_match('/^\d/', $q)) {
            $lookup = preg_replace('/\b(?:apartment|apt|unit|suite|ste)\.?\s*[a-z0-9-]+(?:\s+[NSEW]\b)?|\#\s*[a-z0-9-]+(?:\s+[NSEW]\b)?/i', '', $lookup) ?? $lookup;
        }
        $r = Http::get('https://photon.komoot.io/api/?' . http_build_query(['q'=>$lookup . ', ' . $state,'limit'=>10,'lang'=>'en']), [], 6);
        $features = json_decode($r['body'], true)['features'] ?? [];
        $location = $r['status'] === 200 ? self::fromFeatures($q, (array) $features) : null;
        // Explicit city/state text retains the old city-search fallback when the public geocoder is down.
        $looksLikeBuilding = preg_match('/\b(?:tower|towers|apartments|condominiums?|condos|residences|building|lofts|flats)\b/i', $q)
            || ($parsed === null && substr_count($q, ',') >= 2);
        if (!$location && !$looksLikeBuilding && ($parsed === null || empty($parsed['partial']))) {
            $location = $parsed;
        }
        Cache::put(self::key($q), 'smart_location', ['location'=>$location], $location && $r['status'] === 200 ? 86400 * 7 : 900);
        return $location;
    }

    /**
     * Readings that could also be a building or need a map: a single name ("Skyline Towers"),
     * a street name that is often a building's ("Skyline Square"), "Name, City, ST" read as two
     * cities, and a house number on a street with no town.
     */
    public static function ambiguous(array $parsed): bool
    {
        return match ($parsed['type']) {
            'city' => true,
            'street' => !empty($parsed['name_like']),
            'address' => !empty($parsed['partial']),
            'multi' => count($parsed['parts']) === 2 && $parsed['parts'][0]['type'] === 'city' && $parsed['parts'][1]['type'] === 'city',
            default => false,
        };
    }

    private static function stateAndHead(string $q): array
    {
        $head = trim($q);
        $zip = '';
        if (preg_match('/\s+(\d{5})(?:-\d{4})?$/', $head, $m)) {
            $zip = $m[1];
            $head = rtrim(substr($head, 0, -strlen($m[0])), ', ');
        }
        if (preg_match('/^(.*?)[,\s]+([a-z]{2})$/i', $head, $m) && ($st = Address::stateAbbr($m[2]))) {
            return [$st,trim($m[1], ', '),$zip];
        }
        foreach (Address::STATES as $st=>$name) {
            if (preg_match('/^(.*?)[,\s]+' . preg_quote($name,'/') . '$/i', $head, $m)) {
                return [$st,trim($m[1], ', '),$zip];
            }
        }
        return [Address::homeState(),$head,$zip];
    }

    public static function fromFeatures(string $query, array $features): ?array
    {
        $q = self::normalize($query);
        [$state,$head,$zip] = self::stateAndHead($q);
        $parsed = Address::parseLocation($q);
        $isAddress = preg_match('/^\d/', $q) === 1;
        $valid = [];
        foreach ($features as $feature) {
            $p = $feature['properties'] ?? [];
            if (is_array($p) && strtoupper((string)($p['countrycode']??'')) === 'US' && Address::stateAbbr((string)($p['state']??'')) === $state
                && (!$zip || ($p['postcode']??'') === $zip)) {
                $valid[] = $feature;
            }
        }
        if (!$isAddress && ($parsed['type']??'') === 'city') {
            foreach ($valid as $feature) {
                $p = $feature['properties'];
                if (($p['osm_key']??'') === 'place' && in_array($p['osm_value']??'', ['city','town','village'],true)
                    && strcasecmp((string)($p['name']??''),$parsed['city']) === 0) {
                    return $parsed;
                }
            }
        }
        $addresses = [];
        foreach ($valid as $feature) {
            $p = $feature['properties'];
            if (empty($p['housenumber']) || empty($p['street']) || empty($p['city'])) {
                continue;
            }
            $city = (string)$p['city'];
            $withoutCity = preg_replace('/[,\s]+' . preg_quote($city,'/') . '$/i', '', $head) ?? $head;
            $cityWritten = $withoutCity !== $head;
            $nameOrStreet = trim($withoutCity, ', ');
            if ($isAddress) {
                $base = ['address'=>$p['housenumber'].' '.$p['street'],'city'=>$city,'state'=>$state,'zip'=>$p['postcode']??''];
                if (!Address::sameProperty($base, array_replace($base,['address'=>$nameOrStreet]), true)) {
                    continue;
                }
                if (preg_match('/\b(?:apartment|apt|unit|suite|ste)\.?\s*([a-z0-9-]+(?:\s+[NSEW]\b)?)|\#\s*([a-z0-9-]+(?:\s+[NSEW]\b)?)/i', $nameOrStreet, $unit)) {
                    $base['address'] .= ' Unit ' . trim($unit[1] ?: ($unit[2]??''));
                }
                $hash = Address::hash($base['address'],$base['city'],$base['state'],$base['zip']);
                $addresses[$hash] = ['type'=>'address','key'=>'addr:'.$hash,'label'=>Address::full($base)] + $base;
            } elseif (!empty($p['name']) && BuildingSearch::nameMatches($nameOrStreet,(string)$p['name'])) {
                $label = implode(', ',array_filter([$nameOrStreet,$cityWritten?$city:'',trim($state.' '.$zip)]));
                $loc = BuildingSearch::location($label);
                if ($loc) {
                    $buildings = BuildingSearch::map($valid,$loc);
                    if ($buildings) {
                        return $loc + ['buildings'=>$buildings];
                    }
                }
            }
        }
        // Ambiguous streets need a locality; never select an arbitrary home's data.
        return count($addresses) === 1 ? array_values($addresses)[0] : null;
    }
}

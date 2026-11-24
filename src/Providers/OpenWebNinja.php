<?php
declare(strict_types=1);

namespace App\Providers;

use App\Cache;
use App\Http;
use App\Quota;
use App\Settings;

/**
 * OpenWeb Ninja "Real-Time Zillow Data" client — optional second source.
 * https://www.openwebninja.com/api/real-time-zillow-data
 *
 * What it adds over RentCast: photos, the listing description, Zillow's price
 * and tax history, Zestimate / rent Zestimate, and nearby sold comparables.
 *
 * Field mapping for /property-details-address follows the provider's published
 * sample. /search results are mapped defensively because the item shape is not
 * documented field by field.
 */
final class OpenWebNinja
{
    public const ID = 'openwebninja';
    private const BASE = 'https://api.openwebninja.com/realtime-zillow-data';

    private const TYPES = [
        'SINGLE_FAMILY' => 'Single Family', 'SINGLEFAMILY' => 'Single Family', 'CONDO' => 'Condo',
        'TOWNHOUSE' => 'Townhouse', 'MULTI_FAMILY' => 'Multi-Family', 'MULTIFAMILY' => 'Multi-Family',
        'MANUFACTURED' => 'Manufactured', 'APARTMENT' => 'Apartment', 'LOT' => 'Land', 'LAND' => 'Land',
    ];

    public static function switchedOn(): bool
    {
        return Settings::get('openwebninja_on', '1') === '1';
    }

    public static function enabled(): bool
    {
        return self::switchedOn() && Settings::has('openwebninja_key');
    }

    private static function call(string $path, array $params, string $ctx): array
    {
        if (!self::switchedOn()) {
            return ['ok' => false, 'data' => null, 'error' => t('Zillow data is turned off in Settings.')];
        }
        if (!self::enabled()) {
            return ['ok' => false, 'data' => null, 'error' => 'Add your OpenWeb Ninja key in Settings first.'];
        }
        if (!Quota::can(self::ID, $ctx)) {
            return ['ok' => false, 'data' => null, 'error' => 'This month’s Zillow-data requests are used up. They reset in ' . plural(Quota::resetsIn(), 'day') . '.'];
        }
        $cacheKey = Cache::key(self::ID, $path, $params);
        if (!RentCast::$fresh) {
            $kept = Cache::get($cacheKey);
            if (is_array($kept)) {
                return ['ok' => true, 'data' => $kept, 'error' => null];
            }
        }
        $res = Http::get(self::BASE . $path . '?' . http_build_query($params), ['x-api-key' => (string) Settings::get('openwebninja_key')], 40);
        if ($res['status'] > 0) {
            Quota::spend(self::ID);
        }
        $json = json_decode($res['body'], true);
        $error = null;
        if ($res['status'] !== 200) {
            $error = match (true) {
                $res['status'] === 0 => 'Could not reach OpenWeb Ninja (' . ($res['error'] ?: 'network error') . ').',
                in_array($res['status'], [401, 403], true) => 'OpenWeb Ninja rejected the API key.',
                $res['status'] === 429 => 'OpenWeb Ninja says the monthly or hourly limit is reached.',
                default => 'OpenWeb Ninja answered ' . $res['status'] . '.',
            };
        } elseif (!is_array($json)) {
            $error = 'OpenWeb Ninja sent a response that could not be read.';
        }
        Quota::log(self::ID, $path, $res['status'], $res['ms'], $error, $ctx);
        $data = is_array($json) ? ($json['data'] ?? $json) : null;
        if ($error === null && is_array($data)) {
            Cache::put($cacheKey, self::ID, $data, 12 * 3600); // a retry after a dropped page costs nothing
        }
        return ['ok' => $error === null, 'data' => $error === null ? $data : null, 'error' => $error];
    }

    /**
     * @param 'sale'|'sold' $mode
     * @return array{ok:bool, error:?string, items:array, truncated:bool}
     */
    public static function search(array $loc, string $mode, string $ctx): array
    {
        $r = self::call('/search', [
            'location' => $loc['label'] ?? '',
            'home_status' => $mode === 'sold' ? 'RECENTLY_SOLD' : 'FOR_SALE',
        ], $ctx);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error'], 'items' => [], 'truncated' => false];
        }
        $items = [];
        foreach (self::findList($r['data']) as $row) {
            $m = self::mapItem($row, $mode);
            if ($m) {
                $items[] = $m;
            }
        }
        // One page only: Zillow search pages are short, so treat the pull as partial.
        return ['ok' => true, 'error' => null, 'items' => $items, 'truncated' => true];
    }

    /** Full details for one address, plus the sold comparables Zillow lists next to it. */
    public static function details(string $fullAddress, string $ctx = 'manual'): array
    {
        $r = self::call('/property-details-address', ['address' => $fullAddress], $ctx);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error'], 'item' => null, 'comps' => []];
        }
        $d = is_array($r['data']) && array_is_list($r['data']) ? ($r['data'][0] ?? []) : (array) $r['data'];
        if (empty($d['zpid']) && empty($d['address'])) {
            return ['ok' => true, 'error' => null, 'item' => null, 'comps' => []];
        }
        $item = self::mapDetails($d);
        $comps = [];
        foreach ((array) ($d['comps'] ?? []) as $c) {
            $m = self::mapItem($c['property'] ?? $c, 'sold');
            if ($m && !empty($m['last_sale_price'])) {
                $comps[] = $m;
            }
        }
        return ['ok' => true, 'error' => null, 'item' => $item, 'comps' => $comps];
    }

    /** Finds the array of property rows wherever the response keeps it. */
    private static function findList(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        if (array_is_list($data)) {
            return isset($data[0]) && is_array($data[0]) ? $data : [];
        }
        foreach (['results', 'props', 'listings', 'properties', 'items'] as $k) {
            if (isset($data[$k]) && is_array($data[$k]) && array_is_list($data[$k])) {
                return $data[$k];
            }
        }
        foreach ($data as $v) {
            if (is_array($v) && array_is_list($v) && isset($v[0]['zpid'])) {
                return $v;
            }
        }
        return [];
    }

    private static function addr(array $r): ?array
    {
        $a = $r['address'] ?? null;
        if (is_array($a)) {
            $out = ['address' => $a['streetAddress'] ?? '', 'city' => $a['city'] ?? '', 'state' => $a['state'] ?? '', 'zip' => $a['zipcode'] ?? ''];
        } elseif (!empty($r['streetAddress'])) {
            $out = ['address' => $r['streetAddress'], 'city' => $r['city'] ?? '', 'state' => $r['state'] ?? '', 'zip' => $r['zipcode'] ?? ''];
        } elseif (is_string($a) && ($parsed = \App\Address::parseFullAddress($a))) {
            $out = $parsed;
        } else {
            return null;
        }
        $unit = $r['unitNumber'] ?? (is_array($a) ? ($a['unitNumber'] ?? null) : null);
        if (is_scalar($unit) && trim((string) $unit) !== '' && !str_contains(\App\Address::normalizeStreet((string) $out['address']), ' unit ')) {
            $out['address'] .= ' Unit ' . trim((string) $unit);
        }
        return trim((string) $out['address']) === '' ? null : $out;
    }

    private static function lotSqft(array $r): ?float
    {
        if (!empty($r['lotSize']) && is_numeric($r['lotSize'])) {
            return (float) $r['lotSize'];
        }
        if (!empty($r['lotAreaValue']) && is_numeric($r['lotAreaValue'])) {
            $unit = strtolower((string) ($r['lotAreaUnits'] ?? $r['lotAreaUnit'] ?? 'sqft'));
            return str_starts_with($unit, 'acre') ? (float) $r['lotAreaValue'] * 43560 : (float) $r['lotAreaValue'];
        }
        return null;
    }

    private static function type(?string $t): ?string
    {
        if (!$t) {
            return null;
        }
        return self::TYPES[strtoupper(str_replace(' ', '_', $t))] ?? self::TYPES[strtoupper($t)] ?? null;
    }

    private static function zillowUrl(array $r): ?string
    {
        $u = $r['hdpUrl'] ?? $r['detailUrl'] ?? null;
        if (!is_string($u) || $u === '') {
            return null;
        }
        return str_starts_with($u, 'http') ? $u : 'https://www.zillow.com' . $u;
    }

    private static function photos(array $r): array
    {
        $photos = [$r['imgSrc'] ?? null];
        foreach (['miniCardPhotos', 'responsivePhotos'] as $field) {
            foreach ((array) ($r[$field] ?? []) as $photo) {
                $photos[] = $photo['url'] ?? null;
            }
        }
        $photos = array_filter($photos, static fn ($url) => is_string($url) && str_starts_with($url, 'https://'));
        return array_slice(array_values(array_unique($photos)), 0, 40);
    }

    private static function mapItem(array $r, string $mode): ?array
    {
        $p = self::addr($r);
        if ($p === null) {
            return null;
        }
        $status = strtoupper((string) ($r['homeStatus'] ?? $r['listingStatus'] ?? ''));
        $sold = $mode === 'sold' || in_array($status, ['SOLD', 'RECENTLY_SOLD'], true);
        $price = $r['price'] ?? null;
        $photos = self::photos($r);
        $schools = SchoolEvidence::normalize((array) ($r['schools'] ?? []));
        $p += [
            'lat' => $r['latitude'] ?? null,
            'lng' => $r['longitude'] ?? null,
            'property_type' => self::type($r['homeType'] ?? $r['propertyType'] ?? null),
            'beds' => $r['bedrooms'] ?? null,
            'baths' => $r['bathrooms'] ?? null,
            'sqft' => $r['livingArea'] ?? $r['livingAreaValue'] ?? null,
            'lot_sqft' => self::lotSqft($r),
            'year_built' => $r['yearBuilt'] ?? null,
            'photo_url' => $photos[0] ?? null,
            'description' => is_string($r['description'] ?? null) ? $r['description'] : null,
            'hoa_fee' => $r['monthlyHoaFee'] ?? null,
            'source_id' => isset($r['zpid']) ? (string) $r['zpid'] : null,
            'source_url' => self::zillowUrl($r),
            'est_value' => $r['zestimate'] ?? null,
            'est_rent' => $r['rentZestimate'] ?? null,
            'est_source' => !empty($r['zestimate']) ? 'zestimate' : null,
            'est_at' => !empty($r['zestimate']) ? now() : null,
            'details' => array_filter([
                'building_name' => self::text($r['buildingName'] ?? $r['apartmentName'] ?? null),
                'zillow_search_photos' => $photos,
                'zillow_search_schools' => $schools,
                'zillow_search_schools_at' => $schools ? now() : null,
            ]),
        ];
        if ($sold) {
            $p['status'] = 'sold';
            $p['last_sale_price'] = $r['lastSoldPrice'] ?? $price;
            $p['last_sale_date'] = $r['dateSold'] ?? $r['dateSoldString'] ?? null;
        } else {
            $p['status'] = $status === 'PENDING' ? 'pending' : 'for_sale';
            $p['list_price'] = $price;
            if (isset($r['daysOnZillow']) && is_numeric($r['daysOnZillow']) && $r['daysOnZillow'] >= 0) {
                $p['listed_date'] = gmdate('Y-m-d', time() - (int) $r['daysOnZillow'] * 86400);
            }
        }
        return $p;
    }

    private static function mapDetails(array $d): ?array
    {
        $status = strtoupper((string) ($d['homeStatus'] ?? ''));
        $p = self::mapItem($d, in_array($status, ['SOLD', 'RECENTLY_SOLD'], true) ? 'sold' : 'sale');
        if ($p === null) {
            return null;
        }
        if (!in_array($status, ['FOR_SALE', 'PENDING', 'SOLD', 'RECENTLY_SOLD'], true)) {
            // Off market: keep whatever listing state we already know, record the last sale only.
            unset($p['status'], $p['list_price'], $p['listed_date']);
            $p['last_sale_price'] = $d['lastSoldPrice'] ?? null;
            $p['last_sale_date'] = $d['dateSoldString'] ?? null;
        }

        $events = [];
        foreach ((array) ($d['priceHistory'] ?? []) as $h) {
            $label = strtolower((string) ($h['event'] ?? ''));
            $ev = match (true) {
                str_contains($label, 'sold') => 'sold',
                str_contains($label, 'price change') => 'price_change',
                str_contains($label, 'relisted') => 'relisted',
                str_contains($label, 'listed for sale') => 'listed',
                str_contains($label, 'removed') => 'removed',
                default => null,
            };
            if ($ev && !empty($h['date'])) {
                $events[] = ['event' => $ev, 'price' => $h['price'] ?? 0, 'date' => $h['date']];
            }
        }
        $taxes = [];
        foreach ((array) ($d['taxHistory'] ?? []) as $t) {
            if (!empty($t['time'])) {
                $taxes[gmdate('Y', (int) ($t['time'] / 1000))] = ['total' => $t['taxPaid'] ?? null, 'value' => $t['value'] ?? null];
            }
        }
        $photos = self::photos($d);
        $facts = (array) ($d['resoFacts'] ?? []);
        krsort($taxes);
        $latest = $taxes ? reset($taxes) : null;

        $basement = $facts['basement'] ?? $facts['basementYN'] ?? null;
        unset($p['details']);
        return $p + [
            'data_status' => 'full',
            'source_site' => 'Zillow',
            'garage_spaces' => $facts['garageParkingCapacity'] ?? (isset($facts['hasGarage']) && !$facts['hasGarage'] ? 0 : null),
            'has_basement' => match (true) {
                $basement === null => null,
                is_bool($basement) => $basement,
                is_array($basement) => $basement !== [] && !in_array('None', $basement, true),
                default => !in_array(strtolower((string) $basement), ['', 'none', 'no', 'false', '0'], true),
            },
            'county' => isset($d['county']) ? preg_replace('/\s+County$/i', '', (string) $d['county']) : null,
            'description' => $d['description'] ?? null,
            'hoa_fee' => $d['monthlyHoaFee'] ?? null,
            'tax_annual' => $latest['total'] ?? null,
            'assessed_value' => $d['taxAssessedValue'] ?? null,
            'agent_name' => $d['attributionInfo']['agentName'] ?? null,
            'agent_phone' => $d['attributionInfo']['agentPhoneNumber'] ?? null,
            'office_name' => $d['attributionInfo']['brokerName'] ?? null,
            'mls_number' => $d['attributionInfo']['mlsId'] ?? null,
            'events' => $events,
            'details' => array_filter([
                'zillow' => true,
                'building_name' => self::text($d['buildingName'] ?? $d['apartmentName'] ?? null),
                'schools' => SchoolEvidence::normalize((array) ($d['schools'] ?? [])),
                'schools_checked_at' => now(),
                'photos' => array_slice($photos, 0, 40) ?: null,
                'zillow_taxes' => $taxes ?: null,
                'parcel_id' => $d['parcelId'] ?? null,
                'interior' => array_slice(array_filter((array) ($facts['interiorFeatures'] ?? []), 'is_string'), 0, 20) ?: null,
                'appliances' => array_slice(array_filter((array) ($facts['appliances'] ?? []), 'is_string'), 0, 20) ?: null,
                'heating' => array_slice(array_filter((array) ($facts['heating'] ?? []), 'is_string'), 0, 6) ?: null,
                'cooling' => array_slice(array_filter((array) ($facts['cooling'] ?? []), 'is_string'), 0, 6) ?: null,
                'parking' => array_slice(array_filter((array) ($facts['parkingFeatures'] ?? []), 'is_string'), 0, 8) ?: null,
                // The rest of Zillow's fact sheet, shown under "Property details" (keys missing on a home are simply left out).
                'listing_extra_zillow' => array_filter([
                    'style' => self::text($facts['architecturalStyle'] ?? null),
                    'levels' => self::text($facts['levels'] ?? ($facts['stories'] ?? null)),
                    'fireplaces' => self::text($facts['fireplaces'] ?? null),
                    'hoa_includes' => self::text($facts['associationFeeIncludes'] ?? null),
                    'subdivision' => self::text($facts['subdivisionName'] ?? null),
                    'community' => self::text($facts['communityFeatures'] ?? null),
                    'construction' => self::text($facts['constructionMaterials'] ?? null),
                    'flooring' => self::text($facts['flooring'] ?? null),
                    'laundry' => self::text($facts['laundryFeatures'] ?? null),
                    'lot_features' => self::text($facts['lotFeatures'] ?? null),
                    'water_heater' => self::text($facts['waterHeater'] ?? null),
                    'listing_status' => isset($d['homeStatus']) ? ucwords(strtolower(str_replace('_', ' ', (string) $d['homeStatus']))) : null,
                ], static fn ($v) => $v !== null && $v !== '') ?: null,
            ], static fn ($v) => $v !== null),
        ];
    }

    /** A Zillow fact as short text: strings kept, lists joined, booleans and numbers spelled out. */
    private static function text(mixed $v): ?string
    {
        if (is_array($v)) {
            $v = implode(', ', array_slice(array_filter($v, static fn ($x) => is_string($x) || is_numeric($x)), 0, 12));
        } elseif (is_bool($v)) {
            $v = $v ? 'Yes' : 'No';
        }
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, 200);
    }
}

<?php
declare(strict_types=1);

namespace App\Providers;

use App\Cache;
use App\Http;
use App\PropertyRepo;
use App\Quota;
use App\Settings;

/**
 * RentCast API client — https://developers.rentcast.io
 *
 * Endpoints used: /listings/sale, /properties, /avm/value, /avm/rent/long-term, /markets.
 * Range filters use "min:max" with "*" for an open end; multiple values use "|".
 * A 404 from RentCast means "no results", not a failure.
 */
final class RentCast
{
    public const ID = 'rentcast';
    private const BASE = 'https://api.rentcast.io/v1';
    public const PAGE = 500;
    /** How long an answered request is kept, so a repeat (or a retry after a dropped page) costs nothing. */
    private const KEEP = 12 * 3600;

    /** Set by a deliberate refresh: skip the kept answer and ask RentCast again. */
    public static bool $fresh = false;

    /** HTTP status of the last request (0 = no answer); lets a search fall back to another source. */
    public static int $lastStatus = 0;

    public const DASHBOARD = 'https://app.rentcast.io/app/api';

    /** A refused key: remember why, so Settings and Search can say what to do. */
    public static function keyProblem(int $status, string $message, ?string $at = null): string
    {
        $inactive = $status === 403 && stripos($message, 'subscription') !== false;
        $why = $inactive
            ? 'RentCast says this key has no active API plan yet. Turn on the free Developer plan on the RentCast API dashboard, then search again.'
            : 'RentCast did not accept the key. Check that it was copied whole into Settings, or create a new one on the RentCast API dashboard.';
        Settings::set('rentcast_key_problem', json_encode(['why' => $why, 'status' => $status, 'at' => $at ?? now(), 'inactive' => $inactive]));
        return $why;
    }

    /** @return array{why:string, status:int, at:string, inactive:bool}|null */
    public static function problem(): ?array
    {
        $p = json_decode((string) Settings::get('rentcast_key_problem', ''), true);
        return is_array($p) && isset($p['why']) ? $p : null;
    }

    private static function baseUrl(): string
    {
        // Local tests point this at a fake server; the live site always talks to RentCast.
        $local = (string) \App\Config::get('rentcast_base', '');
        $site = (string) \App\Config::get('base_url', '');
        return $local !== '' && preg_match('~^https?://(127\.0\.0\.1|localhost)[:/]~', $site) ? rtrim($local, '/') : self::BASE;
    }

    public static function enabled(): bool
    {
        return Settings::has('rentcast_key');
    }

    /**
     * @return array{ok:bool, status:int, data:mixed, error:?string}
     */
    private static function call(string $path, array $params, string $ctx): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Add your RentCast key in Settings first.'];
        }
        if (!Quota::can(self::ID, $ctx)) {
            $why = $ctx === 'cron'
                ? 'The RentCast requests set aside for alerts are used up for this month.'
                : 'This month’s RentCast requests are used up. They reset in ' . plural(Quota::resetsIn(), 'day') . '.';
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $why];
        }
        $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
        $cacheKey = Cache::key(self::ID, $path, $params);
        if (!self::$fresh) {
            $kept = Cache::get($cacheKey);
            if (is_array($kept)) {
                return ['ok' => true, 'status' => 200, 'data' => $kept, 'error' => null, 'cached' => true];
            }
        }
        $res = Http::get(self::baseUrl() . $path . '?' . http_build_query($params), ['X-Api-Key' => (string) Settings::get('rentcast_key')], 30);
        self::$lastStatus = $res['status'];
        // Counted on every answered request, to stay on the safe side of the allowance — except a refused
        // key (401/403): RentCast does not bill those, and they say nothing was done.
        if ($res['status'] > 0 && !in_array($res['status'], [401, 403], true)) {
            Quota::spend(self::ID);
        }
        $json = json_decode($res['body'], true);
        $error = null;
        if ($res['status'] === 404) {
            $json = [];
        } elseif ($res['status'] !== 200) {
            $error = match (true) {
                $res['status'] === 0 => 'Could not reach RentCast (' . ($res['error'] ?: 'network error') . ').',
                in_array($res['status'], [401, 403], true) => self::keyProblem($res['status'], is_array($json) ? (string) ($json['message'] ?? '') : ''),
                $res['status'] === 429 => 'RentCast is rate-limiting requests. Try again in a minute.',
                default => 'RentCast answered ' . $res['status'] . (is_array($json) && !empty($json['message']) ? ': ' . $json['message'] : '.'),
            };
        } elseif (!is_array($json)) {
            $error = 'RentCast sent a response that could not be read.';
        }
        Quota::log(self::ID, $path, $res['status'], $res['ms'], $error, $ctx);
        if ($error === null && Settings::get('rentcast_key_problem') !== null) {
            Settings::set('rentcast_key_problem', null);
        }
        if ($error === null) {
            // Kept straight away: if the page is cut off after this point, asking again is free.
            Cache::put($cacheKey, self::ID, $json, self::KEEP);
        }
        return ['ok' => $error === null, 'status' => $res['status'], 'data' => $error === null ? $json : null, 'error' => $error, 'cached' => false];
    }

    private static function range(mixed $min, mixed $max): ?string
    {
        $min = is_numeric($min) && $min > 0 ? (string) ($min + 0) : null;
        $max = is_numeric($max) && $max > 0 ? (string) ($max + 0) : null;
        if ($min === null && $max === null) {
            return null;
        }
        return ($min ?? '*') . ':' . ($max ?? '*');
    }

    private static function where(array $loc): array
    {
        return match ($loc['type'] ?? '') {
            'zip' => ['zipCode' => $loc['zip']],
            'city' => ['city' => $loc['city'], 'state' => $loc['state']],
            'state' => ['state' => $loc['state']],
            // RentCast has no county filter: the county's own circle when it is known (CountyArea), else the
            // state — either way with the filters, and narrowed to the county locally.
            'county' => isset($loc['lat'], $loc['lng'], $loc['radius'])
                ? ['latitude' => $loc['lat'], 'longitude' => $loc['lng'], 'radius' => $loc['radius']]
                : ['state' => $loc['state']],
            'point' => ['latitude' => $loc['lat'], 'longitude' => $loc['lng'], 'radius' => $loc['radius'] ?? 1],
            default => [],
        };
    }

    /** The subset of search filters RentCast can apply server-side. */
    public static function pushable(array $f, bool $withPrice = true): array
    {
        $types = array_values(array_intersect(PropertyRepo::TYPES, (array) ($f['types'] ?? [])));
        return array_filter([
            'propertyType' => $types ? implode('|', $types) : null,
            'bedrooms' => self::range($f['beds_min'] ?? null, $f['beds_max'] ?? null),
            'bathrooms' => self::range($f['baths_min'] ?? null, $f['baths_max'] ?? null),
            'squareFootage' => self::range($f['sqft_min'] ?? null, $f['sqft_max'] ?? null),
            'lotSize' => self::range($f['lot_min'] ?? null, $f['lot_max'] ?? null),
            'yearBuilt' => self::range($f['year_min'] ?? null, $f['year_max'] ?? null),
            'price' => $withPrice ? self::range($f['price_min'] ?? null, $f['price_max'] ?? null) : null,
            // Listings only: days since listed. "New listings" alone means the last 7 days.
            'daysOld' => $withPrice ? self::range($f['dom_min'] ?? null, $f['dom_max'] ?? ((array) ($f['listing'] ?? []) === ['new'] ? 7 : null)) : null,
        ]);
    }

    /**
     * Active for-sale listings in an area.
     * @return array{ok:bool, error:?string, items:array, truncated:bool}
     */
    public static function saleListings(array $loc, array $filters, string $ctx): array
    {
        $r = self::call('/listings/sale', self::where($loc) + self::pushable($filters) + ['status' => 'Active', 'limit' => self::PAGE], $ctx);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error'], 'items' => [], 'truncated' => false];
        }
        $rows = array_is_list((array) $r['data']) ? (array) $r['data'] : [];
        return [
            'ok' => true, 'error' => null,
            'items' => array_values(array_filter(array_map([self::class, 'mapListing'], $rows))),
            'truncated' => count($rows) >= self::PAGE,
        ];
    }

    /**
     * Property records whose last recorded sale falls inside the past $days.
     * @return array{ok:bool, error:?string, items:array, truncated:bool}
     */
    public static function soldRecords(array $loc, int $days, array $filters, string $ctx): array
    {
        $params = self::where($loc) + self::pushable($filters, false) + ['saleDateRange' => '*:' . max(1, $days), 'limit' => self::PAGE];
        $r = self::call('/properties', $params, $ctx);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error'], 'items' => [], 'truncated' => false];
        }
        $rows = array_is_list((array) $r['data']) ? (array) $r['data'] : [];
        return [
            'ok' => true, 'error' => null,
            'items' => array_values(array_filter(array_map([self::class, 'mapRecord'], $rows))),
            'truncated' => count($rows) >= self::PAGE,
        ];
    }

    /** Public-record data for one address. */
    public static function record(string $fullAddress, string $ctx = 'manual'): array
    {
        $r = self::call('/properties', ['address' => $fullAddress], $ctx);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error'], 'item' => null];
        }
        $row = is_array($r['data']) && array_is_list($r['data']) ? ($r['data'][0] ?? null) : null;
        return ['ok' => true, 'error' => null, 'item' => $row ? self::mapRecord($row) : null];
    }

    /** Value estimate plus the comparable listings RentCast based it on. */
    public static function value(array $p, string $ctx = 'manual'): array
    {
        $r = self::call('/avm/value', self::subject($p) + ['compCount' => 20], $ctx);
        if (!$r['ok'] || empty($r['data']['price'])) {
            return ['ok' => false, 'error' => $r['error'] ?? 'RentCast has no value estimate for this address.'];
        }
        $d = $r['data'];
        $comps = [];
        foreach ((array) ($d['comparables'] ?? []) as $c) {
            $m = self::mapListing($c);
            if ($m) {
                $comps[] = $m + ['distance' => $c['distance'] ?? null, 'correlation' => $c['correlation'] ?? null];
            }
        }
        return [
            'ok' => true, 'error' => null,
            'value' => (int) $d['price'], 'low' => (int) ($d['priceRangeLow'] ?? 0), 'high' => (int) ($d['priceRangeHigh'] ?? 0),
            'comps' => $comps,
        ];
    }

    public static function rent(array $p, string $ctx = 'manual'): array
    {
        $r = self::call('/avm/rent/long-term', self::subject($p) + ['compCount' => 10], $ctx);
        if (!$r['ok'] || empty($r['data']['rent'])) {
            return ['ok' => false, 'error' => $r['error'] ?? 'RentCast has no rent estimate for this address.'];
        }
        return ['ok' => true, 'error' => null, 'rent' => (int) $r['data']['rent'],
            'low' => (int) ($r['data']['rentRangeLow'] ?? 0), 'high' => (int) ($r['data']['rentRangeHigh'] ?? 0)];
    }

    /**
     * Listing statistics for a ZIP, with monthly history.
     * @return array{ok:bool, error:?string, now?:array, history?:array<string,array>}
     */
    public static function market(string $zip, string $ctx = 'manual'): array
    {
        $r = self::call('/markets', ['zipCode' => $zip, 'dataType' => 'Sale', 'historyRange' => 36], $ctx);
        if (!$r['ok'] || empty($r['data']['saleData'])) {
            return ['ok' => false, 'error' => $r['error'] ?? 'RentCast has no market data for ' . $zip . '.'];
        }
        $s = $r['data']['saleData'];
        return ['ok' => true, 'error' => null, 'now' => $s, 'history' => (array) ($s['history'] ?? [])];
    }

    private static function subject(array $p): array
    {
        return array_filter([
            'address' => \App\Address::full($p),
            'propertyType' => in_array($p['property_type'] ?? '', PropertyRepo::TYPES, true) ? $p['property_type'] : null,
            'bedrooms' => $p['beds'] ?? null,
            'bathrooms' => $p['baths'] ?? null,
            'squareFootage' => $p['sqft'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    private static function base(array $r): ?array
    {
        $line = trim(($r['addressLine1'] ?? '') . ' ' . ($r['addressLine2'] ?? ''));
        if ($line === '' || (empty($r['zipCode']) && empty($r['city']))) {
            return null;
        }
        return [
            'address' => $line,
            'city' => $r['city'] ?? '',
            'state' => $r['state'] ?? '',
            'zip' => $r['zipCode'] ?? '',
            'county' => $r['county'] ?? null,
            'lat' => $r['latitude'] ?? null,
            'lng' => $r['longitude'] ?? null,
            'property_type' => $r['propertyType'] ?? null,
            'beds' => $r['bedrooms'] ?? null,
            'baths' => $r['bathrooms'] ?? null,
            'sqft' => $r['squareFootage'] ?? null,
            'lot_sqft' => $r['lotSize'] ?? null,
            'year_built' => $r['yearBuilt'] ?? null,
            'hoa_fee' => $r['hoa']['fee'] ?? null,
            'source_id' => $r['id'] ?? null,
        ];
    }

    public static function mapListing(array $r): ?array
    {
        $p = self::base($r);
        if ($p === null) {
            return null;
        }
        $active = ($r['status'] ?? '') === 'Active';
        $events = [];
        foreach ((array) ($r['history'] ?? []) as $date => $h) {
            if (!empty($h['price'])) {
                $events[] = ['event' => 'listed', 'price' => $h['price'], 'date' => $h['listedDate'] ?? $date];
                if (!empty($h['removedDate'])) {
                    $events[] = ['event' => 'removed', 'price' => $h['price'], 'date' => $h['removedDate']];
                }
            }
        }
        return $p + [
            'status' => $active ? 'for_sale' : 'off_market',
            'list_price' => $r['price'] ?? null,
            'listed_date' => $r['listedDate'] ?? null,
            'days_on_market' => $r['daysOnMarket'] ?? null,
            'listing_type' => $r['listingType'] ?? null,
            'mls_name' => $r['mlsName'] ?? null,
            'mls_number' => $r['mlsNumber'] ?? null,
            'agent_name' => $r['listingAgent']['name'] ?? null,
            'agent_phone' => $r['listingAgent']['phone'] ?? null,
            'agent_email' => $r['listingAgent']['email'] ?? null,
            'office_name' => $r['listingOffice']['name'] ?? null,
            'events' => $events,
            'details' => array_filter([
                'builder' => $r['builder'] ?? null,
                'office' => $r['listingOffice'] ?? null,
                'agent_website' => $r['listingAgent']['website'] ?? null,
            ]),
        ];
    }

    public static function mapRecord(array $r): ?array
    {
        $p = self::base($r);
        if ($p === null) {
            return null;
        }
        $events = [];
        foreach ((array) ($r['history'] ?? []) as $date => $h) {
            if (($h['event'] ?? '') === 'Sale' && !empty($h['price'])) {
                $events[] = ['event' => 'sold', 'price' => $h['price'], 'date' => $h['date'] ?? $date];
            }
        }
        $taxes = (array) ($r['propertyTaxes'] ?? []);
        $assess = (array) ($r['taxAssessments'] ?? []);
        krsort($taxes);
        krsort($assess);
        $latestTax = $taxes ? reset($taxes) : null;
        $latestAssess = $assess ? reset($assess) : null;
        return $p + [
            'garage_spaces' => $r['features']['garageSpaces'] ?? (isset($r['features']['garage']) && !$r['features']['garage'] ? 0 : null),
            'last_sale_price' => $r['lastSalePrice'] ?? null,
            'last_sale_date' => $r['lastSaleDate'] ?? null,
            'tax_annual' => $latestTax['total'] ?? null,
            'assessed_value' => $latestAssess['value'] ?? null,
            'events' => $events,
            'details' => array_filter([
                'record' => true,
                'assessor_id' => $r['assessorID'] ?? null,
                'legal' => $r['legalDescription'] ?? null,
                'subdivision' => $r['subdivision'] ?? null,
                'zoning' => $r['zoning'] ?? null,
                'features' => $r['features'] ?? null,
                'taxes' => $taxes ?: null,
                'assessments' => $assess ?: null,
                'owner' => isset($r['owner']) ? array_filter([
                    'names' => $r['owner']['names'] ?? null,
                    'type' => $r['owner']['type'] ?? null,
                    'mailing' => $r['owner']['mailingAddress']['formattedAddress'] ?? null,
                ]) : null,
                'owner_occupied' => $r['ownerOccupied'] ?? null,
            ], static fn ($v) => $v !== null && $v !== []),
        ];
    }
}

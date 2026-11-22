<?php
declare(strict_types=1);

namespace App\Providers;

use App\Address;
use App\Cache;
use App\Geo;
use App\Http;
use App\Quota;
use App\Settings;
use DOMDocument;
use DOMElement;
use DOMXPath;

/** Public, first-page listing evidence. No private endpoints, browser challenge bypass or API quota. */
final class HomesCom
{
    public const ID = 'homes';
    public const TTL = 3600;
    public const CACHE_VERSION = 'public-search-v2';

    public static function enabled(): bool
    {
        return Settings::get('homes_on', '1') === '1';
    }

    /** Known public page shapes. ZIP pages require a verified postal city/state. */
    public static function pageUrl(array $loc): ?string
    {
        $state = strtolower((string) ($loc['state'] ?? ''));
        $slug = static fn (string $s): string => Address::slug($s, '-', true);
        return match ($loc['type']) {
            'zip' => !empty($loc['city']) && $state !== '' ? 'https://www.homes.com/' . $slug($loc['city']) . '-' . $state . '/' . $loc['zip'] . '/' : null,
            'city' => 'https://www.homes.com/' . $slug($loc['city']) . '-' . $state . '/',
            'county' => 'https://www.homes.com/' . $slug($loc['county']) . '-county-' . $state . '/',
            'state' => isset(Address::STATES[strtoupper($state)]) ? 'https://www.homes.com/' . $slug(Address::STATES[strtoupper($state)]) . '/' : null,
            default => null,
        };
    }

    /** @return array{ok:bool, items:array, observed_at:string, cached:bool, error:?string, url:?string} */
    public static function search(array $loc, string $ctx = 'manual', float $budget = 10): array
    {
        $started = microtime(true);
        $key = Cache::key(self::ID, self::CACHE_VERSION, ['key' => $loc['key']]);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return array_replace($cached, ['cached' => true]);
        }
        $out = ['ok' => false, 'items' => [], 'observed_at' => now(), 'cached' => false, 'error' => null, 'url' => null];
        if (Cache::get(Cache::key(self::ID, 'host-cooldown')) !== null) {
            $out['error'] = 'Homes.com is temporarily unavailable; RentCast and saved results are still shown.';
            return $out; // do not hammer a refusing host
        }
        if ($loc['type'] === 'zip' && (empty($loc['city']) || empty($loc['state']))) {
            $postal = self::postal($loc['zip'], max(1, min(3, (int) $budget)));
            if (!$postal) {
                $out['error'] = 'Homes.com could not resolve the ZIP page; saved results are still shown.';
                Cache::put($key, self::ID, $out, 900);
                return $out;
            }
            $loc = array_replace($loc, $postal);
        }
        $url = self::pageUrl($loc);
        $remaining = $budget - (microtime(true) - $started);
        if (!$url || $remaining < 1) {
            $out['error'] = 'Homes.com could not resolve this area within the search time limit.';
            return $out;
        }
        $out['url'] = $url;
        $r = Http::get($url, ['Accept' => 'text/html'], max(1, min(8, (int) $remaining)));
        Quota::log(self::ID, (string) parse_url($url, PHP_URL_PATH), $r['status'], $r['ms'], $r['error'], $ctx);
        if ($r['status'] !== 200) {
            $out['error'] = 'Homes.com is temporarily unavailable; RentCast and saved results are still shown.';
            if (in_array($r['status'], [403, 429, 503], true)) {
                Cache::put(Cache::key(self::ID, 'host-cooldown'), self::ID, true, 3600);
            }
        } else {
            $parsed = self::parse($r['body'], $loc, $url, $out['observed_at']);
            $out['ok'] = $parsed['ok'];
            $out['items'] = $parsed['items'];
            $out['error'] = $parsed['ok'] ? null : 'Homes.com page data could not be verified; its listings were not imported.';
        }
        Cache::put($key, self::ID, $out, $out['ok'] ? self::TTL : 900);
        return $out;
    }

    private static function postal(string $zip, int $timeout): ?array
    {
        if (!preg_match('/^\d{5}$/', $zip)) {
            return null;
        }
        return Cache::remember(self::ID, 'postal-city', ['zip' => $zip], 86400 * 90, static function () use ($zip, $timeout): ?array {
            $r = Http::get('https://api.zippopotam.us/us/' . $zip, [], $timeout);
            $d = json_decode($r['body'], true);
            $p = $d['places'][0] ?? [];
            $st = Address::stateAbbr((string) ($p['state abbreviation'] ?? ''));
            return $r['status'] === 200 && ($d['post code'] ?? '') === $zip && !empty($p['place name']) && $st
                ? ['city' => (string) $p['place name'], 'state' => $st] : null;
        });
    }

    public static function propertyUrl(mixed $url): ?string
    {
        if (!is_string($url)) {
            return null;
        }
        $url = str_starts_with($url, '/property/') ? 'https://www.homes.com' . $url : $url;
        return preg_match('#^https://www\.homes\.com/property/[a-z0-9-]+/[a-z0-9]+/$#i', $url) ? $url : null;
    }

    public static function photoUrl(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https://images\.homes\.com/listings/[a-z0-9/_.%-]+(?:\?[^\s]*)?$#i',$url) ? $url : null;
    }

    public static function parse(string $html, array $loc, string $requestedUrl, string $observed): array
    {
        $bad = ['ok' => false, 'items' => []];
        if (!class_exists(DOMDocument::class) || strlen($html) > 4 * 1024 * 1024) {
            return $bad;
        }
        $dom = new DOMDocument();
        $before = libxml_use_internal_errors(true);
        try {
            if (!$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return $bad;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }
        $xp = new DOMXPath($dom);
        $canonical = $xp->query('//link[@rel="canonical"]')->item(0)?->getAttribute('href');
        // Redirected or unrelated area pages must never be attributed to the requested area.
        if ($canonical !== $requestedUrl || !str_starts_with($requestedUrl, 'https://www.homes.com/') || str_contains($requestedUrl, '/sold/')) {
            return $bad;
        }
        $cards = [];
        foreach ($xp->query('//article[contains(concat(" ",normalize-space(@class)," ")," for-sale-placard ")]') as $card) {
            if ($card instanceof DOMElement && $card->getAttribute('data-est') === 'for sale') {
                $cards[$card->getAttribute('data-pk')] = $card;
            }
        }
        $found = false;
        $items = [];
        foreach ($xp->query('//script[@type="application/ld+json"]') as $script) {
            $json = json_decode($script->textContent, true);
            foreach ((array) ($json['@graph'] ?? [$json]) as $graph) {
                if (!is_array($graph) || ($graph['@type'] ?? '') !== 'CollectionPage' || ($graph['url'] ?? '') !== $canonical
                    || ($graph['mainEntity']['@type'] ?? '') !== 'ItemList') {
                    continue;
                }
                $found = true;
                foreach (array_slice((array) ($graph['mainEntity']['itemListElement'] ?? []), 0, 60) as $listing) {
                    if (!is_array($listing) || !in_array('RealEstateListing', (array) ($listing['@type'] ?? []), true)) {
                        continue;
                    }
                    $url = self::propertyUrl($listing['url'] ?? null);
                    $id = $url ? basename(rtrim($url, '/')) : '';
                    $card = $cards[$id] ?? null;
                    $home = $listing['mainEntity'] ?? [];
                    $a = is_array($home) ? ($home['address'] ?? []) : [];
                    $address = self::address((array) $a);
                    $offer = $listing['offers'] ?? [];
                    if (!$url || !$card || !$address || ($offer['priceCurrency'] ?? '') !== 'USD' || ($offer['availability'] ?? '') !== 'https://schema.org/InStock'
                        || !is_numeric($offer['price'] ?? null) || $offer['price'] <= 0 || $offer['price'] >= 4000000000
                        || ($listing['potentialAction']['@type'] ?? '') !== 'BuyAction') {
                        continue;
                    }
                    $visibleAddress = trim($xp->query('.//address', $card)->item(0)?->textContent ?? '');
                    $cardAddress = Address::parseFullAddress($visibleAddress);
                    if (!$cardAddress || !Address::sameProperty($address, $cardAddress) || !self::inArea($address, $loc)) {
                        continue;
                    }
                    $category = (string) ($home['accommodationCategory'] ?? $home['@type'] ?? '');
                    $item = $address + [
                        'status' => 'for_sale', 'list_price' => (float) $offer['price'],
                        'property_type' => match (strtolower($category)) {
                            'singlefamilyresidence', 'single family', 'single family residence' => 'Single Family',
                            'condominium', 'condo' => 'Condo', 'townhouse', 'townhome' => 'Townhouse',
                            'apartment' => 'Apartment', 'multifamilyresidence', 'multi-family' => 'Multi-Family',
                            'manufacturedhome', 'manufactured' => 'Manufactured', 'land', 'landform' => 'Land', default => null,
                        },
                        'beds' => $home['numberOfBedrooms'] ?? null,
                        'baths' => $home['numberOfBathroomsTotal'] ?? null,
                        'sqft' => ($home['floorSize']['unitCode'] ?? '') === 'FTK' ? ($home['floorSize']['value'] ?? null) : null,
                        'description' => mb_substr(strip_tags((string) ($listing['description'] ?? '')), 0, 2500),
                        'source_id' => $id, 'source_url' => $url, 'source_site' => 'Homes.com', 'data_status' => 'limited',
                        'agent_name' => $offer['offeredBy']['name'] ?? null, 'office_name' => $offer['seller']['name'] ?? null,
                    ];
                    // The visible card retains fractional bathrooms that the page's schema sometimes rounds.
                    foreach ($xp->query('.//ul[contains(@class,"detailed-info-container")]/li', $card) as $detail) {
                        $text = trim($detail->textContent);
                        if (preg_match('/^([\d,.]+)\s+(Beds?|Baths?|Sq\s*Ft)$/i', $text, $m)) {
                            $field = str_starts_with(strtolower($m[2]), 'bed') ? 'beds' : (str_starts_with(strtolower($m[2]), 'bath') ? 'baths' : 'sqft');
                            $item[$field] = (float) str_replace(',', '', $m[1]);
                        }
                    }
                    $photos = [];
                    $photo = self::photoUrl($home['image'] ?? $listing['image'] ?? null);
                    if ($photo) {
                        $photos[] = $photo;
                    }
                    // Only gallery images inside this verified unit's card; never agent photos or adjacent cards.
                    foreach ($xp->query('.//img[contains(concat(" ",normalize-space(@class)," ")," embla__slide__img ")]',$card) as $image) {
                        $photo = self::photoUrl($image->getAttribute('data-defer-src')) ?? self::photoUrl($image->getAttribute('src'));
                        if ($photo) {
                            $photos[] = $photo;
                        }
                    }
                    $photos = array_slice(array_values(array_unique($photos)),0,24);
                    if ($photos) {
                        $item['photo_url'] = $photos[0];
                    }
                    if (Geo::valid($home['geo']['latitude'] ?? null, $home['geo']['longitude'] ?? null)) {
                        $item['lat'] = (float) $home['geo']['latitude'];
                        $item['lng'] = (float) $home['geo']['longitude'];
                    }
                    foreach ((array) ($listing['subjectOf'] ?? []) as $event) {
                        if (isset($item['lat'])) {
                            break;
                        }
                        $place = is_array($event) ? ($event['location'] ?? []) : [];
                        $eventAddress = self::address((array) ($place['address'] ?? []));
                        $geo = $place['geo'] ?? [];
                        if ($eventAddress && Address::sameProperty($address, $eventAddress) && Geo::valid($geo['latitude'] ?? null, $geo['longitude'] ?? null)) {
                            $item['lat'] = (float) $geo['latitude'];
                            $item['lng'] = (float) $geo['longitude'];
                            break;
                        }
                    }
                    if ($loc['type'] === 'county') {
                        $item['county'] = $loc['county']; // membership asserted by this verified county collection page
                    }
                    $item['details'] = ['homes' => ['url' => $url, 'observed_at' => $observed, 'page_url' => $canonical,
                        'address_hash'=>Address::hash($item['address'],$item['city'],$item['state'],$item['zip']), 'photos'=>$photos,
                        'price' => $item['list_price'], 'beds' => $item['beds'], 'baths' => $item['baths'], 'sqft' => $item['sqft'], 'partial' => true]];
                    $items[$id] = $item;
                }
            }
        }
        return ['ok' => $found, 'items' => array_values($items)];
    }

    private static function address(array $a): ?array
    {
        $state = Address::stateAbbr((string) ($a['addressRegion'] ?? ''));
        $street = trim((string) ($a['streetAddress'] ?? ''));
        $zip = (string) ($a['postalCode'] ?? '');
        return $state && preg_match('/^\d+[a-z]?\s+\S+/i', $street) && !empty($a['addressLocality']) && preg_match('/^\d{5}$/', $zip)
            && strtoupper((string) ($a['addressCountry'] ?? 'US')) === 'US'
            ? ['address' => $street, 'city' => Address::titleCity($a['addressLocality']), 'state' => $state, 'zip' => $zip] : null;
    }

    private static function inArea(array $address, array $loc): bool
    {
        if ($loc['type'] === 'zip') {
            return $address['zip'] === $loc['zip'] && (empty($loc['state']) || $address['state'] === $loc['state']);
        }
        return $address['state'] === $loc['state']
            && ($loc['type'] !== 'city' || strcasecmp($address['city'], $loc['city']) === 0);
    }
}

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
    public const CACHE_VERSION = 'public-search-v4';
    public const COOLDOWN_KEY = 'host-cooldown-v2';

    public static function enabled(): bool
    {
        return Settings::get('homes_on', '1') === '1';
    }

    /** Known public page shapes. ZIP pages require a verified postal city/state. */
    public static function pageUrl(array $loc): ?string
    {
        $state = strtolower((string) ($loc['state'] ?? ''));
        $slug = static fn (string $s): string => Address::slug($s, '-', true);
        $division = $state === 'la' ? 'parish' : 'county';
        return match ($loc['type']) {
            'zip' => !empty($loc['city']) && $state !== '' ? 'https://www.homes.com/' . $slug($loc['city']) . '-' . $state . '/' . $loc['zip'] . '/' : null,
            'city' => 'https://www.homes.com/' . $slug($loc['city']) . '-' . $state . '/',
            'county' => 'https://www.homes.com/' . $slug($loc['county']) . '-' . $division . '-' . $state . '/',
            'state' => isset(Address::STATES[strtoupper($state)]) ? 'https://www.homes.com/' . $slug(Address::STATES[strtoupper($state)]) . '/' : null,
            default => null,
        };
    }

    /** Official collection page for a browser visit, including when the host is cooling down. */
    public static function browserPage(array $scope): ?string
    {
        if ($scope['type'] === 'zip' && empty($scope['city'])) {
            $postal = self::postal($scope['zip'], 2);
            if (!$postal) return null;
            $scope = array_replace($scope, $postal);
        }
        return self::pageUrl($scope);
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
        $out = ['ok' => false, 'items' => [], 'observed_at' => now(), 'cached' => false, 'error' => null, 'url' => null,
            'http_status'=>null, 'error_code'=>null, 'retry_at'=>null];
        if (!class_exists(DOMDocument::class)) {
            $out['error']='Homes.com needs the PHP DOM/XML extension enabled on this host.';
            $out['error_code']='missing_dom';
            return $out;
        }
        $cooldown = Cache::get(Cache::key(self::ID, self::COOLDOWN_KEY));
        if (is_array($cooldown)) {
            $out = array_replace($out, array_intersect_key($cooldown, array_flip(['error','error_code','http_status','retry_at'])));
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
        $zipAlias = null;
        if ($loc['type'] === 'zip' && preg_match('/^(St|Ste|Saint|Sainte)\.?\s+(.+)$/i', $loc['city'], $name)) {
            $alternate = match (strtolower($name[1])) {
                'st' => 'Saint', 'ste' => 'Sainte', 'saint' => 'St', 'sainte' => 'Ste',
            };
            $zipAlias = self::pageUrl(array_replace($loc, ['city' => $alternate . ' ' . $name[2]]));
        }
        $remaining = $budget - (microtime(true) - $started);
        if (!$url || $remaining < 1) {
            $out['error'] = 'Homes.com could not resolve this area within the search time limit.';
            return $out;
        }
        $out['url'] = $url;
        for ($attempt=0; $attempt<2; $attempt++) {
            $remaining=$budget-(microtime(true)-$started);
            $r = Http::get($url, ['Accept' => 'text/html'], max(1, min(8, (int) $remaining)));
            Quota::log(self::ID, (string) parse_url($url, PHP_URL_PATH), $r['status'], $r['ms'], $r['error'], $ctx);
            $failure=self::failure($r);
            // One retry for a network or temporary gateway failure, inside the original budget.
            // Refusals, rate limits and explicit Retry-After instructions are never retried here.
            if ($attempt > 0 || $budget - (microtime(true) - $started) < 2) break;
            if ($r['status'] === 404 && $zipAlias && $zipAlias !== $url) {
                $out['url'] = $url = $zipAlias;
            } elseif (!$failure['retryable']) {
                break;
            }
        }
        $out['http_status']=$r['status'];
        if ($r['status'] !== 200) {
            $out['error']=$failure['message'];$out['error_code']=$failure['code'];
            $out['retry_at']=gmdate('Y-m-d H:i:s',time()+$failure['delay']);
            if (in_array($r['status'], [403, 429, 503], true)) {
                Cache::put(Cache::key(self::ID, self::COOLDOWN_KEY), self::ID, $out, $failure['delay']);
            }
        } else {
            $parsed = self::parse($r['body'], $loc, $url, $out['observed_at']);
            $out['ok'] = $parsed['ok'];
            $out['url'] = $parsed['url'] ?? $url;
            $out['items'] = $parsed['items'];
            $out['error'] = $parsed['ok'] ? null : 'Homes.com page data could not be verified; its listings were not imported.';
            $out['error_code']=$parsed['ok']?null:'unverified_page';
        }
        Cache::put($key, self::ID, $out, $out['ok'] ? self::TTL : ($failure['delay']??60));
        return $out;
    }

    /** Classify failures without treating a temporary gateway error as a one-hour host ban. */
    public static function failure(array $response): array
    {
        $status=(int)$response['status'];
        $retry=$response['headers']['retry-after']??null;
        $retrySeconds=is_string($retry)&&ctype_digit($retry)?(int)$retry:($retry?max(0,(strtotime((string)$retry)?:time())-time()):0);
        $delay=min(86400,max($status===403||$status===429?300:60,$retrySeconds));
        [$code,$message]=match(true){
            $status===200=>[null,null],
            $status===403=>['access_denied','Homes.com refused access from this hosting server (HTTP 403).'],
            $status===429=>['rate_limit','Homes.com has temporarily limited requests (HTTP 429).'],
            $status===0=>['connection','The hosting server could not connect to Homes.com before the request timed out.'],
            $status===404=>['page_missing','The requested area page was not found on Homes.com (HTTP 404).'],
            $status>=500=>['upstream','Homes.com returned a temporary server error.'],
            default=>['http_error','Homes.com did not return a successful page response.'],
        };
        return ['code'=>$code,'message'=>$message,'delay'=>$delay,
            'retryable'=>in_array($status,[0,502,503,504],true)&&$retry===null];
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
        // A ZIP is independent of the spelling of its postal city. Both URLs must identify
        // the same ZIP and state. City and county searches still require an exact page match.
        if ($canonical !== $requestedUrl && $loc['type'] === 'zip' && !empty($loc['state'])) {
            $suffix = preg_quote(strtolower($loc['state']) . '/' . $loc['zip'] . '/', '#');
            $pattern = '#^https://www\.homes\.com/[a-z0-9-]+-' . $suffix . '$#D';
            if (preg_match($pattern, $requestedUrl) && preg_match($pattern, (string) $canonical)) {
                $requestedUrl = $canonical;
            }
        }
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
        return ['ok' => $found, 'items' => array_values($items), 'url' => $found ? $canonical : null];
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

<?php
declare(strict_types=1);

namespace App\Services;

use App\Address;
use App\Providers\HomesCom;

final class PropertyPhotos
{
    /** Homes.com photos must carry the identity of this exact property and apartment. */
    public static function homes(array $p): array
    {
        $quote = $p['details']['homes'] ?? [];
        if (!HomesCom::propertyUrl($quote['url'] ?? null) || empty($quote['address_hash'])
            || $quote['address_hash'] !== Address::hash($p['address'],$p['city'],$p['state'],$p['zip'])) {
            return [];
        }
        return array_slice(array_values(array_unique(array_filter(array_map([HomesCom::class,'photoUrl'],(array)($quote['photos']??[]))))),0,24);
    }

    public static function gallery(array $p): array
    {
        $homes = self::homes($p);
        if ($homes) {
            return $homes;
        }
        $photos = array_values(array_filter((array)($p['details']['photos']??[]),static fn($url)=>is_string($url)&&str_starts_with($url,'https://')));
        if (!$photos && str_starts_with((string)($p['photo_url']??''),'https://')) {
            $photos[] = $p['photo_url'];
        }
        return array_slice(array_values(array_unique($photos)),0,24);
    }
}

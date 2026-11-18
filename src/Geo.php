<?php
declare(strict_types=1);

namespace App;

final class Geo
{
    private const EARTH_MILES = 3958.7613;

    public static function miles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * self::EARTH_MILES * asin(min(1.0, sqrt($a)));
    }

    /** Bounding box around a point — lets MySQL use the (lat, lng) index before the exact distance check. */
    public static function box(float $lat, float $lng, float $radiusMiles): array
    {
        $dLat = $radiusMiles / 69.0;
        $dLng = $radiusMiles / max(0.01, 69.172 * cos(deg2rad($lat)));
        return ['lat1' => $lat - $dLat, 'lat2' => $lat + $dLat, 'lng1' => $lng - $dLng, 'lng2' => $lng + $dLng];
    }

    public static function valid(mixed $lat, mixed $lng): bool
    {
        return is_numeric($lat) && is_numeric($lng)
            && abs((float) $lat) <= 90 && abs((float) $lng) <= 180
            && !((float) $lat == 0.0 && (float) $lng == 0.0);
    }
}

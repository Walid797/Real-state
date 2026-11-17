<?php
declare(strict_types=1);

namespace App\Providers;

use App\Cache;
use App\Http;

/** FEMA National Flood Hazard Layer — free, no key. One point lookup per property, cached for months. */
final class Fema
{
    private const URL = 'https://hazards.fema.gov/arcgis/rest/services/public/NFHL/MapServer/28/query';

    /**
     * @return array{zone:string, subtype:string, sfha:bool, label:string, risk:string}|null
     *         null when FEMA could not be reached; zone "" when the point is unmapped.
     */
    public static function zoneAt(float $lat, float $lng): ?array
    {
        $key = ['lat' => round($lat, 5), 'lng' => round($lng, 5)];
        return Cache::remember('fema', 'zone', $key, 86400 * 180, static function () use ($lat, $lng) {
            $res = Http::get(self::URL . '?' . http_build_query([
                'geometry' => $lng . ',' . $lat,
                'geometryType' => 'esriGeometryPoint',
                'inSR' => 4326,
                'spatialRel' => 'esriSpatialRelIntersects',
                'outFields' => 'FLD_ZONE,ZONE_SUBTY,SFHA_TF',
                'returnGeometry' => 'false',
                'f' => 'json',
            ]), [], 25);
            $json = json_decode($res['body'], true);
            if ($res['status'] !== 200 || !is_array($json) || isset($json['error']) || !isset($json['features'])) {
                return null;
            }
            $a = $json['features'][0]['attributes'] ?? null;
            if (!$a) {
                return ['zone' => '', 'subtype' => '', 'sfha' => false, 'label' => 'Not mapped by FEMA', 'risk' => 'unknown'];
            }
            return self::describe((string) ($a['FLD_ZONE'] ?? ''), (string) ($a['ZONE_SUBTY'] ?? ''), ($a['SFHA_TF'] ?? '') === 'T');
        });
    }

    public static function describe(string $zone, string $subtype, bool $sfha): array
    {
        $z = strtoupper(trim($zone));
        $sub = strtoupper($subtype);
        if ($sfha || preg_match('/^(A|V)/', $z)) {
            $risk = 'high';
            $label = str_starts_with($z, 'V') ? 'Coastal high-hazard area' : 'Special Flood Hazard Area (1% annual chance)';
        } elseif ($z === 'X' && str_contains($sub, '0.2')) {
            $risk = 'moderate';
            $label = '0.2% annual chance (500-year) area';
        } elseif ($z === 'X') {
            $risk = 'low';
            $label = 'Minimal flood hazard';
        } elseif ($z === 'D') {
            $risk = 'unknown';
            $label = 'Undetermined — not studied';
        } else {
            $risk = 'unknown';
            $label = $subtype !== '' ? ucfirst(strtolower($subtype)) : 'Not classified';
        }
        return ['zone' => $z, 'subtype' => $subtype, 'sfha' => $sfha, 'label' => $label, 'risk' => $risk];
    }
}

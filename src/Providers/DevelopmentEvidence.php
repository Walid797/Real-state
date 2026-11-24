<?php
declare(strict_types=1);

namespace App\Providers;

use App\Cache;
use App\Http;

final class DevelopmentEvidence
{
    public const SOURCE = 'https://services.arcgis.com/p5v98VHDX9Atv3l7/arcgis/rest/services/SYIP_Approved_Projects/FeatureServer/0';

    public static function nearby(float $lat, float $lng, string $state, bool $fetch = true): array
    {
        if ($state !== 'VA') {
            return [];
        }
        $key = Cache::key('development', 'vdot-syip', ['lat' => round($lat, 4), 'lng' => round($lng, 4)]);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        if (!$fetch) {
            return [];
        }
        $q = [
            'f' => 'json', 'geometry' => $lng . ',' . $lat, 'geometryType' => 'esriGeometryPoint', 'inSR' => 4326,
            'spatialRel' => 'esriSpatialRelIntersects', 'distance' => 4828, 'units' => 'esriSRUnit_Meter',
            'where' => "CN_END_DATE IS NULL OR CN_END_DATE >= TIMESTAMP '" . gmdate('Y-m-d') . " 00:00:00'",
            'outFields' => 'UPC,DESCRIPTION,PROJECT_PURPOSE,JURISDICTION_DESC,POOL_PRJ_STATUS_DSC,CN_START_DATE,CN_END_DATE,CURRENT_YEAR,TOTAL_ALLOCATIONS_CURRENT',
            'returnGeometry' => 'false', 'resultRecordCount' => 10, 'orderByFields' => 'UPC DESC',
        ];
        $r = Http::get(self::SOURCE . '/query?' . http_build_query($q), [], 7);
        $json = json_decode($r['body'], true);
        $ok = $r['status'] === 200 && is_array($json['features'] ?? null) && !isset($json['error']);
        $out = ['available' => $ok, 'projects' => [], 'collected_at' => now()];
        if ($ok) {
            foreach ($json['features'] as $feature) {
                $a = $feature['attributes'] ?? [];
                if (empty($a['UPC'])) {
                    continue;
                }
                $out['projects'][] = [
                    'project_id' => (int) $a['UPC'], 'name' => (string) ($a['DESCRIPTION'] ?? ''),
                    'jurisdiction' => (string) ($a['JURISDICTION_DESC'] ?? ''),
                    'status' => (string) ($a['POOL_PRJ_STATUS_DSC'] ?? ''),
                    'purpose' => (string) ($a['PROJECT_PURPOSE'] ?? ''),
                    'program_year' => (string) ($a['CURRENT_YEAR'] ?? ''),
                    'start_date' => self::date($a['CN_START_DATE'] ?? null),
                    'planned_end_date' => self::date($a['CN_END_DATE'] ?? null),
                    'allocated_funding' => is_numeric($a['TOTAL_ALLOCATIONS_CURRENT'] ?? null) ? (float) $a['TOTAL_ALLOCATIONS_CURRENT'] : null,
                ];
            }
        }
        Cache::put($key, 'development', $out, $ok ? 86400 * 7 : 900);
        return $out;
    }

    private static function date(mixed $value): ?string
    {
        return is_numeric($value) && $value > 0 ? gmdate('Y-m-d', (int) ($value / 1000)) : null;
    }
}

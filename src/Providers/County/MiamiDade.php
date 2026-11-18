<?php
declare(strict_types=1);

namespace App\Providers\County;

use App\Address;
use App\Cache;
use App\PropertyRepo;

/**
 * Miami-Dade County, Florida — the Property Appraiser's comparable-sales service on the county's
 * GIS (free, no key): address, bedrooms, full and half baths, heated area, lot, year built,
 * current and prior values, exemptions, and the last three sales with the appraiser's
 * qualified / unqualified flag. No tax figure: the millage differs by municipality.
 */
final class MiamiDade implements CountySource
{
    private const LAYER = 'https://gisweb.miamidade.gov/arcgis/rest/services/MD_ComparableSales/MapServer/5';
    private const FIELDS = 'FOLIO,TRUE_SITE_ADDR,TRUE_SITE_ADDR_NO_UNIT,TRUE_SITE_UNIT,TRUE_SITE_CITY,TRUE_SITE_ZIP_CODE,DOR_DESC,BEDROOM_COUNT,'
        . 'BATHROOM_COUNT,HALF_BATHROOM_COUNT,FLOOR_COUNT,BUILDING_HEATED_AREA,LOT_SIZE,YEAR_BUILT,ASSESSMENT_YEAR_CUR,LAND_VAL_CUR,'
        . 'BUILDING_VAL_CUR,TOTAL_VAL_CUR,ASSESSED_VAL_CUR,TOTAL_VAL_PRI,HSTEAD_EX_VAL_CUR,SUBDIVISION,PRIMARY_ZONE,'
        . 'DOS_1,PRICE_1,QU_FLG_1,VI_1,DOS_2,PRICE_2,QU_FLG_2,VI_2,DOS_3,PRICE_3,QU_FLG_3,VI_3';

    public static function id(): string
    {
        return 'miamidade';
    }

    public static function name(): string
    {
        return 'Miami-Dade Property Appraiser';
    }

    public static function area(): string
    {
        return 'Miami-Dade County, FL';
    }

    public static function covers(array $p): bool
    {
        return strtoupper((string) ($p['state'] ?? '')) === 'FL' && stripos((string) ($p['county'] ?? ''), 'miami') !== false;
    }

    public static function candidates(): string
    {
        return "state = 'FL' AND county LIKE '%miami%'";
    }

    /** The appraiser flags sales it judged arm's-length as "Q". */
    public static function fairSale(string $type, int $price): bool
    {
        return $price > 1000 && strtoupper(trim($type)) === 'Q';
    }

    public static function home(array $p): ?array
    {
        $a = Street::split((string) $p['address']);
        $zip = substr(preg_replace('/\D/', '', (string) ($p['zip'] ?? '')) ?? '', 0, 5);
        if ($a === null || $zip === '') {
            return null;
        }
        return Cache::remember(self::id(), 'home', ['a' => Address::normalizeStreet((string) $p['address']), 'z' => $zip], 86400 * 30, static function () use ($a, $zip) {
            $best = null;
            foreach (ArcGis::query(self::LAYER, [
                'where' => 'TRUE_SITE_ADDR LIKE ' . ArcGis::str((int) $a['num'] . ' %') . ' AND TRUE_SITE_ZIP_CODE LIKE ' . ArcGis::str($zip . '%'),
                'outFields' => self::FIELDS, 'outSR' => '4326', 'returnGeometry' => 'true',
            ], true, false, 'Miami-Dade') as $f) {
                $r = $f['attributes'];
                $b = Street::split((string) ($r['TRUE_SITE_ADDR_NO_UNIT'] ?? $r['TRUE_SITE_ADDR'] ?? ''));
                if ($b === null || $b['num'] !== (string) (int) $a['num'] || !Street::same($a, $b['street'], $b['street'])) {
                    continue;
                }
                $rank = Street::unitRank($a, $r['TRUE_SITE_UNIT'] ?? null);
                if ($rank > 0 && ($best === null || $rank > $best[0])) {
                    $best = [$rank, $f];
                }
            }
            return $best ? self::shape($best[1]['attributes'], $best[1]['geometry'] ?? []) : ['none' => true];
        }) ?: null;
    }

    public static function salesInZip(string $zip, int $days = 365, float $budgetSeconds = 60): array
    {
        $since = gmdate('Y-m-d', time() - $days * 86400);
        $out = [];
        foreach (ArcGis::query(self::LAYER, [
            'where' => 'TRUE_SITE_ZIP_CODE LIKE ' . ArcGis::str($zip . '%') . " AND PRICE_1 > 1000 AND QU_FLG_1 = 'Q' AND BUILDING_HEATED_AREA > 0",
            'outFields' => self::FIELDS, 'outSR' => '4326', 'returnGeometry' => 'true',
        ], true, true, 'Miami-Dade') as $f) {
            $r = $f['attributes'];
            $date = PropertyRepo::date((string) ($r['DOS_1'] ?? ''));
            $line = trim((string) ($r['TRUE_SITE_ADDR'] ?? ''));
            if ($date === null || $date < $since || !preg_match('/^\d/', $line)) {
                continue;
            }
            $out[] = [
                'address' => ucwords(strtolower($line)), 'city' => Address::titleCity((string) ($r['TRUE_SITE_CITY'] ?? '')), 'state' => 'FL', 'zip' => $zip,
                'county' => 'Miami-Dade', 'status' => 'off_market', 'last_sale_price' => (int) round((float) $r['PRICE_1']), 'last_sale_date' => $date,
                'source_id' => 'mdc:' . $r['FOLIO'], 'data_status' => 'limited',
            ] + self::facts($r, $f['geometry'] ?? []);
        }
        return $out;
    }

    private static function facts(array $r, array $g): array
    {
        $use = strtolower((string) ($r['DOR_DESC'] ?? ''));
        $full = (float) ($r['BATHROOM_COUNT'] ?? 0);
        $half = (int) ($r['HALF_BATHROOM_COUNT'] ?? 0);
        return array_filter([
            'year_built' => (int) ($r['YEAR_BUILT'] ?? 0) > 1600 ? (int) $r['YEAR_BUILT'] : null,
            'beds' => ($r['BEDROOM_COUNT'] ?? null) ?: null,
            'baths' => $full || $half ? $full + 0.5 * $half : null,
            'sqft' => ($r['BUILDING_HEATED_AREA'] ?? null) ? (int) $r['BUILDING_HEATED_AREA'] : null,
            'lot_sqft' => ($r['LOT_SIZE'] ?? null) ? (int) $r['LOT_SIZE'] : null,
            'property_type' => match (true) {
                str_contains($use, 'single family') => 'Single Family',
                str_contains($use, 'condo') => 'Condo',
                str_contains($use, 'townhouse') => 'Townhouse',
                str_contains($use, 'multifamily') || str_contains($use, 'multi-family') => 'Multi-Family',
                default => null,
            },
            'lat' => isset($g['y']) ? round((float) $g['y'], 6) : null,
            'lng' => isset($g['x']) ? round((float) $g['x'], 6) : null,
        ], static fn ($v) => $v !== null);
    }

    private static function shape(array $r, array $g): array
    {
        $facts = self::facts($r, $g) + ['county' => 'Miami-Dade'];
        $market = (int) round((float) ($r['TOTAL_VAL_CUR'] ?? 0));
        if ($market > 0) {
            $facts['assessed_value'] = $market;
        }
        $history = [];
        $last = null;
        for ($i = 1; $i <= 3; $i++) {
            $date = PropertyRepo::date((string) ($r["DOS_$i"] ?? ''));
            if ($date === null) {
                continue;
            }
            $price = (int) round((float) ($r["PRICE_$i"] ?? 0));
            $q = strtoupper(trim((string) ($r["QU_FLG_$i"] ?? '')));
            $history[] = ['date' => $date, 'price' => $price, 'type' => $q === 'Q' ? 'Qualified sale' : 'Not qualified'];
            if ($last === null && self::fairSale($q, $price)) {
                $last = ['price' => $price, 'date' => $date];
            }
        }
        if ($last) {
            $facts['last_sale_price'] = $last['price'];
            $facts['last_sale_date'] = $last['date'];
        }
        $t = static fn (string $k) => ($v = trim((string) ($r[$k] ?? ''))) !== '' ? $v : null;
        $county = array_filter([
            'source' => self::id(),
            'parid' => (string) ($r['FOLIO'] ?? ''),
            'stories' => ($r['FLOOR_COUNT'] ?? null) ?: null,
            'land_use' => $t('DOR_DESC') ? ucfirst(strtolower((string) $r['DOR_DESC'])) : null,
            'exemption' => (int) ($r['HSTEAD_EX_VAL_CUR'] ?? 0) > 0 ? 'Homestead' : 'None',
            'assessed_land' => (int) round((float) ($r['LAND_VAL_CUR'] ?? 0)) ?: null,
            'assessed_building' => (int) round((float) ($r['BUILDING_VAL_CUR'] ?? 0)) ?: null,
            'assessed_total' => $market ?: null,
            'assessed_prior' => (int) round((float) ($r['TOTAL_VAL_PRI'] ?? 0)) ?: null,
            'assessment_year' => ($r['ASSESSMENT_YEAR_CUR'] ?? null) ?: null,
            'sales' => $history,
            'fetched_at' => gmdate('Y-m-d H:i:s'),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
        return ['facts' => $facts, 'county' => $county];
    }
}

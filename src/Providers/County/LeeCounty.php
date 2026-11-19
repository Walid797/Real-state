<?php
declare(strict_types=1);

namespace App\Providers\County;

use App\Address;
use App\Cache;
use App\PropertyRepo;

/**
 * Lee County, Florida — the Property Appraiser's parcel layer (refreshed nightly, free, no key):
 * address, just / assessed / taxable value, year built, heated area, bedrooms, bathrooms, pool,
 * garage, and the last four sales with Florida's qualification code. No tax figure: the millage
 * differs by taxing district.
 */
final class LeeCounty implements CountySource
{
    private const LAYER = 'https://services2.arcgis.com/LvWGAAhHwbCJ2GMP/ArcGIS/rest/services/Lee_County_Parcels/FeatureServer/0';
    private const FIELDS = 'STRAP,SITENUMBER,SITESTREET,SITEUNIT,SITECITY,SITEZIP,JUST,ASSESSED,TAXABLE,LAND,BUILDING,MINBUILTY,HEATEDAREA,'
        . 'BEDROOMS,BATHROOMS,POOL,GARAGE,MAXSTORIES,LANDUSEDES,ZONING,GISACRES,LATITUDE,LONGITUDE,Property_URL,'
        . 'S_1DATE,S_1AMOUNT,S_1VI,S_1TC,S_2DATE,S_2AMOUNT,S_2VI,S_2TC,S_3DATE,S_3AMOUNT,S_3VI,S_3TC,S_4DATE,S_4AMOUNT,S_4VI,S_4TC';

    public static function id(): string
    {
        return 'lee';
    }

    public static function name(): string
    {
        return 'Lee County Property Appraiser';
    }

    public static function area(): string
    {
        return 'Lee County, FL';
    }

    public static function covers(array $p): bool
    {
        return strtoupper((string) ($p['state'] ?? '')) === 'FL' && preg_match('/^lee\b/i', trim((string) ($p['county'] ?? ''))) === 1;
    }

    public static function candidates(): string
    {
        return "state = 'FL' AND county LIKE 'lee%'";
    }

    /** Florida's qualification code 01: a qualified (arm's-length) sale; "I" = improved property. */
    public static function fairSale(string $type, int $price): bool
    {
        return $price > 1000 && str_starts_with($type, '01');
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
                'where' => 'SITENUMBER = ' . ArcGis::str((string) (int) $a['num']) . ' AND SITEZIP = ' . ArcGis::str($zip), 'outFields' => self::FIELDS,
            ], false, false, 'Lee County') as $r) {
                if (!Street::same($a, (string) ($r['SITESTREET'] ?? ''), (string) ($r['SITESTREET'] ?? ''))) {
                    continue;
                }
                $rank = Street::unitRank($a, $r['SITEUNIT'] ?? null);
                if ($rank > 0 && ($best === null || $rank > $best[0])) {
                    $best = [$rank, $r];
                }
            }
            return $best ? self::shape($best[1]) : ['none' => true];
        }) ?: null;
    }

    public static function salesInZip(string $zip, int $days = 365, float $budgetSeconds = 60): array
    {
        $since = gmdate('Y-m-d', time() - $days * 86400);
        $out = [];
        foreach (ArcGis::query(self::LAYER, [
            'where' => 'SITEZIP = ' . ArcGis::str($zip) . " AND S_1DATE >= DATE '$since' AND S_1AMOUNT > 1000 AND S_1TC = '01' AND S_1VI = 'I' AND HEATEDAREA > 0",
            'outFields' => self::FIELDS, 'orderByFields' => 'S_1DATE DESC',
        ], false, true, 'Lee County') as $r) {
            $line = self::street($r);
            if ((string) ($r['SITEZIP'] ?? '') !== $zip || $line === '' || empty($r['STRAP'])) {
                continue;
            }
            $out[] = [
                'address' => $line, 'city' => Address::titleCity((string) ($r['SITECITY'] ?? '')), 'state' => 'FL', 'zip' => $zip, 'county' => 'Lee',
                'status' => 'off_market', 'last_sale_price' => (int) $r['S_1AMOUNT'], 'last_sale_date' => PropertyRepo::date((string) $r['S_1DATE']),
                'source_id' => 'lee:' . $r['STRAP'], 'data_status' => 'limited',
            ] + self::facts($r);
        }
        return $out;
    }

    private static function street(array $r): string
    {
        $n = trim((string) ($r['SITENUMBER'] ?? ''));
        $s = trim((string) ($r['SITESTREET'] ?? ''));
        if ($n === '' || $s === '' || !ctype_digit($n)) {
            return '';
        }
        $u = trim((string) ($r['SITEUNIT'] ?? ''));
        return ucwords(strtolower($n . ' ' . $s . ($u !== '' ? ' Unit ' . $u : '')));
    }

    private static function facts(array $r): array
    {
        $use = strtolower((string) ($r['LANDUSEDES'] ?? ''));
        return array_filter([
            'year_built' => (int) ($r['MINBUILTY'] ?? 0) > 1600 ? (int) $r['MINBUILTY'] : null,
            'beds' => ($r['BEDROOMS'] ?? null) ?: null,
            'baths' => ($r['BATHROOMS'] ?? null) ? round((float) $r['BATHROOMS'], 1) : null,
            'sqft' => ($r['HEATEDAREA'] ?? null) ? (int) $r['HEATEDAREA'] : null,
            'lot_sqft' => ($r['GISACRES'] ?? null) ? (int) round((float) $r['GISACRES'] * 43560) : null,
            'property_type' => match (true) {
                str_contains($use, 'single family') => 'Single Family',
                str_contains($use, 'condo') => 'Condo',
                str_contains($use, 'town') => 'Townhouse',
                str_contains($use, 'mobile') || str_contains($use, 'manufactured') => 'Manufactured',
                default => null,
            },
            'garage_spaces' => ($r['GARAGE'] ?? null) ? (int) $r['GARAGE'] : null,
            'lat' => is_numeric($r['LATITUDE'] ?? null) ? round((float) $r['LATITUDE'], 6) : null,
            'lng' => is_numeric($r['LONGITUDE'] ?? null) ? round((float) $r['LONGITUDE'], 6) : null,
        ], static fn ($v) => $v !== null);
    }

    private static function shape(array $r): array
    {
        $facts = self::facts($r) + ['county' => 'Lee'];
        $just = (int) round((float) ($r['JUST'] ?? 0));
        if ($just > 0) {
            $facts['assessed_value'] = $just;
        }
        $history = [];
        $last = null;
        for ($i = 1; $i <= 4; $i++) {
            $date = PropertyRepo::date((string) ($r["S_{$i}DATE"] ?? ''));
            if ($date === null) {
                continue;
            }
            $price = (int) round((float) ($r["S_{$i}AMOUNT"] ?? 0));
            $tc = trim((string) ($r["S_{$i}TC"] ?? ''));
            $improved = ($r["S_{$i}VI"] ?? '') === 'I';
            $history[] = ['date' => $date, 'price' => $price, 'type' => ($tc === '01' ? 'Qualified sale' : 'Not qualified (code ' . ($tc ?: '—') . ')') . ($improved ? '' : ', vacant land')];
            if ($last === null && $improved && self::fairSale($tc, $price)) {
                $last = ['price' => $price, 'date' => $date];
            }
        }
        usort($history, static fn ($x, $y) => strcmp($y['date'], $x['date']));
        if ($last) {
            $facts['last_sale_price'] = $last['price'];
            $facts['last_sale_date'] = $last['date'];
        }
        $url = trim((string) ($r['Property_URL'] ?? ''));
        $t = static fn (string $k) => ($v = trim((string) ($r[$k] ?? ''))) !== '' ? $v : null;
        $county = array_filter([
            'source' => self::id(),
            'parid' => (string) ($r['STRAP'] ?? ''),
            'stories' => ($r['MAXSTORIES'] ?? null) ?: null,
            'pool' => !empty($r['POOL']) ? 'Yes' : null,
            'land_use' => $t('LANDUSEDES') ? ucfirst(strtolower((string) $r['LANDUSEDES'])) : null,
            'zoning' => $t('ZONING'),
            'assessed_land' => (int) round((float) ($r['LAND'] ?? 0)) ?: null,
            'assessed_building' => (int) round((float) ($r['BUILDING'] ?? 0)) ?: null,
            'assessed_total' => $just ?: null,
            'taxable' => (int) round((float) ($r['TAXABLE'] ?? 0)) ?: null,
            'sales' => $history,
            'record_url' => preg_match('#^https://#i', $url) ? $url : null,
            'fetched_at' => gmdate('Y-m-d H:i:s'),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
        return ['facts' => $facts, 'county' => $county];
    }
}

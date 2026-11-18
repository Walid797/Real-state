<?php
declare(strict_types=1);

namespace App\Providers\County;

use App\Address;
use App\Cache;

/**
 * Washington, DC — the Office of Tax and Revenue's records on DC GIS (free, no key): tax facts
 * (address, assessed values, tax rate, last sale), the residential appraisal record (bedrooms,
 * baths, building area, year built and remodelled, condition, grade) and every recorded sale.
 */
final class DistrictOfColumbia implements CountySource
{
    private const BASE = 'https://maps2.dcgis.dc.gov/dcgis/rest/services/DCGIS_DATA/Property_and_Land_WebMercator/MapServer/';
    private const FACTS = self::BASE . '56';
    private const RESIDENTIAL = self::BASE . '25';
    private const SALES = self::BASE . '57';
    private const POINTS = self::BASE . '8';

    public static function id(): string
    {
        return 'dc';
    }

    public static function name(): string
    {
        return 'DC Office of Tax and Revenue records';
    }

    public static function area(): string
    {
        return 'Washington, DC';
    }

    public static function covers(array $p): bool
    {
        return strtoupper((string) ($p['state'] ?? '')) === 'DC';
    }

    public static function candidates(): string
    {
        return "state = 'DC'";
    }

    /** OTR marks fair-market sales it used for appraisal as "Q" (qualified). */
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
            $rows = ArcGis::query(self::FACTS, [
                'where' => 'PROPERTY_ADDRESS LIKE ' . ArcGis::str((int) $a['num'] . ' %') . ' AND PROPERTY_ADDRESS LIKE ' . ArcGis::str('% ' . $zip),
            ], false, false, 'DC');
            $best = null;
            foreach ($rows as $r) {
                $line = self::streetLine((string) ($r['PROPERTY_ADDRESS'] ?? ''), $zip);
                $b = Street::split($line);
                if ($b === null || $b['num'] !== (string) (int) $a['num'] || $b['street'] !== $a['street']) {
                    continue;
                }
                $rank = Street::unitRank($a, $b['unit']);
                if ($rank > 0 && ($best === null || $rank > $best[0])) {
                    $best = [$rank, $r];
                }
            }
            if ($best === null) {
                return ['none' => true];
            }
            $facts = $best[1];
            $ssl = (string) $facts['SSL'];
            $res = ArcGis::query(self::RESIDENTIAL, ['where' => 'SSL = ' . ArcGis::str($ssl)], false, false, 'DC')[0] ?? null;
            $sales = ArcGis::query(self::SALES, ['where' => 'SSL = ' . ArcGis::str($ssl), 'orderByFields' => 'SALE_DATE DESC'], false, false, 'DC');
            $pt = ArcGis::query(self::POINTS, ['where' => 'SSL = ' . ArcGis::str($ssl), 'outFields' => 'LATITUDE,LONGITUDE'], false, false, 'DC')[0] ?? null;
            return self::shape($facts, $res, $sales, $pt);
        }) ?: null;
    }

    public static function salesInZip(string $zip, int $days = 365, float $budgetSeconds = 60): array
    {
        $t = microtime(true);
        $props = [];
        foreach (ArcGis::query(self::FACTS, [
            'where' => 'PROPERTY_ADDRESS LIKE ' . ArcGis::str('% ' . $zip), 'outFields' => 'SSL,PROPERTY_ADDRESS,LAND_USE_DESCRIPTION,LANDAREA',
        ], false, true, 'DC') as $r) {
            if (stripos((string) ($r['LAND_USE_DESCRIPTION'] ?? ''), 'residential') !== false) {
                $props[(string) $r['SSL']] = $r;
            }
        }
        $since = gmdate('Y-m-d', time() - $days * 86400);
        $latest = [];
        foreach (array_chunk(array_keys($props), 200) as $chunk) {
            if (microtime(true) - $t > $budgetSeconds * 0.6) {
                break;
            }
            foreach (ArcGis::query(self::SALES, [
                'where' => 'SSL IN ' . ArcGis::in($chunk) . " AND SALE_DATE >= DATE '$since' AND QUALIFIED = 'Q' AND SALE_PRICE > 1000",
                'outFields' => 'SSL,SALE_DATE,SALE_PRICE', 'orderByFields' => 'SALE_DATE DESC',
            ], false, true, 'DC') as $s) {
                $latest[(string) $s['SSL']] ??= $s;
            }
        }
        $out = [];
        foreach (array_chunk(array_keys($latest), 150) as $chunk) {
            if (microtime(true) - $t > $budgetSeconds) {
                break;
            }
            $res = $pts = [];
            foreach (ArcGis::query(self::RESIDENTIAL, ['where' => 'SSL IN ' . ArcGis::in($chunk)], false, true, 'DC') as $r) {
                $res[(string) $r['SSL']] ??= $r;
            }
            foreach (ArcGis::query(self::POINTS, ['where' => 'SSL IN ' . ArcGis::in($chunk), 'outFields' => 'SSL,LATITUDE,LONGITUDE'], false, true, 'DC') as $r) {
                $pts[(string) $r['SSL']] ??= $r;
            }
            foreach ($chunk as $ssl) {
                if (!isset($res[$ssl])) {
                    continue; // condos and commercial carry no residential record here
                }
                $line = self::streetLine((string) $props[$ssl]['PROPERTY_ADDRESS'], $zip);
                if (!preg_match('/^\d/', $line)) {
                    continue;
                }
                $out[] = [
                    'address' => ucwords(strtolower($line)), 'city' => 'Washington', 'state' => 'DC', 'zip' => $zip, 'county' => 'District of Columbia',
                    'status' => 'off_market', 'last_sale_price' => (int) $latest[$ssl]['SALE_PRICE'],
                    'last_sale_date' => gmdate('Y-m-d', (int) ($latest[$ssl]['SALE_DATE'] / 1000)),
                    'source_id' => 'dc:' . preg_replace('/\s+/', ' ', $ssl), 'data_status' => 'limited',
                ] + self::facts($res[$ssl], $pts[$ssl] ?? null, (int) ($props[$ssl]['LANDAREA'] ?? 0));
            }
        }
        return $out;
    }

    /* ── shaping ─────────────────────────────────────────────────────── */

    /** "3548 T ST NW WASHINGTON DC 20007" → "3548 T ST NW". */
    private static function streetLine(string $full, string $zip): string
    {
        return trim((string) preg_replace('/\s+WASHINGTON\s+DC\s+' . preg_quote($zip, '/') . '$/i', '', trim($full)));
    }

    private static function facts(?array $res, ?array $pt, int $land): array
    {
        $full = (int) ($res['BATHRM'] ?? 0);
        $half = (int) ($res['HF_BATHRM'] ?? 0);
        $struct = strtolower((string) ($res['STRUCT_D'] ?? ''));
        return array_filter([
            'year_built' => (int) ($res['AYB'] ?? 0) > 1600 ? (int) $res['AYB'] : null,
            'beds' => ($res['BEDRM'] ?? null) ?: null,
            'baths' => $full || $half ? $full + 0.5 * $half : null,
            'sqft' => ($res['GBA'] ?? null) ?: null,
            'lot_sqft' => ((int) ($res['LANDAREA'] ?? 0) ?: $land) ?: null,
            'property_type' => match (true) {
                str_contains($struct, 'row') || str_contains($struct, 'town') || str_contains($struct, 'semi') => 'Townhouse',
                str_contains($struct, 'single') || str_contains($struct, 'detached') => 'Single Family',
                default => null,
            },
            'lat' => is_numeric($pt['LATITUDE'] ?? null) ? round((float) $pt['LATITUDE'], 6) : null,
            'lng' => is_numeric($pt['LONGITUDE'] ?? null) ? round((float) $pt['LONGITUDE'], 6) : null,
        ], static fn ($v) => $v !== null);
    }

    private static function shape(array $f, ?array $res, array $sales, ?array $pt): array
    {
        $facts = self::facts($res, $pt, (int) ($f['LANDAREA'] ?? 0)) + ['county' => 'District of Columbia'];
        $assessed = (int) round((float) ($f['APPRAISED_VALUE_CURRENT_TOTAL'] ?? 0));
        $rate = (float) ($f['TAXRATE'] ?? 0);
        if ($assessed > 0) {
            $facts['assessed_value'] = $assessed;
            if ($rate > 0) {
                $facts['tax_annual'] = (int) round($assessed * $rate / 100);
            }
        }
        $history = [];
        $last = null;
        foreach ($sales as $s) {
            $price = (int) round((float) ($s['SALE_PRICE'] ?? 0));
            $ms = $s['SALE_DATE'] ?? null;
            if (!is_numeric($ms) || $ms < 0) {
                continue; // placeholder 1900 dates
            }
            $date = gmdate('Y-m-d', (int) ($ms / 1000));
            $q = (string) ($s['QUALIFIED'] ?? '');
            $history[] = ['date' => $date, 'price' => $price, 'type' => $q === 'Q' ? 'Qualified (market) sale' : 'Not qualified'];
            if ($last === null && self::fairSale($q, $price)) {
                $last = ['price' => $price, 'date' => $date];
            }
        }
        if ($last) {
            $facts['last_sale_price'] = $last['price'];
            $facts['last_sale_date'] = $last['date'];
        }
        $t = static fn (?array $r, string $k) => ($v = trim((string) ($r[$k] ?? ''))) !== '' ? $v : null;
        $county = array_filter([
            'source' => self::id(),
            'parid' => preg_replace('/\s+/', ' ', (string) $f['SSL']),
            'style' => $t($res, 'STYLE_D'),
            'stories' => ($res['STORIES'] ?? null) ?: null,
            'year_remodeled' => ($res['YR_RMDL'] ?? null) ?: null,
            'effective_year' => ($res['EYB'] ?? null) ?: null,
            'condition' => $t($res, 'CNDTN_D'),
            'grade' => $t($res, 'GRADE_D'),
            'fireplaces' => ($res['FIREPLACES'] ?? null) ?: null,
            'heating' => trim(implode(' / ', array_filter([$t($res, 'HEAT_D'), ($res['AC'] ?? '') === 'Y' ? 'Air conditioning' : null]))) ?: null,
            'roof' => $t($res, 'ROOF_D'),
            'exterior' => $t($res, 'EXTWALL_D'),
            'land_use' => $t($f, 'LAND_USE_DESCRIPTION'),
            'neighborhood' => $t($f, 'OTR_NEIGHBORHOOD_NAME'),
            'exemption' => $t($f, 'HOMESTEAD_DESCRIPTION') ?? 'None',
            'assessed_land' => (int) round((float) ($f['APPRAISED_VALUE_CURRENT_LAND'] ?? 0)) ?: null,
            'assessed_building' => (int) round((float) ($f['APPRAISED_VALUE_CURRENT_BLDG'] ?? 0)) ?: null,
            'assessed_total' => $assessed ?: null,
            'assessed_prior' => (int) round((float) ($f['APPRAISED_VALUE_PRIOR_TOTAL'] ?? 0)) ?: null,
            'tax_rate' => $rate ?: null,
            'tax_note' => 'before homestead deduction and assessment cap',
            'sales' => array_slice($history, 0, 12),
            'fetched_at' => gmdate('Y-m-d H:i:s'),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
        return ['facts' => $facts, 'county' => $county];
    }
}

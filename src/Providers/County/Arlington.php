<?php
declare(strict_types=1);

namespace App\Providers\County;

use App\Address;
use App\Cache;
use App\Http;
use App\Settings;

/**
 * Arlington County, Virginia — the county's Real Estate Assessments data hub (OData-style JSON,
 * free, no key): property, dwelling, rooms by floor, assessments and every recorded sale.
 */
final class Arlington implements CountySource
{
    private const BASE = 'https://datahub-v2.arlingtonva.us/api/RealEstate/';

    public static function id(): string
    {
        return 'arlington';
    }

    public static function name(): string
    {
        return 'Arlington County public records';
    }

    public static function area(): string
    {
        return 'Arlington County, VA';
    }

    /** Calendar-year 2026 rate, adopted April 22, 2026: $1.053 per $100. */
    public static function taxRate(): float
    {
        return max(0.0, (float) (Settings::get('arlington_tax_rate') ?? '1.053'));
    }

    public static function covers(array $p): bool
    {
        if (strtoupper((string) ($p['state'] ?? '')) !== 'VA') {
            return false;
        }
        $county = strtolower((string) ($p['county'] ?? ''));
        if ($county !== '') {
            return str_contains($county, 'arlington');
        }
        return (bool) preg_match('/^222\d\d$/', (string) ($p['zip'] ?? ''));
    }

    public static function candidates(): string
    {
        return "state = 'VA' AND (county LIKE '%arlington%' OR ((county IS NULL OR county = '') AND zip LIKE '222__'))";
    }

    public static function fairSale(string $type, int $price): bool
    {
        // No type is an ordinary market sale; coded ones are gifts, family, non-market and the like.
        return $price > 1000 && ($type === '' || !preg_match('/not market|gift|relative|foreclos|multiple|partial|exempt|correct|estate|trust|lender|auction|deed/i', $type));
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
            foreach (self::get('Property', 'propertyStreetNbr eq ' . (int) $a['num'] . " and propertyZipCode eq '" . $zip . "'") as $r) {
                if (!empty($r['propertyExpiredInd'])) {
                    continue;
                }
                $full = trim(implode(' ', array_filter([$r['propertyStreetDirectionPrefixCode'] ?? '', $r['propertyStreetName'] ?? '', $r['propertyStreetTypeCode'] ?? '', $r['propertyStreetDirectionSuffixCode'] ?? ''])));
                if (!Street::same($a, (string) ($r['propertyStreetName'] ?? ''), $full) || strtolower((string) ($r['propertyStreetNbrSuffixCode'] ?? '')) !== $a['sfx']) {
                    continue;
                }
                $rank = Street::unitRank($a, $r['propertyUnitNbr'] ?? null) + (!empty($r['physicalAddressPrimeInd']) ? 1 : 0);
                if ($rank > 0 && ($best === null || $rank > $best[0])) {
                    $best = [$rank, $r];
                }
            }
            if ($best === null) {
                return ['none' => true];
            }
            $prop = $best[1];
            $rpc = (string) $prop['realEstatePropertyCode'];
            $by = "realEstatePropertyCode eq '" . self::esc($rpc) . "'";
            $dw = self::get('ImprovementDwelling', $by)[0] ?? null;
            $floors = self::get('ImprovementInterior', $by);
            $values = self::get('Assessment', $by, 'assessmentDate desc', 2);
            $sales = self::get('SalesHistory', $by, 'saleDate desc', 12);
            return self::shape($prop, $dw, $floors, $values, $sales);
        }) ?: null;
    }

    public static function salesInZip(string $zip, int $days = 365, float $budgetSeconds = 60): array
    {
        $since = gmdate('Y-m-d', time() - $days * 86400);
        $props = [];
        foreach (self::get('Property', "propertyZipCode eq '" . self::esc($zip) . "' and propertyExpiredInd eq false", null, 20000) as $r) {
            $props[(string) $r['realEstatePropertyCode']] = $r;
        }
        if (!$props) {
            return [];
        }
        $latest = [];
        foreach (self::get('SalesHistory', 'saleDate ge ' . $since . ' and saleAmt gt 1000', 'saleDate desc', 20000) as $s) {
            $rpc = (string) $s['realEstatePropertyCode'];
            if (isset($props[$rpc]) && !isset($latest[$rpc]) && self::fairSale(trim((string) ($s['salesTypeDsc'] ?? '')), (int) $s['saleAmt'])) {
                $latest[$rpc] = $s;
            }
        }
        $out = [];
        $t = microtime(true);
        foreach (array_chunk(array_keys($latest), 25) as $chunk) {
            if (microtime(true) - $t > $budgetSeconds) {
                break;
            }
            $or = implode(' or ', array_map(static fn ($c) => "realEstatePropertyCode eq '" . self::esc((string) $c) . "'", $chunk));
            $dws = $floorsBy = [];
            foreach (self::get('ImprovementDwelling', $or, null, 500) as $d) {
                $dws[(string) $d['realEstatePropertyCode']] ??= $d;
            }
            foreach (self::get('ImprovementInterior', $or, null, 2000) as $f) {
                $floorsBy[(string) $f['realEstatePropertyCode']][] = $f;
            }
            foreach ($chunk as $rpc) {
                $prop = $props[$rpc];
                $facts = self::facts($prop, $dws[$rpc] ?? null, $floorsBy[$rpc] ?? []);
                if (empty($facts['sqft']) && empty($facts['beds'])) {
                    continue; // land, or a building without a dwelling record: not a comparable home
                }
                $out[] = [
                    'address' => self::street($prop), 'city' => Address::titleCity((string) ($prop['propertyCityName'] ?? 'Arlington')),
                    'state' => 'VA', 'zip' => $zip, 'county' => 'Arlington', 'status' => 'off_market',
                    'last_sale_price' => (int) $latest[$rpc]['saleAmt'], 'last_sale_date' => substr((string) $latest[$rpc]['saleDate'], 0, 10),
                    'source_id' => 'arl:' . $rpc, 'data_status' => 'limited',
                ] + $facts;
            }
        }
        return $out;
    }

    /* ── shaping ─────────────────────────────────────────────────────── */

    private static function street(array $r): string
    {
        $s = trim((string) ($r['propertyStreetNbrNameText'] ?? ''));
        if (!empty($r['propertyUnitNbr'])) {
            $s .= ' Unit ' . trim((string) $r['propertyUnitNbr']);
        }
        return ucwords(strtolower($s));
    }

    private static function facts(array $prop, ?array $dw, array $floors): array
    {
        $beds = $full = $half = $area = 0;
        foreach ($floors as $f) {
            $beds += (int) ($f['bedroomCnt'] ?? 0);
            $half += (int) ($f['twoFixtureBathroomCnt'] ?? 0);
            $full += (int) ($f['threeFixtureBathroomCnt'] ?? 0) + (int) ($f['fourFixtureBathroomCnt'] ?? 0) + (int) ($f['fiveFixtureBathroomCnt'] ?? 0);
            $area += (int) ($f['finishedAreaSquareFeetQty'] ?? 0);
        }
        $class = strtolower((string) ($prop['propertyClassTypeDsc'] ?? ''));
        $type = match (true) {
            str_contains($class, 'single family detached') => 'Single Family',
            str_contains($class, 'town') || str_contains($class, 'semi') || str_contains($class, 'attached') => 'Townhouse',
            str_contains($class, 'condo') => 'Condo',
            default => null,
        };
        $lat = is_numeric($prop['latitudeCrd'] ?? null) ? round((float) $prop['latitudeCrd'], 6) : null;
        $lng = is_numeric($prop['longitudeCrd'] ?? null) ? round((float) $prop['longitudeCrd'], 6) : null;
        return array_filter([
            'year_built' => ($dw['dwellingYearBuiltDate'] ?? null) ?: (($prop['propertyYearBuilt'] ?? null) ?: null),
            'beds' => $beds ?: null,
            'baths' => $full || $half ? $full + 0.5 * $half : null,
            'sqft' => $area ?: (($prop['grossFloorAreaSquareFeetQty'] ?? null) ?: null),
            'lot_sqft' => ($prop['lotSizeQty'] ?? null) ?: null,
            'has_basement' => $dw !== null && ((int) ($dw['basementFinishedRecRoomSquareFeetQty'] ?? 0) > 0 || !empty($dw['basementRecRoomTypeDsc'])) ? true : null,
            'property_type' => $type,
            'lat' => $lat, 'lng' => $lng,
        ], static fn ($v) => $v !== null);
    }

    private static function shape(array $prop, ?array $dw, array $floors, array $values, array $sales): array
    {
        $facts = self::facts($prop, $dw, $floors) + ['county' => 'Arlington'];
        $cur = $values[0] ?? null;
        $prior = $values[1] ?? null;
        $assessed = (int) ($cur['totalValueAmt'] ?? 0);
        $exempt = trim((string) ($prop['taxExemptionTypeDsc'] ?? ''));
        if ($assessed > 0) {
            $facts['assessed_value'] = $assessed;
            if (self::taxRate() > 0 && $exempt === '') {
                $facts['tax_annual'] = (int) round($assessed * self::taxRate() / 100);
            }
        }
        $history = [];
        $last = null;
        foreach ($sales as $s) {
            $price = (int) ($s['saleAmt'] ?? 0);
            $date = substr((string) ($s['saleDate'] ?? ''), 0, 10);
            if ($date === '') {
                continue;
            }
            $type = trim((string) ($s['salesTypeDsc'] ?? ''));
            $history[] = ['date' => $date, 'price' => $price, 'type' => $type !== '' ? preg_replace('/^\d+-/', '', $type) : 'Market sale'];
            if ($last === null && self::fairSale($type, $price)) {
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
            'parid' => (string) $prop['realEstatePropertyCode'],
            'style' => $t($dw, 'dwellingTypeDsc') ? preg_replace('/^\d+\s*-\s*/', '', (string) $dw['dwellingTypeDsc']) : null,
            'stories' => $t($dw, 'storiesQuantityDsc'),
            'basement' => $t($dw, 'basementRecRoomTypeDsc'),
            'rec_room_sqft' => ($dw['basementFinishedRecRoomSquareFeetQty'] ?? null) ?: null,
            'fireplaces' => ($dw['fireplaceCnt'] ?? null) ?: null,
            'heating' => trim(implode(' / ', array_filter([$t($dw, 'heatingTypeDsc'), $t($dw, 'coolingTypeDsc')]))) ?: null,
            'land_use' => $t($prop, 'propertyClassTypeDsc') ? preg_replace('/^\d+-/', '', (string) $prop['propertyClassTypeDsc']) : null,
            'zoning' => $t($prop, 'zoningDescListText'),
            'exemption' => $exempt !== '' ? $exempt : 'None',
            'assessed_land' => (int) ($cur['landValueAmt'] ?? 0) ?: null,
            'assessed_building' => (int) ($cur['improvementValueAmt'] ?? 0) ?: null,
            'assessed_total' => $assessed ?: null,
            'assessed_prior' => (int) ($prior['totalValueAmt'] ?? 0) ?: null,
            'assessment_year' => $cur ? (int) substr((string) $cur['assessmentDate'], 0, 4) : null,
            'tax_rate' => self::taxRate() ?: null,
            'tax_note' => 'county rate; districts and commercial surcharge extra',
            'sales' => $history,
            'fetched_at' => gmdate('Y-m-d H:i:s'),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
        return ['facts' => $facts, 'county' => $county];
    }

    /* ── transport ──────────────────────────────────────────────────── */

    /** @return list<array> */
    private static function get(string $table, string $filter, ?string $orderBy = null, int $top = 50): array
    {
        $q = '$filter=' . rawurlencode($filter) . '&$top=' . $top . ($orderBy ? '&$orderby=' . rawurlencode($orderBy) : '');
        $res = Http::get(self::BASE . $table . '?' . $q, [], 30);
        $d = json_decode((string) $res['body'], true);
        if ($res['status'] !== 200 || !is_array($d) || isset($d['error'])) {
            throw new \RuntimeException('Arlington County records did not answer (' . ($d['error']['message'] ?? ($res['error'] ?: 'HTTP ' . $res['status'])) . ').');
        }
        return array_values(array_filter($d, 'is_array'));
    }

    private static function esc(string $s): string
    {
        return str_replace("'", "''", $s);
    }
}

<?php
declare(strict_types=1);

namespace App\Providers;

use App\Address;
use App\Cache;
use App\Http;
use App\Providers\County\CountySource;
use App\Settings;

/**
 * Fairfax County, Virginia — the county's own open data (Department of Tax Administration and
 * the Master Address Repository), published on ArcGIS Online. Free, no key, no monthly limit.
 *
 * For one home: the address point (map position + parcel id), the dwelling record (style, year
 * built and remodelled, bedrooms, baths, living area, basement, condition, heating, roof,
 * fireplaces), assessed values, lot size, land use, zoning, utilities and every recorded sale.
 * For an area: every arm's-length sale the county recorded, with the same facts, for comps.
 *
 * The county's tables have no photos, listing text or HOA fees. Fairfax City and Falls Church
 * are separate jurisdictions and are not in these tables.
 */
final class Fairfax implements CountySource
{
    public const ID = 'fairfax';
    public const NAME = 'Fairfax County public records';
    private const BASE = 'https://services1.arcgis.com/ioennV6PpG5Xodq0/ArcGIS/rest/services';

    private const ADDRESS = 'Address_Points/FeatureServer/0';
    private const LEGAL = 'OpenData_A7/FeatureServer/1';
    private const DWELLING = 'OpenData_A7/FeatureServer/2';
    private const SALES = 'OpenData_A5/FeatureServer/1';
    private const PARCEL = 'OpenData_A6/FeatureServer/1';
    private const VALUES = 'OpenData_A6/FeatureServer/2';
    private const LAND = 'OpenData_A6/FeatureServer/3';
    private const SHAPES = 'Parcels/FeatureServer/0';

    /** Sale types the county marks as fair market sales of one parcel — the ones fit to price other homes by. */
    private const ARMS_LENGTH = "(SALEVAL_DESC = 'Valid and verified sale' OR SALEVAL_DESC LIKE 'Atypical upgrades%')";

    public static function id(): string
    {
        return self::ID;
    }

    public static function name(): string
    {
        return self::NAME;
    }

    public static function area(): string
    {
        return 'Fairfax County, VA';
    }

    public static function candidates(): string
    {
        return "state = 'VA' AND ((county LIKE '%fairfax%' AND county NOT LIKE '%city%')"
            . " OR ((county IS NULL OR county = '' OR county LIKE '%columbia%') AND (zip LIKE '201__' OR zip LIKE '220__' OR zip LIKE '221__' OR zip LIKE '223__')))";
    }

    public static function fairSale(string $type, int $price): bool
    {
        return $price > 1000 && (bool) preg_match('/^(Valid and verified|Atypical upgrades)/i', $type);
    }

    /** Could this home be in Fairfax County? (A lookup that finds nothing settles it.) */
    public static function covers(array $p): bool
    {
        if (strtoupper((string) ($p['state'] ?? '')) !== 'VA') {
            return false;
        }
        $county = strtolower((string) ($p['county'] ?? ''));
        if ($county !== '' && !str_contains($county, 'columbia')) {
            return str_contains($county, 'fairfax') && !str_contains($county, 'city');
        }
        return (bool) preg_match('/^(201|220|221|223)\d\d$/', (string) ($p['zip'] ?? '')); // Fairfax County's ZIP ranges; the lookup settles it
    }

    /** Fairfax County's adopted rate in dollars per $100 of assessed value (FY 2027: $1.12). 0 = unknown. */
    public static function taxRate(): float
    {
        return max(0.0, (float) (Settings::get('fairfax_tax_rate') ?? '1.12'));
    }

    /**
     * Everything the county holds on one home, or null when the address is not a Fairfax County parcel.
     * @return array{parid:string, lat:?float, lng:?float, facts:array, county:array}|null
     */
    public static function home(array $p): ?array
    {
        $parsed = self::splitStreet((string) $p['address']);
        $zip = substr(preg_replace('/\D/', '', (string) ($p['zip'] ?? '')) ?? '', 0, 5);
        if ($parsed === null || $zip === '') {
            return null;
        }
        return Cache::remember(self::ID, 'home', ['a' => Address::normalizeStreet((string) $p['address']), 'z' => $zip], 86400 * 30, static function () use ($parsed, $zip) {
            $point = self::findAddress($parsed, $zip);
            if ($point === null) {
                return ['none' => true];
            }
            $parid = $point['parid'];
            $one = static fn (string $layer) => self::query($layer, ['where' => 'PARID = ' . self::str($parid), 'outFields' => '*'])[0] ?? null;
            $dw = $one(self::DWELLING);
            $val = $one(self::VALUES);
            $land = $one(self::LAND);
            $parcel = $one(self::PARCEL);
            $sales = self::query(self::SALES, ['where' => 'PARID = ' . self::str($parid), 'outFields' => 'SALEDT,PRICE,SALEVAL_DESC', 'orderByFields' => 'SALEDT DESC']);
            return self::shape($parid, $point, $dw, $val, $land, $parcel, $sales);
        }) ?: null;
    }

    /**
     * Arm's-length sales recorded in one ZIP code since a date, with each home's facts and position.
     * @return list<array> rows ready for PropertyRepo::upsert()
     */
    public static function salesInZip(string $zip, int $days = 365, float $budgetSeconds = 60): array
    {
        $t = microtime(true);
        // Every parcel id in the ZIP, from the address points (they carry the position too).
        $points = [];
        foreach (self::query(self::ADDRESS, [
            'where' => 'ZIP = ' . self::str($zip) . " AND ADDRESS_STATUS = 'Current' AND JURISDICTION = 'FAIRFAX COUNTY'",
            'outFields' => 'PARCEL_PIN,STREET_NUM,STREET_NUM_SUF,STREET_PREFIX,STREET_NAME,STREET_TYPE,STREET_SUFFIX,UNIT_TYPE,UNIT_NUMBER,CITY,ZIP',
            'outSR' => '4326', 'returnGeometry' => 'true', 'orderByFields' => 'OBJECTID',
        ], true, true) as $r) {
            $pin = (string) ($r['attributes']['PARCEL_PIN'] ?? '');
            if ($pin !== '' && !isset($points[$pin])) {
                $points[$pin] = self::pointRow($r);
            }
        }

        $since = gmdate('Y-m-d', time() - $days * 86400);
        $sales = [];
        foreach (array_chunk(array_keys($points), 150) as $chunk) {
            if (microtime(true) - $t > $budgetSeconds) {
                break;
            }
            $in = implode(',', array_map([self::class, 'str'], $chunk));
            foreach (self::query(self::SALES, [
                'where' => "PARID IN ($in) AND SALEDT >= DATE '$since' AND PRICE > 1000 AND " . self::ARMS_LENGTH,
                'outFields' => 'PARID,SALEDT,PRICE,SALEVAL_DESC', 'orderByFields' => 'SALEDT DESC',
            ], false, true) as $s) {
                $sales[$s['PARID']] ??= $s; // latest sale per parcel
            }
        }
        if (!$sales) {
            return [];
        }
        $dwell = [];
        foreach (array_chunk(array_keys($sales), 150) as $chunk) {
            $in = implode(',', array_map([self::class, 'str'], $chunk));
            foreach (self::query(self::DWELLING, ['where' => "PARID IN ($in)", 'outFields' => 'PARID,STYLE_DESC,YRBLT,RMBED,FIXBATH,FIXHALF,SFLA,BSMT_DESC'], false, true) as $d) {
                $dwell[$d['PARID']] ??= $d;
            }
        }
        $out = [];
        foreach ($sales as $parid => $s) {
            $pt = $points[$parid];
            $d = $dwell[$parid] ?? null;
            if ($pt['address'] === '' || $d === null) {
                continue; // land, commercial or a parcel without a dwelling: not a comparable home
            }
            $out[] = [
                'address' => $pt['address'], 'city' => $pt['city'], 'state' => 'VA', 'zip' => $pt['zip'], 'county' => 'Fairfax',
                'lat' => $pt['lat'], 'lng' => $pt['lng'], 'status' => 'off_market',
                'last_sale_price' => (int) $s['PRICE'], 'last_sale_date' => gmdate('Y-m-d', (int) ($s['SALEDT'] / 1000)),
                'source_id' => 'ffx:' . $parid, 'data_status' => 'limited',
            ] + self::dwellingFacts($d);
        }
        return $out;
    }

    /* ── shaping ─────────────────────────────────────────────────────── */

    private static function shape(string $parid, array $pt, ?array $dw, ?array $val, ?array $land, ?array $parcel, array $sales): array
    {
        $facts = ['lat' => $pt['lat'], 'lng' => $pt['lng'], 'county' => 'Fairfax'];
        if ($dw) {
            $facts += self::dwellingFacts($dw);
        }
        $lot = $land ? ((int) ($land['SF'] ?? 0) ?: (int) round(((float) ($land['ACRES'] ?? 0)) * 43560)) : 0;
        if ($lot > 0) {
            $facts['lot_sqft'] = $lot;
        }
        $assessed = $val ? (int) round((float) ($val['APRTOT'] ?? 0)) : 0;
        if ($assessed > 0) {
            $facts['assessed_value'] = $assessed;
            $ex = strtolower(trim((string) ($val['FLAG4_DESC'] ?? '')));
            if (self::taxRate() > 0 && ($ex === '' || str_contains($ex, 'no exemption'))) {
                // County rate only; special-district levies (e.g. stormwater) come on top.
                $facts['tax_annual'] = (int) round($assessed * self::taxRate() / 100);
            }
        }
        $history = [];
        $lastValid = null;
        foreach ($sales as $s) {
            $price = (int) ($s['PRICE'] ?? 0);
            $date = isset($s['SALEDT']) ? gmdate('Y-m-d', (int) ($s['SALEDT'] / 1000)) : null;
            if ($date === null) {
                continue;
            }
            $history[] = ['date' => $date, 'price' => $price, 'type' => trim((string) ($s['SALEVAL_DESC'] ?? ''))];
            $fair = self::fairSale((string) ($s['SALEVAL_DESC'] ?? ''), $price);
            if ($fair && $lastValid === null) {
                $lastValid = ['price' => $price, 'date' => $date];
            }
        }
        if ($lastValid) {
            $facts['last_sale_price'] = $lastValid['price'];
            $facts['last_sale_date'] = $lastValid['date'];
        }
        $t = static fn (?array $r, string $k) => ($v = trim((string) ($r[$k] ?? ''))) !== '' ? $v : null;
        $county = array_filter([
            'source' => self::ID,
            'parid' => $parid,
            'style' => $t($dw, 'STYLE_DESC'),
            'year_remodeled' => ($dw['YRREMOD'] ?? null) ?: null,
            'effective_year' => ($dw['EFFYR'] ?? null) ?: null,
            'condition' => $t($dw, 'CDU_DESC'),
            'grade' => $t($dw, 'GRADE_DESC'),
            'basement' => $t($dw, 'BSMT_DESC'),
            'basement_type' => $t($dw, 'USER9_DESC'),
            'rec_room_sqft' => ($dw['RECROMAREA'] ?? null) ?: null,
            'fireplaces' => ($dw['WBFP_PF'] ?? null) ?: null,
            'heating' => $t($dw, 'HEAT_DESC'),
            'roof' => $t($dw, 'USER13_DESC'),
            'exterior' => $t($dw, 'EXTWALL_DESC'),
            'land_use' => $t($parcel, 'LUC_DESC'),
            'zoning' => $t($parcel, 'ZONING_DESC'),
            'water' => $t($parcel, 'UTIL1_DESC'),
            'sewer' => $t($parcel, 'UTIL2_DESC'),
            'gas' => $t($parcel, 'UTIL3_DESC'),
            'assessed_land' => $val ? (int) round((float) ($val['APRLAND'] ?? 0)) ?: null : null,
            'assessed_building' => $val ? (int) round((float) ($val['APRBLDG'] ?? 0)) ?: null : null,
            'assessed_total' => $assessed ?: null,
            'assessed_prior' => $val ? (int) round((float) ($val['PRITOT'] ?? 0)) ?: null : null,
            'assessment_year' => $val['TAXYR'] ?? null,
            'exemption' => $t($val, 'FLAG4_DESC'),
            'tax_rate' => self::taxRate() ?: null,
            'sales' => array_slice($history, 0, 12),
            'fetched_at' => gmdate('Y-m-d H:i:s'),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
        return ['parid' => $parid, 'lat' => $pt['lat'], 'lng' => $pt['lng'], 'facts' => $facts, 'county' => $county];
    }

    private static function dwellingFacts(array $d): array
    {
        $full = (int) ($d['FIXBATH'] ?? 0);
        $half = (int) ($d['FIXHALF'] ?? 0);
        $style = strtolower((string) ($d['STYLE_DESC'] ?? ''));
        $bsmt = strtolower(trim((string) ($d['BSMT_DESC'] ?? '')));
        return array_filter([
            'year_built' => ($d['YRBLT'] ?? null) ?: null,
            'beds' => ($d['RMBED'] ?? null) ?: null,
            'baths' => $full || $half ? $full + 0.5 * $half : null,
            'sqft' => ($d['SFLA'] ?? null) ?: null,
            'has_basement' => $bsmt === '' ? null : ($bsmt !== 'none' && $bsmt !== 'no basement'),
            'property_type' => str_contains($style, 'town') ? 'Townhouse' : null,
        ], static fn ($v) => $v !== null);
    }

    /* ── address matching ───────────────────────────────────────────── */

    /** "5863 Monticello Rd Unit 2" → number 5863, words "monticello rd", unit "2". */
    private static function splitStreet(string $line): ?array
    {
        if (!preg_match('/^\s*(\d+)([a-z])?\s+(.+)$/i', trim($line), $m)) {
            return null;
        }
        $rest = $m[3];
        $unit = null;
        if (preg_match('/\s+(?:#|unit|apt|ste|suite|cnd|condo)\.?\s*([a-z0-9-]+)\s*$/i', $rest, $u)) {
            $unit = strtolower($u[1]);
            $rest = substr($rest, 0, -strlen($u[0]));
        }
        return ['num' => $m[1], 'sfx' => strtolower($m[2] ?? ''), 'street' => Address::normalizeStreet($rest), 'unit' => $unit];
    }

    private static function findAddress(array $a, string $zip): ?array
    {
        $rows = self::query(self::ADDRESS, [
            'where' => 'STREET_NUM = ' . self::str($a['num']) . ' AND ZIP = ' . self::str($zip) . " AND JURISDICTION = 'FAIRFAX COUNTY'",
            'outFields' => 'PARCEL_PIN,STREET_NUM,STREET_NUM_SUF,STREET_PREFIX,STREET_NAME,STREET_TYPE,STREET_SUFFIX,UNIT_TYPE,UNIT_NUMBER,CITY,ZIP,ADDRESS_STATUS',
            'outSR' => '4326', 'returnGeometry' => 'true',
        ], true);
        $best = null;
        foreach ($rows as $r) {
            $x = $r['attributes'];
            $street = Address::normalizeStreet(trim(implode(' ', array_filter([$x['STREET_PREFIX'] ?? '', $x['STREET_NAME'] ?? '', $x['STREET_TYPE'] ?? '', $x['STREET_SUFFIX'] ?? '']))));
            $name = Address::normalizeStreet((string) ($x['STREET_NAME'] ?? ''));
            $sameStreet = $street === $a['street'] || ($name !== '' && str_contains(' ' . $a['street'] . ' ', ' ' . $name . ' '));
            if (!$sameStreet || strtolower((string) ($x['STREET_NUM_SUF'] ?? '')) !== $a['sfx']) {
                continue;
            }
            $unit = strtolower(trim((string) ($x['UNIT_NUMBER'] ?? '')));
            $rank = ($unit === ($a['unit'] ?? '') ? 2 : 0) + (($x['ADDRESS_STATUS'] ?? '') === 'Current' ? 1 : 0) + ($street === $a['street'] ? 1 : 0);
            if ($a['unit'] !== null && $unit !== '' && $unit !== $a['unit']) {
                continue; // another unit in the same building
            }
            if ($best === null || $rank > $best[0]) {
                $best = [$rank, $r];
            }
        }
        return $best ? self::pointRow($best[1]) : null;
    }

    private static function pointRow(array $r): array
    {
        $x = $r['attributes'];
        $street = trim(implode(' ', array_filter([
            trim(($x['STREET_NUM'] ?? '') . ($x['STREET_NUM_SUF'] ?? '')), $x['STREET_PREFIX'] ?? '', $x['STREET_NAME'] ?? '', $x['STREET_TYPE'] ?? '', $x['STREET_SUFFIX'] ?? '',
        ])));
        if (!empty($x['UNIT_NUMBER'])) {
            $street .= ' Unit ' . $x['UNIT_NUMBER'];
        }
        $g = $r['geometry'] ?? [];
        return [
            'parid' => (string) ($x['PARCEL_PIN'] ?? ''),
            'address' => $street === '' ? '' : ucwords(strtolower($street)),
            'city' => Address::titleCity((string) ($x['CITY'] ?? '')),
            'zip' => (string) ($x['ZIP'] ?? ''),
            'lat' => isset($g['y']) ? round((float) $g['y'], 6) : null,
            'lng' => isset($g['x']) ? round((float) $g['x'], 6) : null,
        ];
    }

    /* ── transport ──────────────────────────────────────────────────── */

    /**
     * Runs one ArcGIS query (POST, so long IN lists fit) and returns the attribute rows,
     * or whole features when $features is true. Pages past the server's 1000-row cap when $all is true.
     */
    private static function query(string $layer, array $params, bool $features = false, bool $all = false): array
    {
        $out = [];
        $offset = (int) ($params['resultOffset'] ?? 0);
        for ($page = 0; $page < 30; $page++) {
            $p = $params + ['f' => 'json', 'returnGeometry' => 'false'];
            if ($all) {
                $p['resultOffset'] = $offset;
                $p['resultRecordCount'] = 1000;
            }
            $res = Http::postForm(self::BASE . '/' . $layer . '/query', $p, 20);
            $d = json_decode((string) $res['body'], true);
            if ($res['status'] !== 200 || !is_array($d) || isset($d['error'])) {
                throw new \RuntimeException('Fairfax County records did not answer (' . ($d['error']['message'] ?? ($res['error'] ?: 'HTTP ' . $res['status'])) . ').');
            }
            $rows = (array) ($d['features'] ?? []);
            foreach ($rows as $f) {
                $out[] = $features ? $f : ($f['attributes'] ?? []);
            }
            if (!$all || empty($d['exceededTransferLimit'])) {
                break;
            }
            $offset += count($rows);
        }
        return $out;
    }

    private static function str(string $s): string
    {
        return "'" . str_replace("'", "''", $s) . "'";
    }
}

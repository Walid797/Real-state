<?php
declare(strict_types=1);

namespace App\Providers\County;

use App\Address;
use App\Cache;

/**
 * Maryland — the whole state in one source: the Department of Planning's MdProperty View, built
 * from the State Department of Assessments and Taxation (SDAT) records for all 24 jurisdictions.
 * Parcel points carry the address, year built, structure size, style, lot, assessed value and the
 * last transfer; the sales layer lists every recorded transfer with its price and how it was conveyed.
 * No bedroom or bathroom counts, and no tax figure (rates differ by county and town).
 */
final class Maryland implements CountySource
{
    private const PARCELS = 'https://mdgeodata.md.gov/imap/rest/services/PlanningCadastre/MD_PropertyData/MapServer/0';
    private const SALES = 'https://mdgeodata.md.gov/imap/rest/services/PlanningCadastre/MD_PropertySales/MapServer/0';
    private const FIELDS = 'ACCTID,ADDRESS,PREMSNUM,PREMSDIR,PREMSNAM,PREMSTYP,STRTUNT,PREMCITY,PREMZIP,YEARBLT,SQFTSTRC,CONSIDR1,TRADATE,CONVEY1,'
        . 'NFMTTLVL,NFMLNDVL,NFMIMPVL,DESCSTYL,DESCCNST,DESCLU,ZONING,RESITYP,LANDAREA,LUOM,BLDG_STORY,JURSCODE,SDATWEBADR';

    public static function id(): string
    {
        return 'maryland';
    }

    public static function name(): string
    {
        return 'Maryland property records (SDAT)';
    }

    public static function area(): string
    {
        return 'Maryland, all 24 jurisdictions';
    }

    public static function covers(array $p): bool
    {
        return strtoupper((string) ($p['state'] ?? '')) === 'MD';
    }

    public static function candidates(): string
    {
        return "state = 'MD'";
    }

    /** SDAT conveyance code 1: an arm's-length sale of an improved property. */
    public static function fairSale(string $type, int $price): bool
    {
        return $price > 1000 && ($type === '1' || stripos($type, 'arms length improved') !== false);
    }

    public static function home(array $p): ?array
    {
        $a = Street::split((string) $p['address']);
        $zip = substr(preg_replace('/\D/', '', (string) ($p['zip'] ?? '')) ?? '', 0, 5);
        if ($a === null || $zip === '') {
            return null;
        }
        return Cache::remember(self::id(), 'home', ['a' => Address::normalizeStreet((string) $p['address']), 'z' => $zip], 86400 * 30, static function () use ($a, $zip) {
            $rows = ArcGis::query(self::PARCELS, [
                'where' => 'PREMSNUM = ' . ArcGis::str((string) (int) $a['num']) . ' AND PREMZIP = ' . ArcGis::str($zip),
                'outFields' => self::FIELDS, 'outSR' => '4326', 'returnGeometry' => 'true',
            ], true, false, 'Maryland');
            $best = null;
            foreach ($rows as $f) {
                $x = $f['attributes'];
                $full = trim(implode(' ', array_filter([$x['PREMSDIR'] ?? '', $x['PREMSNAM'] ?? '', $x['PREMSTYP'] ?? ''])));
                if (!Street::same($a, (string) ($x['PREMSNAM'] ?? ''), $full)) {
                    continue;
                }
                $rank = Street::unitRank($a, $x['STRTUNT'] ?? null);
                if ($rank > 0 && ($best === null || $rank > $best[0])) {
                    $best = [$rank, $f];
                }
            }
            return $best ? self::shape($best[1]) : ['none' => true];
        }) ?: null;
    }

    public static function salesInZip(string $zip, int $days = 365, float $budgetSeconds = 60): array
    {
        $since = gmdate('Ymd', time() - $days * 86400);
        $rows = ArcGis::query(self::SALES, [
            'where' => 'PREMZIP = ' . ArcGis::str($zip) . ' AND TRADATE >= ' . ArcGis::str($since) . ' AND CONVEY1 = 1 AND CONSIDR1 > 1000 AND SQFTSTRC > 0',
            'outFields' => 'ACCTID,ADDRESS,PREMCITY,PREMZIP,YEARBLT,SQFTSTRC,CONSIDR1,TRADATE,DESCSTYL,LU,LANDAREA,LUOM,NFMTTLVL',
            'outSR' => '4326', 'returnGeometry' => 'true', 'resultRecordCount' => 2000,
        ], true, false, 'Maryland'); // this MapServer layer does not page; one ZIP's sales fit in 2,000 rows
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['attributes']['TRADATE'] ?? ''), (string) ($a['attributes']['TRADATE'] ?? '')));
        $out = [];
        $seen = [];
        foreach ($rows as $f) {
            $x = $f['attributes'];
            $acct = (string) ($x['ACCTID'] ?? '');
            if ($acct === '' || isset($seen[$acct]) || (($x['LU'] ?? 'R') !== 'R' && ($x['LU'] ?? '') !== '')) {
                continue; // latest sale per parcel, residential only
            }
            $seen[$acct] = true;
            $addr = trim((string) ($x['ADDRESS'] ?? ''));
            if ($addr === '' || !preg_match('/^\d/', $addr)) {
                continue;
            }
            $out[] = [
                'address' => ucwords(strtolower($addr)), 'city' => Address::titleCity((string) ($x['PREMCITY'] ?? '')),
                'state' => 'MD', 'zip' => $zip, 'status' => 'off_market',
                'last_sale_price' => (int) $x['CONSIDR1'], 'last_sale_date' => self::date((string) $x['TRADATE']),
                'source_id' => 'md:' . $acct, 'data_status' => 'limited',
            ] + self::facts($x, $f['geometry'] ?? []);
        }
        return $out;
    }

    /* ── shaping ─────────────────────────────────────────────────────── */

    private static function facts(array $x, array $g): array
    {
        $area = (float) ($x['LANDAREA'] ?? 0);
        $lot = match (strtoupper((string) ($x['LUOM'] ?? ''))) {
            'A' => (int) round($area * 43560),
            'S' => (int) round($area),
            default => 0,
        };
        $type = match (strtoupper(trim((string) ($x['RESITYP'] ?? '')))) {
            'SF' => 'Single Family',
            'TH' => 'Townhouse',
            'CN' => 'Condo',
            default => null,
        };
        $style = strtolower((string) ($x['DESCSTYL'] ?? ''));
        return array_filter([
            'year_built' => (int) ($x['YEARBLT'] ?? 0) > 1600 ? (int) $x['YEARBLT'] : null,
            'sqft' => ($x['SQFTSTRC'] ?? null) ?: null,
            'lot_sqft' => $lot ?: null,
            'property_type' => $type ?? (str_contains($style, 'town') ? 'Townhouse' : null),
            'has_basement' => str_contains($style, 'with basement') ? true : (str_contains($style, 'no basement') ? false : null),
            'lat' => isset($g['y']) ? round((float) $g['y'], 6) : null,
            'lng' => isset($g['x']) ? round((float) $g['x'], 6) : null,
        ], static fn ($v) => $v !== null);
    }

    private static function shape(array $f): array
    {
        $x = $f['attributes'];
        $facts = self::facts($x, $f['geometry'] ?? []);
        $assessed = (int) ($x['NFMTTLVL'] ?? 0);
        if ($assessed > 0) {
            $facts['assessed_value'] = $assessed;
        }
        $price = (int) ($x['CONSIDR1'] ?? 0);
        $date = self::date((string) ($x['TRADATE'] ?? ''));
        $conv = (string) ($x['CONVEY1'] ?? '');
        if ($date !== null && self::fairSale($conv, $price)) {
            $facts['last_sale_price'] = $price;
            $facts['last_sale_date'] = $date;
        }
        $url = trim((string) ($x['SDATWEBADR'] ?? ''));
        $t = static fn (string $k) => ($v = trim((string) ($x[$k] ?? ''))) !== '' ? $v : null;
        $county = array_filter([
            'source' => self::id(),
            'parid' => (string) ($x['ACCTID'] ?? ''),
            'jurisdiction' => $t('JURSCODE'),
            'style' => $t('DESCSTYL') ? preg_replace('/^[A-Z]{3,5}\s+/', '', (string) $x['DESCSTYL']) : null,
            'stories' => ($x['BLDG_STORY'] ?? null) ?: null,
            'exterior' => $t('DESCCNST') ? preg_replace('/^CNST\s+/', '', (string) $x['DESCCNST']) : null,
            'land_use' => $t('DESCLU'),
            'zoning' => $t('ZONING'),
            'assessed_land' => (int) ($x['NFMLNDVL'] ?? 0) ?: null,
            'assessed_building' => (int) ($x['NFMIMPVL'] ?? 0) ?: null,
            'assessed_total' => $assessed ?: null,
            'sales' => $date !== null ? [['date' => $date, 'price' => $price, 'type' => self::conveyance($conv)]] : [],
            'record_url' => preg_match('#^https://sdat\.dat\.maryland\.gov/#i', $url) ? $url : null,
            'fetched_at' => gmdate('Y-m-d H:i:s'),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
        return ['facts' => $facts, 'county' => $county];
    }

    private static function conveyance(string $code): string
    {
        return match ($code) {
            '1' => 'Arm’s-length sale, improved',
            '2' => 'Arm’s-length sale, unimproved',
            '3' => 'Arm’s-length sale, several parcels',
            '4' => 'Not arm’s-length',
            default => 'Transfer',
        };
    }

    private static function date(string $ymd): ?string
    {
        return preg_match('/^(\d{4})(\d{2})(\d{2})$/', $ymd, $m) && $m[1] > '1900' ? "$m[1]-$m[2]-$m[3]" : null;
    }
}

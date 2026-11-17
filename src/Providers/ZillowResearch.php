<?php
declare(strict_types=1);

namespace App\Providers;

use App\Db;
use App\Http;

/**
 * Zillow Research public datasets — free CSV downloads, no key.
 * https://www.zillow.com/research/data/
 *
 * The ZIP and city files are large (tens of thousands of rows), so they are
 * streamed and only the rows for regions you track are kept.
 */
final class ZillowResearch
{
    private const BASE = 'https://files.zillowstatic.com/research/public_csvs/';

    /** metric → [file for ZIPs, file for cities, file for metro areas] */
    public const FILES = [
        'zhvi' => [
            'zip' => 'zhvi/Zip_zhvi_uc_sfrcondo_tier_0.33_0.67_sm_sa_month.csv',
            'city' => 'zhvi/City_zhvi_uc_sfrcondo_tier_0.33_0.67_sm_sa_month.csv',
        ],
        'median_sale' => ['metro' => 'median_sale_price/Metro_median_sale_price_uc_sfrcondo_sm_sa_month.csv'],
        'inventory' => ['metro' => 'invt_fs/Metro_invt_fs_uc_sfrcondo_sm_month.csv'],
        'days_pending' => ['metro' => 'mean_doz_pending/Metro_mean_doz_pending_uc_sfrcondo_sm_month.csv'],
    ];

    public const LABELS = [
        'zhvi' => 'Typical home value',
        'median_sale' => 'Median sale price (metro)',
        'inventory' => 'Homes for sale (metro)',
        'days_pending' => 'Days to pending (metro)',
        'median_list' => 'Median list price',
        'median_ppsf' => 'Median list $/sqft',
        'median_dom' => 'Median days on market',
        'listings' => 'Active listings',
    ];

    private const KEEP_MONTHS = 132;

    /**
     * Imports every Zillow series for the tracked regions.
     * @return array{regions:int, points:int, errors:string[]}
     */
    public static function import(?int $onlyRegionId = null): array
    {
        $regions = $onlyRegionId
            ? Db::all('SELECT * FROM market_regions WHERE id = ?', [$onlyRegionId])
            : Db::all('SELECT * FROM market_regions');
        $out = ['regions' => count($regions), 'points' => 0, 'errors' => []];
        if (!$regions) {
            return $out;
        }
        $zips = $cities = [];
        foreach ($regions as $r) {
            if ($r['kind'] === 'zip') {
                $zips[$r['name']] = (int) $r['id'];
            } else {
                $cities[mb_strtolower($r['name']) . '|' . $r['state']] = (int) $r['id'];
            }
        }
        $jobs = [];
        if ($zips) {
            $jobs[] = ['zhvi', self::FILES['zhvi']['zip'], 'zip', $zips];
        }
        if ($cities) {
            $jobs[] = ['zhvi', self::FILES['zhvi']['city'], 'city', $cities];
            foreach (['median_sale', 'inventory', 'days_pending'] as $metric) {
                $jobs[] = [$metric, self::FILES[$metric]['metro'], 'metro', $cities];
            }
        }
        foreach ($jobs as [$metric, $file, $kind, $wanted]) {
            $r = self::importFile(self::BASE . $file, $metric, $kind, $wanted);
            $out['points'] += $r['points'];
            if ($r['error']) {
                $out['errors'][] = $r['error'];
            }
        }
        foreach ($regions as $r) {
            Db::run('UPDATE market_regions SET last_import_at = ? WHERE id = ?', [now(), $r['id']]);
        }
        return $out;
    }

    /**
     * @param array<string,int> $wanted lookup key → region id
     * @return array{points:int, error:?string}
     */
    public static function importFile(string $url, string $metric, string $kind, array $wanted): array
    {
        $header = null;
        $dateCols = [];
        $points = 0;
        $left = count($wanted);
        $res = Http::streamLines($url, static function (string $line) use (&$header, &$dateCols, &$points, &$left, $metric, $kind, $wanted) {
            if ($line === '') {
                return true;
            }
            $row = str_getcsv($line, ',', '"', '');
            if ($header === null) {
                $header = array_flip($row);
                foreach ($row as $i => $name) {
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $name)) {
                        $dateCols[$i] = $name;
                    }
                }
                $dateCols = array_slice($dateCols, -self::KEEP_MONTHS, null, true);
                return isset($header['RegionName']) && $dateCols !== [];
            }
            $key = self::rowKey($row, $header, $kind);
            if ($key === null || !isset($wanted[$key])) {
                return true;
            }
            $points += self::store($wanted[$key], $metric, $row, $dateCols);
            return --$left > 0; // stop downloading once every tracked region was found
        }, 600);

        $error = null;
        if ($header === null || !isset($header['RegionName'])) {
            $error = "Zillow file for “$metric” could not be read (HTTP {$res['status']}" . ($res['error'] ? ', ' . $res['error'] : '') . ').';
        }
        return ['points' => $points, 'error' => $error];
    }

    /** The lookup key for one CSV row: "78704" for ZIPs, "austin|TX" for cities and metros. */
    public static function rowKey(array $row, array $header, string $kind): ?string
    {
        $name = $row[$header['RegionName']] ?? '';
        if ($kind === 'zip') {
            return str_pad($name, 5, '0', STR_PAD_LEFT);
        }
        if ($kind === 'city') {
            $state = $row[$header['State'] ?? $header['StateName'] ?? -1] ?? '';
            return mb_strtolower($name) . '|' . strtoupper($state);
        }
        // Metro rows are named "Austin, TX" after their principal city.
        if (!str_contains($name, ', ')) {
            return null;
        }
        [$city, $state] = explode(', ', $name, 2);
        return mb_strtolower($city) . '|' . strtoupper($state);
    }

    private static function store(int $regionId, string $metric, array $row, array $dateCols): int
    {
        $n = 0;
        Db::tx(static function () use ($regionId, $metric, $row, $dateCols, &$n) {
            foreach ($dateCols as $i => $date) {
                $v = $row[$i] ?? '';
                if ($v === '' || !is_numeric($v)) {
                    continue;
                }
                Db::run(
                    'INSERT INTO market_series (region_id, metric, period, value) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE value = VALUES(value)',
                    [$regionId, $metric, substr($date, 0, 8) . '01', round((float) $v, 2)]
                );
                $n++;
            }
        });
        return $n;
    }

    /** Stores RentCast /markets history for a ZIP as extra series. */
    public static function storeRentCast(int $regionId, array $history, array $current): int
    {
        $map = ['medianPrice' => 'median_list', 'medianPricePerSquareFoot' => 'median_ppsf', 'medianDaysOnMarket' => 'median_dom', 'totalListings' => 'listings'];
        $history[gmdate('Y-m')] = $current + ($history[gmdate('Y-m')] ?? []);
        $n = 0;
        foreach ($history as $ym => $h) {
            if (!preg_match('/^\d{4}-\d{2}$/', (string) $ym) || !is_array($h)) {
                continue;
            }
            foreach ($map as $field => $metric) {
                if (isset($h[$field]) && is_numeric($h[$field])) {
                    Db::run(
                        'INSERT INTO market_series (region_id, metric, period, value) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE value = VALUES(value)',
                        [$regionId, $metric, $ym . '-01', round((float) $h[$field], 2)]
                    );
                    $n++;
                }
            }
        }
        return $n;
    }
}

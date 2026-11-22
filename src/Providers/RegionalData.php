<?php
declare(strict_types=1);

namespace App\Providers;

use App\Cache;
use App\Http;
use App\Settings;

/** County-level public statistics, shared across properties by verified Census FIPS. */
final class RegionalData
{
    public const ACS_SOURCE = 'https://api.censusreporter.org/1.0/data/show/latest';
    public const BLS_SOURCE = 'https://www.bls.gov/lau/';
    public const MORTGAGE_SOURCE = 'https://fred.stlouisfed.org/series/MORTGAGE30US';

    public static function county(string $fips, bool $fetch = false): array
    {
        if (!preg_match('/^\d{5}$/D', $fips)) {
            return [];
        }
        $acs = self::cached('acs', ['fips' => $fips], 30, $fetch, static fn () => self::fetchAcs($fips));
        $employment = self::cached('bls', ['fips' => $fips], 7, $fetch, static fn () => self::fetchBls($fips));
        $history = $acs && Census::hasKey()
            ? self::cached('acs-history', ['fips' => $fips, 'year' => $acs['year'] - 5], 90, $fetch,
                static fn () => self::fetchAcsHistory($fips, $acs['year'] - 5))
            : [];
        return ['acs' => $acs, 'employment' => $employment, 'history' => $history];
    }

    public static function tract(array $tract, bool $fetch = false): array
    {
        $geoid = '14000US' . ($tract['state'] ?? '') . ($tract['county'] ?? '') . ($tract['tract'] ?? '');
        if (!preg_match('/^14000US\d{11}$/D', $geoid)) {
            return [];
        }
        return self::cached('tract-acs', ['geoid' => $geoid], 30, $fetch, static function () use ($geoid) {
            $query = http_build_query([
                'table_ids' => 'B01003,B11001,B19013,B25064,B25077,B25001,B07003,B01002,B11003,B15003',
                'geo_ids' => $geoid,
            ]);
            $r = Http::get(self::ACS_SOURCE . '?' . $query, [], 7);
            return $r['status'] === 200 ? self::decodeAcs(json_decode($r['body'], true), $geoid) : null;
        });
    }

    public static function mortgage(bool $fetch = false): array
    {
        return self::cached('mortgage', [], 3, $fetch, static function (): ?array {
            $from = gmdate('Y-m-d', time() - 60 * 86400);
            $r = Http::get('https://fred.stlouisfed.org/graph/fredgraph.csv?id=MORTGAGE30US&cosd=' . $from, [], 5);
            if ($r['status'] !== 200) {
                return null;
            }
            foreach (array_reverse(explode("\n", trim($r['body']))) as $line) {
                $columns = str_getcsv(trim($line), ',', '"', '');
                if (count($columns) === 2 && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $columns[0]) && is_numeric($columns[1])) {
                    $rate = (float) $columns[1];
                    return $rate > 0 && $rate < 30 ? ['date' => $columns[0], 'rate' => $rate] : null;
                }
            }
            return null;
        });
    }

    private static function cached(string $what, array $params, int $days, bool $fetch, callable $load): array
    {
        $key = Cache::key('regional', $what, $params);
        $hit = Cache::get($key);
        if (is_array($hit)) {
            return $hit;
        }
        if (!$fetch) {
            return [];
        }
        $data = $load();
        Cache::put($key, 'regional', $data ?? [], $data === null ? 900 : 86400 * $days);
        return $data ?? [];
    }

    private static function fetchAcs(string $fips): ?array
    {
        $geoid = '05000US' . $fips;
        $query = http_build_query([
            'table_ids' => 'B01003,B11001,B19013,B25064,B25077,B25001,B07003,B07403',
            'geo_ids' => $geoid,
        ]);
        $r = Http::get(self::ACS_SOURCE . '?' . $query, [], 6);
        return $r['status'] === 200 ? self::decodeAcs(json_decode($r['body'], true), $geoid) : null;
    }

    public static function decodeAcs(mixed $data, string $geoid): ?array
    {
        if (!is_array($data['data'][$geoid] ?? null)
            || !preg_match('/^acs(\d{4})_5yr$/D', (string) ($data['release']['id'] ?? ''), $release)) {
            return null;
        }
        $tables = $data['data'][$geoid];
        $read = static function (string $column, string $kind = 'estimate') use ($tables): ?int {
            $v = $tables[substr($column, 0, 6)][$kind][$column] ?? null;
            return is_numeric($v) && $v >= 0 ? (int) $v : null;
        };
        $inState = $read('B07003010');
        $inOther = $read('B07003013');
        $outState = $read('B07403010');
        $outOther = $read('B07403013');
        $errors = array_map(static fn ($column) => $read($column, 'error'), ['B07003010', 'B07003013', 'B07403010', 'B07403013']);
        return [
            'year' => (int) $release[1],
            'name' => (string) ($data['geography'][$geoid]['name'] ?? ''),
            'population' => $read('B01003001'),
            'median_age' => isset($tables['B01002']['estimate']['B01002001'])
                && is_numeric($tables['B01002']['estimate']['B01002001'])
                && $tables['B01002']['estimate']['B01002001'] >= 0
                && $tables['B01002']['estimate']['B01002001'] < 130
                    ? (float) $tables['B01002']['estimate']['B01002001'] : null,
            'families_with_children' => in_array(null, [$read('B11003003'), $read('B11003010'), $read('B11003016')], true)
                ? null : $read('B11003003') + $read('B11003010') + $read('B11003016'),
            'families' => $read('B11003001'),
            'college_graduates' => in_array(null, [$read('B15003022'), $read('B15003023'), $read('B15003024'), $read('B15003025')], true)
                ? null : $read('B15003022') + $read('B15003023') + $read('B15003024') + $read('B15003025'),
            'adults_25_plus' => $read('B15003001'),
            'households' => $read('B11001001'),
            'housing_units' => $read('B25001001'),
            'income' => $read('B19013001'),
            'rent' => $read('B25064001'),
            'home_value' => $read('B25077001'),
            'arrivals_other_states' => $inOther,
            'net_domestic' => in_array(null, [$inState, $inOther, $outState, $outOther], true) ? null : $inState + $inOther - $outState - $outOther,
            'net_moe' => in_array(null, $errors, true) ? null : (int) ceil(sqrt(array_sum(array_map(static fn ($m) => $m ** 2, $errors)))),
        ];
    }

    private static function fetchAcsHistory(string $fips, int $year): ?array
    {
        $query = http_build_query([
            'get' => 'B01003_001E,B11001_001E,B25001_001E',
            'for' => 'county:' . substr($fips, 2), 'in' => 'state:' . substr($fips, 0, 2),
            'key' => Settings::get('census_key'),
        ]);
        $r = Http::get("https://api.census.gov/data/$year/acs/acs5?" . $query, [], 5);
        $data = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($data[0] ?? null) || !is_array($data[1] ?? null) || count($data[0]) !== count($data[1])) {
            return null;
        }
        $row = array_combine($data[0], $data[1]);
        if (($row['state'] ?? '') . ($row['county'] ?? '') !== $fips) {
            return null;
        }
        $out = ['year' => $year];
        foreach (['B01003_001E' => 'population', 'B11001_001E' => 'households', 'B25001_001E' => 'housing_units'] as $col => $key) {
            $out[$key] = is_numeric($row[$col] ?? null) && $row[$col] > 0 ? (int) $row[$col] : null;
        }
        return $out;
    }

    private static function fetchBls(string $fips): ?array
    {
        $prefix = 'LAUCN' . $fips . '00000000';
        $ids = [$prefix . '03', $prefix . '05'];
        $r = Http::request('POST', 'https://api.bls.gov/publicAPI/v2/timeseries/data/', ['Content-Type' => 'application/json'],
            json_encode(['seriesid' => $ids], JSON_THROW_ON_ERROR), 6);
        return $r['status'] === 200 ? self::decodeBls(json_decode($r['body'], true), $ids) : null;
    }

    public static function decodeBls(mixed $data, array $ids): ?array
    {
        if (($data['status'] ?? null) !== 'REQUEST_SUCCEEDED') {
            return null;
        }
        $series = [];
        foreach ($data['Results']['series'] ?? [] as $item) {
            if (!in_array($item['seriesID'] ?? '', $ids, true)) {
                continue;
            }
            foreach ($item['data'] ?? [] as $row) {
                if (preg_match('/^M(0[1-9]|1[0-2])$/D', (string) ($row['period'] ?? ''), $m)
                    && preg_match('/^\d{4}$/D', (string) ($row['year'] ?? '')) && is_numeric($row['value'] ?? null) && $row['value'] >= 0) {
                    $series[$item['seriesID']][$row['year'] . '-' . $m[1]] = (float) $row['value'];
                }
            }
        }
        $rates = $series[$ids[0]] ?? [];
        if (!$rates) {
            return null;
        }
        krsort($rates);
        $month = array_key_first($rates);
        $lastYear = ((int) substr($month, 0, 4) - 1) . substr($month, 4);
        $jobs = $series[$ids[1]] ?? [];
        return [
            'month' => $month, 'unemployment' => $rates[$month],
            'employment_change' => isset($jobs[$month]) && !empty($jobs[$lastYear])
                ? ($jobs[$month] / $jobs[$lastYear] - 1) * 100 : null,
        ];
    }
}

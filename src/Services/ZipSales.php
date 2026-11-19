<?php
declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Providers\County\Arlington;
use App\Providers\County\DistrictOfColumbia;
use App\Providers\County\LeeCounty;
use App\Providers\County\Maryland;
use App\Providers\County\MiamiDade;
use App\Providers\Fairfax;

/** Temporary search results from official records, separate from the team's saved properties. */
final class ZipSales
{
    private const TTL = 48 * 3600;

    /** These prefixes select candidate registries, each of which must verify the actual property ZIP. */
    private static function source(string $zip): ?string
    {
        if (!preg_match('/^[0-9]{5}$/D', $zip)) {
            return null;
        }
        return match (true) {
            preg_match('/^(201|220|221|223)/', $zip) === 1 => Fairfax::class,
            str_starts_with($zip, '222') => Arlington::class,
            preg_match('/^(200|20[2-5])/', $zip) === 1 => DistrictOfColumbia::class,
            preg_match('/^(20[6-9]|21[0-9])/', $zip) === 1 => Maryland::class,
            str_starts_with($zip, '339') => LeeCounty::class,
            preg_match('/^33[0-2]/', $zip) === 1 => MiamiDade::class,
            default => null,
        };
    }

    /** @return array{state:string, source:?string, fetched_at:?string, rows:array} */
    public static function request(string $zip): array
    {
        $source = self::source($zip);
        if ($source === null) {
            return ['state' => 'unavailable', 'source' => null, 'fetched_at' => null, 'rows' => []];
        }

        $at = now();
        $row = Db::one('SELECT state, fetched_at, payload FROM zip_sales WHERE zip = ? AND expires_at > ?', [$zip, $at]);
        if ($row === null) {
            Db::tx(static function () use ($zip, $at): void {
                Db::run('DELETE FROM zip_sales WHERE zip = ? AND expires_at <= ?', [$zip, $at]);
                Db::run("INSERT IGNORE INTO zip_sales (zip, state, requested_at, next_attempt_at, expires_at)
                         VALUES (?, 'pending', ?, ?, ?)", [$zip, $at, $at, gmdate('Y-m-d H:i:s', time() + self::TTL)]);
            });
        }
        return [
            'state' => $row['state'] ?? 'pending',
            'source' => $source::name(),
            'fetched_at' => $row['fetched_at'] ?? null,
            'rows' => $row && $row['state'] === 'ready' ? self::verifiedRows($zip, json_decode($row['payload'], true) ?? []) : [],
        ];
    }

    /** Remove expired responses, then retrieve one ZIP without blocking interactive searches. */
    public static function run(): ?string
    {
        $at = now();
        Db::run('DELETE FROM zip_sales WHERE expires_at <= ?', [$at]);
        $job = Db::one("SELECT zip, requested_at FROM zip_sales WHERE state = 'pending' AND next_attempt_at <= ?
                        ORDER BY requested_at, zip LIMIT 1", [$at]);
        if ($job === null) {
            return null;
        }

        $zip = $job['zip'];
        $key = [$zip, $job['requested_at']];
        $claim = Db::run("UPDATE zip_sales SET next_attempt_at = ? WHERE zip = ? AND requested_at = ?
                         AND state = 'pending' AND next_attempt_at <= ?",
            [gmdate('Y-m-d H:i:s', time() + 1800), ...$key, $at]);
        if ($claim->rowCount() !== 1) {
            return null;
        }
        $source = self::source($zip);
        try {
            if ($source === null) {
                throw new \RuntimeException('The configured ZIP source is no longer available.');
            }
            $rows = self::verifiedRows($zip, $source::salesInZip($zip, 365, 60));
            $payload = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (\RuntimeException | \JsonException $e) {
            error_log('[zip sales ' . $zip . '] ' . $e->getMessage());
            Db::run("UPDATE zip_sales SET state = 'error', expires_at = ?
                     WHERE zip = ? AND requested_at = ? AND state = 'pending'",
                [gmdate('Y-m-d H:i:s', time() + 1800), ...$key]);
            return "Official ZIP sales $zip: source unavailable.";
        }
        Db::run("UPDATE zip_sales SET state = 'ready', fetched_at = ?, expires_at = ?, payload = ?
                 WHERE zip = ? AND requested_at = ? AND state = 'pending'",
            [now(), gmdate('Y-m-d H:i:s', time() + self::TTL), $payload, ...$key]);
        return "Official ZIP sales $zip: " . count($rows) . ' records.';
    }

    /** Only complete sale records whose address ZIP matches exactly. Never fill blanks from other homes. */
    public static function verifiedRows(string $zip, array $rows): array
    {
        $fields = array_flip(['source_id', 'address', 'city', 'state', 'zip', 'county',
            'last_sale_price', 'last_sale_date', 'property_type', 'beds', 'baths', 'sqft', 'lot_sqft',
            'year_built', 'assessed_value', 'tax_annual', 'has_basement', 'garage_spaces', 'lat', 'lng']);
        $verified = [];
        $since = gmdate('Y-m-d', time() - 365 * 86400);
        $today = gmdate('Y-m-d');
        foreach ($rows as $row) {
            if (($row['zip'] ?? null) !== $zip || trim((string) ($row['address'] ?? '')) === ''
                || empty($row['source_id']) || (int) ($row['last_sale_price'] ?? 0) <= 0) {
                continue;
            }
            $date = $row['last_sale_date'] ?? '';
            if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $ymd)
                || !checkdate((int) $ymd[2], (int) $ymd[3], (int) $ymd[1]) || $date < $since || $date > $today) {
                continue;
            }
            $id = $row['source_id'];
            if (!isset($verified[$id]) || $date > $verified[$id]['last_sale_date']) {
                $verified[$id] = array_intersect_key($row, $fields);
            }
        }
        uasort($verified, static fn (array $a, array $b): int => strcmp($b['last_sale_date'], $a['last_sale_date'])
            ?: strcmp($a['address'], $b['address']));
        return array_values($verified);
    }
}

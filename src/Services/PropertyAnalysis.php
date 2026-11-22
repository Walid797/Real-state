<?php
declare(strict_types=1);

namespace App\Services;

use App\Address;
use App\Db;
use App\Geo;
use App\Providers\Census;
use App\PropertyRepo;
use App\Providers\OpenRouter;

final class PropertyAnalysis
{
    public static function enqueue(int $id, bool $refresh = false): void
    {
        $previous = Db::one('SELECT fingerprint, status FROM property_analyses WHERE property_id=?', [$id]);
        if (!$refresh && !empty($previous['fingerprint']) && $previous['status'] !== 'collecting') {
            $property = PropertyRepo::find($id);
            $refresh = $property && $previous['fingerprint'] !== self::fingerprint($property);
        }
        if ($refresh) {
            // An active worker finishes its current snapshot. It may be refreshed after completion.
            Db::run("UPDATE property_analyses SET status='pending', attempts=0, error_code=NULL, next_attempt_at=?, requested_at=?, updated_at=?
                WHERE property_id=? AND status <> 'collecting'", [now(), now(), now(), $id]);
        }
        Db::run(
            "INSERT INTO property_analyses (property_id, requested_at, updated_at, next_attempt_at, opened_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status = IF(status IN ('ready','evidence_ready','awaiting_key','failed') AND generated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 48 HOUR), 'pending', status),
                attempts = IF(status='pending', 0, attempts), opened_at = VALUES(opened_at)",
            [$id, now(), now(), now(), now()]
        );
    }

    public static function get(int $id): ?array
    {
        $row = Db::one('SELECT * FROM property_analyses WHERE property_id = ?', [$id]);
        if (!$row) {
            return null;
        }
        $row['evidence'] = json_decode((string) $row['evidence'], true);
        $row['narrative'] = json_decode((string) $row['narrative'], true);
        $row['ai_configured'] = OpenRouter::enabled();
        $row['stale'] = false;
        if ($row['evidence']) {
            $p = PropertyRepo::find($id);
            $row['stale'] = !$p || self::fingerprint($p) !== $row['fingerprint'];
            if ($p && $row['stale'] && $row['status'] !== 'collecting') {
                self::enqueue($id, true);
                $row['status'] = 'pending';
            }
        }
        return $row;
    }

    public static function publicReport(int $id): ?array
    {
        $r = self::get($id);
        if (!$r) {
            return null;
        }
        unset($r['fingerprint']);
        return $r;
    }

    /** Run the requested house immediately, with one lock shared by its AJAX and cron workers. */
    public static function run(int $budgetSeconds = 110, ?int $propertyId = null): array
    {
        Db::run("UPDATE property_analyses SET status='retry', error_code='interrupted', next_attempt_at=UTC_TIMESTAMP()
            WHERE opened_at IS NOT NULL AND status='collecting' AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)
            AND (? IS NULL OR property_id=?)", [$propertyId, $propertyId]);
        $row = self::due($propertyId);
        if (!$row) {
            return ['Property intelligence has no due opened homes.'];
        }
        $id = (int) $row['property_id'];
        $lockName = 'plat_intelligence_' . $id;
        if ((int) Db::val('SELECT GET_LOCK(?, 0)', [$lockName]) !== 1) {
            return ['This property is already being analyzed.'];
        }
        $start = microtime(true);
        try {
            $row = self::due($id);
            if (!$row) {
                return ['This property has already been handled.'];
            }
            $p = PropertyRepo::find($id);
            if (!$p) {
                return ['Property no longer exists.'];
            }
            Db::run("UPDATE property_analyses SET status='collecting', updated_at=? WHERE property_id=?", [now(), $id]);
            if (!Geo::valid($p['lat'], $p['lng'])) {
                $geocode = Census::geocode(Address::full($p));
                $street = $geocode ? explode(',', $geocode['matched'])[0] : '';
                if ($geocode && ($geocode['state'] ?? '') === $p['state']
                    && (empty($p['zip']) || ($geocode['zip'] ?? '') === $p['zip'])
                    && Address::normalizeStreet($street) === Address::normalizeStreet($p['address'])) {
                    Db::update('properties', ['lat' => $geocode['lat'], 'lng' => $geocode['lng'], 'updated_at' => now()], $id);
                    $p = PropertyRepo::find($id);
                }
            }
            $fingerprint = self::fingerprint($p);
            $packet = json_decode((string) $row['evidence'], true);
            if (!$packet || !in_array($row['status'], ['evidence_ready','awaiting_key','retry'], true)
                || $row['fingerprint'] !== $fingerprint
                || !$row['generated_at'] || strtotime($row['generated_at'] . ' UTC') < time() - 48 * 3600) {
                // Store canonical English evidence so reports can switch language independently.
                $language = $_COOKIE['plat_language'] ?? null;
                $_COOKIE['plat_language'] = 'en';
                try {
                    $packet = InvestmentEvidence::collect($p);
                } finally {
                    if ($language === null) { unset($_COOKIE['plat_language']); } else { $_COOKIE['plat_language'] = $language; }
                }
                Db::run("UPDATE property_analyses SET evidence=?, narrative=NULL, fingerprint=?, generated_at=?, updated_at=? WHERE property_id=?",
                    [json_encode($packet, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $fingerprint, now(), now(), $id]);
            }
            if (!OpenRouter::enabled()) {
                self::state($id, 'awaiting_key', null, 0);
                return ['Property ' . $id . ' evidence collected; OpenRouter key needed.'];
            }
            if (microtime(true) - $start > $budgetSeconds - 60) {
                self::state($id, 'evidence_ready', null, 0);
                return ['Property ' . $id . ' evidence collected; AI will continue on the next request.'];
            }
            $result = OpenRouter::analyze($packet);
            if (!$result['ok']) {
                $attempts = (int) $row['attempts'] + 1;
                $error = $result['error'];
                $delay = match ($error) { 'ai_limit' => $result['retry_after'] ?? 3600, 'ai_key' => 3600, default => min(86400, 300 * 2 ** min($attempts, 8)) };
                Db::run('UPDATE property_analyses SET attempts=? WHERE property_id=?', [$attempts, $id]);
                self::state($id, $attempts >= 5 && $error === 'ai_invalid' ? 'failed' : 'retry', $error, $delay);
                return ['Property ' . $id . ' AI deferred: ' . $error . '.'];
            }
            Db::run("UPDATE property_analyses SET status='ready', narrative=?, model=?, generated_at=?, updated_at=?, error_code=NULL, attempts=0 WHERE property_id=?",
                [json_encode($result['report'], JSON_UNESCAPED_UNICODE), $result['model'], now(), now(), $id]);
            return ['Property ' . $id . ' individual AI report completed.'];
        } catch (\Throwable $e) {
            if (isset($id)) {
                self::state($id, 'retry', 'data_unavailable', 900);
            }
            error_log('Property intelligence failed: ' . get_class($e));
            return ['Property intelligence will retry after a data-source failure.'];
        } finally {
            Db::val('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    private static function state(int $id, string $status, ?string $error, int $delay): void
    {
        Db::run('UPDATE property_analyses SET status=?, error_code=?, updated_at=?, next_attempt_at=? WHERE property_id=?',
            [$status, $error, now(), gmdate('Y-m-d H:i:s', time() + $delay), $id]);
    }

    private static function fingerprint(array $p): string
    {
        $fields = array_intersect_key($p, array_flip(['id','address','city','state','zip','county','lat','lng','property_type',
            'list_price','status','sqft','beds','baths','year_built','lot_sqft','hoa_fee','tax_annual','description','details','last_sale_price','last_sale_date']));
        return hash('sha256', json_encode([InvestmentEvidence::VERSION, $fields, Deal::saved((int) $p['id']), Condition::get((int) $p['id'])], JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private static function due(?int $propertyId): ?array
    {
        return Db::one("SELECT * FROM property_analyses WHERE opened_at IS NOT NULL AND next_attempt_at <= UTC_TIMESTAMP()
            AND (status IN ('pending','retry') OR (status IN ('evidence_ready','awaiting_key') AND ? = 1))
            AND (? IS NULL OR property_id=?)
            ORDER BY opened_at DESC, property_id LIMIT 1", [OpenRouter::enabled(), $propertyId, $propertyId]);
    }
}

<?php
declare(strict_types=1);

namespace App;

/**
 * Counts metered API calls per calendar month and refuses to go past the
 * free allowance. Scheduled jobs stop earlier (limit − reserve) so alerts
 * can never use up the requests kept for searching by hand.
 */
final class Quota
{
    public const PROVIDERS = [
        'rentcast' => 'RentCast',
        'openwebninja' => 'Zillow data',
        'cesium' => '3D views',
    ];

    public static function period(): string
    {
        return gmdate('Y-m');
    }

    public static function limit(string $provider): int
    {
        return max(0, Settings::int($provider . '_limit', 0));
    }

    public static function reserve(string $provider): int
    {
        return max(0, min(self::limit($provider), Settings::int($provider . '_reserve', 0)));
    }

    public static function used(string $provider): int
    {
        return (int) Db::val('SELECT calls FROM api_usage WHERE provider = ? AND period = ?', [$provider, self::period()]);
    }

    public static function left(string $provider, string $context = 'manual'): int
    {
        $cap = self::limit($provider) - ($context === 'cron' ? self::reserve($provider) : 0);
        return max(0, $cap - self::used($provider));
    }

    public static function can(string $provider, string $context = 'manual'): bool
    {
        return self::left($provider, $context) > 0;
    }

    public static function spend(string $provider): void
    {
        Db::run(
            'INSERT INTO api_usage (provider, period, calls) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE calls = calls + 1',
            [$provider, self::period()]
        );
    }

    public static function log(string $provider, string $endpoint, int $status, int $ms, ?string $note, string $context): void
    {
        Db::insert('api_log', [
            'provider' => $provider,
            'endpoint' => mb_substr($endpoint, 0, 190),
            'status' => $status,
            'ms' => $ms,
            'note' => $note === null ? null : mb_substr($note, 0, 255),
            'context' => $context,
            'created_at' => now(),
        ]);
    }

    /** Days until the monthly counters reset (UTC). */
    public static function resetsIn(): int
    {
        $next = strtotime(gmdate('Y-m-01 00:00:00', strtotime('first day of next month UTC')) . ' UTC');
        return max(1, (int) ceil(($next - time()) / 86400));
    }
}

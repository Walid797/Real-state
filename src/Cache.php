<?php
declare(strict_types=1);

namespace App;

/** JSON response cache in MySQL — used for the free lookups (flood zone, census, geocoding). */
final class Cache
{
    public static function key(string $provider, string $what, array $params = []): string
    {
        ksort($params);
        return hash('sha256', $provider . '|' . $what . '|' . json_encode($params));
    }

    public static function get(string $key): mixed
    {
        $row = Db::one('SELECT payload FROM api_cache WHERE cache_key = ? AND expires_at > ?', [$key, now()]);
        return $row ? json_decode($row['payload'], true) : null;
    }

    public static function put(string $key, string $provider, mixed $data, int $ttlSeconds): void
    {
        Db::run(
            'INSERT INTO api_cache (cache_key, provider, payload, created_at, expires_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), created_at = VALUES(created_at), expires_at = VALUES(expires_at)',
            [$key, $provider, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), now(), gmdate('Y-m-d H:i:s', time() + $ttlSeconds)]
        );
    }

    public static function remember(string $provider, string $what, array $params, int $ttl, callable $fn): mixed
    {
        $key = self::key($provider, $what, $params);
        $hit = self::get($key);
        if ($hit !== null) {
            return $hit;
        }
        $fresh = $fn();
        if ($fresh !== null) {
            self::put($key, $provider, $fresh, $ttl);
        }
        return $fresh;
    }

    public static function prune(): int
    {
        return Db::run('DELETE FROM api_cache WHERE expires_at < ?', [now()])->rowCount();
    }
}

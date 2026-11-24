<?php
declare(strict_types=1);
// Start tests/http_fixture.php as documented there, then: php tests/http_concurrent.php
require dirname(__DIR__) . '/src/bootstrap.php';
use App\Http;
if (!str_starts_with((string) App\Config::get('base_url'), 'http://127.0.0.1:')) exit(1);
$url = 'http://127.0.0.1:8097';
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$t = microtime(true);
$results = Http::concurrent([
    'first' => static fn() => Http::get($url . '/first'),
    'chain' => static function () use ($url) { Http::get($url . '/fail'); return Http::get($url . '/fallback'); },
    'failure' => static fn() => Http::get($url . '/fail'),
    'exception' => static fn() => throw new RuntimeException('isolated source failure'),
], 4);
$elapsed = microtime(true) - $t;
$check($results['first']['status'] === 200 && $results['chain']['status'] === 200, 'Independent and chained sources complete');
$check($results['failure']['status'] === 503 && $results['exception'] === null, 'Source failures remain isolated');
$check($elapsed < 1.4, 'Requests overlap instead of taking the serial 1.6 seconds');
$t = microtime(true);
$results = Http::concurrent([
    'fast' => static fn() => Http::get($url . '/first'),
    'slow' => static fn() => Http::get($url . '/slow'),
], 1);
$check(microtime(true) - $t < 1.8, 'Batch deadline bounds waiting');
$check($results['fast']['status'] === 200 && $results['slow']['status'] === 0 && $results['slow']['body'] === '' && !empty($results['slow']['error']), 'Deadline preserves good results and rejects incomplete responses');
$check(Http::concurrent([]) === [], 'Empty batch resets scheduler');
$check(Http::get($url . '/after')['status'] === 200, 'Ordinary requests work after batch cleanup');
echo 'Concurrent transport checks passed; overlapping fixture requests took ' . round($elapsed, 2) . "s.\n";

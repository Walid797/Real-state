<?php
declare(strict_types=1);

/*
 * Checks that listing translation works from this server: translates one short sentence and
 * prints the provider and the result (or the error). Uses a few characters of the daily allowance.
 *   php bin/translate-check.php
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

$r = App\Services\Translate::text('Fully renovated colonial with a new roof and an updated kitchen. Sold as-is.', 'fa');
echo $r['ok'] ? 'OK via ' . $r['provider'] . ': ' . $r['text'] : 'FAILED: ' . ($r['error'] ?? '?'), "\n";

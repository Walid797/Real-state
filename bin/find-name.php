<?php
declare(strict_types=1);

/*
 * Read-only: lists where a name appears in the database (users, notes, activity, alerts,
 * search profiles, pipeline). Prints table, id and column only — never other data.
 *   php bin/find-name.php "some name"
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

$needle = mb_strtolower(trim((string) ($argv[1] ?? '')));
if ($needle === '') {
    exit("Give a name.\n");
}
$found = 0;
$db = (string) App\Db::val('SELECT DATABASE()');
$cols = App\Db::all(
    "SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = ? AND DATA_TYPE IN ('varchar','text','mediumtext','longtext','char','json')",
    [$db]
);
foreach ($cols as $col) {
    $t = $col['t'];
    $c = $col['c'];
    if (in_array($t, ['api_cache', 'cache', 'sessions'], true)) {
        continue;
    }
    $hasId = (bool) App\Db::val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$db, $t, 'id']);
    $rows = App\Db::all("SELECT " . ($hasId ? 'id' : "'-' id") . " FROM `$t` WHERE LOCATE(?, LOWER(`$c`)) > 0 LIMIT 50", [$needle]);
    foreach ($rows as $r) {
        echo "$t.$c id=" . $r['id'] . "\n";
        $found++;
    }
}
echo "Found $found.\n";

<?php
declare(strict_types=1);

/*
 * Writes config.php once, from values passed on the command line.
 * For hosts where files can't be uploaded but a command can be run (a one-off cron job):
 *
 *   php bin/write_config.php <base64 of a JSON object>
 *
 * JSON keys: db_name, db_user, db_pass, app_key, setup_token, cron_key, base_url
 *            optional: db_host (127.0.0.1), db_port (3306), timezone (America/New_York)
 *
 * It never overwrites an existing config.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$target = dirname(__DIR__) . '/config.php';
if (is_file($target)) {
    echo "config.php already exists — left untouched.\n";
    exit(0);
}

$in = json_decode((string) base64_decode((string) ($argv[1] ?? ''), true), true);
$need = ['db_name', 'db_user', 'db_pass', 'app_key', 'setup_token', 'cron_key', 'base_url'];
if (!is_array($in) || array_diff($need, array_keys(array_filter($in, 'is_string')))) {
    fwrite(STDERR, "Expected base64 JSON with: " . implode(', ', $need) . "\n");
    exit(1);
}
if (!preg_match('/^[0-9a-f]{64}$/', $in['app_key']) || !preg_match('#^https://[a-z0-9.-]+$#', $in['base_url'])) {
    fwrite(STDERR, "app_key must be 64 hex characters and base_url an https:// origin.\n");
    exit(1);
}

$config = [
    'db' => [
        'host' => $in['db_host'] ?? '127.0.0.1',
        'port' => (int) ($in['db_port'] ?? 3306),
        'name' => $in['db_name'],
        'user' => $in['db_user'],
        'pass' => $in['db_pass'],
        'charset' => 'utf8mb4',
    ],
    'app_key' => $in['app_key'],
    'base_url' => $in['base_url'],
    'setup_token' => $in['setup_token'],
    'cron_key' => $in['cron_key'],
    'timezone' => $in['timezone'] ?? 'America/New_York',
    'debug' => false,
];

$php = "<?php\n// Written by bin/write_config.php on " . gmdate('Y-m-d H:i') . " UTC. Keep this file private.\nreturn " . var_export($config, true) . ";\n";
$tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
if (file_put_contents($tmp, $php, LOCK_EX) === false || !chmod($tmp, 0600) || !rename($tmp, $target)) {
    @unlink($tmp);
    fwrite(STDERR, "Could not write config.php (check folder permissions).\n");
    exit(1);
}
echo "config.php written.\n";

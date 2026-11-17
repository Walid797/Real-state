<?php
/**
 * Copy this file to config.php (one level ABOVE /public, never inside it)
 * or to ../realestate-config.php, and fill in real values.
 *
 * NEVER commit the real config. It is listed in .gitignore.
 *
 * API keys (RentCast, OpenWeb Ninja, Telegram, CallMeBot, SMTP password …)
 * are NOT stored here: enter them in the site's Settings page. They are
 * saved encrypted in MySQL using app_key below.
 */
return [
    'db' => [
        // On Hostinger shared hosting use 127.0.0.1 or localhost (NOT srvXXXX.hstgr.io).
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'u000000000_realestate',
        'user'    => 'u000000000_realestate',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // 64 hex chars (32 random bytes). Generate with:
    //   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    // Changing it later makes stored API keys unreadable (re-enter them).
    'app_key' => 'CHANGE_ME_64_HEX_CHARS',

    // Public base URL of the site, no trailing slash. Used in alert links.
    'base_url' => 'https://realestate.example.com',

    // One-time token for /setup (creates tables + your login). Set to a long
    // random string; setup refuses to run again once a user exists.
    'setup_token' => 'CHANGE_ME_LONG_RANDOM',

    // Secret for calling cron over HTTP (if SSH cron is not available):
    //   https://realestate.example.com/cron?key=THIS_VALUE&job=alerts
    'cron_key' => 'CHANGE_ME_LONG_RANDOM',

    'timezone' => 'America/New_York',

    // Set true only while debugging. Never in production.
    'debug' => false,
];

<?php
declare(strict_types=1);

/*
 * Requests RentCast refused (401/403: wrong key, no active plan) used to be counted
 * against the monthly allowance. They are not billed, so this month's are given back,
 * and a key that was refused last time is flagged so Settings and Search say so.
 */

use App\Db;
use App\Migrator;
use App\Providers\RentCast;
use App\Quota;

return static function (Migrator $m): void {
    $refused = (int) Db::val(
        "SELECT COUNT(*) FROM api_log WHERE provider = 'rentcast' AND status IN (401, 403) AND created_at >= ?",
        [gmdate('Y-m-01 00:00:00')]
    );
    if ($refused > 0) {
        Db::run(
            'UPDATE api_usage SET calls = GREATEST(0, CAST(calls AS SIGNED) - ?) WHERE provider = ? AND period = ?',
            [$refused, 'rentcast', Quota::period()]
        );
    }
    $last = Db::one("SELECT status, note, created_at FROM api_log WHERE provider = 'rentcast' ORDER BY id DESC LIMIT 1");
    if ($last && in_array((int) $last['status'], [401, 403], true) && RentCast::problem() === null) {
        RentCast::keyProblem((int) $last['status'], (string) $last['note'], $last['created_at']);
    }
};

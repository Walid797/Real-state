<?php
declare(strict_types=1);

/*
 * County sales lists now bring three years of every recorded sale (not just the latest fair one),
 * so buy-and-resell pairs — flips — show up. The ZIP fetch marks are cleared so each ZIP is fetched
 * again on the next scheduled run, and homes for sale are re-scored: the calculator's months held
 * now come from the area's flips where there are enough.
 */

use App\Db;
use App\Migrator;

return static function (Migrator $m): void {
    Db::run("DELETE FROM settings WHERE k LIKE 'county\\_sales\\_%'");
    Db::run("UPDATE properties SET scored_at = NULL WHERE status IN ('for_sale','pending')");
};

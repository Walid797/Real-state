<?php
declare(strict_types=1);

/*
 * ARV can now come from asking prices of similar homes nearby when no recorded sales are on
 * file, and property tax is estimated when the record has none. Every listing is scored again
 * by the scheduler (it picks up homes with no scored_at first).
 */

use App\Db;
use App\Migrator;

return static function (Migrator $m): void {
    Db::run("UPDATE properties SET scored_at = NULL WHERE status IN ('for_sale','pending')");
};

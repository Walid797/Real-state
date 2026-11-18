<?php
declare(strict_types=1);

/*
 * Some Virginia listings came labelled with the county "District of Columbia". The county is
 * cleared on those (the ZIP then picks the right county's records) and their record lookup is
 * reset, so they are looked up again at once instead of in a month.
 */

use App\Db;
use App\Migrator;

return static function (Migrator $m): void {
    Db::run("UPDATE properties SET county = NULL,
                details = JSON_REMOVE(COALESCE(details, JSON_OBJECT()), '$.county_tried_at')
             WHERE state <> 'DC' AND county LIKE '%district of columbia%'");
};

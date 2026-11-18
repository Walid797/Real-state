<?php
declare(strict_types=1);

/*
 * Miami-Dade writes sale dates as "YYYYMMDD" text, which was read as a 1970 timestamp: the
 * county records fetched so far carry wrong sale dates and no ZIP sales came in. Those records,
 * their cached answers, the 1970 sale events and the ZIP fetch marks are cleared, so the
 * scheduler fetches them again with the dates read correctly.
 */

use App\Db;
use App\Migrator;

return static function (Migrator $m): void {
    Db::run("DELETE FROM api_cache WHERE provider = 'miamidade'");
    Db::run("DELETE FROM price_events WHERE event = 'sold' AND event_date < '1971-01-01'");
    Db::run("UPDATE properties SET last_sale_price = NULL, last_sale_date = NULL WHERE last_sale_date < '1971-01-01'");
    Db::run("UPDATE properties SET details = JSON_REMOVE(details, '$.county_record', '$.county_tried_at')
             WHERE JSON_UNQUOTE(JSON_EXTRACT(details, '$.county_record.source')) = 'miamidade'");
    Db::run("DELETE FROM settings WHERE k LIKE 'county\\_sales\\_330%' OR k LIKE 'county\\_sales\\_331%' OR k LIKE 'county\\_sales\\_332%'");
};

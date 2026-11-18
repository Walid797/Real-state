<?php
declare(strict_types=1);

/*
 * Two free sources: listing e-mails read from the site's own mailbox (one row per message
 * read, for the Settings page). Fairfax County records need no table: they are kept on each
 * home like other provider data.
 */

use App\Migrator;

return static function (Migrator $m): void {
    if (!$m->hasTable('mail_log')) {
        $m->exec("CREATE TABLE mail_log (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uid        INT UNSIGNED NOT NULL,
            sender     VARCHAR(120) NOT NULL,
            subject    VARCHAR(250) NOT NULL DEFAULT '',
            found      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            added      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            note       VARCHAR(250) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};

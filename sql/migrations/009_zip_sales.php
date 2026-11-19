<?php
declare(strict_types=1);

return static function (App\Migrator $m): void {
    $m->exec("CREATE TABLE IF NOT EXISTS zip_sales (
        zip CHAR(5) NOT NULL PRIMARY KEY,
        state ENUM('pending', 'ready', 'error') NOT NULL,
        requested_at DATETIME NOT NULL,
        next_attempt_at DATETIME NOT NULL,
        fetched_at DATETIME NULL,
        expires_at DATETIME NOT NULL,
        payload LONGTEXT NULL,
        KEY ix_zip_sales_queue (state, next_attempt_at, requested_at),
        KEY ix_zip_sales_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};

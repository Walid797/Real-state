<?php
declare(strict_types=1);

return static function (App\Migrator $m): void {
    $m->exec("CREATE TABLE IF NOT EXISTS property_analyses (
        property_id INT UNSIGNED NOT NULL PRIMARY KEY,
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        evidence MEDIUMTEXT NULL,
        narrative MEDIUMTEXT NULL,
        fingerprint CHAR(64) NULL,
        model VARCHAR(120) NULL,
        attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        error_code VARCHAR(40) NULL,
        requested_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        next_attempt_at DATETIME NOT NULL,
        generated_at DATETIME NULL,
        KEY ix_analysis_due (status, next_attempt_at),
        CONSTRAINT fk_analysis_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $m->exec("CREATE TABLE IF NOT EXISTS analysis_searches (
        signature CHAR(64) NOT NULL PRIMARY KEY,
        filters TEXT NOT NULL,
        cursor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        max_id BIGINT UNSIGNED NOT NULL,
        completed TINYINT NOT NULL DEFAULT 0,
        requested_at DATETIME NOT NULL,
        KEY ix_analysis_search_due (completed, requested_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};

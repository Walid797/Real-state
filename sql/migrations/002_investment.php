<?php
declare(strict_types=1);

/*
 * Investment analysis: listing flags, data status, building condition,
 * deal calculator inputs, opportunity / risk scores.
 */

use App\Migrator;

return static function (Migrator $m): void {
    // ── facts that matter for a flip ───────────────────────────────────
    $m->addColumn('properties', 'garage_spaces', 'TINYINT UNSIGNED NULL AFTER hoa_fee');
    $m->addColumn('properties', 'has_basement', 'TINYINT(1) NULL AFTER garage_spaces');
    // comma list: foreclosure,short_sale,auction,fixer,new_construction,as_is,estate
    $m->addColumn('properties', 'flags', "VARCHAR(160) NOT NULL DEFAULT '' AFTER listing_type");
    $m->addColumn('properties', 'data_status', "ENUM('full','limited','link_only','link_dead','removed') NOT NULL DEFAULT 'limited' AFTER source_url");
    $m->addColumn('properties', 'source_site', 'VARCHAR(40) NULL AFTER data_status');

    // ── computed analysis, stored so lists can sort by it ──────────────
    $m->addColumn('properties', 'score', 'TINYINT UNSIGNED NULL');
    $m->addColumn('properties', 'risk', 'TINYINT UNSIGNED NULL');
    $m->addColumn('properties', 'confidence', 'TINYINT UNSIGNED NULL');
    $m->addColumn('properties', 'est_arv', 'INT UNSIGNED NULL');
    $m->addColumn('properties', 'est_reno', 'INT UNSIGNED NULL');
    $m->addColumn('properties', 'est_profit', 'INT NULL');
    $m->addColumn('properties', 'est_roi', 'DECIMAL(7,1) NULL');
    $m->addColumn('properties', 'scored_at', 'DATETIME NULL');
    $m->addIndex('properties', 'ix_properties_score', 'KEY ix_properties_score (score)');
    $m->addIndex('properties', 'ix_properties_profit', 'KEY ix_properties_profit (est_profit)');
    $m->addIndex('properties', 'ix_properties_county', 'KEY ix_properties_county (state, county)');

    $m->exec("CREATE TABLE IF NOT EXISTS property_condition (
      property_id    INT UNSIGNED NOT NULL,
      overall        ENUM('not_disclosed','needs_renovation','major_repair','partially_renovated','recently_renovated','move_in_ready') NOT NULL DEFAULT 'not_disclosed',
      kitchen        ENUM('unknown','needs_work','original','updated','new') NOT NULL DEFAULT 'unknown',
      bathroom       ENUM('unknown','needs_work','original','updated','new') NOT NULL DEFAULT 'unknown',
      roof           ENUM('unknown','needs_work','original','updated','new') NOT NULL DEFAULT 'unknown',
      hvac           ENUM('unknown','needs_work','original','updated','new') NOT NULL DEFAULT 'unknown',
      flooring       ENUM('unknown','needs_work','original','updated','new') NOT NULL DEFAULT 'unknown',
      electrical     ENUM('unknown','needs_work','original','updated','new') NOT NULL DEFAULT 'unknown',
      plumbing       ENUM('unknown','needs_work','original','updated','new') NOT NULL DEFAULT 'unknown',
      windows        ENUM('unknown','needs_work','original','updated','new') NOT NULL DEFAULT 'unknown',
      renovated_year SMALLINT UNSIGNED NULL,
      source         ENUM('auto','manual') NOT NULL DEFAULT 'auto',
      evidence       TEXT NULL,
      updated_by     INT UNSIGNED NULL,
      updated_at     DATETIME NOT NULL,
      PRIMARY KEY (property_id),
      KEY ix_condition_overall (overall),
      CONSTRAINT fk_condition_property FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // One shared calculator per home. NULL means "use the estimate".
    $m->exec("CREATE TABLE IF NOT EXISTS deals (
      property_id      INT UNSIGNED NOT NULL,
      purchase_price   INT UNSIGNED NULL,
      closing_pct      DECIMAL(5,2) NULL,
      reno_cost        INT UNSIGNED NULL,
      holding_months   DECIMAL(4,1) NULL,
      holding_monthly  INT UNSIGNED NULL,
      loan_ltv_pct     DECIMAL(5,2) NULL,
      loan_rate_pct    DECIMAL(5,2) NULL,
      loan_points_pct  DECIMAL(5,2) NULL,
      selling_pct      DECIMAL(5,2) NULL,
      arv              INT UNSIGNED NULL,
      contingency_pct  DECIMAL(5,2) NULL,
      actual_purchase  INT UNSIGNED NULL,
      actual_costs     INT UNSIGNED NULL,
      actual_sale      INT UNSIGNED NULL,
      updated_by       INT UNSIGNED NULL,
      updated_at       DATETIME NOT NULL,
      PRIMARY KEY (property_id),
      CONSTRAINT fk_deal_property FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── alerts: instant or daily digest ────────────────────────────────
    $m->addColumn('alerts', 'delivery', "ENUM('instant','daily') NOT NULL DEFAULT 'instant' AFTER channels");
    $m->addColumn('alerts', 'last_digest_at', 'DATETIME NULL');
};

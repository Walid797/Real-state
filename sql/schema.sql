-- Plat — schema. Safe to run more than once (CREATE TABLE IF NOT EXISTS).
-- Works on MySQL 8 and MariaDB 10.6+. All DATETIME values are UTC.

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip           VARCHAR(45) NOT NULL,
  success      TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_attempts_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  k          VARCHAR(64) NOT NULL,
  v          MEDIUMTEXT NULL,
  is_secret  TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per physical property, whatever the source. addr_hash dedupes
-- the same house arriving from RentCast, Zillow data or manual entry.
CREATE TABLE IF NOT EXISTS properties (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  addr_hash       CHAR(40) NOT NULL,
  address         VARCHAR(190) NOT NULL,
  city            VARCHAR(100) NOT NULL DEFAULT '',
  state           CHAR(2) NOT NULL DEFAULT '',
  zip             VARCHAR(5) NOT NULL DEFAULT '',
  county          VARCHAR(100) NULL,
  lat             DECIMAL(9,6) NULL,
  lng             DECIMAL(9,6) NULL,
  property_type   VARCHAR(30) NULL,
  beds            DECIMAL(4,1) NULL,
  baths           DECIMAL(4,1) NULL,
  sqft            INT UNSIGNED NULL,
  lot_sqft        INT UNSIGNED NULL,
  year_built      SMALLINT UNSIGNED NULL,
  hoa_fee         DECIMAL(9,2) NULL,
  status          ENUM('for_sale','pending','sold','off_market') NOT NULL DEFAULT 'off_market',
  list_price      INT UNSIGNED NULL,
  listed_date     DATE NULL,
  days_on_market  INT UNSIGNED NULL,
  listing_type    VARCHAR(30) NULL,
  last_sale_price INT UNSIGNED NULL,
  last_sale_date  DATE NULL,
  est_value       INT UNSIGNED NULL,
  est_value_low   INT UNSIGNED NULL,
  est_value_high  INT UNSIGNED NULL,
  est_rent        INT UNSIGNED NULL,
  est_source      VARCHAR(20) NULL,
  est_at          DATETIME NULL,
  tax_annual      INT UNSIGNED NULL,
  assessed_value  INT UNSIGNED NULL,
  mls_name        VARCHAR(80) NULL,
  mls_number      VARCHAR(40) NULL,
  agent_name      VARCHAR(120) NULL,
  agent_phone     VARCHAR(40) NULL,
  agent_email     VARCHAR(190) NULL,
  office_name     VARCHAR(160) NULL,
  photo_url       VARCHAR(500) NULL,
  description     TEXT NULL,
  source          VARCHAR(20) NOT NULL DEFAULT 'manual',
  source_id       VARCHAR(190) NULL,
  source_url      VARCHAR(500) NULL,
  details         MEDIUMTEXT NULL,
  notes           TEXT NULL,
  first_seen_at   DATETIME NOT NULL,
  last_seen_at    DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_properties_addr (addr_hash),
  KEY ix_properties_city (state, city),
  KEY ix_properties_zip (zip),
  KEY ix_properties_geo (lat, lng),
  KEY ix_properties_status_price (status, list_price),
  KEY ix_properties_sale_date (last_sale_date),
  KEY ix_properties_first_seen (first_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Everything that ever happened to a property's price: listings, cuts, sales.
CREATE TABLE IF NOT EXISTS price_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id INT UNSIGNED NOT NULL,
  event       ENUM('listed','price_change','sold','removed','relisted') NOT NULL,
  price       INT UNSIGNED NOT NULL DEFAULT 0,
  event_date  DATE NOT NULL,
  source      VARCHAR(20) NOT NULL DEFAULT 'manual',
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_event (property_id, event, event_date, price),
  KEY ix_event_created (created_at),
  CONSTRAINT fk_event_property FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which area + filter combination was already pulled from a provider, and when.
-- This is what keeps a repeated search from spending another request.
CREATE TABLE IF NOT EXISTS snapshots (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider     VARCHAR(20) NOT NULL,
  mode         ENUM('sale','sold') NOT NULL,
  loc_key      VARCHAR(190) NOT NULL,
  filter_sig   CHAR(32) NOT NULL DEFAULT '',
  filter_json  TEXT NULL,
  result_count INT UNSIGNED NOT NULL DEFAULT 0,
  truncated    TINYINT(1) NOT NULL DEFAULT 0,
  fetched_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_snap_lookup (loc_key, mode, fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_cache (
  cache_key  CHAR(64) NOT NULL,
  provider   VARCHAR(20) NOT NULL,
  payload    LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (cache_key),
  KEY ix_cache_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_usage (
  provider VARCHAR(20) NOT NULL,
  period   CHAR(7) NOT NULL,
  calls    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (provider, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider   VARCHAR(20) NOT NULL,
  endpoint   VARCHAR(190) NOT NULL,
  status     SMALLINT NOT NULL DEFAULT 0,
  ms         INT UNSIGNED NOT NULL DEFAULT 0,
  note       VARCHAR(255) NULL,
  context    VARCHAR(10) NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saved_searches (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  params      TEXT NOT NULL,
  created_at  DATETIME NOT NULL,
  last_run_at DATETIME NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS watchlist (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id INT UNSIGNED NOT NULL,
  stage       ENUM('considering','visited','offer','passed','bought') NOT NULL DEFAULT 'considering',
  source_url  VARCHAR(500) NULL,
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_watch_property (property_id),
  CONSTRAINT fk_watch_property FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Other real estate sites to jump to. property_url / area_url are templates
-- with placeholders such as {address_slug} or {zip}; see ExternalSites.php.
CREATE TABLE IF NOT EXISTS external_sites (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(80) NOT NULL,
  category     ENUM('listings','records','maps','other') NOT NULL DEFAULT 'listings',
  property_url VARCHAR(500) NULL,
  area_url     VARCHAR(500) NULL,
  sort_order   INT NOT NULL DEFAULT 100,
  enabled      TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alerts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name            VARCHAR(120) NOT NULL,
  kind            ENUM('new_listing','price_drop','new_sale') NOT NULL DEFAULT 'new_listing',
  location        VARCHAR(190) NOT NULL DEFAULT '',
  criteria        TEXT NOT NULL,
  every_hours     SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  channels        VARCHAR(60) NOT NULL DEFAULT 'email',
  enabled         TINYINT(1) NOT NULL DEFAULT 1,
  use_quota       TINYINT(1) NOT NULL DEFAULT 1,
  last_checked_at DATETIME NULL,
  last_hit_at     DATETIME NULL,
  last_note       VARCHAR(255) NULL,
  created_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_alerts_due (enabled, last_checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per (alert, property, price): the same house is never announced twice
-- at the same price, but a later price cut is.
CREATE TABLE IF NOT EXISTS alert_hits (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  alert_id    INT UNSIGNED NOT NULL,
  property_id INT UNSIGNED NOT NULL,
  price       INT UNSIGNED NOT NULL DEFAULT 0,
  summary     VARCHAR(255) NOT NULL DEFAULT '',
  notified    TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hit (alert_id, property_id, price),
  KEY ix_hit_created (created_at),
  CONSTRAINT fk_hit_alert FOREIGN KEY (alert_id) REFERENCES alerts (id) ON DELETE CASCADE,
  CONSTRAINT fk_hit_property FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notify_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  channel    VARCHAR(20) NOT NULL,
  ok         TINYINT(1) NOT NULL DEFAULT 0,
  subject    VARCHAR(190) NOT NULL DEFAULT '',
  detail     VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_notify_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS market_regions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind           ENUM('zip','city') NOT NULL,
  name           VARCHAR(120) NOT NULL,
  state          CHAR(2) NOT NULL DEFAULT '',
  added_at       DATETIME NOT NULL,
  last_import_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_region (kind, name, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS market_series (
  region_id INT UNSIGNED NOT NULL,
  metric    VARCHAR(24) NOT NULL,
  period    DATE NOT NULL,
  value     DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (region_id, metric, period),
  CONSTRAINT fk_series_region FOREIGN KEY (region_id) REFERENCES market_regions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

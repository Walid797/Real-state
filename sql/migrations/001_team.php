<?php
declare(strict_types=1);

/*
 * Team workspace: roles, invitations, password reset, Google sign-in,
 * shared pipeline statuses, notes with authors, activity history.
 */

use App\Migrator;

return static function (Migrator $m): void {
    // ── users ───────────────────────────────────────────────────────────
    $m->addColumn('users', 'name', "VARCHAR(80) NOT NULL DEFAULT '' AFTER email");
    $m->addColumn('users', 'role', "ENUM('admin','partner','viewer') NOT NULL DEFAULT 'partner' AFTER name");
    $m->addColumn('users', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER role');
    $m->addColumn('users', 'google_sub', 'VARCHAR(64) NULL AFTER password_hash');
    $m->addColumn('users', 'session_epoch', 'INT UNSIGNED NOT NULL DEFAULT 1');
    $m->addColumn('users', 'telegram_chat', 'VARCHAR(64) NULL');
    $m->addColumn('users', 'invited_by', 'INT UNSIGNED NULL');
    $m->addIndex('users', 'uq_users_google', 'UNIQUE KEY uq_users_google (google_sub)');
    // Whoever set the site up is its administrator.
    $m->sql("UPDATE users SET role = 'admin' WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM users) t)");
    $m->sql("UPDATE users SET name = SUBSTRING_INDEX(email, '@', 1) WHERE name = ''");

    $m->exec("CREATE TABLE IF NOT EXISTS user_tokens (
      id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id     INT UNSIGNED NOT NULL,
      kind        ENUM('invite','reset') NOT NULL,
      token_hash  CHAR(64) NOT NULL,
      expires_at  DATETIME NOT NULL,
      used_at     DATETIME NULL,
      created_at  DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uq_token (token_hash),
      KEY ix_token_user (user_id),
      CONSTRAINT fk_token_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $m->exec("CREATE TABLE IF NOT EXISTS activity_log (
      id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id     INT UNSIGNED NULL,
      property_id INT UNSIGNED NULL,
      action      VARCHAR(40) NOT NULL,
      detail      VARCHAR(500) NOT NULL DEFAULT '',
      created_at  DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY ix_activity_property (property_id, created_at),
      KEY ix_activity_created (created_at),
      CONSTRAINT fk_activity_property FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE,
      CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── notes: many per home, each with its author ─────────────────────
    $m->exec("CREATE TABLE IF NOT EXISTS notes (
      id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
      property_id INT UNSIGNED NOT NULL,
      user_id     INT UNSIGNED NULL,
      body        TEXT NOT NULL,
      created_at  DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY ix_notes_property (property_id, created_at),
      CONSTRAINT fk_note_property FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE,
      CONSTRAINT fk_note_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ($m->hasColumn('properties', 'notes')) {
        $m->sql(
            "INSERT INTO notes (property_id, user_id, body, created_at)
             SELECT p.id, (SELECT MIN(id) FROM users), p.notes, p.updated_at FROM properties p
             WHERE p.notes IS NOT NULL AND p.notes <> ''
               AND NOT EXISTS (SELECT 1 FROM notes n WHERE n.property_id = p.id)"
        );
    }

    // ── shared pipeline (the watchlist table keeps its name) ───────────
    $type = (string) App\Db::val(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'watchlist' AND COLUMN_NAME = 'stage'"
    );
    if (str_contains($type, 'considering')) {
        $m->exec("ALTER TABLE watchlist MODIFY stage VARCHAR(20) NOT NULL DEFAULT 'new'");
        $m->sql("UPDATE watchlist SET stage = CASE stage
                   WHEN 'considering' THEN 'under_review' WHEN 'visited' THEN 'inspection'
                   WHEN 'offer' THEN 'offer' WHEN 'passed' THEN 'rejected' WHEN 'bought' THEN 'purchased' ELSE 'new' END");
        $m->exec("ALTER TABLE watchlist MODIFY stage ENUM('new','under_review','contact_agent','inspection','offer','rejected','purchased','sold') NOT NULL DEFAULT 'new'");
    }
    $m->addColumn('watchlist', 'owner_id', 'INT UNSIGNED NULL AFTER stage');
    $m->addColumn('watchlist', 'added_by', 'INT UNSIGNED NULL AFTER owner_id');
    $m->addColumn('watchlist', 'updated_by', 'INT UNSIGNED NULL AFTER added_by');
    $m->sql('UPDATE watchlist SET added_by = (SELECT MIN(id) FROM users) WHERE added_by IS NULL');

    // ── alerts and saved searches belong to a person ───────────────────
    $m->addColumn('alerts', 'user_id', 'INT UNSIGNED NULL AFTER id');
    $m->addColumn('saved_searches', 'user_id', 'INT UNSIGNED NULL AFTER id');
    $m->sql('UPDATE alerts SET user_id = (SELECT MIN(id) FROM users) WHERE user_id IS NULL');
    $m->sql('UPDATE saved_searches SET user_id = (SELECT MIN(id) FROM users) WHERE user_id IS NULL');
};

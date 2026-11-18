<?php
declare(strict_types=1);

namespace App;

/**
 * Applies numbered migrations from sql/migrations/NNN_name.php exactly once, in order.
 *
 * Each file returns a callable(Migrator $m). Helpers below make every step
 * safe to repeat (works on MySQL 8 and MariaDB, which differ on IF NOT EXISTS
 * for columns), so a migration interrupted halfway can simply run again.
 */
final class Migrator
{
    private const DIR = APP_ROOT . '/sql/migrations';

    public static function latest(): int
    {
        $n = 0;
        foreach (glob(self::DIR . '/*.php') ?: [] as $f) {
            $n = max($n, (int) basename($f));
        }
        return $n;
    }

    public static function current(): int
    {
        try {
            return (int) Db::val("SELECT v FROM settings WHERE k = 'schema_version'");
        } catch (\PDOException) {
            return 0;
        }
    }

    public static function pending(): bool
    {
        return self::current() < self::latest();
    }

    /** @return string[] names of the migrations applied now */
    public static function run(): array
    {
        $done = [];
        $lock = (int) Db::val("SELECT GET_LOCK('plat_migrate', 20)");
        if ($lock !== 1) {
            throw new \RuntimeException('Another request is updating the database. Reload in a minute.');
        }
        try {
            $files = glob(self::DIR . '/*.php') ?: [];
            sort($files, SORT_NATURAL);
            $at = self::current();
            foreach ($files as $file) {
                $n = (int) basename($file);
                if ($n <= $at) {
                    continue;
                }
                $fn = require $file;
                $fn(new self());
                Db::run(
                    "INSERT INTO settings (k, v, is_secret, updated_at) VALUES ('schema_version', ?, 0, ?)
                     ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)",
                    [(string) $n, now()]
                );
                $done[] = basename($file, '.php');
            }
        } finally {
            Db::val("SELECT RELEASE_LOCK('plat_migrate')");
        }
        return $done;
    }

    public function sql(string $sql, array $params = []): void
    {
        Db::run($sql, $params);
    }

    public function hasTable(string $table): bool
    {
        return (int) Db::val(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    }

    public function hasColumn(string $table, string $column): bool
    {
        return (int) Db::val(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    }

    public function hasIndex(string $table, string $index): bool
    {
        return (int) Db::val(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        ) > 0;
    }

    public function addColumn(string $table, string $column, string $definition): void
    {
        if (!$this->hasColumn($table, $column)) {
            Db::pdo()->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }

    public function addIndex(string $table, string $index, string $definition): void
    {
        if (!$this->hasIndex($table, $index)) {
            Db::pdo()->exec("ALTER TABLE `$table` ADD $definition");
        }
    }

    public function exec(string $ddl): void
    {
        Db::pdo()->exec($ddl);
    }
}

<?php
declare(strict_types=1);

namespace App;

/**
 * Daily database backup written by PHP itself (shared hosting may not allow mysqldump).
 * Gzipped SQL in storage/backups (closed to the web), newest 14 kept.
 * The response cache and login attempts are skipped: they rebuild themselves.
 */
final class Backup
{
    private const DIR = APP_ROOT . '/storage/backups';
    private const KEEP = 14;
    private const SKIP = ['api_cache', 'login_attempts', 'zip_sales'];

    public static function run(): string
    {
        if (!is_dir(self::DIR)) {
            mkdir(self::DIR, 0700, true);
        }
        $file = self::DIR . '/plat-' . gmdate('Y-m-d-His') . '.sql.gz';
        $gz = gzopen($file, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('Could not write the backup file.');
        }
        $pdo = Db::pdo();
        gzwrite($gz, "-- Plat backup " . gmdate('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(\PDO::FETCH_NUM);
        foreach ($tables as [$table]) {
            $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(\PDO::FETCH_NUM)[1];
            gzwrite($gz, "DROP TABLE IF EXISTS `$table`;\n$create;\n\n");
            if (in_array($table, self::SKIP, true)) {
                continue;
            }
            $st = $pdo->query('SELECT * FROM `' . $table . '`', \PDO::FETCH_ASSOC);
            $batch = [];
            foreach ($st as $row) {
                $batch[] = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
                if (count($batch) >= 200) {
                    gzwrite($gz, 'INSERT INTO `' . $table . '` VALUES ' . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                gzwrite($gz, 'INSERT INTO `' . $table . '` VALUES ' . implode(",\n", $batch) . ";\n");
            }
            gzwrite($gz, "\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n");
        gzclose($gz);
        chmod($file, 0600);

        $all = self::list();
        foreach (array_slice($all, self::KEEP) as $old) {
            @unlink(self::DIR . '/' . $old['name']);
        }
        return basename($file);
    }

    /** @return array<int, array{name:string, size:int, time:int}> newest first */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::DIR . '/plat-*.sql.gz') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'time' => (int) filemtime($f)];
        }
        usort($out, static fn ($a, $b) => $b['time'] <=> $a['time']);
        return $out;
    }

    public static function path(string $name): ?string
    {
        if (!preg_match('/^plat-[0-9-]+\.sql\.gz$/', $name)) {
            return null;
        }
        $p = self::DIR . '/' . $name;
        return is_file($p) ? $p : null;
    }
}

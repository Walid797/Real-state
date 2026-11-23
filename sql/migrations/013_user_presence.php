<?php
declare(strict_types=1);

return static function (App\Migrator $m): void {
    $m->addColumn('users','last_active_at','DATETIME NULL');
    $m->exec("CREATE TABLE IF NOT EXISTS user_presence (
        session_key CHAR(64) NOT NULL PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        session_epoch INT NOT NULL,
        last_active_at DATETIME NOT NULL,
        KEY ix_presence_user (user_id, last_active_at),
        CONSTRAINT fk_presence_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};

<?php
declare(strict_types=1);

namespace App\Services;

use App\Db;

final class Presence
{
    public const ONLINE_SECONDS = 90;

    public static function touch(int $userId, int $epoch): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        $key = hash('sha256', session_id());
        Db::run('INSERT INTO user_presence(session_key,user_id,session_epoch,last_active_at) VALUES(?,?,?,?)
            ON DUPLICATE KEY UPDATE last_active_at=VALUES(last_active_at), session_epoch=VALUES(session_epoch)',[$key,$userId,$epoch,now()]);
        Db::run('UPDATE users SET last_active_at=? WHERE id=? AND (last_active_at IS NULL OR last_active_at < ?)',
            [now(),$userId,gmdate('Y-m-d H:i:s',time()-20)]);
    }

    public static function leave(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            Db::run('DELETE FROM user_presence WHERE session_key=?',[hash('sha256',session_id())]);
        }
    }

    public static function statuses(): array
    {
        $rows = Db::all('SELECT u.id,u.last_active_at, EXISTS(SELECT 1 FROM user_presence p WHERE p.user_id=u.id
            AND p.session_epoch=u.session_epoch AND p.last_active_at>=?) AND u.is_active=1 AS online FROM users u',
            [gmdate('Y-m-d H:i:s',time()-self::ONLINE_SECONDS)]);
        return array_column(array_map(static function(array $row): array {
            $row['online'] = (bool)$row['online'];
            $row['label'] = $row['online'] ? t('Online now') : ($row['last_active_at'] ? ago($row['last_active_at']) : t('No activity recorded yet'));
            $row['title'] = $row['last_active_at'] ? $row['last_active_at'].' UTC' : '';
            return $row;
        },$rows),null,'id');
    }
}

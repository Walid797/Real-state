<?php
declare(strict_types=1);

/*
 * WhatsApp per person. CallMeBot can only message the number that asked it for the key,
 * so a single site-wide number sent everyone's alerts to one phone. Each person now keeps
 * their own number and key (the key encrypted like other secrets). A site-wide number set
 * before this moves to the first admin, who set it up.
 */

use App\Db;
use App\Migrator;
use App\Settings;

return static function (Migrator $m): void {
    $m->addColumn('users', 'whatsapp_phone', 'VARCHAR(24) NULL AFTER telegram_chat');
    $m->addColumn('users', 'callmebot_key', 'VARCHAR(255) NULL AFTER whatsapp_phone');

    $phone = (string) Settings::get('callmebot_phone', '');
    $key = (string) Settings::get('callmebot_key', '');
    if ($phone !== '' && $key !== '') {
        $admin = Db::val("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 AND (whatsapp_phone IS NULL OR whatsapp_phone = '') ORDER BY id LIMIT 1");
        if ($admin) {
            Db::run('UPDATE users SET whatsapp_phone = ?, callmebot_key = ? WHERE id = ?', [$phone, Settings::seal($key), $admin]);
        }
    }
    Settings::set('callmebot_phone', null);
    Settings::set('callmebot_key', null);
};

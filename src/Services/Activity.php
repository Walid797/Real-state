<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth;
use App\Db;

/** The team's shared history: who changed what, and when. */
final class Activity
{
    public const LABELS = [
        'stage' => 'Status',
        'note' => 'Note',
        'deal' => 'Deal numbers',
        'condition' => 'Condition',
        'edit' => 'Facts',
        'claim' => 'Assignment',
        'added' => 'Added',
        'pull' => 'Data pulled',
        'user' => 'Team',
    ];

    public static function log(string $action, ?int $propertyId, string $detail = '', ?int $userId = null): void
    {
        Db::insert('activity_log', [
            'user_id' => $userId ?? (Auth::userId() ?: null),
            'property_id' => $propertyId,
            'action' => mb_substr($action, 0, 40),
            'detail' => mb_substr($detail, 0, 500),
            'created_at' => now(),
        ]);
    }

    public static function forProperty(int $propertyId, int $limit = 40): array
    {
        return Db::all(
            'SELECT a.*, u.name AS user_name, u.email AS user_email FROM activity_log a
             LEFT JOIN users u ON u.id = a.user_id WHERE a.property_id = ? ORDER BY a.id DESC LIMIT ' . max(1, $limit),
            [$propertyId]
        );
    }

    public static function recent(int $limit = 12): array
    {
        return Db::all(
            'SELECT a.*, u.name AS user_name, u.email AS user_email, p.address, p.city, p.state
             FROM activity_log a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN properties p ON p.id = a.property_id
             ORDER BY a.id DESC LIMIT ' . max(1, $limit)
        );
    }

    public static function who(array $row): string
    {
        $n = (string) ($row['user_name'] ?? '');
        return $n !== '' ? $n : ((string) ($row['user_email'] ?? '') ?: 'System');
    }
}

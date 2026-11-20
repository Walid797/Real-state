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
        'delete' => 'Deleted',
        'pipeline' => 'Pipeline',
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
        return $n !== '' ? $n : ((string) ($row['user_email'] ?? '') ?: t('System'));
    }

    /** One history entry in the site's language: "Status: New → Under review". */
    public static function line(array $row): string
    {
        $label = self::LABELS[$row['action']] ?? ucfirst((string) $row['action']);
        return t($label) . ': ' . self::detail((string) $row['action'], (string) $row['detail']);
    }

    /**
     * The stored detail (always English, so the log reads the same for everyone) in the site's
     * language. Notes and names stay as written; known phrases are translated piece by piece.
     */
    public static function detail(string $action, string $detail): string
    {
        if ($action === 'note' || \App\Locale::language() === 'en') {
            return $detail;
        }
        $patterns = [
            '/^Deleted ([\d,]+) homes?$/' => static fn ($m) => t('Deleted %s', plural((int) str_replace(',', '', $m[1]), 'home')),
            '/^Removed ([\d,]+) homes? from the pipeline$/' => static fn ($m) => t('Removed %s from the pipeline', plural((int) str_replace(',', '', $m[1]), 'home')),
            '/^Changed (.+)$/' => static fn ($m) => t('Changed %s', self::pieces($m[1])),
            '/^Handled by (.+)$/' => static fn ($m) => t('Handled by %s', $m[1]),
            '/^Invited (\S+) as (\w+)$/' => static fn ($m) => t('Invited %1$s as %2$s', $m[1], t($m[2])),
            '/^(.+) is now (\w+)$/' => static fn ($m) => t('%1$s is now %2$s', $m[1], t($m[2])),
            '/^Disabled (.+)$/' => static fn ($m) => t('Disabled %s', $m[1]),
            '/^Re-enabled (.+)$/' => static fn ($m) => t('Re-enabled %s', $m[1]),
            '/^From an? (.+) e-mail$/' => static fn ($m) => t('From a %s e-mail', $m[1]),
            '/^From an? (.+) link$/' => static fn ($m) => t('From a %s link', $m[1]),
            '/^Downloaded backup (.+)$/' => static fn ($m) => t('Downloaded backup %s', $m[1]),
            '/^Listing data marked “(.+)”$/' => static fn ($m) => t('Listing data marked “%s”', t(ucfirst($m[1]))),
            '/^Removed (.+)$/' => static fn ($m) => t('Removed %s', $m[1]),
            '/^Deleted (.+)$/' => static fn ($m) => t('Deleted %s', $m[1]),
        ];
        if (t($detail) !== $detail) {
            return t($detail);
        }
        foreach ($patterns as $re => $fn) {
            if (preg_match($re, $detail, $m)) {
                return $fn($m);
            }
        }
        return self::pieces($detail);
    }

    /** "overall → Needs renovation, Roof → Needs work": each piece translated when there is a translation. */
    private static function pieces(string $s): string
    {
        $parts = preg_split('/( → |, )/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$s];
        return implode('', array_map(static function ($x) {
            if ($x === ' → ' || $x === ', ') {
                return $x;
            }
            $tr = t($x);
            return $tr !== $x ? $tr : t(ucfirst($x));
        }, $parts));
    }
}

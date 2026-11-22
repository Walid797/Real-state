<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth;
use App\Db;

/**
 * The team's shared list of homes being worked, with one status each and an owner,
 * so two people never chase the same house without knowing it.
 */
final class Pipeline
{
    public const STAGES = [
        'new' => 'New',
        'under_review' => 'Under review',
        'contact_agent' => 'Contact agent',
        'inspection' => 'Inspection needed',
        'offer' => 'Offer submitted',
        'rejected' => 'Rejected',
        'purchased' => 'Purchased',
        'sold' => 'Sold',
    ];

    /** Stages that mean someone is actively pursuing the home. */
    public const ACTIVE = ['under_review', 'contact_agent', 'inspection', 'offer'];

    public static function add(int $propertyId, ?string $sourceUrl = null): void
    {
        $exists = Db::val('SELECT id FROM watchlist WHERE property_id = ?', [$propertyId]);
        if ($exists) {
            if ($sourceUrl) {
                Db::run('UPDATE watchlist SET source_url = COALESCE(source_url, ?) WHERE property_id = ?', [$sourceUrl, $propertyId]);
            }
            return;
        }
        Db::insert('watchlist', [
            'property_id' => $propertyId, 'stage' => 'new', 'added_by' => Auth::userId() ?: null, 'updated_by' => Auth::userId() ?: null,
            'source_url' => $sourceUrl, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Activity::log('stage', $propertyId, 'Added to favorites');
    }

    public static function remove(int $propertyId): void
    {
        if (Db::run('DELETE FROM watchlist WHERE property_id = ?', [$propertyId])->rowCount()) {
            Activity::log('stage', $propertyId, 'Removed from favorites');
        }
    }

    public static function setStage(int $propertyId, string $stage): void
    {
        if (!isset(self::STAGES[$stage])) {
            return;
        }
        self::add($propertyId);
        $before = (string) Db::val('SELECT stage FROM watchlist WHERE property_id = ?', [$propertyId]);
        if ($before === $stage) {
            return;
        }
        // Moving a home into an active stage makes you its owner unless someone already is.
        Db::run(
            'UPDATE watchlist SET stage = ?, updated_by = ?, updated_at = ?, owner_id = IF(owner_id IS NULL AND ? = 1, ?, owner_id) WHERE property_id = ?',
            [$stage, Auth::userId() ?: null, now(), in_array($stage, self::ACTIVE, true) ? 1 : 0, Auth::userId() ?: null, $propertyId]
        );
        Activity::log('stage', $propertyId, (self::STAGES[$before] ?? 'New') . ' → ' . self::STAGES[$stage]);
    }

    public static function setOwner(int $propertyId, ?int $userId): void
    {
        self::add($propertyId);
        Db::run('UPDATE watchlist SET owner_id = ?, updated_by = ?, updated_at = ? WHERE property_id = ?', [$userId, Auth::userId() ?: null, now(), $propertyId]);
        $name = $userId ? (string) Db::val('SELECT COALESCE(NULLIF(name, \'\'), email) FROM users WHERE id = ?', [$userId]) : null;
        Activity::log('claim', $propertyId, $name ? 'Handled by ' . $name : 'No one assigned');
    }
}

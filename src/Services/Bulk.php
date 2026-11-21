<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth;
use App\Db;

/**
 * Deleting several things at once — the ticked ones, or all of a list — with each list's own
 * rules: team members (admins; never yourself or the last admin), homes (and everything kept on
 * them), pipeline entries (the home stays on file), alerts and search profiles (your own; an
 * admin's reach covers everyone's).
 */
final class Bulk
{
    public const TYPES = ['users', 'homes', 'pipeline', 'alerts', 'profiles'];

    /** Who may delete each kind. */
    private const PERM = ['users' => 'admin', 'homes' => 'edit', 'pipeline' => 'edit', 'alerts' => 'view', 'profiles' => 'view'];

    /** Never more than this many in one go. */
    private const MAX = 5000;

    public static function allowed(string $type): bool
    {
        return isset(self::PERM[$type]) && Auth::can(self::PERM[$type]);
    }

    /**
     * @param int[] $ids the ticked items (ignored when $all)
     * @param int[] $allIds what "all" means for this list, worked out by the caller (e.g. every home in the current results)
     * @return array{count:int, skipped:int}
     */
    public static function delete(string $type, array $ids, bool $all, array $allIds = []): array
    {
        if (!self::allowed($type)) {
            throw new \RuntimeException('Not allowed.');
        }
        $me = (int) Auth::userId();
        $admin = Auth::can('admin');
        $ids = array_values(array_unique(array_filter(array_map('intval', $all ? $allIds : $ids), static fn ($i) => $i > 0)));
        $ids = array_slice($ids, 0, self::MAX);
        if ($type !== 'users' && $all && !$allIds) {
            $ids = self::everything($type, $me, $admin);
        }
        if (!$ids) {
            return ['count' => 0, 'skipped' => 0];
        }
        $in = implode(',', $ids);
        $skipped = 0;
        switch ($type) {
            case 'users':
                // Never yourself, and never leave the team without an active admin.
                $keep = array_map('intval', array_column(Db::all("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 AND id NOT IN ($in)"), 'id'));
                $before = count($ids);
                $ids = array_values(array_filter($ids, static fn ($i) => $i !== $me));
                if (!$keep && $ids) {
                    // every remaining admin is in the list: keep the first so one is left
                    $firstAdmin = (int) Db::val("SELECT MIN(id) FROM users WHERE role = 'admin' AND is_active = 1 AND id IN ($in)");
                    $ids = array_values(array_filter($ids, static fn ($i) => $i !== $firstAdmin));
                }
                $skipped = $before - count($ids);
                if (!$ids) {
                    return ['count' => 0, 'skipped' => $skipped];
                }
                $in = implode(',', $ids);
                $names = array_column(Db::all("SELECT email FROM users WHERE id IN ($in)"), 'email');
                Db::run("DELETE FROM alert_hits WHERE alert_id IN (SELECT id FROM alerts WHERE user_id IN ($in))");
                Db::run("DELETE FROM alerts WHERE user_id IN ($in)");
                Db::run("DELETE FROM saved_searches WHERE user_id IN ($in)");
                foreach (['owner_id', 'added_by', 'updated_by'] as $col) {
                    Db::run("UPDATE watchlist SET $col = NULL WHERE $col IN ($in)");
                }
                Db::run("UPDATE deals SET updated_by = NULL WHERE updated_by IN ($in)");
                Db::run("UPDATE property_condition SET updated_by = NULL WHERE updated_by IN ($in)");
                $n = Db::run("DELETE FROM users WHERE id IN ($in)")->rowCount();
                Activity::log('user', null, 'Removed ' . implode(', ', array_slice($names, 0, 5)) . (count($names) > 5 ? ' and ' . (count($names) - 5) . ' more' : ''));
                return ['count' => $n, 'skipped' => $skipped];

            case 'homes':
                $n = 0;
                foreach (array_chunk($ids, 500) as $chunk) {
                    $n += Db::run('DELETE FROM properties WHERE id IN (' . implode(',', $chunk) . ')')->rowCount();
                }
                Activity::log('delete', null, 'Deleted ' . plural($n, 'home'));
                return ['count' => $n, 'skipped' => 0];

            case 'pipeline':
                $n = Db::run("DELETE FROM watchlist WHERE property_id IN ($in)")->rowCount();
                Activity::log('pipeline', null, 'Removed ' . plural($n, 'home') . ' from the pipeline');
                return ['count' => $n, 'skipped' => 0];

            case 'alerts':
                $own = $admin ? '' : ' AND user_id = ' . $me;
                Db::run("DELETE FROM alert_hits WHERE alert_id IN (SELECT id FROM alerts WHERE id IN ($in)$own)");
                return ['count' => Db::run("DELETE FROM alerts WHERE id IN ($in)$own")->rowCount(), 'skipped' => 0];

            case 'profiles':
                return ['count' => Db::run("DELETE FROM saved_searches WHERE id IN ($in) AND user_id = ?", [$me])->rowCount(), 'skipped' => 0];
        }
        return ['count' => 0, 'skipped' => 0];
    }

    /** "All" for the lists that have no filter: the whole pipeline, your alerts (everyone's for an admin), your profiles. @return int[] */
    private static function everything(string $type, int $me, bool $admin): array
    {
        return array_map('intval', array_column(match ($type) {
            'pipeline' => Db::all('SELECT property_id id FROM watchlist LIMIT ' . self::MAX),
            'alerts' => $admin ? Db::all('SELECT id FROM alerts') : Db::all('SELECT id FROM alerts WHERE user_id = ?', [$me]),
            'profiles' => Db::all('SELECT id FROM saved_searches WHERE user_id = ?', [$me]),
            default => [],
        }, 'id'));
    }
}

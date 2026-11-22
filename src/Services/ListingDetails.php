<?php
declare(strict_types=1);

namespace App\Services;

use App\Address;
use App\Db;
use App\PropertyRepo;
use App\Providers\OpenWebNinja;
use App\Quota;
use App\Settings;

/**
 * The full listing — taxes, HOA, features, photos, description — from Zillow data (OpenWeb Ninja),
 * fetched by the site itself the first time someone opens a home or adds it to the pipeline.
 * Each home is fetched once; a monthly cap keeps the automatic share of the free plan in check.
 */
final class ListingDetails
{
    /** A home Zillow had nothing for is not asked about again for this long. */
    private const RETRY_DAYS = 30;

    public static function autoLimit(): int
    {
        return max(0, Settings::int('openwebninja_auto', 60));
    }

    public static function autoUsed(): int
    {
        return (int) Db::val(
            "SELECT COUNT(*) FROM api_log WHERE provider = 'openwebninja' AND context = 'auto' AND created_at >= ?",
            [gmdate('Y-m-01 00:00:00')]
        );
    }

    /** Should the site fetch this home's listing on its own right now? */
    public static function wanted(array $p): bool
    {
        $d = (array) ($p['details'] ?? []);
        if ((!empty($d['zillow']) && array_key_exists('schools', $d)) || !OpenWebNinja::enabled() || self::autoLimit() === 0) {
            return false;
        }
        // Nothing on Zillow: ask again in a month. A failed request (network, limit): ask again the next day.
        $wait = !empty($d['zillow_error']) ? 86400 : self::RETRY_DAYS * 86400;
        if ((empty($d['zillow']) || !empty($d['zillow_error'])) && !empty($d['zillow_tried_at']) && strtotime($d['zillow_tried_at'] . ' UTC') > time() - $wait) {
            return false;
        }
        return self::autoUsed() < self::autoLimit() && Quota::can(OpenWebNinja::ID, 'manual');
    }

    /**
     * Fetches and stores the listing, its sold comparables, and re-scores the home.
     * @param 'manual'|'auto' $ctx manual = someone pressed the button; auto = the site decided
     * @return array{status:'fetched'|'none'|'error', message:string}
     */
    public static function fetch(array $p, string $ctx = 'manual'): array
    {
        $id = (int) $p['id'];
        // Remember the attempt first, so a failure is not retried on every page view.
        Db::run('UPDATE properties SET details = JSON_SET(COALESCE(details, JSON_OBJECT()), \'$.zillow_tried_at\', ?, \'$.zillow_error\', false) WHERE id = ?', [now(), $id]);
        $r = OpenWebNinja::details(Address::full($p), $ctx);
        if (!$r['ok']) {
            Db::run('UPDATE properties SET details = JSON_SET(details, \'$.zillow_error\', true) WHERE id = ?', [$id]);
            return ['status' => 'error', 'message' => (string) $r['error']];
        }
        if (!$r['item']) {
            return ['status' => 'none', 'message' => 'Zillow has no page for this address yet.'];
        }
        if (!Address::sameProperty($p, $r['item'])) {
            return ['status' => 'none', 'message' => 'The source returned a different address or apartment. Its details were not added to this home.'];
        }
        PropertyRepo::upsert(['address' => $p['address'], 'city' => $p['city'], 'state' => $p['state'], 'zip' => $p['zip']] + $r['item'], 'openwebninja');
        $n = 0;
        foreach ($r['comps'] as $c) {
            try {
                PropertyRepo::upsert($c, 'openwebninja');
                $n++;
            } catch (\InvalidArgumentException) {
            }
        }
        Activity::log('pull', $id, $ctx === 'auto' ? 'Listing details fetched automatically' : 'Zillow details');
        Score::refresh($id);
        return ['status' => 'fetched', 'message' => 'Listing details added' . ($n ? ', plus ' . plural($n, 'nearby sale') : '') . '.'];
    }
}

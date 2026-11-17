<?php
declare(strict_types=1);

namespace App\Services;

use App\Address;
use App\Db;
use App\Notify\Notifier;
use App\PropertyRepo;

/**
 * Alerts: a saved set of criteria that is re-checked on a schedule.
 *
 *   new_listing — a home matching the criteria appears (or one already matching gets cheaper)
 *   price_drop  — a home on the watchlist is re-priced lower
 *   new_sale    — a sale matching the criteria is recorded in the area
 *
 * The first check of an alert only records what already matches (the baseline);
 * messages go out for what turns up after that. alert_hits makes sure the same
 * house at the same price is never announced twice.
 */
final class Alerts
{
    public const KINDS = [
        'new_listing' => 'New listing',
        'price_drop' => 'Price drop on my watchlist',
        'new_sale' => 'New sale nearby',
    ];

    public const FREQUENCIES = [6 => 'Every 6 hours', 12 => 'Twice a day', 24 => 'Once a day', 72 => 'Every 3 days', 168 => 'Once a week'];

    private const MAX_PER_MESSAGE = 8;

    /** Rough number of provider requests one alert uses in a month. */
    public static function monthlyCost(array $alert): int
    {
        if (empty($alert['use_quota']) || empty($alert['enabled']) || trim((string) $alert['location']) === '') {
            return 0;
        }
        return (int) ceil(30 * 24 / max(1, (int) $alert['every_hours']));
    }

    /** @return array{checked:int, hits:int, notes:string[]} */
    public static function runDue(string $ctx = 'cron', int $budgetSeconds = 100): array
    {
        $started = time();
        $out = ['checked' => 0, 'hits' => 0, 'notes' => []];
        $due = Db::all(
            'SELECT * FROM alerts WHERE enabled = 1
               AND (last_checked_at IS NULL OR last_checked_at <= DATE_SUB(?, INTERVAL every_hours HOUR))
             ORDER BY last_checked_at IS NOT NULL, last_checked_at',
            [now()]
        );
        foreach ($due as $alert) {
            if (time() - $started > $budgetSeconds) {
                $out['notes'][] = 'Stopped early to stay inside the time limit; the rest run next time.';
                break;
            }
            $r = self::runOne($alert, $ctx);
            $out['checked']++;
            $out['hits'] += count($r['hits']);
            $out['notes'][] = $alert['name'] . ': ' . $r['note'];
        }
        return $out;
    }

    /**
     * @return array{hits:array, note:string, baseline:bool, sent:array}
     */
    public static function runOne(array $alert, string $ctx = 'cron', bool $notify = true): array
    {
        $crit = json_decode((string) $alert['criteria'], true) ?: [];
        $baseline = $alert['last_checked_at'] === null;
        $loc = Address::parseLocation((string) $alert['location']);
        $notice = null;

        // Refresh the data for the alert's area — at most once per alert interval.
        if ($loc && $loc['type'] !== 'address' && !empty($alert['use_quota'])) {
            $f = Search::fromRequest($crit + ['q' => $alert['location'], 'mode' => $alert['kind'] === 'new_sale' ? 'sold' : 'sale', 'sold_days' => 90]);
            $snap = Search::ensureSnapshot($loc, $f, $ctx, false, max(1, (int) $alert['every_hours'] - 1));
            $notice = $snap['notice'];
        }

        $candidates = match ($alert['kind']) {
            'price_drop' => self::watchlistCandidates($loc, $crit),
            'new_sale' => self::searchCandidates($loc, $crit, $alert, 'sold'),
            default => self::searchCandidates($loc, $crit, $alert, 'sale'),
        };

        $seen = [];
        foreach (Db::all('SELECT property_id, MIN(price) AS min_price FROM alert_hits WHERE alert_id = ? GROUP BY property_id', [$alert['id']]) as $h) {
            $seen[(int) $h['property_id']] = (int) $h['min_price'];
        }

        $hits = [];
        foreach ($candidates as $p) {
            $pid = (int) $p['id'];
            $price = (int) ($alert['kind'] === 'new_sale' ? $p['last_sale_price'] : $p['list_price']);
            if ($price <= 0) {
                continue;
            }
            $prev = $seen[$pid] ?? null;
            $why = null;
            if ($alert['kind'] === 'price_drop') {
                $minDrop = (float) ($crit['min_drop_pct'] ?? 0);
                if ($prev !== null && $price < $prev && ($prev - $price) / $prev * 100 >= $minDrop) {
                    $why = 'Price cut ' . money($prev) . ' → ' . money($price) . ' (' . pct(($price - $prev) / $prev * 100) . ')';
                }
            } elseif ($prev === null) {
                $why = $alert['kind'] === 'new_sale' ? 'Sold ' . fmt_date($p['last_sale_date']) : 'New match';
            } elseif ($alert['kind'] === 'new_listing' && $price < $prev) {
                $why = 'Price cut ' . money($prev) . ' → ' . money($price);
            }
            // Record every candidate so the next run compares against today's price.
            $ins = Db::run(
                'INSERT IGNORE INTO alert_hits (alert_id, property_id, price, summary, notified, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$alert['id'], $pid, $price, mb_substr($why ?? 'baseline', 0, 255), ($why && !$baseline) ? 1 : 0, now()]
            );
            if ($why !== null && !$baseline && $ins->rowCount() > 0) {
                $hits[] = $p + ['why' => $why, 'hit_price' => $price];
            }
        }

        $sent = [];
        if ($hits && $notify) {
            [$subject, $text, $html] = self::compose($alert, $hits, $loc);
            $sent = Notifier::send($subject, $text, array_filter(explode(',', (string) $alert['channels'])), $html);
        }

        if ($baseline) {
            $note = count($candidates) . ' already match — you’ll hear about what comes next.';
        } elseif ($hits) {
            $failed = array_keys(array_filter($sent, static fn ($s) => !$s['ok']));
            $note = plural(count($hits), 'new match', 'new matches') . ($failed ? ' — could not send by ' . implode(', ', $failed) : ' — sent');
        } else {
            $note = 'Nothing new.';
        }
        if ($notice) {
            $note .= ' ' . $notice;
        }
        Db::run(
            'UPDATE alerts SET last_checked_at = ?, last_note = ?, last_hit_at = COALESCE(?, last_hit_at) WHERE id = ?',
            [now(), mb_substr($note, 0, 255), $hits ? now() : null, $alert['id']]
        );
        return ['hits' => $hits, 'note' => $note, 'baseline' => $baseline, 'sent' => $sent];
    }

    private static function searchCandidates(?array $loc, array $crit, array $alert, string $mode): array
    {
        if (!$loc || $loc['type'] === 'address') {
            return [];
        }
        $f = Search::fromRequest($crit + ['q' => $alert['location'], 'mode' => $mode, 'sold_days' => 90]);
        $rows = PropertyRepo::search($f + ['loc' => $loc, 'limit' => 500])['rows'];
        $under = (float) ($crit['below_median'] ?? 0);
        if ($mode === 'sale' && $under > 0) {
            $area = Search::areaPpsf($loc);
            if ($area === null) {
                return []; // not enough listings saved to know the area's level yet
            }
            $ceiling = $area * (1 - $under / 100);
            $rows = array_values(array_filter($rows, static function ($r) use ($ceiling) {
                $v = ppsf($r['list_price'], $r['sqft']);
                return $v !== null && $v <= $ceiling;
            }));
        }
        return $rows;
    }

    private static function watchlistCandidates(?array $loc, array $crit): array
    {
        $f = ['mode' => 'sale', 'watch_only' => true, 'limit' => 500];
        if ($loc && $loc['type'] !== 'address') {
            $f['loc'] = $loc;
        }
        return PropertyRepo::search($f)['rows'];
    }

    /** @return array{0:string,1:string,2:string} subject, plain text, html */
    public static function compose(array $alert, array $hits, ?array $loc): array
    {
        $site = (string) \App\Settings::get('site_name', 'Plat');
        $n = count($hits);
        $what = match ($alert['kind']) {
            'price_drop' => plural($n, 'price cut'),
            'new_sale' => plural($n, 'new sale'),
            default => plural($n, 'new home'),
        };
        $subject = "$site · $what — " . $alert['name'];
        $area = ($loc && $alert['kind'] !== 'new_sale') ? Search::areaPpsf($loc) : null;

        $lines = [];
        $html = '<div style="font-family:Georgia,serif;color:#18211f;max-width:560px">'
            . '<p style="font:13px/1.4 Arial,sans-serif;color:#55605b;margin:0 0 18px">' . e($alert['name']) . ' · ' . e($what) . '</p>';
        foreach (array_slice($hits, 0, self::MAX_PER_MESSAGE) as $p) {
            $addr = Address::full($p);
            $pp = ppsf($p['hit_price'], $p['sqft']);
            $facts = PropertyRepo::facts($p);
            $metric = $pp ? '$' . number_format($pp) . '/sqft' . ($area ? ' (area median $' . number_format($area) . ')' : '') : '';
            $link = abs_url('/property/' . (int) $p['id']);
            $ext = ExternalSites::forProperty($p)[0] ?? null;

            $lines[] = money($p['hit_price']) . ' · ' . $addr;
            $lines[] = trim($p['why'] . ($facts ? ' · ' . $facts : '') . ($metric ? ' · ' . $metric : ''));
            $lines[] = $link;
            if ($ext) {
                $lines[] = $ext['name'] . ': ' . $ext['url'];
            }
            $lines[] = '';

            $html .= '<div style="border-top:1px solid #e2ded3;padding:14px 0">'
                . '<div style="font-size:22px">' . e(money($p['hit_price'])) . '</div>'
                . '<div style="font:14px/1.5 Arial,sans-serif"><a href="' . e($link) . '" style="color:#0369a1">' . e($addr) . '</a></div>'
                . '<div style="font:13px/1.5 Arial,sans-serif;color:#55605b">' . e(trim($p['why'] . ($facts ? ' · ' . $facts : '') . ($metric ? ' · ' . $metric : ''))) . '</div>'
                . ($ext ? '<div style="font:13px/1.5 Arial,sans-serif"><a href="' . e($ext['url']) . '" style="color:#0369a1">Open on ' . e($ext['name']) . '</a></div>' : '')
                . '</div>';
        }
        if ($n > self::MAX_PER_MESSAGE) {
            $more = '+ ' . ($n - self::MAX_PER_MESSAGE) . ' more: ' . abs_url('/alerts/' . (int) $alert['id']);
            $lines[] = $more;
            $html .= '<p style="font:13px Arial,sans-serif"><a href="' . e(abs_url('/alerts/' . (int) $alert['id'])) . '" style="color:#0369a1">and ' . ($n - self::MAX_PER_MESSAGE) . ' more</a></p>';
        }
        $html .= '</div>';
        return [$subject, rtrim(implode("\n", $lines)), $html];
    }
}

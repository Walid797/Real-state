<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth;
use App\Db;

/**
 * Building condition and renovation status.
 *
 * Feeds don't carry a "condition" field, so it is read from the listing text
 * with plain keyword rules, and the team can correct it by hand. Anything the
 * text doesn't mention stays "not disclosed" / "unknown" — never assumed fine.
 */
final class Condition
{
    public const OVERALL = [
        'not_disclosed' => 'Not disclosed',
        'needs_renovation' => 'Needs renovation',
        'major_repair' => 'Major repair needed',
        'partially_renovated' => 'Partially renovated',
        'recently_renovated' => 'Recently renovated',
        'move_in_ready' => 'Move-in ready',
    ];

    public const PARTS = [
        'kitchen' => 'Kitchen', 'bathroom' => 'Bathroom', 'roof' => 'Roof', 'hvac' => 'HVAC',
        'flooring' => 'Flooring', 'electrical' => 'Electrical', 'plumbing' => 'Plumbing', 'windows' => 'Windows',
    ];

    public const STATES = [
        'unknown' => 'Not disclosed', 'needs_work' => 'Needs work', 'original' => 'Original / dated',
        'updated' => 'Updated', 'new' => 'New',
    ];

    /** Filter choices for the search page: Any, Updated (updated or new), Needs work (needs work or original). */
    public const FILTER = ['any' => 'Any', 'updated' => 'Updated', 'needs_work' => 'Needs work'];

    public const FLAGS = [
        'foreclosure' => 'Foreclosure', 'short_sale' => 'Short sale', 'auction' => 'Auction', 'fixer' => 'Fixer-upper',
        'as_is' => 'Sold as-is', 'estate' => 'Estate sale', 'new_construction' => 'New construction',
    ];

    /** Words for each part, as they appear in US listings. */
    private const PART_WORDS = [
        'kitchen' => 'kitchen|cabinets|countertops?|appliances',
        'bathroom' => 'bath(?:room)?s?|vanit(?:y|ies)|showers?',
        'roof' => 'roof|shingles',
        'hvac' => 'hvac|furnace|heat pump|a/?c\b|air condition(?:er|ing)|ac unit|heating system',
        'flooring' => 'floor(?:ing|s)?|carpet|lvp|hardwoods?',
        'electrical' => 'electrical|electric panel|panel|wiring',
        'plumbing' => 'plumbing|pipes|water heater',
        'windows' => 'windows',
    ];

    private const NEW_WORDS = 'brand[- ]new|new|newer|replaced|installed';
    private const UPDATED_WORDS = 'updated|renovated|remodel(?:l)?ed|upgraded|refinished|redone|modernized';
    private const BAD_WORDS = 'needs?|needing|dated|old|original|worn|damaged|leak(?:s|ing|y)?|outdated|tired|repairs? needed';

    /**
     * @return array{overall:string, parts:array<string,string>, flags:string[], basement:?bool, garage:?int, renovated_year:?int, evidence:string[]}
     */
    public static function detect(string $text, ?string $listingType = null): array
    {
        $t = ' ' . mb_strtolower(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '') . ' ';
        $parts = array_fill_keys(array_keys(self::PARTS), 'unknown');
        $evidence = [];
        $flags = [];

        foreach (self::PART_WORDS as $part => $words) {
            // "~" as delimiter: the HVAC words contain a slash ("a/c").
            $nearby = static fn (string $adj) => '~\b(?:' . $adj . ')\b[^.;!]{0,30}?\b(?:' . $words . ')\b|\b(?:' . $words . ')\b[^.;!]{0,25}?\b(?:' . $adj . ')\b~u';
            // The adjective closest to the word wins: "new HVAC, updated kitchen" → kitchen updated, HVAC new.
            $best = null;
            foreach (['needs_work' => self::BAD_WORDS, 'new' => self::NEW_WORDS, 'updated' => self::UPDATED_WORDS] as $state => $adj) {
                if (preg_match_all($nearby($adj), $t, $all)) {
                    foreach ($all[0] as $hit) {
                        if (preg_match('~\bno (?:' . $words . ')\b|\bneeds? (?:a )?new\b~', $hit) && $state !== 'needs_work') {
                            $state2 = 'needs_work';
                        } else {
                            $state2 = $state;
                        }
                        if (preg_match('~\bno (?:' . $words . ')\b~', $hit)) {
                            continue;
                        }
                        if ($best === null || mb_strlen($hit) < mb_strlen($best[1])) {
                            $best = [$state2, $hit];
                        }
                    }
                }
            }
            if ($best !== null) {
                [$state, $hit] = $best;
                if ($state === 'needs_work' && preg_match('/original|dated|\bold\b|outdated/', $hit)) {
                    $state = 'original';
                }
                $parts[$part] = $state;
                $evidence[] = trim($hit);
            }
        }

        $flagWords = [
            'auction' => '\bauction\b',
            'fixer' => 'fixer[- ]?upper|handyman special|investor special|needs (?:a lot of |some )?(?:tlc|work)|\btlc\b|bring your (?:tools|contractor|vision)|needs? (?:a )?rehab|rehab (?:opportunity|project)|cash (?:buyers? )?only|needs (?:full |complete )?renovation|\\bgut job\\b',
            'as_is' => '\bas[- ]is\b',
            'estate' => 'estate sale|sold by (?:the )?estate',
            'foreclosure' => 'foreclos|bank[- ]owned|\breo\b',
            'short_sale' => 'short sale',
            'new_construction' => 'new construction|newly built|builder warranty',
        ];
        foreach ($flagWords as $flag => $re) {
            if (preg_match('/' . $re . '/u', $t, $m)) {
                $flags[] = $flag;
                $evidence[] = trim($m[0]);
            }
        }
        $lt = mb_strtolower((string) $listingType);
        if ($lt === 'foreclosure') {
            $flags[] = 'foreclosure';
        } elseif ($lt === 'short sale') {
            $flags[] = 'short_sale';
        } elseif ($lt === 'new construction') {
            $flags[] = 'new_construction';
        }
        $flags = array_values(array_unique($flags));

        $major = (bool) preg_match('/foundation (?:issue|problem|crack|repair)|structural|fire damage|water damage|mold|needs (?:a )?new roof|roof (?:leaks?|needs)|condemned|uninhabitable|not livable/u', $t, $mm);
        if ($major) {
            $evidence[] = trim($mm[0]);
        }
        $good = count(array_filter($parts, static fn ($s) => $s === 'new' || $s === 'updated'));
        $bad = count(array_filter($parts, static fn ($s) => $s === 'needs_work' || $s === 'original'));
        $fully = (bool) preg_match('/(?:fully|completely|totally|newly|beautifully|recently) (?:renovated|remodel(?:l)?ed|updated|rehabbed)|top to bottom|complete renovation|gut renovation (?:completed|done)/u', $t, $mf);

        $overall = match (true) {
            $major => 'major_repair',
            in_array('fixer', $flags, true) || $bad >= 2 && $good === 0 => 'needs_renovation',
            $fully || $good >= 4 => 'recently_renovated',
            $good >= 1 && $bad >= 1, $good >= 1 && $good < 4 => 'partially_renovated',
            (bool) preg_match('/move[- ]in ready|turn[- ]?key|meticulously maintained|immaculate/u', $t) => 'move_in_ready',
            default => 'not_disclosed',
        };
        if ($fully) {
            $evidence[] = trim($mf[0]);
        }

        $basement = null;
        if (preg_match('/\bno basement\b|\bslab foundation\b/u', $t)) {
            $basement = false;
        } elseif (preg_match('/\b(?:finished|unfinished|walk[- ]?out|full|partial|daylight)? ?basement\b/u', $t)) {
            $basement = true;
        }
        $garage = null;
        if (preg_match('/\b(one|two|three|four|1|2|3|4)[- ]car garage\b/u', $t, $g)) {
            $garage = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4][$g[1]] ?? (int) $g[1];
        }
        $year = null;
        if (preg_match('/(?:renovated|remodel(?:l)?ed|updated) in (20[0-3]\d|19[89]\d)/u', $t, $y)) {
            $year = (int) $y[1];
        }

        return ['overall' => $overall, 'parts' => $parts, 'flags' => $flags, 'basement' => $basement, 'garage' => $garage,
            'renovated_year' => $year, 'evidence' => array_values(array_unique(array_slice($evidence, 0, 12)))];
    }

    public static function get(int $propertyId): array
    {
        $row = Db::one('SELECT * FROM property_condition WHERE property_id = ?', [$propertyId]);
        if (!$row) {
            $row = ['property_id' => $propertyId, 'overall' => 'not_disclosed', 'renovated_year' => null, 'source' => 'auto', 'evidence' => null, 'updated_at' => null, 'updated_by' => null];
            foreach (self::PARTS as $k => $_) {
                $row[$k] = 'unknown';
            }
        }
        $row['evidence'] = json_decode((string) ($row['evidence'] ?? ''), true) ?: [];
        return $row;
    }

    /** Reads the listing text again unless someone set the condition by hand. Also fills flags, basement and garage. */
    public static function refresh(array $p): array
    {
        $current = Db::one('SELECT source FROM property_condition WHERE property_id = ?', [$p['id']]);
        // The listing description, plus any facts someone pasted from the listing page.
        $details = is_array($p['details'] ?? null) ? $p['details'] : (json_decode((string) ($p['details'] ?? ''), true) ?: []);
        $text = trim((string) ($p['description'] ?? '') . "\n" . (string) ($details['listing_text'] ?? ''));
        $d = self::detect($text, $p['listing_type'] ?? null);

        $flags = array_values(array_unique(array_merge(array_filter(explode(',', (string) ($p['flags'] ?? ''))), $d['flags'])));
        $set = ['flags' => implode(',', array_intersect(array_keys(self::FLAGS), $flags))];
        if ($d['basement'] !== null && $p['has_basement'] === null) {
            $set['has_basement'] = $d['basement'] ? 1 : 0;
        }
        if ($d['garage'] !== null && $p['garage_spaces'] === null) {
            $set['garage_spaces'] = $d['garage'];
        }
        Db::update('properties', $set, (int) $p['id']);

        if (($current['source'] ?? '') !== 'manual') {
            self::save((int) $p['id'], $d['overall'], $d['parts'], $d['renovated_year'], 'auto', $d['evidence'], null);
        }
        return self::get((int) $p['id']);
    }

    public static function save(int $propertyId, string $overall, array $parts, ?int $year, string $source, array $evidence, ?int $userId): void
    {
        $overall = isset(self::OVERALL[$overall]) ? $overall : 'not_disclosed';
        $cols = ['property_id' => $propertyId, 'overall' => $overall, 'renovated_year' => $year, 'source' => $source,
            'evidence' => $evidence ? json_encode($evidence) : null, 'updated_by' => $userId, 'updated_at' => now()];
        foreach (self::PARTS as $k => $_) {
            $cols[$k] = isset(self::STATES[$parts[$k] ?? '']) ? $parts[$k] : 'unknown';
        }
        $names = array_keys($cols);
        Db::run(
            'INSERT INTO property_condition (`' . implode('`,`', $names) . '`) VALUES (:' . implode(',:', $names) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn ($c) => "`$c` = VALUES(`$c`)", array_diff($names, ['property_id']))),
            $cols
        );
    }

    /** Manual edit from the property page. */
    public static function setByHand(int $propertyId, array $in): void
    {
        $parts = [];
        foreach (self::PARTS as $k => $_) {
            $parts[$k] = (string) ($in[$k] ?? 'unknown');
        }
        $year = isset($in['renovated_year']) && is_numeric($in['renovated_year']) && $in['renovated_year'] >= 1900 && $in['renovated_year'] <= (int) gmdate('Y')
            ? (int) $in['renovated_year'] : null;
        $before = self::get($propertyId);
        self::save($propertyId, (string) ($in['overall'] ?? 'not_disclosed'), $parts, $year, 'manual', $before['evidence'], Auth::userId());
        $changes = [];
        if ($before['overall'] !== ($in['overall'] ?? '')) {
            $changes[] = 'overall → ' . (self::OVERALL[$in['overall'] ?? ''] ?? 'Not disclosed');
        }
        foreach (self::PARTS as $k => $label) {
            if ($before[$k] !== $parts[$k]) {
                $changes[] = $label . ' → ' . (self::STATES[$parts[$k]] ?? '?');
            }
        }
        if ($changes) {
            Activity::log('condition', $propertyId, implode(', ', $changes));
        }
    }

    /** Renovation tier used by the cost estimate. */
    public static function tier(string $overall): string
    {
        return match ($overall) {
            'move_in_ready', 'recently_renovated' => 'reno_light',
            'partially_renovated' => 'reno_medium',
            'needs_renovation' => 'reno_heavy',
            'major_repair' => 'reno_major',
            default => 'reno_unknown',
        };
    }
}

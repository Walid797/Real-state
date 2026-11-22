<?php
declare(strict_types=1);

namespace App;

/** Address parsing, normalisation and the hash that de-duplicates a house across sources. */
final class Address
{
    public const STATES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
        'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia',
        'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois',
        'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana',
        'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
        'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon',
        'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
        'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia',
        'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    ];

    private const SUFFIX = [
        'street' => 'st', 'avenue' => 'ave', 'boulevard' => 'blvd', 'drive' => 'dr', 'road' => 'rd',
        'lane' => 'ln', 'court' => 'ct', 'place' => 'pl', 'terrace' => 'ter', 'circle' => 'cir',
        'highway' => 'hwy', 'parkway' => 'pkwy', 'trail' => 'trl', 'square' => 'sq', 'cove' => 'cv',
        'north' => 'n', 'south' => 's', 'east' => 'e', 'west' => 'w',
        'northeast' => 'ne', 'northwest' => 'nw', 'southeast' => 'se', 'southwest' => 'sw',
        'apartment' => 'unit', 'apt' => 'unit', 'suite' => 'unit', 'ste' => 'unit', 'number' => 'unit',
    ];

    public static function stateAbbr(string $s): ?string
    {
        $s = trim($s);
        $up = strtoupper($s);
        if (isset(self::STATES[$up])) {
            return $up;
        }
        foreach (self::STATES as $abbr => $name) {
            if (strcasecmp($name, $s) === 0) {
                return $abbr;
            }
        }
        return null;
    }

    public static function normalizeStreet(string $line): string
    {
        $s = mb_strtolower(trim($line));
        $s = str_replace('#', ' unit ', $s);
        $s = preg_replace('/[^a-z0-9\s\/-]/u', ' ', $s) ?? $s;
        $words = preg_split('/\s+/', trim($s)) ?: [];
        $words = array_map(static fn ($w) => self::SUFFIX[$w] ?? $w, $words);
        $line = implode(' ', $words);
        // Tower letters are often separated in pasted addresses: Apt 1703 S / #1703S.
        return preg_replace('/\bunit ([a-z0-9-]+) ([nsew])$/', 'unit $1$2', $line) ?? $line;
    }

    /** Same house → same hash, whether it came as "123 Main Street" or "123 MAIN ST". */
    public static function hash(string $line1, string $city, string $state, string $zip): string
    {
        $zip = substr(preg_replace('/\D/', '', $zip) ?? '', 0, 5);
        $area = $zip !== '' ? $zip : mb_strtolower(trim($city)) . '|' . strtoupper(trim($state));
        return sha1(self::normalizeStreet($line1) . '|' . $area);
    }

    public static function full(array $p): string
    {
        $tail = trim(($p['state'] ?? '') . ' ' . ($p['zip'] ?? ''));
        return implode(', ', array_filter([$p['address'] ?? '', $p['city'] ?? '', $tail]));
    }

    public static function titleCity(string $city): string
    {
        $city = trim(preg_replace('/\s+/', ' ', $city) ?? $city);
        // Respect deliberate casing ("McKinney", "DeSoto"); only fix all-lower / all-upper input.
        if ($city !== mb_strtolower($city) && $city !== mb_strtoupper($city)) {
            return $city;
        }
        return ucwords(mb_strtolower($city), " -'");
    }

    /** The state an "entire state" / county / bare-city search means (Settings → home state). */
    public static function homeState(): string
    {
        try {
            return \App\Settings::get('default_state', 'VA') ?: 'VA';
        } catch (\Throwable) {
            return 'VA';
        }
    }

    /**
     * Understands, case-insensitively:
     *   "78704"                       one ZIP
     *   "22030, 20176 22102"          several ZIPs
     *   "Virginia", "VA", "all of VA" the whole state
     *   "Fairfax County, VA"          a county (state optional: home state assumed)
     *   "Fairfax County, Loudoun County, VA"   several counties in one state
     *   "Fairfax County, VA, Montgomery County, MD"   counties in different states
     *   "Austin, TX", "Richmond"      a city (state optional: home state assumed)
     *   "Fairfax County; Richmond"    several areas, separated by ";"
     *   "5500 Grand Lake Dr, San Antonio, TX 78244"   one street address
     *
     * @return array{type:string, key:string, label:string, ...}|null
     *   type: zip | city | county | state | multi | address. "multi" carries "parts".
     */
    public static function parseLocation(string $q): ?array
    {
        $q = strtr($q, ['،' => ',', '؛' => ';']);
        $q = trim(preg_replace('/\s+/', ' ', $q) ?? '');
        if ($q === '') {
            return null;
        }
        // Several ZIPs, separated by commas and/or spaces.
        if (preg_match('/^\d{5}(?:[\s,;]+\d{5})+$/', $q)) {
            $zips = array_values(array_unique(preg_split('/[\s,;]+/', $q) ?: []));
            return self::multi(array_map(static fn ($z) => self::parseOne($z), array_slice($zips, 0, 40)));
        }
        if (str_contains($q, ';')) {
            $parts = [];
            foreach (explode(';', $q) as $chunk) {
                $chunk = trim($chunk);
                $areas = self::countyList($chunk) ?: [self::parseOne($chunk)];
                foreach ($areas as $area) {
                    if ($area && $area['type'] !== 'address') {
                        $parts[] = $area;
                    }
                }
            }
            return self::multi($parts);
        }
        $counties = self::countyList($q);
        return $counties ? self::multi($counties) : self::parseOne($q);
    }

    /** A comma before a county name separates areas; a comma before a state stays with its county. */
    private static function countyList(string $q): array
    {
        $suffix = '(?:county|parish|borough)';
        $names = preg_split('/,\s*(?=[^,]+?\s+' . $suffix . '\b)/i', $q) ?: [];
        if (count($names) < 2) {
            return [];
        }
        $parts = array_map(self::parseOne(...), $names);
        if (count(array_filter($parts, static fn ($part) => ($part['type'] ?? '') === 'county')) !== count($names)) {
            return [];
        }
        $unqualified = array_filter($names, static fn ($name) => preg_match('/\s' . $suffix . '$/i', trim($name)));
        $last = count($names) - 1;
        // A single state written at the very end applies to the whole comma-separated list.
        if (count($unqualified) === $last && !isset($unqualified[$last])) {
            foreach ($unqualified as $i => $name) {
                $parts[$i] = self::parseOne($name . ', ' . $parts[$last]['state']);
            }
        }
        return $parts;
    }

    private static function multi(array $parts): ?array
    {
        $parts = array_slice(array_values(array_column(array_filter($parts), null, 'key')), 0, 40);
        if (!$parts) {
            return null;
        }
        if (count($parts) === 1) {
            return $parts[0];
        }
        $keys = array_column($parts, 'key');
        sort($keys);
        return [
            'type' => 'multi', 'parts' => $parts,
            'key' => 'multi:' . substr(sha1(implode('|', $keys)), 0, 16),
            'label' => implode(' · ', array_column($parts, 'label')),
        ];
    }

    private static function parseOne(string $q): ?array
    {
        $q = trim($q, " \t,");
        if ($q === '') {
            return null;
        }
        if (preg_match('/^\d{5}(-\d{4})?$/', $q)) {
            $zip = substr($q, 0, 5);
            return ['type' => 'zip', 'zip' => $zip, 'key' => 'zip:' . $zip, 'label' => $zip];
        }
        // Starts with a house number → a street address.
        if (preg_match('/^\d+[a-z]?\s+\S+/i', $q)) {
            $a = self::parseFullAddress($q);
            if ($a !== null) {
                return ['type' => 'address', 'key' => 'addr:' . self::hash($a['address'], $a['city'], $a['state'], $a['zip']), 'label' => self::full($a)] + $a;
            }
            return null;
        }
        // The whole state: "Virginia", "VA", "all of Virginia", "entire VA", "Virginia statewide".
        $bare = preg_replace('/^(?:all of|entire|whole|statewide)\s+|\s+(?:statewide|state)$/i', '', $q) ?? $q;
        if (($st = self::stateAbbr($bare)) && (strlen($bare) === 2 ? strtoupper($bare) === $bare || $bare !== $q : true)) {
            return ['type' => 'state', 'state' => $st, 'key' => 'state:' . $st, 'label' => self::STATES[$st]];
        }

        // Split off a trailing state: "X, ST", "X ST", "X, State name".
        $state = null;
        $head = $q;
        if (preg_match('/^(.+?)[,\s]\s*([A-Za-z]{2})$/', $q, $m) && ($st = self::stateAbbr($m[2]))) {
            [$state, $head] = [$st, rtrim($m[1], ', ')];
        } elseif (str_contains($q, ',')) {
            [$c, $sn] = array_map('trim', explode(',', $q, 2));
            if ($st = self::stateAbbr($sn)) {
                [$state, $head] = [$st, $c];
            }
        } else {
            foreach (self::STATES as $abbr => $name) {
                if (preg_match('/^(.+)\s+' . preg_quote($name, '/') . '$/i', $q, $m)) {
                    [$state, $head] = [$abbr, $m[1]];
                    break;
                }
            }
        }
        $state ??= self::homeState();
        $head = trim($head);
        if ($head === '' || preg_match('/\d/', $head)) {
            return null;
        }
        if (preg_match('/^(.+?)\s+(county|parish|borough)$/i', $head, $m)) {
            $county = self::titleCity($m[1]);
            return ['type' => 'county', 'county' => $county, 'state' => $state,
                'key' => 'county:' . $state . ':' . mb_strtolower($county), 'label' => $county . ' County, ' . $state];
        }
        $city = self::titleCity($head);
        return ['type' => 'city', 'city' => $city, 'state' => $state,
            'key' => 'city:' . $state . ':' . mb_strtolower($city), 'label' => $city . ', ' . $state];
    }

    /** The areas a location stands for, flattened ("multi" → its parts). */
    public static function areas(array $loc): array
    {
        return $loc['type'] === 'multi' ? $loc['parts'] : [$loc];
    }

    /** @return array{address:string, city:string, state:string, zip:string}|null */
    public static function parseFullAddress(string $q): ?array
    {
        $q = strtr($q, ['،' => ',', "\r\n" => ',', "\n" => ',', "\r" => ',']);
        $q = trim(preg_replace('/\s+/', ' ', $q) ?? '');
        // Postal addresses pasted as: street / apartment / ZIP city state.
        if (preg_match('/^(.+?)[,\s]+(\d{5})(?:-\d{4})?\s+([A-Za-z][A-Za-z .\x27-]*?)\s+([A-Za-z]{2})$/', $q, $m)
            && ($st = self::stateAbbr($m[4]))) {
            return self::parseFullAddress(rtrim($m[1], ', ') . ', ' . $m[3] . ', ' . $st . ' ' . $m[2]);
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $q)), 'strlen'));
        if (count($parts) < 3) {
            // An explicit street suffix provides the boundary when commas are absent.
            if (!preg_match('/^(.+?)\s+([A-Za-z]{2})\s+(\d{5})(?:-\d{4})?$/', $q, $tail)
                || !($st = self::stateAbbr($tail[2]))) {
                return null;
            }
            $head = trim($tail[1], ', ');
            if (str_contains($head, ',')) {
                $chunks = array_map('trim', explode(',', $head));
                $city = array_pop($chunks);
                $line = implode(' ', $chunks);
            } else {
                $suffixes = implode('|', array_map('preg_quote', array_unique(array_merge(array_keys(self::SUFFIX), array_values(self::SUFFIX)))));
                // Unit markers are kept with the street, including an optional tower letter.
                $pattern = '/^(\d+[a-z]?\s+.+?\s+(?:' . $suffixes . ')\.?(?:\s+[NSEW]{1,2})?(?:\s+(?:apartment|apt|unit|suite|ste|\#)\.?\s*[a-z0-9-]+(?:\s+[NSEW])?)?)\s+([A-Za-z][A-Za-z .\x27-]+)$/i';
                if (!preg_match($pattern, $head, $m)) {
                    return null;
                }
                [$line, $city] = [$m[1], $m[2]];
            }
            if (!preg_match('/^\d+[a-z]?\s+\S+/i', $line) || $city === '' || preg_match('/\d|\b(?:apt|apartment|unit)\b/i', $city)) {
                return null;
            }
            return ['address' => $line, 'city' => self::titleCity($city), 'state' => $st, 'zip' => $tail[3]];
        }
        $last = array_pop($parts);
        if (!preg_match('/^([A-Za-z .]+?)\s*(\d{5})?(?:-\d{4})?$/', $last, $m)) {
            // "..., Austin, TX, 78704"
            if (preg_match('/^\d{5}/', $last) && $parts) {
                $zip = substr($last, 0, 5);
                $st = self::stateAbbr((string) array_pop($parts));
            } else {
                return null;
            }
        } else {
            $st = self::stateAbbr(trim($m[1]));
            $zip = $m[2] ?? '';
        }
        if (!$st || count($parts) < 2) {
            return null;
        }
        $city = self::titleCity((string) array_pop($parts));
        $line = implode(', ', $parts);
        // "123 Main St, Apt 4" → keep the unit on the street line.
        return ['address' => preg_replace('/,\s*/', ' ', $line) ?? $line, 'city' => $city, 'state' => $st, 'zip' => $zip];
    }

    /** Compare actual addresses, retaining the unit; never merge a different apartment. */
    public static function sameProperty(array $a, array $b, bool $buildingOnly = false): bool
    {
        $left = self::normalizeStreet((string) ($a['address'] ?? ''));
        $right = self::normalizeStreet((string) ($b['address'] ?? ''));
        if ($buildingOnly) {
            $left = preg_replace('/\s+unit\b.*$/', '', $left) ?? $left;
            $right = preg_replace('/\s+unit\b.*$/', '', $right) ?? $right;
        }
        if ($left === '' || $left !== $right || strtoupper((string) ($a['state'] ?? '')) !== strtoupper((string) ($b['state'] ?? ''))) {
            return false;
        }
        if (!empty($a['zip']) && !empty($b['zip'])) {
            return substr($a['zip'], 0, 5) === substr($b['zip'], 0, 5);
        }
        return !empty($a['city']) && strcasecmp(trim($a['city']), trim((string) ($b['city'] ?? ''))) === 0;
    }

    public static function slug(string $s, string $sep = '-', bool $lower = false): string
    {
        $s = preg_replace('/[^A-Za-z0-9]+/', $sep, trim($s)) ?? '';
        $s = trim($s, $sep);
        return $lower ? strtolower($s) : $s;
    }
}

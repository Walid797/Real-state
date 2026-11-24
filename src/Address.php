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

    /** Street-type words used to tell a street ("Seminary Rd") from a place ("Falls Church"). Search only; never hashed. */
    private const STREET_WORDS = [
        'st' => 'street', 'rd' => 'road', 'ave' => 'avenue', 'av' => 'avenue', 'blvd' => 'boulevard', 'dr' => 'drive', 'ln' => 'lane',
        'ct' => 'court', 'pl' => 'place', 'ter' => 'terrace', 'cir' => 'circle', 'hwy' => 'highway', 'pkwy' => 'parkway', 'trl' => 'trail',
        'sq' => 'square', 'way' => 'way', 'pike' => 'pike', 'tpke' => 'turnpike', 'plz' => 'plaza', 'loop' => 'loop', 'row' => 'row',
        'cv' => 'cove', 'aly' => 'alley', 'xing' => 'crossing', 'walk' => 'walk', 'bnd' => 'bend', 'run' => 'run', 'pass' => 'pass',
    ];
    /** Street words that are just as often part of a building's name. */
    private const NAME_WORDS = ['square', 'sq', 'plaza', 'plz', 'place', 'pl', 'court', 'ct', 'walk', 'row'];
    private const DIRECTIONS = ['n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw', 'north', 'south', 'east', 'west', 'northeast', 'northwest', 'southeast', 'southwest'];

    /** Persian / Arabic digits and punctuation → ASCII; one space between words. */
    public static function clean(string $q): string
    {
        $q = strtr($q, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '،' => ',', '؛' => ';', "\u{200C}" => ' ', "\u{00A0}" => ' ',
        ]);
        $q = preg_replace('/[ \t]+/u', ' ', $q) ?? $q;
        return trim(preg_replace('/\s*,\s*(?:USA|United States(?: of America)?|US)\.?$/i', '', trim($q)) ?? $q);
    }

    /** Is this word a street type ("rd", "Road", "Pike")? */
    public static function isStreetWord(string $w): bool
    {
        $w = strtolower(rtrim($w, '.'));
        return isset(self::STREET_WORDS[$w]) || in_array($w, self::STREET_WORDS, true);
    }

    /**
     * Understands, case-insensitively, any mix of:
     *   "78704" · "22030, 20176 22102"              ZIP codes
     *   "Virginia", "VA", "all of VA"                 the whole state
     *   "Fairfax County", "Fairfax County, VA"        a county (state optional: home state assumed)
     *   "Fairfax County, Loudoun County, VA"          several counties; a trailing state applies to all
     *   "Austin, TX", "Richmond", "Fairfax City"      a city or independent city
     *   "Falls Church, Arlington, Alexandria"         several cities
     *   "Seminary Rd", "Columbia Pike, Arlington"     every home on a street
     *   "5500 Grand Lake Dr, San Antonio, TX 78244"   one home, with or without commas, ZIP or state
     *   "5501 Seminary Rd"                            one home, city unknown (looked up)
     *   "Fairfax County; Richmond; 5501 Seminary Rd…" several of the above: ";", "|", a new line or "and"
     *
     * @return array{type:string, key:string, label:string, ...}|null
     *   type: zip | city | county | state | street | address | multi. "multi" carries "parts".
     */
    public static function parseLocation(string $q): ?array
    {
        $q = self::clean(strtr($q, ["\r\n" => "\n", "\r" => "\n"]));
        if ($q === '') {
            return null;
        }
        // Tampa Bay is a metropolitan area, not a city in the user's default state.
        // OMB/Census CBSA 45300 (July 2023 delineation): these four Florida counties.
        if (preg_match('/^Tampa\s+Bay(?:\s+(?:Area|metro|metropolitan\s+area))?(?:[,\s]+(Florida|[A-Za-z]{2}))?$/i', $q, $metro)) {
            if (!empty($metro[1]) && self::stateAbbr($metro[1]) !== 'FL') return null;
            $location = self::parseLocation('Hernando County, FL; Hillsborough County, FL; Pasco County, FL; Pinellas County, FL');
            $location['label'] = 'Tampa–St. Petersburg–Clearwater metro, FL';
            $location['metro_counties'] = ['Hernando','Hillsborough','Pasco','Pinellas'];
            $location['geography_source'] = 'https://www.census.gov/programs-surveys/metro-micro/about/delineation-files.html';
            return $location;
        }
        // A pasted postal address keeps its lines together (street / apartment / ZIP city state).
        if (str_contains($q, "\n") && preg_match('/^\d/', $q) && ($a = self::parseFullAddress($q)) !== null) {
            return self::addressLoc($a);
        }
        $parts = [];
        foreach (preg_split('/\s*(?:;|\||\n)\s*/', $q) ?: [] as $chunk) {
            foreach (self::parseChunk($chunk) as $part) {
                $parts[] = $part;
            }
        }
        return self::multi($parts);
    }

    /** One ";"-separated piece: an address, a ZIP list, or a comma / "and" list of places. @return list<array> */
    private static function parseChunk(string $chunk): array
    {
        $chunk = trim($chunk, " \t,");
        if ($chunk === '') {
            return [];
        }
        if (preg_match('/^\d{5}(?:[\s,]+\d{5})*$/', $chunk)) {
            return array_map(static fn ($z) => self::parseOne($z), array_slice(array_values(array_unique(preg_split('/[\s,]+/', $chunk) ?: [])), 0, 40));
        }
        if (preg_match('/^\d+[a-z]?(?:-\d+)?\s+\D/i', $chunk) && !preg_match('/^\d{5}\s/', $chunk)) {
            $one = self::parseOne($chunk);
            return $one ? [$one] : [];
        }
        // "Fairfax County and Arlington County", "Reston & Herndon"
        if (!preg_match('/king\s+and\s+queen/i', $chunk)) {
            $chunk = preg_replace('/\s+(?:and|&|و)\s+/iu', ', ', $chunk) ?? $chunk;
        }
        // Commas: a state (or "ST 12345") belongs to the place before it.
        $groups = [];
        foreach (array_values(array_filter(array_map('trim', explode(',', $chunk)), 'strlen')) as $piece) {
            $isStateTail = self::stateAbbr($piece) !== null || (preg_match('/^([A-Za-z]{2})\s+\d{5}(?:-\d{4})?$/', $piece, $m) && self::stateAbbr($m[1]));
            if ($groups && $isStateTail && !self::endsWithState($groups[count($groups) - 1])) {
                $groups[count($groups) - 1] .= ', ' . $piece;
            } else {
                $groups[] = $piece;
            }
        }
        if (count($groups) === 1) {
            $one = self::parseOne($groups[0]);
            return $one ? [$one] : [];
        }
        // "Seminary Rd, Falls Church, VA" or "Skyline Square, Falls Church": a street or name in a city.
        if (count($groups) === 2 && self::looksLikeStreet($groups[0]) && !preg_match('/\b(?:county|parish|borough)\b|^\d{5}$/i', $groups[1])) {
            $place = self::parseOne($groups[1]);
            if ($place && $place['type'] === 'city') {
                return [self::streetLoc($groups[0], $place['city'], $place['state'])];
            }
        }
        $parts = array_map(self::parseOne(...), $groups);
        // A single state written at the end applies to the earlier places that have none.
        $last = count($groups) - 1;
        if (self::endsWithState($groups[$last]) && ($parts[$last]['state'] ?? null)) {
            foreach ($groups as $i => $g) {
                if ($i < $last && !self::endsWithState($g) && !preg_match('/^\d{5}$/', $g)) {
                    $parts[$i] = self::parseOne($g . ', ' . $parts[$last]['state']);
                }
            }
        }
        return array_values(array_filter($parts));
    }

    /** "Fairfax County, VA", "Austin TX", "Richmond, Virginia 23220" */
    private static function endsWithState(string $s): bool
    {
        $s = trim(preg_replace('/\s+\d{5}(?:-\d{4})?$/', '', trim($s)) ?? $s);
        if (preg_match('/[,\s]([A-Za-z]{2})$/', $s, $m) && self::stateAbbr($m[1])) {
            return true;
        }
        foreach (self::STATES as $name) {
            if (preg_match('/[,\s]' . preg_quote($name, '/') . '$/i', $s)) {
                return true;
            }
        }
        return false;
    }

    /** "Seminary Rd", "Columbia Pike", "Skyline Square" — a name ending in a street word, without a house number. */
    private static function looksLikeStreet(string $s): bool
    {
        $s = trim($s);
        if ($s === '' || preg_match('/\d/', $s) || !str_contains($s, ' ')) {
            return false;
        }
        $words = preg_split('/\s+/', $s) ?: [];
        $last = strtolower(rtrim((string) end($words), '.'));
        if (in_array($last, self::DIRECTIONS, true) && count($words) > 2) {
            $last = strtolower(rtrim((string) $words[count($words) - 2], '.'));
        }
        return self::isStreetWord($last);
    }

    private static function streetLoc(string $street, string $city, string $state): array
    {
        $street = self::titleCity(trim($street, ' ,'));
        $norm = self::normalizeStreet($street);
        $city = $city !== '' ? self::titleCity($city) : '';
        $label = implode(', ', array_filter([$street, $city, $state]));
        $words = preg_split('/\s+/', strtolower($street)) ?: [];
        $maybeName = in_array(rtrim((string) end($words), '.'), self::NAME_WORDS, true);
        return ['type' => 'street', 'street' => $street, 'city' => $city, 'state' => $state, 'name_like' => $maybeName,
            'key' => 'street:' . $state . ':' . mb_strtolower($city) . ':' . $norm, 'label' => $label];
    }

    /**
     * SQL regular expression for a street name, matching its long and short spellings anywhere in an address line:
     * "seminary rd" → (^|[^a-z0-9])seminary (rd|road)([^a-z0-9]|$)
     */
    public static function streetRegex(string $street): string
    {
        $words = preg_split('/\s+/', strtolower(trim(preg_replace('/[^a-z0-9\s]/i', ' ', $street) ?? ''))) ?: [];
        $alts = [];
        foreach (array_filter($words, 'strlen') as $w) {
            $forms = [$w];
            foreach (self::STREET_WORDS as $short => $long) {
                if ($w === $short || $w === $long) {
                    $forms = [$short, $long];
                    if ($short === 'av') {
                        $forms[] = 'ave';
                    }
                    if ($short === 'ave') {
                        $forms[] = 'av';
                    }
                }
            }
            foreach (['n' => 'north', 's' => 'south', 'e' => 'east', 'w' => 'west'] as $short => $long) {
                if ($w === $short || $w === $long) {
                    $forms = [$short, $long];
                }
            }
            if ($w === 'saint' || $w === 'st' && count($alts) === 0) {
                $forms = array_unique(array_merge($forms, ['st', 'saint']));
            }
            $alts[] = count($forms) > 1 ? '(' . implode('|', array_unique($forms)) . ')' : $forms[0];
        }
        return '(^|[^a-z0-9])' . implode(' ', $alts) . '([^a-z0-9]|$)';
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

    private static function addressLoc(array $a): array
    {
        $partial = ($a['city'] ?? '') === '' && ($a['zip'] ?? '') === '';
        return ['type' => 'address', 'partial' => $partial,
            'key' => 'addr:' . self::hash($a['address'], $a['city'], $a['state'], $a['zip']), 'label' => self::full($a)] + $a;
    }

    private static function parseOne(string $q): ?array
    {
        $q = trim($q, " \t,");
        $q = preg_replace('/^(?:zip(?:\s*code)?|postal\s+code)\s*:?\s*(?=\d{5})/i', '', $q) ?? $q;
        if ($q === '') {
            return null;
        }
        if (preg_match('/^\d{5}(-\d{4})?$/', $q)) {
            $zip = substr($q, 0, 5);
            return ['type' => 'zip', 'zip' => $zip, 'key' => 'zip:' . $zip, 'label' => $zip];
        }
        // "22041 Falls Church VA": a ZIP code followed by its town, not a house number.
        if (preg_match('/^(\d{5})(?:-\d{4})?\s+([^\d]+)$/', $q, $m) && !self::looksLikeStreet(preg_replace('/[,\s]+[A-Za-z]{2}$/', '', $m[2]) ?? $m[2])
            && !preg_match('/\s(?:' . self::streetWordPattern() . ')\.?(?:\s|$)/i', ' ' . $m[2])) {
            return self::parseOne($m[1]);
        }
        // Starts with a house number → a street address.
        if (preg_match('/^\d+[a-z]?(?:-\d+)?\s+\S+/i', $q)) {
            $a = self::parseFullAddress($q) ?? self::parseLooseAddress($q);
            return $a !== null ? self::addressLoc($a) : null;
        }
        // "Fairfax, VA 22030" / "Falls Church 22041": the ZIP is the most precise part.
        if (preg_match('/^\D+?[,\s]+(\d{5})(?:-\d{4})?$/', $q, $m)) {
            return self::parseOne($m[1]);
        }
        // The whole state: "Virginia", "VA", "all of Virginia", "entire VA", "Virginia statewide".
        $bare = preg_replace('/^(?:all of|entire|whole|statewide)\s+|\s+(?:statewide|state)$/i', '', $q) ?? $q;
        if (($st = self::stateAbbr($bare)) && (strlen($bare) === 2 ? strtoupper($bare) === $bare || $bare !== $q : true)) {
            return ['type' => 'state', 'state' => $st, 'key' => 'state:' . $st, 'label' => self::STATES[$st]];
        }

        // Split off a trailing state: "X, ST", "X ST", "X, State name".
        $state = null;
        $head = $q;
        if (preg_match('/^(.+?)[,\s]\s*([A-Za-z]{2})$/', $q, $m) && ($st = self::stateAbbr($m[2])) && !(self::isStreetWord($m[2]) && self::looksLikeStreet($q))) {
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
        $head = trim($head, " \t,");
        if ($head === '' || preg_match('/\d/', $head)) {
            return null;
        }
        if (preg_match('/^(.+?)\s+(county|parish|borough)$/i', $head, $m)) {
            $county = self::titleCity($m[1]);
            return ['type' => 'county', 'county' => $county, 'state' => $state,
                'key' => 'county:' . $state . ':' . mb_strtolower($county), 'label' => $county . ' County, ' . $state];
        }
        // "City of Fairfax" is the independent city "Fairfax City".
        if (preg_match('/^city\s+of\s+(.+)$/i', $head, $m)) {
            $head = $m[1] . ' City';
        }
        // A street, with or without its city: "Seminary Rd", "Leesburg Pike Falls Church".
        if (self::looksLikeStreet($head)) {
            return self::streetLoc($head, '', $state);
        }
        if (!preg_match('/\b(?:port|fort|ft|mount|mt)\s+st\.?\s/i', $head)
            && preg_match('/^(.+?\s+(?:' . self::streetWordPattern() . ')\.?(?:\s+(?:n|s|e|w|ne|nw|se|sw))?)\s+([a-z][a-z .\'-]+)$/i', $head, $m)
            && self::looksLikeStreet($m[1])) {
            return self::streetLoc($m[1], $m[2], $state);
        }
        $city = self::titleCity($head);
        return ['type' => 'city', 'city' => $city, 'state' => $state,
            'key' => 'city:' . $state . ':' . mb_strtolower($city), 'label' => $city . ', ' . $state];
    }

    private static function streetWordPattern(): string
    {
        $all = array_unique(array_merge(array_keys(self::STREET_WORDS), array_values(self::STREET_WORDS)));
        usort($all, static fn ($a, $b) => strlen($b) <=> strlen($a));
        return implode('|', array_map(static fn ($w) => preg_quote($w, '/'), $all));
    }

    /**
     * Addresses typed loosely: "5501 seminary road falls church va", "5501 Seminary Rd 22041",
     * "5501 Seminary Rd" (city unknown). The street ends at its street word (plus a direction and
     * a unit); what follows is the city. Null when that cannot be told apart.
     * @return array{address:string, city:string, state:string, zip:string}|null
     */
    public static function parseLooseAddress(string $q): ?array
    {
        $s = trim(preg_replace('/\s+/', ' ', str_replace(',', ' ', $q)) ?? '');
        if (!preg_match('/^\d+[a-z]?(?:-\d+)?\s+\S/i', $s)) {
            return null;
        }
        $zip = '';
        if (preg_match('/\s(\d{5})(?:-\d{4})?$/', $s, $m)) {
            $zip = $m[1];
            $s = trim(substr($s, 0, -strlen($m[0])));
        }
        $state = '';
        foreach (self::STATES as $abbr => $name) {
            if (preg_match('/\s' . preg_quote($name, '/') . '$/i', $s, $m)) {
                [$state, $s] = [$abbr, trim(substr($s, 0, -strlen($m[0])))];
                break;
            }
        }
        $words = explode(' ', $s);
        // A two-letter state at the end — unless it is the street's own type ("123 Oak Ct").
        if ($state === '' && count($words) > 2) {
            $tail = (string) end($words);
            $streetWordsBefore = count(array_filter(array_slice($words, 1, -1), static fn ($w) => self::isStreetWord($w)));
            if (strlen($tail) === 2 && self::stateAbbr($tail) && (!self::isStreetWord($tail) || $streetWordsBefore > 0)) {
                $state = strtoupper($tail);
                array_pop($words);
            }
        }
        // The first street word after the name ends the street; "St" followed by a name is "Saint".
        $end = null;
        for ($i = 2, $n = count($words); $i < $n; $i++) {
            $w = strtolower(rtrim($words[$i], '.'));
            if (!self::isStreetWord($w)) {
                continue;
            }
            // "Old St Andrews Way": this "St" is Saint when another kind of street word follows.
            if ($w === 'st' && array_filter(array_slice($words, $i + 1), static fn ($x) => self::isStreetWord($x) && strtolower(rtrim($x, '.')) !== 'st')) {
                continue;
            }
            $end = $i;
            break;
        }
        if ($end === null) {
            // No street word: the whole line is the street, and nothing may follow it.
            if ($state !== '' || $zip !== '') {
                return null;
            }
            return ['address' => self::titleStreet($s), 'city' => '', 'state' => '', 'zip' => ''];
        }
        $j = $end + 1;
        if (isset($words[$j]) && in_array(strtolower($words[$j]), self::DIRECTIONS, true)) {
            $j++;
        }
        if (isset($words[$j]) && preg_match('/^(?:apt|apartment|unit|suite|ste|#)\.?(.*)$/i', $words[$j], $u)) {
            $j += $u[1] === '' ? 2 : 1;
            if (isset($words[$j]) && preg_match('/^[NSEW]$/', $words[$j])) {
                $j++; // tower letter: "Apt 1703 S"
            }
        } elseif (isset($words[$j]) && str_starts_with($words[$j], '#')) {
            $j++;
        }
        $line = implode(' ', array_slice($words, 0, $j));
        $city = trim(implode(' ', array_slice($words, $j)));
        if ($city !== '' && preg_match('/\d/', $city)) {
            return null;
        }
        if ($city !== '' && $state === '') {
            $state = self::homeState();
        }
        return ['address' => self::titleStreet($line), 'city' => $city !== '' ? self::titleCity($city) : '', 'state' => $state, 'zip' => $zip];
    }

    /** "5501 seminary rd" → "5501 Seminary Rd"; deliberate casing is kept. */
    private static function titleStreet(string $line): string
    {
        return $line !== mb_strtolower($line) && $line !== mb_strtoupper($line) ? $line : ucwords(mb_strtolower($line));
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

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
        return implode(' ', $words);
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

    /**
     * Understands "78704", "Austin, TX", "austin tx", "San Antonio, Texas"
     * and "5500 Grand Lake Dr, San Antonio, TX 78244".
     *
     * @return array{type:string, key:string, label:string, zip?:string, city?:string, state?:string, address?:string}|null
     */
    public static function parseLocation(string $q): ?array
    {
        $q = trim(preg_replace('/\s+/', ' ', $q) ?? '');
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
        // "City, ST" or "City ST" or "City, State name"
        if (preg_match('/^(.+?)[,\s]\s*([A-Za-z]{2})$/', $q, $m) && ($st = self::stateAbbr($m[2]))) {
            $city = self::titleCity(rtrim($m[1], ', '));
        } elseif (str_contains($q, ',')) {
            [$c, $s] = array_map('trim', explode(',', $q, 2));
            $st = self::stateAbbr($s);
            $city = self::titleCity($c);
        } else {
            $st = null;
            $city = '';
            foreach (self::STATES as $abbr => $name) {
                if (preg_match('/^(.+)\s+' . preg_quote($name, '/') . '$/i', $q, $m)) {
                    $st = $abbr;
                    $city = self::titleCity($m[1]);
                    break;
                }
            }
        }
        if (empty($st) || $city === '') {
            return null;
        }
        return [
            'type' => 'city', 'city' => $city, 'state' => $st,
            'key' => 'city:' . $st . ':' . mb_strtolower($city), 'label' => $city . ', ' . $st,
        ];
    }

    /** @return array{address:string, city:string, state:string, zip:string}|null */
    public static function parseFullAddress(string $q): ?array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $q)), 'strlen'));
        if (count($parts) < 3) {
            // "123 Main St Austin TX 78704" — too ambiguous to split reliably.
            return null;
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

    public static function slug(string $s, string $sep = '-', bool $lower = false): string
    {
        $s = preg_replace('/[^A-Za-z0-9]+/', $sep, trim($s)) ?? '';
        $s = trim($s, $sep);
        return $lower ? strtolower($s) : $s;
    }
}

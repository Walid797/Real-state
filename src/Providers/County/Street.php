<?php
declare(strict_types=1);

namespace App\Providers\County;

use App\Address;

/** Matching a listing's street line against a county's address parts. */
final class Street
{
    /** "5863 Monticello Rd Unit 2" → number 5863, words "monticello rd", unit "2". @return array{num:string, sfx:string, street:string, unit:?string}|null */
    public static function split(string $line): ?array
    {
        if (!preg_match('/^\s*(\d+)([a-z])?\s+(.+)$/i', trim($line), $m)) {
            return null;
        }
        $rest = $m[3];
        $unit = null;
        if (preg_match('/\s+(?:#|unit|apt|ste|suite|cnd|condo)\.?\s*([a-z0-9-]+)\s*$/i', $rest, $u)) {
            $unit = strtolower($u[1]);
            $rest = substr($rest, 0, -strlen($u[0]));
        }
        return ['num' => $m[1], 'sfx' => strtolower($m[2] ?? ''), 'street' => Address::normalizeStreet($rest), 'unit' => $unit];
    }

    /** Does the county's street (prefix, name, type, suffix) name the listing's street? */
    public static function same(array $a, string $name, string $full): bool
    {
        $street = self::plain($a['street']);
        $full = self::plain(Address::normalizeStreet($full));
        $name = self::plain(Address::normalizeStreet($name));
        return $full === $street || ($name !== '' && str_contains(' ' . $street . ' ', ' ' . $name . ' '));
    }

    /** "2nd" and "2" are the same street number word (Miami-Dade writes "SE 2 ST"). */
    private static function plain(string $s): string
    {
        return trim((string) preg_replace('/\b(\d+)(?:st|nd|rd|th)\b/', '$1', $s));
    }

    /** 0 = another unit of the building; higher = better match. */
    public static function unitRank(array $a, ?string $unit): int
    {
        $unit = strtolower(trim((string) $unit));
        if ($a['unit'] !== null && $unit !== '' && ltrim($unit, '0') !== ltrim($a['unit'], '0')) {
            return 0;
        }
        return $unit === (string) $a['unit'] || ($a['unit'] !== null && ltrim($unit, '0') === ltrim($a['unit'], '0')) ? 3 : 1;
    }
}

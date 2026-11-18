<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Reads the facts block someone copied from a listing page (Homes.com, Zillow, Realtor.com,
 * Redfin) and pulls out what the site uses: tax, HOA, size, year, rooms, parking, basement,
 * parcel number, plus every line grouped under its heading for display.
 *
 * It only reads text the person pasted — nothing is fetched from those sites.
 */
final class ListingText
{
    public const MAX = 20000;

    /** Headings these sites use for groups of facts. */
    private const HEADINGS = [
        'property details', 'home details', 'facts & features', 'facts and features', 'overview', 'home design', 'interior spaces', 'interior',
        'kitchen', 'bedrooms and bathrooms', 'bedrooms & bathrooms', 'parking', 'utilities', 'listing and financial details', 'financial',
        'community details', 'amenities', 'pet policy', 'exterior', 'exterior features', 'lot', 'lot details', 'construction', 'heating',
        'cooling', 'appliances', 'features', 'building', 'hoa', 'hoa & financial', 'location', 'school', 'schools', 'property', 'other',
    ];

    /** "Label" on one line, value on the next (Homes.com). */
    private const PAIRS = ['home type', 'est. annual taxes', 'annual taxes', 'year built', 'hoa fees', 'hoa fee', 'lot size', 'price per sq ft', 'sq ft', 'heating', 'cooling', 'parking'];

    /**
     * @return array{fields: array<string,mixed>, details: array<string,mixed>, facts: array<string,string[]>}
     */
    public static function parse(string $text): array
    {
        $text = mb_substr(str_replace(["\r\n", "\r"], "\n", $text), 0, self::MAX);
        $lines = array_values(array_filter(array_map(static fn ($l) => trim(preg_replace('/\s+/u', ' ', $l) ?? ''), explode("\n", $text)), static fn ($l) => $l !== ''));

        // Group lines under headings; join "Label" + next-line value pairs into "Label: value".
        $facts = [];
        $section = 'Details';
        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            $l = $lines[$i];
            $low = mb_strtolower(rtrim($l, ':'));
            if (in_array($low, self::HEADINGS, true)) {
                $section = rtrim($l, ':');
                continue;
            }
            if (in_array($low, self::PAIRS, true) && isset($lines[$i + 1]) && !in_array(mb_strtolower($lines[$i + 1]), self::HEADINGS, true)) {
                $l = rtrim($l, ':') . ': ' . $lines[++$i];
            }
            if (mb_strlen($l) > 300) {
                $l = mb_substr($l, 0, 300) . '…';
            }
            $facts[$section][] = $l;
        }
        // Read values from the joined "Label: value" lines, so both page layouts look the same.
        $all = implode("\n", array_merge(...array_values($facts ?: [[]])));

        $money = static function (?string $s): ?int {
            if ($s === null || !preg_match('/\$?\s*([\d,]+(?:\.\d+)?)/', $s, $m)) {
                return null;
            }
            $v = (float) str_replace(',', '', $m[1]);
            return $v > 0 ? (int) round($v) : null;
        };
        $grab = static function (string $re) use ($all): ?string {
            return preg_match($re, $all, $m) ? trim($m[1]) : null;
        };

        $f = [];
        // Tax: "Est. Annual Taxes: $4,417", "Annual tax amount: $4,417", "Taxes: $4,417 (2025)"
        $tax = $money($grab('/(?:est\.?\s*)?annual\s+tax(?:es|\s+amount)?\s*:?\s*(\$[\d,]+)/i') ?? $grab('/^tax(?:es)?\s*:\s*(\$[\d,]+)/im'));
        if ($tax !== null && $tax < 200000) {
            $f['tax_annual'] = $tax;
        }
        // HOA: "$340 Monthly HOA Fees", "HOA fee: $340 monthly", "$1,020 quarterly"
        if (preg_match('/hoa[^\n$]*?(\$[\d,]+)\s*(?:\/\s*)?(monthly|month|mo|quarterly|quarter|annually|yearly|year|annual)?|(\$[\d,]+)\s*(monthly|quarterly|annual|yearly)\s+hoa/i', $all, $m)) {
            $amt = $money($m[1] ?: $m[3]);
            $per = strtolower(($m[2] ?? '') ?: ($m[4] ?? 'monthly'));
            if ($amt !== null) {
                $f['hoa_fee'] = (int) round(match (true) {
                    str_starts_with($per, 'quarter') => $amt / 3,
                    in_array($per, ['annually', 'yearly', 'year', 'annual'], true) => $amt / 12,
                    default => $amt,
                });
            }
        } elseif (preg_match('/\b(?:no hoa|hoa\s*:\s*(?:none|no))\b/i', $all)) {
            $f['hoa_fee'] = 0;
        }
        if (($y = $grab('/(?:year built|built in)\s*:?\s*(?:built in\s*)?(1[89]\d\d|20\d\d)/i')) !== null) {
            $f['year_built'] = (int) $y;
        }
        $sq = $grab('/([\d,]{3,7})[ \t]*(?:sq\.?[ \t]*ft|sqft|square feet)\b(?![ \t]*lot)/i') ?? $grab('/(?:livable area|living area|interior livable area)\s*:?\s*([\d,]{3,7})/i');
        if ($sq !== null && ($v = (int) str_replace(',', '', $sq)) >= 200 && $v < 50000) {
            $f['sqft'] = $v;
        }
        $beds = $grab('/(\d{1,2})[ \t]+(?:main level[ \t]+|upper level[ \t]+|lower level[ \t]+)?bedrooms?\b/i') ?? $grab('/bedrooms?\s*:\s*(\d{1,2})/i');
        if ($beds !== null) {
            $f['beds'] = (int) $beds;
        }
        $full = $grab('/(\d{1,2})[ \t]+full[ \t]+bath(?:room)?s?\b/i') ?? $grab('/full bath(?:room)?s?\s*:\s*(\d{1,2})/i');
        $half = $grab('/(\d{1,2})[ \t]+half[ \t]+bath(?:room)?s?\b/i') ?? $grab('/(?:half|1\/2) bath(?:room)?s?[ \t]*:[ \t]*(\d{1,2})/i');
        if ($full !== null) {
            $f['baths'] = (int) $full + 0.5 * (int) ($half ?? 0);
        } elseif (($b = $grab('/bathrooms?\s*:\s*(\d{1,2}(?:\.\d)?)/i')) !== null) {
            $f['baths'] = (float) $b;
        }
        if (($g = $grab('/(\d{1,2})\s*(?:-|\s)?car\s+(?:attached\s+|detached\s+)?garage/i') ?? $grab('/garage\s+spaces?\s*:\s*(\d{1,2})/i') ?? $grab('/(\d{1,2})\s+(?:attached|detached)?\s*garage\s+spaces?/i')) !== null) {
            $f['garage_spaces'] = (int) $g;
        } elseif (preg_match('/\bno garage\b/i', $all)) {
            $f['garage_spaces'] = 0;
        }
        if (preg_match('/\b(?:no basement|basement\s*:\s*(?:none|no))\b/i', $all)) {
            $f['has_basement'] = 0;
        } elseif (preg_match('/\b(?:finished|unfinished|partial|full|walk-?out|daylight)\s+basement\b|\bbasement\s*:\s*(?!none|no\b)\w/i', $all)) {
            $f['has_basement'] = 1;
        }
        if (($t = $grab('/home type\s*:\s*([^\n]+)/i') ?? $grab('/(?:property|home) (?:type|subtype)\s*:\s*([^\n]+)/i')) !== null) {
            $map = ['condo' => 'Condo', 'condominium' => 'Condo', 'townhouse' => 'Townhouse', 'townhome' => 'Townhouse', 'single family' => 'Single Family',
                'multi-family' => 'Multi-Family', 'multi family' => 'Multi-Family', 'manufactured' => 'Manufactured', 'land' => 'Land', 'apartment' => 'Apartment'];
            foreach ($map as $k => $v) {
                if (stripos($t, $k) !== false) {
                    $f['property_type'] = $v;
                    break;
                }
            }
        }

        $d = array_filter([
            'parcel_id' => $grab('/(?:assessor parcel number|parcel number|apn|parcel id|tax id)\s*:?\s*([A-Z0-9][A-Z0-9 .\-]{3,30})/i'),
            'levels' => $grab('/property has (\d+|one|two|three) levels?/i') ?? $grab('/levels?\s*:\s*([^\n]{1,30})/i') ?? $grab('/stories\s*:\s*([^\n]{1,20})/i'),
            'fireplaces' => $grab('/(\d{1,2})[ \t]+fireplaces?\b/i') ?? $grab('/fireplace(?:\(s\)|s)?[ \t]*:[ \t]*([^\n]{1,30})/i'),
            'hoa_includes' => $grab('/(?:association fees? includes?|hoa fee includes?|fee includes)\s*:?\s*([^\n]{3,200})/i'),
            'subdivision' => $grab('/(?:subdivision(?: name)?)\s*:\s*([^\n]{2,80})/i') ?? $grab('/^([^\n]{2,60}) subdivision$/im'),
            'pets' => $grab('/((?:dogs|cats|pets)[^\n]{0,40}allowed|no pets[^\n]{0,30})/i'),
            'coming_soon' => $grab('/coming soon on\s*([0-9\/\-]{6,10})/i'),
            'style' => $grab('/^([A-Za-z][A-Za-z \-]{2,30}) architecture$/im') ?? $grab('/architectural style\s*:\s*([^\n]{2,40})/i'),
        ], static fn ($v) => $v !== null && $v !== '');

        return ['fields' => $f, 'details' => $d, 'facts' => $facts];
    }
}

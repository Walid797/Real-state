<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Reads search filters people type into the search box, the way listing sites do:
 *
 *   "3 bed townhouse in Falls Church under 500k"
 *   "2+ baths, $300k–$450k, Fairfax County; Arlington County"
 *   "sold homes in 22041 built after 1990"
 *   "۳ خوابه زیر ۵۰۰ هزار در Fairfax"
 *
 * What is recognised becomes a normal filter (the form's own value always wins) and is
 * removed from the text; what is left is the place. A street address is never taken apart:
 * "3 Bath St" stays an address.
 */
final class SearchQuery
{
    private const BED = '(?:bedrooms|bedroom|bdrms|bdrm|beds|bed|bds|bdr|bd|br|اتاق\s*خوابه|اتاق\s*خواب|خوابه|خواب|اتاق)';
    private const BATH = '(?:bathrooms|bathroom|baths|bath|bth|ba|حمامه|حمام|سرویس)';
    private const NUM = '(\d+(?:\.\d+)?)';
    /** A money amount: 450k, $1.2m, 450,000, 450 thousand, ۴۵۰ هزار. */
    private const MONEY = '\$?\s*(\d[\d,]*(?:\.\d+)?)\s*(k|m|mm|thousand|million|هزار|میلیون)?(?![\d\w])';
    private const STREET_SUFFIX = 'st|street|rd|road|ave|avenue|dr|drive|ln|lane|ct|court|pl|place|blvd|way|ter|cir|pike|hwy|pkwy|trl|sq';

    /** Words for property types → the site's types. */
    private const TYPES = [
        'Townhouse' => 'town\s*houses?|town\s*homes?|townhouses?|townhomes?|row\s*houses?|تاون\s*هاوس|تاونهاوس',
        'Condo' => 'condos|condo(?=\s+(?:in|near|around|under|over|with|for)\b)|کاندو|آپارتمان',
        'Single Family' => 'single[\s-]*family(?:\s+homes?|\s+houses?)?|detached\s+(?:homes?|houses?)|houses|sfh|خانه\s*ویلایی|ویلایی',
        'Multi-Family' => 'multi[\s-]*family|duplex(?:es)?|triplex(?:es)?|fourplex(?:es)?|quadplex(?:es)?',
        'Manufactured' => 'manufactured(?:\s+homes?)?|mobile\s+homes?',
        'Land' => 'vacant\s+land|land\s+lots?|lots\s+for\s+sale|زمین',
    ];

    /**
     * @return array{q:string, filters:array<string,mixed>, understood:list<string>}
     */
    public static function understand(string $raw): array
    {
        $q = ' ' . SmartLocation::normalize($raw) . ' ';
        $filters = [];
        $said = [];
        $none = ['q' => trim($q), 'filters' => [], 'understood' => []];
        if (trim($q) === '' || self::isAddress(trim($q))) {
            return $none;
        }
        $take = static function (string $re, callable $fn) use (&$q): void {
            $q = preg_replace_callback('/' . $re . '/iu', static function ($m) use ($fn) {
                return $fn($m) === false ? $m[0] : ' ';
            }, $q) ?? $q;
        };

        // Bedrooms and bathrooms: "3 bed", "3+ beds", "3-4 bedrooms", "3 to 4 br", "at least 2 baths", "۳ تا ۴ خواب".
        foreach (['beds' => self::BED, 'baths' => self::BATH] as $k => $unit) {
            $take('(?<![\w$])' . self::NUM . '\s*(?:-|–|to|تا)\s*' . self::NUM . '\s*\+?\s*' . $unit . '(?![\w])',
                static function ($m) use ($k, &$filters) {
                    [$a, $b] = [(float) $m[1], (float) $m[2]];
                    if ($a > 20 || $b > 20) {
                        return false;
                    }
                    $filters[$k . '_min'] = min($a, $b);
                    $filters[$k . '_max'] = max($a, $b);
                });
            $take('(?:(at\s+least|min(?:imum)?|حداقل)\s+)?(?<![\w$.])' . self::NUM . '\s*(\+|plus|or\s+more|و\s+بیشتر)?\s*' . $unit . '(?![\w])(?!\s+(?:' . self::STREET_SUFFIX . ')\b)',
                static function ($m) use ($k, &$filters) {
                    $n = (float) $m[2];
                    if ($n > 20 || isset($filters[$k . '_min'])) {
                        return false;
                    }
                    $filters[$k . '_min'] = $n;
                });
        }

        // Price: "300k-450k", "$300,000 to $450,000", "between 300k and 450k".
        $take('(?:between\s+|بین\s+)?' . self::MONEY . '\s*(?:-|–|to|and|تا|و)\s*' . self::MONEY,
            static function ($m) use (&$filters) {
                $a = self::money($m[1], $m[2] ?? '', true);
                $b = self::money($m[3], $m[4] ?? '', true);
                $marked = str_contains($m[0], '$') || ($m[2] ?? '') !== '' || ($m[4] ?? '') !== '';
                if (!$marked || $a === null || $b === null) {
                    return false; // a pair of bare numbers (ZIP codes, house numbers) is not a price
                }
                $filters['price_min'] = min($a, $b);
                $filters['price_max'] = max($a, $b);
            });
        // "under 500k", "below $450,000", "max 600k", "زیر ۵۰۰ هزار".
        $take('(?:under|below|less\s+than|max(?:imum)?|up\s+to|at\s+most|no\s+more\s+than|<=?|زیر|کمتر\s+از|حداکثر)\s*' . self::MONEY . '(?!\s*' . self::BED . ')(?!\s*' . self::BATH . ')(?!\s*(?:sq|sf|square|فوت))',
            static function ($m) use (&$filters) {
                $v = self::money($m[1], $m[2] ?? '', true);
                if ($v === null || isset($filters['price_max'])) {
                    return false;
                }
                $filters['price_max'] = $v;
            });
        // "over 300k", "above $250,000", "min 300k", "from 300k", "بالای ۳۰۰ هزار".
        $take('(?:over|above|more\s+than|min(?:imum)?|at\s+least|starting\s+at|>=?|بالای|بیشتر\s+از)\s*' . self::MONEY . '(?!\s*' . self::BED . ')(?!\s*' . self::BATH . ')(?!\s*(?:sq|sf|square|فوت))',
            static function ($m) use (&$filters) {
                $v = self::money($m[1], $m[2] ?? '', true);
                if ($v === null || isset($filters['price_min'])) {
                    return false;
                }
                $filters['price_min'] = $v;
            });

        // Living area: "1500+ sqft", "over 2,000 sq ft", "1200-1800 sqft".
        $take('(?:(?:over|above|at\s+least|min(?:imum)?)\s+)?(\d[\d,]*)\s*(?:-|–|to)\s*(\d[\d,]*)\s*(?:sq\.?\s*ft|sqft|square\s+feet|sf|فوت(?:\s*مربع)?)(?![\w])',
            static function ($m) use (&$filters) {
                $filters['sqft_min'] = (float) str_replace(',', '', $m[1]);
                $filters['sqft_max'] = (float) str_replace(',', '', $m[2]);
            });
        $take('(?:(over|above|at\s+least|min(?:imum)?)|(under|below|max(?:imum)?|up\s+to))?\s*(\d[\d,]*)\s*\+?\s*(?:sq\.?\s*ft|sqft|square\s+feet|sf|فوت(?:\s*مربع)?)(?![\w])',
            static function ($m) use (&$filters) {
                $v = (float) str_replace(',', '', $m[3]);
                if ($v < 100 || isset($filters['sqft_min']) || isset($filters['sqft_max'])) {
                    return false;
                }
                $filters[!empty($m[2]) ? 'sqft_max' : 'sqft_min'] = $v;
            });

        // Year built: "built after 1990", "newer than 2000", "built before 1950".
        $take('(?:built\s+)?(?:after|since|newer\s+than|ساخت\s+بعد\s+از)\s+((?:19|20)\d\d)', static function ($m) use (&$filters) {
            $filters['year_min'] = (float) $m[1];
        });
        $take('(?:built\s+)?(?:before|older\s+than|ساخت\s+قبل\s+از)\s+((?:18|19|20)\d\d)', static function ($m) use (&$filters) {
            $filters['year_max'] = (float) $m[1];
        });

        // Garage and HOA.
        $take('(\d)\s*(?:\+|or\s+more)?\s*(?:car\s+)?garages?(?![\w])', static function ($m) use (&$filters) {
            $filters['garage_min'] = (float) $m[1];
        });
        $take('(?:with\s+(?:a\s+)?)?garage(?![\w])|پارکینگ', static function () use (&$filters) {
            $filters['garage_min'] ??= 1.0;
        });
        $take('no\s+hoa|without\s+(?:an?\s+)?hoa|بدون\s+hoa', static function () use (&$filters) {
            $filters['hoa_max'] = 0.0;
        });

        // Property types.
        foreach (self::TYPES as $type => $words) {
            $take('(?<![\w])(?:' . $words . ')(?![\w])', static function () use ($type, &$filters) {
                $filters['types'][] = $type;
            });
        }
        if (isset($filters['types'])) {
            $filters['types'] = array_values(array_unique($filters['types']));
        }

        // Listing kinds and sold / for sale.
        $listing = [
            'new' => 'new\s+listings?|just\s+listed|newly\s+listed|آگهی\s*(?:های)?\s*جدید',
            'reduced' => 'price\s+(?:reduced|cuts?|drops?)|reduced(?:\s+price)?|کاهش\s+قیمت',
            'foreclosure' => 'foreclosures?|short\s+sales?|bank[\s-]+owned|reo|توقیفی',
            'auction' => 'auctions?|حراج|مزایده',
            'fixer' => 'fixer[\s-]*uppers?|fixers?|needs?\s+work|handyman\s+specials?|investor\s+specials?|تعمیری|نیاز\s+به\s+(?:تعمیر|بازسازی)',
            'basement' => '(?:with\s+(?:a\s+)?)?(?:finished\s+)?basements?|زیرزمین',
        ];
        foreach ($listing as $k => $words) {
            $take('(?<![\w])(?:' . $words . ')(?![\w])', static function () use ($k, &$filters) {
                $filters['listing'][] = $k;
            });
        }
        $take('(?<![\w])(?:recently\s+sold|sold\s+(?:homes?|houses?|properties)|sold|فروخته\s*شده|فروش\s*رفته)(?![\w])', static function () use (&$filters) {
            $filters['mode'] = 'sold';
        });
        $take('(?<![\w])(?:for\s+sale|on\s+the\s+market|برای\s+فروش|فروشی)(?![\w])', static function () use (&$filters) {
            $filters['mode'] ??= 'sale';
        });

        $connector = preg_match('/\s(?:in|near|around|در|نزدیک)\s/iu', $q) === 1;
        if (!$filters && !$connector) {
            return $none;
        }
        // What is left is the place. Drop the glue words around it.
        $glue = '(?:homes?|houses?|properties|listings|real\s+estate|in|near|around|at|within|with|and|for|of|the|a|an|plus|or|to|خانه|خانه\s*ها|ملک|در|نزدیک|با|و)';
        $place = preg_replace('/\s*[,;]\s*(?=[,;]|$)/u', '', trim(preg_replace('/\s+/u', ' ', $q) ?? '')) ?? '';
        for ($i = 0; $i < 4; $i++) {
            $place = trim(preg_replace(['/^(?:' . $glue . ')(?:\s+|$)/iu', '/(?:^|\s+)(?:' . $glue . ')$/iu', '/^[\s,;]+|[\s,;]+$/u'], '', $place) ?? $place);
        }
        $place = preg_replace('/\s*,\s*/u', ', ', preg_replace('/\s+/u', ' ', $place) ?? $place) ?? $place;
        return ['q' => $place, 'filters' => $filters, 'understood' => self::describe($filters)];
    }

    /** A street address — or anything that starts like one — is left exactly as typed. */
    public static function isAddress(string $q): bool
    {
        if (!preg_match('/^(\d+[a-z]?)\s+([^\s,]+)/iu', $q, $m)) {
            return false;
        }
        // "3 bed …", "2+ baths …", "300k …", "3-4 beds …": a filter, not a house number.
        if (preg_match('/^\d+(?:\.\d+)?\s*(?:\+|-|–|to)/u', $q) || preg_match('/^\d[\d,.]*\s*(?:k|m)\b/iu', $q)) {
            return false;
        }
        if (preg_match('/^' . self::BED . '$|^' . self::BATH . '$/iu', $m[2])) {
            // "3 Bath St" is an address; "3 bath townhouse" is not.
            return (bool) preg_match('/^\d+[a-z]?\s+\S+\s+(?:' . self::STREET_SUFFIX . ')\b\.?(?:\s|,|$)/iu', $q);
        }
        if (preg_match('/^(?:sq|sqft|sf|car|garage)/iu', $m[2])) {
            return false;
        }
        return !preg_match('/^\d{5}$/', $m[1]); // a leading ZIP code is a place, not a house number
    }

    /** "450k" → 450000. Bare numbers after a price word below 10,000 mean thousands ("under 500"). */
    private static function money(string $n, string $unit, bool $priceWord): ?float
    {
        $v = (float) str_replace(',', '', $n);
        $unit = mb_strtolower($unit);
        $v *= match ($unit) {
            'k', 'thousand', 'هزار' => 1000,
            'm', 'mm', 'million', 'میلیون' => 1000000,
            default => ($priceWord && $v > 0 && $v < 10000 ? 1000 : 1),
        };
        return $v >= 1000 && $v <= 1000000000 ? $v : null;
    }

    /** @return list<string> short labels, e.g. ["3+ bd", "up to $500K", "Townhouse"] */
    public static function describe(array $f): array
    {
        $out = [];
        $span = static function (?float $lo, ?float $hi, callable $fmt, string $unit): ?string {
            if ($lo !== null && $hi !== null) {
                return $lo == $hi ? $fmt($lo) . $unit : $fmt($lo) . '–' . $fmt($hi) . $unit;
            }
            if ($lo !== null) {
                return $fmt($lo) . '+' . $unit;
            }
            return $hi !== null ? t('up to %s', $fmt($hi) . $unit) : null;
        };
        $out[] = $span($f['beds_min'] ?? null, $f['beds_max'] ?? null, 'trim_num', ' ' . t('bd'));
        $out[] = $span($f['baths_min'] ?? null, $f['baths_max'] ?? null, 'trim_num', ' ' . t('ba'));
        $out[] = $span($f['price_min'] ?? null, $f['price_max'] ?? null, 'money_short', '');
        $out[] = $span($f['sqft_min'] ?? null, $f['sqft_max'] ?? null, static fn ($v) => number_format($v), ' ' . t('sqft'));
        if (isset($f['year_min'])) {
            $out[] = t('built %d or later', (int) $f['year_min']);
        }
        if (isset($f['year_max'])) {
            $out[] = t('built %d or earlier', (int) $f['year_max']);
        }
        if (isset($f['garage_min'])) {
            $out[] = t('%d+ parking', (int) $f['garage_min']);
        }
        if (isset($f['hoa_max']) && (float) $f['hoa_max'] === 0.0) {
            $out[] = t('no HOA');
        }
        foreach ($f['types'] ?? [] as $type) {
            $out[] = t($type);
        }
        foreach ($f['listing'] ?? [] as $l) {
            $out[] = t(\App\PropertyRepo::LISTING[$l] ?? $l);
        }
        if (($f['mode'] ?? '') === 'sold') {
            $out[] = t('Sold');
        }
        return array_values(array_filter($out));
    }
}

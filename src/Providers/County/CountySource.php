<?php
declare(strict_types=1);

namespace App\Providers\County;

/**
 * One jurisdiction's (or one state's) public property records. Each source answers for the
 * homes it covers; App\Services\CountyRecords picks the source and applies what it returns.
 */
interface CountySource
{
    /** Short id stored with each record, e.g. "fairfax". */
    public static function id(): string;

    /** Shown on the home page and in Settings, e.g. "Fairfax County public records". */
    public static function name(): string;

    /** What this source covers, for the Settings page, e.g. "Fairfax County, VA". */
    public static function area(): string;

    /** Could this home be covered? Cheap: no network. A lookup that finds nothing settles it. */
    public static function covers(array $p): bool;

    /** SQL condition (on `properties`) picking the homes covers() may accept, for the scheduler. */
    public static function candidates(): string;

    /**
     * The record for one home, or ['none' => true] when the address is not in the source.
     * @return array{facts:array, county:array}|array{none:true}|null
     * @throws \RuntimeException when the source does not answer
     */
    public static function home(array $p): ?array;

    /** Is this recorded sale a fair-market one, fit to price other homes by? */
    public static function fairSale(string $type, int $price): bool;

    /**
     * Fair-market sales recorded in one ZIP since $days ago, as rows for PropertyRepo::upsert().
     * Empty when the source has no sale prices.
     * @throws \RuntimeException when the source does not answer
     */
    public static function salesInZip(string $zip, int $days = 365, float $budgetSeconds = 60): array;
}

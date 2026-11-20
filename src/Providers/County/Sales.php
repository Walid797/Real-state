<?php
declare(strict_types=1);

namespace App\Providers\County;

/**
 * Turns one parcel's recorded sales into the row fields PropertyRepo::upsert() takes: the latest
 * fair-market sale (for comparables) and every sale as a history event (for flip detection,
 * where the purchase is often a foreclosure or cash sale the county does not mark as fair).
 */
final class Sales
{
    /**
     * @param list<array{date:?string, price:int, fair:bool}> $sales any order
     * @return array{last_sale_price?:int, last_sale_date?:string, events:list<array>}
     */
    public static function fields(array $sales): array
    {
        $sales = array_values(array_filter($sales, static fn ($s) => !empty($s['date']) && (int) $s['price'] > 1000));
        usort($sales, static fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));
        $out = ['events' => []];
        foreach ($sales as $s) {
            $out['events'][] = ['event' => 'sold', 'price' => (int) $s['price'], 'date' => $s['date']];
            if (!isset($out['last_sale_price']) && $s['fair']) {
                $out['last_sale_price'] = (int) $s['price'];
                $out['last_sale_date'] = (string) $s['date'];
            }
        }
        return $out;
    }
}

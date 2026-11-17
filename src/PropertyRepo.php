<?php
declare(strict_types=1);

namespace App;

/**
 * The local property database. Everything a provider returns — and everything
 * typed in by hand — lands here once, so later searches, comps and alerts
 * read from MySQL instead of spending API requests.
 */
final class PropertyRepo
{
    /** Columns a caller may write through upsert(). */
    private const WRITABLE = [
        'county', 'lat', 'lng', 'property_type', 'beds', 'baths', 'sqft', 'lot_sqft', 'year_built', 'hoa_fee',
        'status', 'list_price', 'listed_date', 'days_on_market', 'listing_type', 'last_sale_price', 'last_sale_date',
        'est_value', 'est_value_low', 'est_value_high', 'est_rent', 'est_source', 'est_at',
        'tax_annual', 'assessed_value', 'mls_name', 'mls_number', 'agent_name', 'agent_phone', 'agent_email',
        'office_name', 'photo_url', 'description', 'source_id', 'source_url',
    ];

    public const TYPES = ['Single Family', 'Condo', 'Townhouse', 'Multi-Family', 'Manufactured', 'Apartment', 'Land'];

    public const SORTS = [
        'newest' => 'Newest',
        'price_asc' => 'Price, low to high',
        'price_desc' => 'Price, high to low',
        'ppsf_asc' => '$/sqft, low to high',
        'sqft_desc' => 'Largest',
    ];

    /**
     * Insert or merge one property.
     *
     * @param array $p normalised fields; needs address + (zip or city/state)
     * @return array{id:int, created:bool, old_price:?int, new_price:?int}
     */
    public static function upsert(array $p, string $source): array
    {
        $address = trim((string) ($p['address'] ?? ''));
        $city = Address::titleCity((string) ($p['city'] ?? ''));
        $state = strtoupper(substr(trim((string) ($p['state'] ?? '')), 0, 2));
        $zip = substr(preg_replace('/\D/', '', (string) ($p['zip'] ?? '')) ?? '', 0, 5);
        if ($address === '' || ($zip === '' && ($city === '' || $state === ''))) {
            throw new \InvalidArgumentException('A property needs a street address and a ZIP (or city and state).');
        }
        $hash = Address::hash($address, $city, $state, $zip);
        $now = now();

        $data = [];
        foreach (self::WRITABLE as $col) {
            if (array_key_exists($col, $p) && $p[$col] !== null && $p[$col] !== '') {
                $data[$col] = self::clean($col, $p[$col]);
            }
        }
        $data = array_filter($data, static fn ($v) => $v !== null);

        $existing = Db::one('SELECT * FROM properties WHERE addr_hash = ?', [$hash]);
        $old = $new = null;

        if ($existing === null) {
            $details = isset($p['details']) && is_array($p['details']) ? $p['details'] : [];
            $id = Db::insert('properties', $data + [
                'addr_hash' => $hash,
                'address' => mb_substr($address, 0, 190),
                'city' => mb_substr($city, 0, 100),
                'state' => $state,
                'zip' => $zip,
                'status' => $data['status'] ?? 'off_market',
                'source' => $source,
                'details' => $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'updated_at' => $now,
            ]);
            if (($data['status'] ?? '') === 'for_sale' && !empty($data['list_price'])) {
                self::addEvent($id, 'listed', (int) $data['list_price'], $data['listed_date'] ?? today(), $source);
            }
            $created = true;
        } else {
            $id = (int) $existing['id'];
            $created = false;
            $wasForSale = $existing['status'] === 'for_sale';
            $isForSale = ($data['status'] ?? $existing['status']) === 'for_sale';
            $oldPrice = (int) ($existing['list_price'] ?? 0);
            $newPrice = (int) ($data['list_price'] ?? $oldPrice);

            if ($isForSale && $newPrice > 0) {
                if (!$wasForSale) {
                    self::addEvent($id, $oldPrice > 0 ? 'relisted' : 'listed', $newPrice, $data['listed_date'] ?? today(), $source);
                } elseif ($oldPrice > 0 && $newPrice !== $oldPrice) {
                    self::addEvent($id, 'price_change', $newPrice, today(), $source);
                    $old = $oldPrice;
                    $new = $newPrice;
                }
            } elseif ($wasForSale && isset($data['status']) && $data['status'] !== 'for_sale' && $data['status'] !== 'pending') {
                self::addEvent($id, 'removed', $oldPrice, today(), $source);
            }

            // Fill in an empty city/state/zip, never blank out a known one.
            foreach (['city' => $city, 'state' => $state, 'zip' => $zip] as $col => $val) {
                if ($val !== '' && (string) $existing[$col] === '') {
                    $data[$col] = $val;
                }
            }
            if (isset($p['details']) && is_array($p['details']) && $p['details']) {
                $prev = json_decode((string) ($existing['details'] ?? ''), true);
                $merged = array_replace(is_array($prev) ? $prev : [], $p['details']); // newer block replaces the older one, key by key
                $data['details'] = json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            // A hand-entered record keeps its "manual" origin unless a provider now knows the house too.
            if ($source !== 'manual' && $existing['source'] === 'manual') {
                $data['source'] = $source;
            }
            $data['last_seen_at'] = $now;
            $data['updated_at'] = $now;
            Db::update('properties', $data, $id);
        }

        if (!empty($data['last_sale_price']) && !empty($data['last_sale_date'])) {
            self::addEvent($id, 'sold', (int) $data['last_sale_price'], (string) $data['last_sale_date'], $source);
        }
        foreach ((array) ($p['events'] ?? []) as $ev) {
            if (!empty($ev['date']) && !empty($ev['event'])) {
                self::addEvent($id, (string) $ev['event'], (int) ($ev['price'] ?? 0), (string) $ev['date'], $source);
            }
        }

        return ['id' => $id, 'created' => $created, 'old_price' => $old, 'new_price' => $new];
    }

    private static function clean(string $col, mixed $v): mixed
    {
        switch ($col) {
            case 'lat':
            case 'lng':
                return is_numeric($v) ? round((float) $v, 6) : null;
            case 'beds':
            case 'baths':
                return is_numeric($v) && $v >= 0 && $v < 100 ? round((float) $v, 1) : null;
            case 'hoa_fee':
                return is_numeric($v) && $v >= 0 ? round((float) $v, 2) : null;
            case 'sqft':
            case 'lot_sqft':
            case 'list_price':
            case 'last_sale_price':
            case 'est_value':
            case 'est_value_low':
            case 'est_value_high':
            case 'est_rent':
            case 'tax_annual':
            case 'assessed_value':
            case 'days_on_market':
                return is_numeric($v) && $v > 0 && $v < 4000000000 ? (int) round((float) $v) : null;
            case 'year_built':
                return is_numeric($v) && $v >= 1600 && $v <= (int) gmdate('Y') + 2 ? (int) $v : null;
            case 'listed_date':
            case 'last_sale_date':
                return self::date($v);
            case 'status':
                return in_array($v, ['for_sale', 'pending', 'sold', 'off_market'], true) ? $v : null;
            case 'est_at':
                return (string) $v;
            case 'description':
                return mb_substr(trim((string) $v), 0, 20000);
            case 'photo_url':
            case 'source_url':
                $u = trim((string) $v);
                return preg_match('#^https://#i', $u) ? mb_substr($u, 0, 500) : null;
            default:
                $limits = ['county' => 100, 'property_type' => 30, 'listing_type' => 30, 'est_source' => 20,
                    'mls_name' => 80, 'mls_number' => 40, 'agent_name' => 120, 'agent_phone' => 40,
                    'agent_email' => 190, 'office_name' => 160, 'source_id' => 190];
                return mb_substr(trim((string) $v), 0, $limits[$col] ?? 190);
        }
    }

    /** Accepts "2024-11-18", ISO timestamps and millisecond epochs. */
    public static function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            $n = (float) $v;
            $ts = $n > 20000000000 ? (int) ($n / 1000) : (int) $n;
            return $ts > 0 ? gmdate('Y-m-d', $ts) : null;
        }
        $s = (string) $v;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        $ts = strtotime($s);
        return $ts ? gmdate('Y-m-d', $ts) : null;
    }

    public static function addEvent(int $propertyId, string $event, int $price, string $date, string $source): void
    {
        $date = self::date($date);
        if ($date === null || !in_array($event, ['listed', 'price_change', 'sold', 'removed', 'relisted'], true)) {
            return;
        }
        Db::run(
            'INSERT IGNORE INTO price_events (property_id, event, price, event_date, source, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$propertyId, $event, max(0, $price), $date, $source, now()]
        );
    }

    public static function find(int $id): ?array
    {
        $row = Db::one(
            'SELECT p.*, w.id AS watch_id, w.stage AS watch_stage, w.source_url AS watch_url
             FROM properties p LEFT JOIN watchlist w ON w.property_id = p.id WHERE p.id = ?',
            [$id]
        );
        if ($row) {
            $d = json_decode((string) ($row['details'] ?? ''), true);
            $row['details'] = is_array($d) ? $d : [];
        }
        return $row;
    }

    public static function events(int $id): array
    {
        return Db::all('SELECT event, price, event_date, source FROM price_events WHERE property_id = ? ORDER BY event_date DESC, id DESC', [$id]);
    }

    /**
     * Filtered search over the local database.
     *
     * @return array{rows:array, total:int}
     */
    public static function search(array $f): array
    {
        $mode = ($f['mode'] ?? 'sale') === 'sold' ? 'sold' : 'sale';
        $priceCol = $mode === 'sold' ? 'p.last_sale_price' : 'p.list_price';
        $w = [];
        $a = [];

        if ($mode === 'sold') {
            $days = max(1, min(3650, (int) ($f['sold_days'] ?? 180)));
            $w[] = 'p.last_sale_price > 0 AND p.last_sale_date >= ?';
            $a[] = gmdate('Y-m-d', time() - $days * 86400);
        } else {
            $w[] = "p.status IN ('for_sale','pending') AND p.list_price > 0";
        }

        $loc = $f['loc'] ?? null;
        if (is_array($loc)) {
            if (($loc['type'] ?? '') === 'zip') {
                $w[] = 'p.zip = ?';
                $a[] = $loc['zip'];
            } elseif (($loc['type'] ?? '') === 'city') {
                $w[] = 'p.state = ? AND p.city = ?';
                $a[] = $loc['state'];
                $a[] = $loc['city'];
            }
        }
        if (!empty($f['center']) && Geo::valid($f['center'][0] ?? null, $f['center'][1] ?? null)) {
            $box = Geo::box((float) $f['center'][0], (float) $f['center'][1], (float) ($f['radius'] ?? 5));
            $w[] = 'p.lat BETWEEN ? AND ? AND p.lng BETWEEN ? AND ?';
            array_push($a, $box['lat1'], $box['lat2'], $box['lng1'], $box['lng2']);
        }

        $range = static function (string $col, mixed $min, mixed $max) use (&$w, &$a): void {
            if (is_numeric($min) && $min > 0) {
                $w[] = "$col >= ?";
                $a[] = $min + 0;
            }
            if (is_numeric($max) && $max > 0) {
                $w[] = "$col <= ?";
                $a[] = $max + 0;
            }
        };
        $range($priceCol, $f['price_min'] ?? null, $f['price_max'] ?? null);
        $range('p.beds', $f['beds_min'] ?? null, null);
        $range('p.baths', $f['baths_min'] ?? null, null);
        $range('p.sqft', $f['sqft_min'] ?? null, $f['sqft_max'] ?? null);
        $range('p.lot_sqft', $f['lot_min'] ?? null, null);
        $range('p.year_built', $f['year_min'] ?? null, $f['year_max'] ?? null);

        if (is_numeric($f['hoa_max'] ?? null)) {
            $w[] = '(p.hoa_fee IS NULL OR p.hoa_fee <= ?)';
            $a[] = (float) $f['hoa_max'];
        }
        if ($mode === 'sale' && is_numeric($f['dom_max'] ?? null) && $f['dom_max'] > 0) {
            $w[] = 'p.listed_date >= ?';
            $a[] = gmdate('Y-m-d', time() - (int) $f['dom_max'] * 86400);
        }
        if (is_numeric($f['ppsf_max'] ?? null) && $f['ppsf_max'] > 0) {
            $w[] = "p.sqft > 0 AND $priceCol / p.sqft <= ?";
            $a[] = (float) $f['ppsf_max'];
        }
        $types = array_values(array_intersect(self::TYPES, (array) ($f['types'] ?? [])));
        if ($types) {
            $w[] = 'p.property_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            array_push($a, ...$types);
        }
        if (!empty($f['since'])) {
            $w[] = 'p.first_seen_at > ?';
            $a[] = $f['since'];
        }
        if (!empty($f['watch_only'])) {
            $w[] = 'w.id IS NOT NULL';
        }

        $order = match ($f['sort'] ?? 'newest') {
            'price_asc' => "$priceCol ASC",
            'price_desc' => "$priceCol DESC",
            'ppsf_asc' => "(CASE WHEN p.sqft > 0 THEN $priceCol / p.sqft ELSE 999999 END) ASC",
            'sqft_desc' => 'p.sqft DESC',
            default => $mode === 'sold' ? 'p.last_sale_date DESC' : 'COALESCE(p.listed_date, DATE(p.first_seen_at)) DESC',
        };

        $where = implode(' AND ', $w);
        $from = 'FROM properties p LEFT JOIN watchlist w ON w.property_id = p.id WHERE ' . $where;
        $total = (int) Db::val("SELECT COUNT(*) $from", $a);
        $limit = max(1, min(1000, (int) ($f['limit'] ?? 60)));
        $offset = max(0, (int) ($f['offset'] ?? 0));
        $rows = Db::all(
            "SELECT p.id, p.address, p.city, p.state, p.zip, p.lat, p.lng, p.property_type, p.beds, p.baths, p.sqft,
                    p.lot_sqft, p.year_built, p.hoa_fee, p.status, p.list_price, p.listed_date, p.days_on_market,
                    p.listing_type, p.last_sale_price, p.last_sale_date, p.est_value, p.photo_url, p.source,
                    p.first_seen_at, p.county, w.id AS watch_id
             $from ORDER BY $order, p.id DESC LIMIT $limit OFFSET $offset",
            $a
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * After a complete, unfiltered pull of an area: listings we had as "for sale"
     * that the provider no longer returns have left the market.
     */
    public static function retireMissing(array $loc, string $source, string $since): int
    {
        if (($loc['type'] ?? '') === 'zip') {
            $cond = 'zip = ?';
            $args = [$loc['zip']];
        } elseif (($loc['type'] ?? '') === 'city') {
            $cond = 'state = ? AND city = ?';
            $args = [$loc['state'], $loc['city']];
        } else {
            return 0;
        }
        $gone = Db::all(
            "SELECT id, list_price FROM properties WHERE status = 'for_sale' AND source = ? AND $cond AND last_seen_at < ?",
            array_merge([$source], $args, [$since])
        );
        foreach ($gone as $g) {
            self::addEvent((int) $g['id'], 'removed', (int) $g['list_price'], today(), $source);
            Db::run("UPDATE properties SET status = 'off_market', updated_at = ? WHERE id = ?", [now(), $g['id']]);
        }
        return count($gone);
    }

    /** Days on market, live: provider value when fresh, otherwise counted from the listing date. */
    public static function dom(array $p): ?int
    {
        if (!empty($p['listed_date'])) {
            return days_since($p['listed_date']);
        }
        return isset($p['days_on_market']) && $p['days_on_market'] !== null ? (int) $p['days_on_market'] : null;
    }

    public static function facts(array $p): string
    {
        $bits = [];
        if ($p['beds'] !== null && $p['beds'] !== '') {
            $bits[] = ((float) $p['beds'] == 0.0 ? 'Studio' : trim_num($p['beds']) . ' bd');
        }
        if ($p['baths'] !== null && $p['baths'] !== '') {
            $bits[] = trim_num($p['baths']) . ' ba';
        }
        if (!empty($p['sqft'])) {
            $bits[] = number_format((int) $p['sqft']) . ' sqft';
        }
        if (!empty($p['year_built'])) {
            $bits[] = 'built ' . (int) $p['year_built'];
        }
        return implode(' · ', $bits);
    }

    public static function lot(mixed $sqft): string
    {
        $n = (float) $sqft;
        if ($n <= 0) {
            return '—';
        }
        return $n >= 21780 ? rtrim(rtrim(number_format($n / 43560, 2), '0'), '.') . ' acres' : number_format($n) . ' sqft';
    }
}

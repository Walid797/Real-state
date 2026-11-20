<?php
declare(strict_types=1);

/*
 * Fills a LOCAL database with made-up homes so the pages can be tried without
 * spending API requests. Never run this against the live database.
 *
 *   php tests/seed_demo.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Db;
use App\PropertyRepo;

if (!str_contains((string) App\Config::get('base_url', ''), '127.0.0.1') && !str_contains((string) App\Config::get('base_url', ''), 'localhost')) {
    fwrite(STDERR, "Refusing to seed: base_url is not a local address.\n");
    exit(1);
}

mt_srand(20261116);
$streets = ['Maple St', 'Bluebonnet Ln', 'Kinney Ave', 'S 5th St', 'Garner Ave', 'Oltorf St', 'Treadwell St', 'Hether St', 'Frazier Ave', 'Ashby Ave',
    'Rabb Rd', 'Dexter St', 'Goodrich Ave', 'Paramount Ave', 'Montclaire St', 'Arpdale St', 'Clawson Rd', 'Fortview Rd', 'Bauerle Ave', 'Ford St'];
$agents = [['Dana Whitfield', 'Lamar & Oak Realty'], ['Marcus Ibarra', 'Violet Crown Homes'], ['Priya Raman', 'Bouldin Group'], ['Tom Eckhart', 'Capitol City Brokers']];
$zips = ['78704' => [30.2460, -97.7680], '78745' => [30.2070, -97.7950], '78741' => [30.2310, -97.7200]];
$types = ['Single Family', 'Single Family', 'Single Family', 'Condo', 'Townhouse'];
$rand = static fn (float $a, float $b) => $a + ($b - $a) * (mt_rand() / mt_getrandmax());
$texts = [
    'Investor special, sold as-is. Original kitchen and dated bathrooms, roof is older. Cash or rehab loan only.',
    'Needs TLC throughout. Bring your contractor — great bones on a quiet street.',
    'Fully renovated in 2023: new roof, new HVAC, updated kitchen with quartz counters, remodeled baths, new LVP flooring.',
    'Updated kitchen with stainless appliances. Bathrooms are original. Finished basement and 2-car garage.',
    'Move-in ready and meticulously maintained. Fresh paint, new carpet in the bedrooms.',
    'Estate sale. Foundation issues noted by seller; water damage in the basement. Priced accordingly.',
    'Spacious home close to schools and parks.',
    '',
];

$n = ['sale' => 0, 'sold' => 0];
Db::tx(static function () use ($streets, $agents, $zips, $types, $rand, $texts, &$n) {
    foreach (array_keys($zips) as $zi => $zip) {
        [$clat, $clng] = $zips[$zip];
        for ($i = 0; $i < 90; $i++) {
            $type = $types[mt_rand(0, count($types) - 1)];
            $sqft = (int) round($rand($type === 'Condo' ? 620 : 1050, $type === 'Condo' ? 1350 : 2900), -1);
            $beds = max(1, min(5, (int) round($sqft / 620)));
            $ppsf = $rand(330, 520) * ($zip === '78704' ? 1.18 : 1.0);
            $price = (int) round($sqft * $ppsf, -3);
            $base = [
                'address' => mt_rand(900, 4900) . ' ' . $streets[($i + $zi * 7) % count($streets)] . ($type === 'Condo' ? ' Unit ' . mt_rand(101, 320) : ''),
                'city' => 'Austin', 'state' => 'TX', 'zip' => $zip, 'county' => 'Travis',
                'lat' => $clat + $rand(-0.016, 0.016), 'lng' => $clng + $rand(-0.019, 0.019),
                'property_type' => $type, 'beds' => $beds, 'baths' => max(1, $beds - mt_rand(0, 1)) - (mt_rand(0, 3) === 0 ? 0.5 : 0),
                'sqft' => $sqft, 'lot_sqft' => $type === 'Condo' ? null : (int) round($rand(4200, 11000), -1),
                'year_built' => mt_rand(1948, 2023), 'hoa_fee' => $type === 'Single Family' ? (mt_rand(0, 4) === 0 ? mt_rand(35, 90) : 0) : mt_rand(180, 460),
            ];
            $base['description'] = $texts[mt_rand(0, count($texts) - 1)] ?: null;
            if ($i % 5 < 2) { // for sale
                $days = mt_rand(0, 120);
                [$agent, $office] = $agents[mt_rand(0, count($agents) - 1)];
                $listed = gmdate('Y-m-d', time() - $days * 86400);
                $first = (int) round($price * ($days > 40 && mt_rand(0, 2) === 0 ? $rand(1.03, 1.09) : 1), -3);
                $res = PropertyRepo::upsert($base + [
                    'status' => 'for_sale', 'list_price' => $first, 'listed_date' => $listed, 'days_on_market' => $days,
                    'listing_type' => mt_rand(0, 14) === 0 ? 'New Construction' : 'Standard',
                    'agent_name' => $agent, 'office_name' => $office, 'agent_phone' => '512555' . mt_rand(1000, 9999),
                    'mls_name' => 'UnlockMLS', 'mls_number' => (string) mt_rand(1000000, 9999999),
                ], 'rentcast');
                if ($first !== $price) {
                    PropertyRepo::addEvent($res['id'], 'price_change', $price, gmdate('Y-m-d', time() - mt_rand(2, 30) * 86400), 'rentcast');
                    Db::run('UPDATE properties SET list_price = ? WHERE id = ?', [$price, $res['id']]);
                }
                if (mt_rand(0, 2) === 0) {
                    PropertyRepo::addEvent($res['id'], 'sold', (int) round($price * $rand(0.55, 0.8), -3), gmdate('Y-m-d', time() - mt_rand(1500, 4200) * 86400), 'rentcast');
                }
                $n['sale']++;
            } else { // sold in the last two years
                PropertyRepo::upsert($base + [
                    'last_sale_price' => (int) round($price * $rand(0.93, 1.02), -3),
                    'last_sale_date' => gmdate('Y-m-d', time() - mt_rand(6, 700) * 86400),
                    'tax_annual' => (int) round($price * 0.019, -1), 'assessed_value' => (int) round($price * 0.88, -3),
                ], 'rentcast');
                $n['sold']++;
            }
        }
        foreach (['sale', 'sold'] as $mode) {
            Db::insert('snapshots', ['provider' => 'rentcast', 'mode' => $mode, 'loc_key' => 'zip:' . $zip, 'filter_sig' => $mode === 'sold' ? md5('{"days":730}') : '',
                'filter_json' => $mode === 'sold' ? '{"days":730}' : '[]', 'result_count' => 0, 'truncated' => 0, 'fetched_at' => now()]);
        }
    }
    foreach (['sale', 'sold'] as $mode) {
        Db::insert('snapshots', ['provider' => 'rentcast', 'mode' => $mode, 'loc_key' => 'city:TX:austin', 'filter_sig' => $mode === 'sold' ? md5('{"days":730}') : '',
            'filter_json' => $mode === 'sold' ? '{"days":730}' : '[]', 'result_count' => $n[$mode], 'truncated' => 0, 'fetched_at' => now()]);
    }
});

// A partner and a viewer to test roles (password: "correct horse battery staple").
foreach ([['partner@example.com', 'Pat Partner', 'partner'], ['viewer@example.com', 'Vera Viewer', 'viewer']] as [$em, $nm, $role]) {
    if (!Db::val('SELECT id FROM users WHERE email = ?', [$em])) {
        Db::insert('users', ['email' => $em, 'name' => $nm, 'role' => $role, 'password_hash' => App\Auth::hash('correct horse battery staple'), 'created_at' => now()]);
    }
}
echo "Seeded {$n['sale']} listings and {$n['sold']} sales in Austin, TX, plus a partner and a viewer.\n";

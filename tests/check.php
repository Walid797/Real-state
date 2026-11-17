<?php
declare(strict_types=1);

/*
 * Logic checks against a LOCAL database seeded with tests/seed_demo.php.
 * No network is used: provider responses are sample payloads in the documented shape.
 *
 *   php tests/check.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Address;
use App\Db;
use App\Geo;
use App\PropertyRepo;
use App\Providers\Fema;
use App\Providers\RentCast;
use App\Providers\ZillowResearch;
use App\Quota;
use App\Services\Alerts;
use App\Services\Comps;
use App\Services\ExternalSites;
use App\Services\Search;
use App\Settings;

if (!str_contains((string) App\Config::get('base_url', ''), '127.0.0.1')) {
    fwrite(STDERR, "Refusing to run: base_url is not a local address.\n");
    exit(1);
}

$fail = 0;
$n = 0;
$ok = static function (bool $cond, string $what) use (&$fail, &$n): void {
    $n++;
    if (!$cond) {
        $fail++;
        echo "  FAIL  $what\n";
    }
};
$eq = static function (mixed $got, mixed $want, string $what) use ($ok): void {
    $ok($got === $want, $what . ' — got ' . var_export($got, true) . ', wanted ' . var_export($want, true));
};

/* ── Address parsing ─────────────────────────────────────────────────── */
$eq(Address::parseLocation('78704')['key'] ?? null, 'zip:78704', 'ZIP');
$eq(Address::parseLocation('austin, tx')['label'] ?? null, 'Austin, TX', 'city, st');
$eq(Address::parseLocation('San Antonio TX')['label'] ?? null, 'San Antonio, TX', 'city st without comma');
$eq(Address::parseLocation('Salt Lake City, Utah')['label'] ?? null, 'Salt Lake City, UT', 'full state name');
$eq(Address::parseLocation('McKinney, TX')['city'] ?? null, 'McKinney', 'keeps deliberate casing');
$eq(Address::parseLocation('Austin'), null, 'city without state is refused');
$a = Address::parseLocation('5500 Grand Lake Dr, San Antonio, TX 78244');
$eq($a['type'] ?? null, 'address', 'street address detected');
$eq([$a['address'], $a['city'], $a['state'], $a['zip']], ['5500 Grand Lake Dr', 'San Antonio', 'TX', '78244'], 'address parts');
$eq(Address::parseLocation('5500 Grand Lake Dr, San Antonio, TX, 78244')['zip'] ?? null, '78244', 'RentCast address format');
$eq(Address::hash('123 Main Street', 'Austin', 'TX', '78704'), Address::hash('123 MAIN ST.', 'austin', 'tx', '78704-1234'), 'hash ignores spelling');
$ok(Address::hash('123 Main St Apt 4', '', '', '78704') === Address::hash('123 Main St #4', '', '', '78704'), 'unit spellings match');
$ok(Address::hash('123 Main St', '', '', '78704') !== Address::hash('125 Main St', '', '', '78704'), 'different houses differ');

/* ── Small helpers ───────────────────────────────────────────────────── */
$eq(parse_num('$450,000'), 450000.0, 'parse_num money');
$eq(parse_num('600k'), 600000.0, 'parse_num k');
$eq(parse_num('1.2m'), 1200000.0, 'parse_num m');
$eq(parse_num('abc'), null, 'parse_num junk');
$eq(money_short(1250000), '$1.25M', 'money_short');
$eq(median([3, 1, 2, 10]), 2.5, 'median');
$eq(PropertyRepo::date(1726617600000), '2024-09-18', 'ms epoch date');
$eq(PropertyRepo::date('2024-11-18T00:00:00.000Z'), '2024-11-18', 'ISO date');
$ok(abs(Geo::miles(30.2672, -97.7431, 29.4241, -98.4936) - 73.8) < 1.5, 'Austin–San Antonio distance');

/* ── RentCast mapping (shape from developers.rentcast.io schemas) ───── */
$listing = RentCast::mapListing([
    'id' => '3821-Hargis-St,-Austin,-TX-78723', 'addressLine1' => '3821 Hargis St', 'addressLine2' => null, 'city' => 'Austin', 'state' => 'TX',
    'zipCode' => '78723', 'county' => 'Travis', 'latitude' => 30.290643, 'longitude' => -97.701547, 'propertyType' => 'Single Family',
    'bedrooms' => 4, 'bathrooms' => 2.5, 'squareFootage' => 2345, 'lotSize' => 3284, 'yearBuilt' => 2008, 'hoa' => ['fee' => 65],
    'status' => 'Active', 'price' => 899000, 'listingType' => 'Standard', 'listedDate' => '2024-06-24T00:00:00.000Z', 'daysOnMarket' => 99,
    'mlsName' => 'UnlockMLS', 'mlsNumber' => '5519228', 'listingAgent' => ['name' => 'Jennifer Welch', 'phone' => '5124313110'],
    'listingOffice' => ['name' => 'Gottesman Residential RE'],
    'history' => ['2024-06-24' => ['event' => 'Sale Listing', 'price' => 899000, 'listedDate' => '2024-06-24T00:00:00.000Z', 'removedDate' => null]],
]);
$eq([$listing['status'], $listing['list_price'], $listing['hoa_fee'], $listing['agent_name']], ['for_sale', 899000, 65, 'Jennifer Welch'], 'listing mapped');
$record = RentCast::mapRecord([
    'id' => '5500-Grand-Lake-Dr,-San-Antonio,-TX-78244', 'addressLine1' => '5500 Grand Lake Dr', 'city' => 'San Antonio', 'state' => 'TX', 'zipCode' => '78244',
    'latitude' => 29.475962, 'longitude' => -98.351442, 'propertyType' => 'Single Family', 'bedrooms' => 3, 'bathrooms' => 2, 'squareFootage' => 1878,
    'lastSaleDate' => '2024-11-18T00:00:00.000Z', 'lastSalePrice' => 270000, 'assessorID' => '05076-103-0500',
    'taxAssessments' => ['2023' => ['year' => 2023, 'value' => 200000], '2024' => ['year' => 2024, 'value' => 216513, 'land' => 59380, 'improvements' => 157133]],
    'propertyTaxes' => ['2024' => ['year' => 2024, 'total' => 4065]],
    'history' => ['2024-11-18' => ['event' => 'Sale', 'date' => '2024-11-18T00:00:00.000Z', 'price' => 270000]],
    'owner' => ['names' => ['Rolando Villarreal'], 'type' => 'Individual'], 'ownerOccupied' => true,
]);
$eq([$record['last_sale_price'], $record['tax_annual'], $record['assessed_value']], [270000, 4065, 216513], 'record mapped, latest year picked');
$ok(!isset($record['status']), 'a public record does not change listing status');
$eq(RentCast::pushable(['price_min' => 300000, 'beds_min' => 3, 'types' => ['Condo', 'Townhouse'], 'year_max' => 2000]),
    ['propertyType' => 'Condo|Townhouse', 'bedrooms' => '3:*', 'yearBuilt' => '*:2000', 'price' => '300000:*'], 'RentCast range / multi-value syntax');

/* ── Store: merge, price history ─────────────────────────────────────── */
Db::run("DELETE FROM properties WHERE zip = '78723'");
$r1 = PropertyRepo::upsert($listing, 'rentcast');
$ok($r1['created'], 'new listing inserted');
$r2 = PropertyRepo::upsert(['list_price' => 875000] + $listing, 'rentcast');
$eq([$r2['created'], $r2['old_price'], $r2['new_price']], [false, 899000, 875000], 'price cut detected on re-pull');
$r3 = PropertyRepo::upsert(['address' => '3821 HARGIS STREET', 'city' => 'austin', 'state' => 'tx', 'zip' => '78723', 'last_sale_price' => 640000, 'last_sale_date' => '2019-03-02'], 'manual');
$eq($r3['id'], $r1['id'], 'same house from another source merges');
$events = array_column(PropertyRepo::events($r1['id']), 'event');
sort($events);
$eq($events, ['listed', 'price_change', 'sold'], 'events recorded once each');
$p = PropertyRepo::find($r1['id']);
$eq([$p['status'], (int) $p['list_price'], (int) $p['last_sale_price'], $p['source']], ['for_sale', 875000, 640000, 'rentcast'], 'merged row');
PropertyRepo::upsert(['status' => 'off_market'] + $listing, 'rentcast');
$ok(in_array('removed', array_column(PropertyRepo::events($r1['id']), 'event'), true), 'delisting recorded');

/* ── Search filters (seeded Austin data) ─────────────────────────────── */
$loc = Address::parseLocation('Austin, TX');
$all = PropertyRepo::search(['loc' => $loc, 'mode' => 'sale', 'limit' => 1000]);
$ok($all['total'] >= 100, 'seeded listings found');
$f = PropertyRepo::search(['loc' => $loc, 'mode' => 'sale', 'price_max' => 600000, 'beds_min' => 3, 'types' => ['Single Family'], 'limit' => 1000]);
$ok($f['total'] > 0 && $f['total'] < $all['total'], 'filters narrow the result');
$bad = array_filter($f['rows'], static fn ($r) => $r['list_price'] > 600000 || $r['beds'] < 3 || $r['property_type'] !== 'Single Family');
$eq(count($bad), 0, 'every row obeys the filters');
$sorted = array_column(PropertyRepo::search(['loc' => $loc, 'mode' => 'sale', 'sort' => 'price_asc', 'limit' => 50])['rows'], 'list_price');
$copy = $sorted;
sort($copy);
$eq($sorted, $copy, 'price sort');
$sold = PropertyRepo::search(['loc' => Address::parseLocation('78704'), 'mode' => 'sold', 'sold_days' => 90, 'limit' => 1000]);
$ok(count(array_filter($sold['rows'], static fn ($r) => $r['last_sale_date'] < gmdate('Y-m-d', time() - 91 * 86400))) === 0, 'sold window respected');
// Injection attempt goes in as data, not SQL.
$inj = PropertyRepo::search(['loc' => ['type' => 'city', 'city' => "Austin' OR '1'='1", 'state' => 'TX'], 'mode' => 'sale']);
$eq($inj['total'], 0, 'quote in a city name is harmless');

/* ── Search service: nothing fetched without a provider; snapshots reused ─ */
Settings::set('rentcast_key', null);
Settings::set('openwebninja_key', null);
$res = Search::run(Search::fromRequest(['q' => 'Austin, TX']));
$ok($res['total'] >= 100 && !$res['fetched'] && $res['snapshot'] !== null, 'seeded snapshot reused, no fetch');
$res = Search::run(Search::fromRequest(['q' => 'Dallas, TX']));
$ok(!$res['fetched'] && str_contains((string) $res['notice'], 'No data source'), 'unknown area without provider explains itself');

/* ── Quota guard ─────────────────────────────────────────────────────── */
Settings::set('rentcast_key', 'dummy-key-for-quota-test');
Settings::set('rentcast_limit', '3');
Settings::set('rentcast_reserve', '2');
Db::run('DELETE FROM api_usage');
$ok(Quota::can('rentcast', 'manual') && Quota::can('rentcast', 'cron'), 'fresh month allows both');
Quota::spend('rentcast');
$ok(Quota::can('rentcast', 'manual') && !Quota::can('rentcast', 'cron'), 'alerts stop at limit − reserve');
Quota::spend('rentcast');
Quota::spend('rentcast');
$ok(!Quota::can('rentcast', 'manual'), 'manual stops at the limit');
$eq(Search::provider('manual'), null, 'no provider offered once the allowance is spent');
$blocked = RentCast::saleListings($loc, [], 'manual');
$ok(!$blocked['ok'] && str_contains((string) $blocked['error'], 'used up'), 'call refused before any network use');
$eq(Quota::used('rentcast'), 3, 'a refused call is not counted');
Settings::set('rentcast_key', null);
Settings::set('rentcast_limit', '50');
Settings::set('rentcast_reserve', '15');
Db::run('DELETE FROM api_usage');

/* ── Secrets are encrypted at rest ───────────────────────────────────── */
Settings::set('telegram_token', '123456:ABC-secret');
$raw = (string) Db::val("SELECT v FROM settings WHERE k = 'telegram_token'");
$ok(str_starts_with($raw, 'v1:') && !str_contains($raw, 'ABC-secret'), 'secret stored encrypted');
$eq(Settings::get('telegram_token'), '123456:ABC-secret', 'secret reads back');
$eq(Settings::masked('telegram_token'), '••••••••cret', 'masked for display');
Settings::set('telegram_token', null);

/* ── Comps ───────────────────────────────────────────────────────────── */
$subject = Db::one("SELECT * FROM properties WHERE status = 'for_sale' AND zip = '78704' AND property_type = 'Single Family' ORDER BY id LIMIT 1");
$c = Comps::find($subject, ['radius' => 2, 'months' => 24]);
$ok(count($c['comps']) >= 3, 'comps found near a seeded home');
$ok(max(array_column($c['comps'], 'distance')) <= 2.0, 'all comps inside the radius');
$ok(count(array_filter($c['comps'], static fn ($x) => $x['property_type'] !== 'Single Family')) === 0, 'same property type only');
$ok(count(array_filter($c['comps'], static fn ($x) => abs($x['sqft'] - $subject['sqft']) / $subject['sqft'] > 0.35)) === 0, 'size within 35%');
$scores = array_column($c['comps'], 'score');
$sortedScores = $scores;
rsort($sortedScores);
$eq($scores, $sortedScores, 'best match first');
$ok($c['stats']['value_low'] <= $c['stats']['value'] && $c['stats']['value'] <= $c['stats']['value_high'], 'value sits inside its range');
$eq(Comps::find(['lat' => null, 'lng' => null])['comps'], [], 'no coordinates → no comps, no error');

/* ── Links to other sites ────────────────────────────────────────────── */
$home = ['address' => '139 E Del Rio Dr', 'city' => 'Tempe', 'state' => 'AZ', 'zip' => '85282', 'county' => 'Maricopa', 'lat' => 33.39724, 'lng' => -111.93654];
$links = array_column(ExternalSites::forProperty($home), 'url', 'name');
$eq($links['Zillow'] ?? null, 'https://www.zillow.com/homes/139-E-Del-Rio-Dr-Tempe-AZ-85282_rb/', 'Zillow home link');
$eq($links['Google Maps'] ?? null, 'https://www.google.com/maps/search/?api=1&query=139%20E%20Del%20Rio%20Dr%2C%20Tempe%2C%20AZ%2085282', 'Maps link');
$area = array_column(ExternalSites::forArea(Address::parseLocation('San Antonio, TX')), 'url', 'name');
$eq($area['Realtor.com'] ?? null, 'https://www.realtor.com/realestateandhomes-search/San-Antonio_TX', 'Realtor area link');
$eq($area['Trulia'] ?? null, 'https://www.trulia.com/TX/San_Antonio/', 'Trulia area link');
$ok(!isset($area['Redfin']), 'a ZIP-only link is left out of a city search');
$zipArea = array_column(ExternalSites::forArea(Address::parseLocation('78704')), 'url', 'name');
$eq($zipArea['Redfin'] ?? null, 'https://www.redfin.com/zipcode/78704', 'Redfin ZIP link');
$ok(!ExternalSites::validTemplate('javascript:alert(1)') && !ExternalSites::validTemplate('http://example.com/'), 'only https templates accepted');

/* ── Zillow Research CSV rows ────────────────────────────────────────── */
$head = array_flip(str_getcsv('RegionID,SizeRank,RegionName,RegionType,StateName,State,City,Metro,CountyName,2026-08-31,2026-09-30', ',', '"', ''));
$eq(ZillowResearch::rowKey(str_getcsv('91982,1,2134,zip,MA,MA,Boston,"Boston-Cambridge-Newton, MA-NH",Suffolk County,700000,701000', ',', '"', ''), $head, 'zip'), '02134', 'ZIP keeps its leading zero');
$headCity = array_flip(str_getcsv('RegionID,SizeRank,RegionName,RegionType,StateName,State,Metro,CountyName,2026-09-30', ',', '"', ''));
$eq(ZillowResearch::rowKey(str_getcsv('10221,10,Austin,city,TX,TX,"Austin-Round Rock-Georgetown, TX",Travis County,540000', ',', '"', ''), $headCity, 'city'), 'austin|TX', 'city key');
$headMetro = array_flip(str_getcsv('RegionID,SizeRank,RegionName,RegionType,StateName,2026-09-30', ',', '"', ''));
$eq(ZillowResearch::rowKey(str_getcsv('394355,29,"Austin, TX",msa,TX,455000', ',', '"', ''), $headMetro, 'metro'), 'austin|TX', 'metro key');
$eq(ZillowResearch::rowKey(str_getcsv('102001,0,United States,country,,360000', ',', '"', ''), $headMetro, 'metro'), null, 'national row skipped');

/* ── FEMA zone wording ───────────────────────────────────────────────── */
$eq(Fema::describe('AE', 'FLOODWAY', true)['risk'], 'high', 'AE is high risk');
$eq(Fema::describe('X', '0.2 PCT ANNUAL CHANCE FLOOD HAZARD', false)['risk'], 'moderate', 'shaded X is moderate');
$eq(Fema::describe('X', 'AREA OF MINIMAL FLOOD HAZARD', false)['risk'], 'low', 'unshaded X is low');

/* ── Alerts: baseline, new match, price cut, no repeats ──────────────── */
Db::run("DELETE FROM alerts WHERE name LIKE 'check:%'");
$aid = Db::insert('alerts', ['name' => 'check: Austin under 600k', 'kind' => 'new_listing', 'location' => 'Austin, TX',
    'criteria' => json_encode(['price_max' => 600000, 'beds_min' => 3]), 'every_hours' => 24, 'channels' => 'email', 'enabled' => 1, 'use_quota' => 0, 'created_at' => now()]);
$alert = static fn () => Db::one('SELECT * FROM alerts WHERE id = ?', [$aid]);
$first = Alerts::runOne($alert(), 'cron', false);
$ok($first['baseline'] && $first['hits'] === [], 'first run is a silent baseline');
$second = Alerts::runOne($alert(), 'cron', false);
$eq(count($second['hits']), 0, 'nothing new → nothing sent');
$new = PropertyRepo::upsert(['address' => '777 Checker Ln', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78704', 'status' => 'for_sale',
    'list_price' => 555000, 'beds' => 3, 'baths' => 2, 'sqft' => 1500, 'lat' => 30.25, 'lng' => -97.76], 'rentcast');
PropertyRepo::upsert(['address' => '778 Checker Ln', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78704', 'status' => 'for_sale', 'list_price' => 955000, 'beds' => 4], 'rentcast');
$third = Alerts::runOne($alert(), 'cron', false);
$eq(array_column($third['hits'], 'id'), [$new['id']], 'only the matching new home is reported');
$eq($third['hits'][0]['why'] ?? null, 'New match', 'reason given');
$eq(count(Alerts::runOne($alert(), 'cron', false)['hits']), 0, 'the same home is not reported twice');
PropertyRepo::upsert(['address' => '777 Checker Ln', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78704', 'status' => 'for_sale', 'list_price' => 529000], 'rentcast');
$cut = Alerts::runOne($alert(), 'cron', false);
$ok(count($cut['hits']) === 1 && str_starts_with($cut['hits'][0]['why'], 'Price cut $555,000 → $529,000'), 'a later price cut is reported');
[$subject, $text, $html] = Alerts::compose($alert(), $cut['hits'], Address::parseLocation('Austin, TX'));
$ok(str_contains($subject, '1 new home') && str_contains($text, '$529,000 · 777 Checker Ln, Austin, TX 78704') && str_contains($text, '/property/' . $new['id']) && str_contains($text, 'zillow.com/homes/777-Checker-Ln'), 'message has price, address, own link and Zillow link');
$ok(str_contains($html, 'href="http://127.0.0.1:8088/property/' . $new['id'] . '"'), 'HTML version links to the home');

// price_drop on the watchlist
Db::run('INSERT IGNORE INTO watchlist (property_id, stage, created_at, updated_at) VALUES (?, ?, ?, ?)', [$new['id'], 'considering', now(), now()]);
$did = Db::insert('alerts', ['name' => 'check: cuts', 'kind' => 'price_drop', 'location' => '', 'criteria' => json_encode(['min_drop_pct' => 2]),
    'every_hours' => 24, 'channels' => 'email', 'enabled' => 1, 'use_quota' => 0, 'created_at' => now()]);
$drop = static fn () => Db::one('SELECT * FROM alerts WHERE id = ?', [$did]);
Alerts::runOne($drop(), 'cron', false);
PropertyRepo::upsert(['address' => '777 Checker Ln', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78704', 'status' => 'for_sale', 'list_price' => 525000], 'rentcast');
$eq(count(Alerts::runOne($drop(), 'cron', false)['hits']), 0, 'a cut under the threshold is ignored (0.8%)');
PropertyRepo::upsert(['address' => '777 Checker Ln', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78704', 'status' => 'for_sale', 'list_price' => 499000], 'rentcast');
$eq(count(Alerts::runOne($drop(), 'cron', false)['hits']), 1, 'a cut over the threshold is reported');
$eq(Alerts::monthlyCost(['use_quota' => 1, 'enabled' => 1, 'location' => 'Austin, TX', 'every_hours' => 24]), 30, 'daily alert ≈ 30 requests a month');

Db::run("DELETE FROM alerts WHERE name LIKE 'check:%'");
Db::run("DELETE FROM properties WHERE address LIKE '77_ Checker Ln' OR zip = '78723'");

echo $fail === 0 ? "All $n checks passed.\n" : "$fail of $n checks failed.\n";
exit($fail === 0 ? 0 : 1);

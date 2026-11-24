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
use App\Services\Condition;
use App\Services\Deal;
use App\Services\Score;
use App\Services\ExternalSites;
use App\Services\Search;
use App\Settings;

if (!str_contains((string) App\Config::get('base_url', ''), '127.0.0.1')) {
    fwrite(STDERR, "Refusing to run: base_url is not a local address.\n");
    exit(1);
}
$homesWasOn = Settings::get('homes_on', '1');
Settings::set('homes_on','0'); // Offline checks must never read public listing pages.
App\Services\SmartLocation::$network = false;
App\Providers\CountyArea::$network = false;

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
$eq(Address::parseLocation('Austin')['label'] ?? null, 'Austin, VA', 'city without state uses the home state');
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

/* ── Which earlier pulls answer a new request for free ───────────────── */
$ok(Search::covers([], ['price' => '*:600000', 'bedrooms' => '3:*']), 'an unfiltered pull covers any filter');
$ok(Search::covers(['price' => '*:600000'], ['price' => '200000:500000', 'bedrooms' => '3:*']), 'wider price range covers a narrower one');
$ok(!Search::covers(['price' => '*:600000'], ['price' => '*:700000']), 'narrower price range does not cover a wider one');
$ok(!Search::covers(['bedrooms' => '3:*'], ['price' => '*:600000']), 'a pull limited to 3+ beds does not cover "any beds"');
$ok(Search::covers(['propertyType' => 'Condo|Townhouse'], ['propertyType' => 'Condo']), 'type subset is covered');
$ok(!Search::covers(['propertyType' => 'Condo'], ['propertyType' => 'Condo|Townhouse']), 'type superset is not');
$ok(Search::covers(['days' => 365], ['days' => 180, 'bedrooms' => '2:*']) && !Search::covers(['days' => 90], ['days' => 180]), 'sold window');
$ok(!Search::covers(['partial' => true], []), 'a partial (Zillow-data) pull never counts as complete');

// A truncated pull exists for Dallas and a key is set: filtering by hand must NOT spend a request on its own.
Settings::set('rentcast_key', 'dummy-key-never-sent');
Db::run("DELETE FROM snapshots WHERE loc_key = 'city:TX:dallas'");
Db::run('DELETE FROM api_log');
Db::insert('snapshots', ['provider' => 'rentcast', 'mode' => 'sale', 'loc_key' => 'city:TX:dallas', 'filter_sig' => '', 'filter_json' => '[]',
    'result_count' => 500, 'truncated' => 1, 'fetched_at' => now()]);
$res = Search::run(Search::fromRequest(['q' => 'Dallas, TX', 'price_max' => '300000']));
$ok($res['partial'] && !$res['fetched'], 'changed filters after a truncated pull → saved data, flagged as partial');
$eq((int) Db::val('SELECT COUNT(*) FROM api_log'), 0, 'and no request was attempted');
$res = Search::run(Search::fromRequest(['q' => 'Dallas, TX']));
$ok(!$res['partial'] && !$res['fetched'], 'the same request as the pull is simply reused');
Db::run("DELETE FROM snapshots WHERE loc_key = 'city:TX:dallas'");
Settings::set('rentcast_key', null);

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
$ok(str_contains($subject, 'New investment property found') && str_contains($text, '$529,000 · 777 Checker Ln, Austin, TX 78704') && str_contains($text, '/property/' . $new['id']) && str_contains($text, 'zillow.com/homes/777-Checker-Ln'), 'message has price, address, own link and Zillow link');
$ok(str_contains($html, 'href="http://127.0.0.1:8088/property/' . $new['id'] . '"'), 'HTML version links to the home');

// price_drop on the watchlist
Db::run('INSERT IGNORE INTO watchlist (property_id, stage, created_at, updated_at) VALUES (?, ?, ?, ?)', [$new['id'], 'under_review', now(), now()]);
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

/* ── Areas: state, county, several ZIPs, several areas ───────────────── */
$eq(Address::parseLocation('Virginia')['key'] ?? null, 'state:VA', 'state by name');
$eq(Address::parseLocation('all of VA')['type'] ?? null, 'state', 'state by abbreviation');
$eq(Address::parseLocation('Fairfax County')['key'] ?? null, 'county:VA:fairfax', 'county, home state assumed');
$eq(Address::parseLocation('Loudoun County, VA')['label'] ?? null, 'Loudoun County, VA', 'county with state');
$multi = Address::parseLocation('22030, 20176 22102');
$eq([$multi['type'] ?? null, count($multi['parts'] ?? [])], ['multi', 3], 'three ZIPs');
$mix = Address::parseLocation('Fairfax County; Richmond; 22030');
$eq(array_column($mix['parts'] ?? [], 'type'), ['county', 'city', 'zip'], 'mixed areas');
$plan = Search::plan($mix, Search::fromRequest(['q' => 'x']));
$eq(array_column($plan['needs'], 'key'), ['state:VA', 'city:VA:richmond', 'zip:22030'], 'a county is pulled through its state; one pull per area');
$a2 = [];
$ok(str_contains(PropertyRepo::locSql($mix, $a2), ' OR ') && count($a2) === 8, 'several areas search with OR');
$counties = Address::parseLocation('Fairfax County, Loudoun County, VA');
$eq(array_column(Address::areas($counties), 'key'), ['county:VA:fairfax', 'county:VA:loudoun'], 'county list shares a trailing state');
$eq(array_column(Address::areas(Address::parseLocation('Fairfax County, VA, Montgomery County, MD')), 'key'), ['county:VA:fairfax', 'county:MD:montgomery'], 'commas preserve each explicit county state');
$eq(Address::parseLocation('Loudoun County؛ Fairfax County؛ Fairfax County')['key'], $counties['key'], 'reordered and repeated counties share the same search key');
$otherStates = Address::parseLocation('Travis County، Hays County، TX؛ Montgomery County, Maryland');
$eq(array_column(Address::areas($otherStates), 'key'), ['county:TX:travis', 'county:TX:hays', 'county:MD:montgomery'], 'mixed county lists keep their correct states');
$eq(array_column(Search::plan($otherStates, Search::fromRequest([]))['needs'], 'key'), ['state:TX', 'state:MD'], 'one provider pull per state');
$areaSearch = Search::run(Search::fromRequest(['q' => 'Fairfax County, Loudoun County, VA']));
$ok($areaSearch['partial'] && !$areaSearch['fetched'] && count($areaSearch['needs']) === 1, 'county list waits for confirmation even when only one pull is needed');
$eq(Address::parseLocation('123 Main Street, Fairfax, VA 22030')['type'], 'address', 'street address commas retain their meaning');
$checkCounties = [
    ['Fairfax', 'VA', '22030'], ['Loudoun County', 'VA', '20176'], ['Fairfax city', 'VA', '22032'], ['Fairfax', 'TX', '78711'], ['Arlington', 'VA', '22213'],
];
$countyCheckIds = [];
try {
    foreach ($checkCounties as $i => [$county, $state, $zip]) {
        $countyCheckIds[] = PropertyRepo::upsert([
            'address' => (900 + $i) . ' County Search Fixture Rd', 'city' => 'Fixture', 'county' => $county,
            'state' => $state, 'zip' => $zip, 'status' => 'for_sale', 'list_price' => 300000 + $i,
        ], 'manual')['id'];
    }
    $foundIds = array_column(PropertyRepo::search(['loc' => $counties, 'sort' => 'price_asc', 'limit' => 2000])['rows'], 'id');
    $eq(array_values(array_map('intval', array_intersect($foundIds, $countyCheckIds))), array_slice($countyCheckIds, 0, 2), 'county search includes both selected counties and excludes the independent city, other states and other counties');
} finally {
    foreach ($countyCheckIds as $id) {
        Db::run('DELETE FROM properties WHERE id = ?', [$id]);
    }
}

/* ── Condition from listing text ─────────────────────────────────────── */
$d = Condition::detect('Investor special, sold as-is. Original kitchen and dated bathrooms. Cash only.');
$eq([$d['overall'], $d['parts']['kitchen'], in_array('fixer', $d['flags'], true), in_array('as_is', $d['flags'], true)], ['needs_renovation', 'original', true, true], 'fixer-upper text');
$d = Condition::detect('Fully renovated in 2022: new roof, new HVAC, updated kitchen. Finished basement, 2-car garage.');
$eq([$d['overall'], $d['parts']['roof'], $d['parts']['hvac'], $d['parts']['kitchen'], $d['basement'], $d['garage'], $d['renovated_year']],
    ['recently_renovated', 'new', 'new', 'updated', true, 2, 2022], 'renovated text: parts, basement, garage, year');
$d = Condition::detect('Spacious home near schools. Gutters cleaned.');
$eq([$d['overall'], $d['flags']], ['not_disclosed', []], 'nothing said → not disclosed, no false "gut" match');
$eq(Condition::detect('Foundation issues and water damage.')['overall'], 'major_repair', 'major repair');

/* ── Deal arithmetic (numbers worked by hand) ───────────────────────── */
Db::run("DELETE FROM properties WHERE address = '1 Calc Way'");
$calcId = PropertyRepo::upsert(['address' => '1 Calc Way', 'city' => 'Richmond', 'state' => 'VA', 'zip' => '23220', 'status' => 'for_sale',
    'list_price' => 200000, 'sqft' => 1500, 'lat' => 37.55, 'lng' => -77.46], 'manual')['id'];
Deal::save($calcId, ['purchase_price' => '200000', 'arv' => '320000', 'reno_cost' => '40000', 'contingency_pct' => '10', 'closing_pct' => '2',
    'selling_pct' => '7', 'holding_months' => '6', 'holding_monthly' => '500', 'loan_ltv_pct' => '0', 'loan_rate_pct' => '0', 'loan_points_pct' => '0']);
$pc = PropertyRepo::find($calcId);
$dl = Deal::analyse($pc);
// total = 200000 + 4000 + 40000 + 4000 + 3000 = 251000; selling = 22400; profit = 320000 − 251000 − 22400 = 46600
$eq([$dl['ok'], (int) round($dl['base']['total']), (int) round($dl['base']['selling']), (int) round($dl['base']['profit'])], [true, 251000, 22400, 46600], 'total, selling, profit');
$eq(round($dl['base']['roi'], 2), round(46600 / 251000 * 100, 2), 'ROI = profit ÷ total');
$eq((int) round($dl['rule70']), 184000, '70% rule: 320000 × 0.7 − 40000');
$eq((int) round($dl['break_even']), (int) round(251000 / 0.93), 'break-even sale price');
// max offer for the default $30,000 target: (320000×0.93 − 44000 − 3000 − 30000) ÷ 1.02
$eq((int) round($dl['max_offer']), (int) round((320000 * 0.93 - 44000 - 3000 - 30000) / 1.02), 'max offer leaves the target profit');
$ok($dl['scenarios']['pessimistic']['profit'] < $dl['base']['profit'] && $dl['scenarios']['optimistic']['profit'] > $dl['base']['profit'], 'scenarios bracket the typical case');
$eq($dl['source']['arv'], 'set', 'typed figures are marked as set');
Deal::save($calcId, ['purchase_price' => '', 'arv' => '']);
$eq(Deal::analyse(PropertyRepo::find($calcId))['source']['purchase_price'], 'est', 'clearing a box goes back to the estimate');
$sc = Score::compute($pc, $dl, Condition::get($calcId));
$ok($sc['score'] !== null && $sc['score'] >= 0 && $sc['score'] <= 100 && $sc['risk'] >= 0 && $sc['risk'] <= 100, 'scores stay within 0–100');
$ok(in_array('Condition not disclosed', $sc['risks'], true), 'unknown condition counts as a risk');

/* ── Roles ──────────────────────────────────────────────────────────── */
$_SESSION = [];
foreach (['admin' => [true, true, true], 'partner' => [true, true, false], 'viewer' => [true, false, false]] as $role => $want) {
    $u = Db::one('SELECT * FROM users WHERE role = ? LIMIT 1', [$role]);
    $_SESSION = ['uid' => (int) $u['id'], 'epoch' => (int) $u['session_epoch']];
    $r = new ReflectionProperty(App\Auth::class, 'user');
    $r->setValue(null, null);
    $eq([App\Auth::can('view'), App\Auth::can('edit'), App\Auth::can('admin')], $want, "$role permissions");
}
App\Auth::signOutEverywhere((int) $u['id']);
(new ReflectionProperty(App\Auth::class, 'user'))->setValue(null, null);
$eq(App\Auth::user(), null, 'signing out everywhere ends an existing session');
$_SESSION = [];

/* ── Invitation tokens ──────────────────────────────────────────────── */
$tok = App\Auth::issueToken((int) $u['id'], 'reset', 60);
$ok(App\Auth::peekToken($tok, 'reset') !== null, 'fresh token is valid');
$ok(App\Auth::peekToken($tok, 'invite') === null, 'token only works for its kind');
App\Auth::useToken((int) App\Auth::peekToken($tok, 'reset')['token_id']);
$ok(App\Auth::peekToken($tok, 'reset') === null, 'token works once');
$ok((int) Db::val('SELECT COUNT(*) FROM user_tokens WHERE token_hash = ?', [$tok]) === 0, 'only the hash is stored');

/* ── Links from other sites ─────────────────────────────────────────── */
$eq(App\Services\LinkImport::addressText('https://www.zillow.com/homedetails/139-E-Del-Rio-Dr-Tempe-AZ-85282/7594920_zpid/'), '139 E Del Rio Dr Tempe, AZ 85282', 'Zillow link');
$eq(App\Services\LinkImport::addressText('https://www.realtor.com/realestateandhomes-detail/1234-Main-St_Richmond_VA_23220_M12345-67890'), '1234 Main St, Richmond, VA 23220', 'Realtor link');
$eq(App\Services\LinkImport::addressText('https://www.redfin.com/VA/Richmond/1234-Main-St-23220/home/12345'), '1234 Main St, Richmond, VA 23220', 'Redfin link');
$eq(App\Services\LinkImport::addressText('https://www.homes.com/homes-for-sale/'), null, 'a search page has no address');
$eq(App\Services\LinkImport::site('https://www.homes.com/property/x/'), 'Homes.com', 'site name');

/* ── Excel file is a valid workbook ─────────────────────────────────── */
$xl = new App\Export\Xlsx();
$xl->addSheet('Test <&>', ['Name', 'Price'], [['=1+1', 250000], ['Ünïcode “quotes”', 1.5]]);
$tmp = tempnam(sys_get_temp_dir(), 'xl');
$xl->save($tmp);
$zip = new ZipArchive();
$ok($zip->open($tmp) === true && $zip->locateName('xl/worksheets/sheet1.xml') !== false, 'xlsx has a worksheet');
$sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
$ok(simplexml_load_string($sheet) !== false && str_contains($sheet, 't="inlineStr"') && str_contains($sheet, '<v>250000</v>'), 'well-formed XML, numbers stay numbers');
$ok(!str_contains($sheet, '<f>'), 'text starting with = is never a formula');
$zip->close();
unlink($tmp);
Db::run("DELETE FROM properties WHERE address = '1 Calc Way'");

/* ── A kept provider answer is reused, and costs nothing ────────────── */
$hadKey = App\Settings::has('rentcast_key');
if (!$hadKey) {
    App\Settings::set('rentcast_key', 'check-only');
}
$kLoc = ['type' => 'city', 'city' => 'Keptville', 'state' => 'VA', 'key' => 'city:VA:keptville', 'label' => 'Keptville, VA'];
App\Cache::put(App\Cache::key('rentcast', '/listings/sale', ['city' => 'Keptville', 'state' => 'VA', 'status' => 'Active', 'limit' => 500]), 'rentcast',
    [['id' => 'k1', 'addressLine1' => '1 Kept St', 'city' => 'Keptville', 'state' => 'VA', 'zipCode' => '24999', 'status' => 'Active', 'price' => 100000]], 3600);
$before = App\Quota::used('rentcast');
$kr = App\Providers\RentCast::saleListings($kLoc, [], 'manual');
$ok($kr['ok'] && count($kr['items']) === 1 && $kr['items'][0]['address'] === '1 Kept St', 'kept answer is served');
$ok(App\Quota::used('rentcast') === $before, 'serving a kept answer spends no request');
Db::run("DELETE FROM api_cache WHERE provider = 'rentcast'");
if (!$hadKey) {
    App\Settings::set('rentcast_key', null);
}

/* ── Scoring order and the log reader ───────────────────────────────── */
$a = PropertyRepo::upsert(['address' => '1 Order St', 'city' => 'Orderton', 'state' => 'VA', 'zip' => '24998', 'status' => 'for_sale', 'list_price' => 300000, 'sqft' => 1000], 'manual')['id'];
$b = PropertyRepo::upsert(['address' => '2 Order St', 'city' => 'Orderton', 'state' => 'VA', 'zip' => '24998', 'status' => 'for_sale', 'list_price' => 150000, 'sqft' => 1000], 'manual')['id'];
$c = PropertyRepo::upsert(['address' => '3 Order St', 'city' => 'Orderton', 'state' => 'VA', 'zip' => '24998', 'status' => 'for_sale', 'list_price' => 90000], 'manual')['id'];
$eq(App\Services\Search::scoreFirst([$a, $b, $c], 2), [$b, $a], 'cheapest per sqft are scored first; homes without size wait');
Db::run("DELETE FROM properties WHERE city = 'Orderton'");
$ok(is_array(App\Diagnostics::logTail(5)) && count(App\Diagnostics::logTail(5)) <= 5, 'log tail reads at most what was asked');
$ok(count(App\Diagnostics::report()) > 4, 'status report has content');

/* ── Email and WhatsApp delivery ─────────────────────────────────── */
$smtp = new App\Notify\Smtp('smtp.example.com', 465, 'ssl', 'alerts@example.com', 'x');
$msg = $smtp->message('alerts@example.com', 'Plat Alerts', 'you@example.com', 'Subject', "Body\n.dot line", '<p>Body</p>',
    ['Reply-To' => 'alerts@example.com', 'List-Unsubscribe' => '<https://example.com/alerts>', 'Bad Header' => 'x', 'X-Inject' => "a\r\nBcc: evil@example.com"]);
$ok(str_contains($msg, "Reply-To: alerts@example.com") && str_contains($msg, 'List-Unsubscribe: <https://example.com/alerts>'), 'mail carries Reply-To and List-Unsubscribe');
$ok(str_contains($msg, 'multipart/alternative'), 'mail has text and HTML parts');
$ok(!str_contains($msg, 'Bad Header') && !preg_match('/^Bcc:/mi', $msg), 'odd header names and injected lines are dropped');
$html = App\Notify\Mail::fromText("Hi <b>you</b>\n\nSee https://example.com/x?a=1&b=2");
$ok(str_contains($html, '&lt;b&gt;') && str_contains($html, '<a href="https://example.com/x?a=1&amp;b=2"'), 'text becomes safe HTML with links');
$ok(str_contains(App\Notify\Mail::layout('T', '<p>x</p>'), 'Change or stop alerts'), 'every email says how to stop it');

$hadUser = App\Settings::get('smtp_user');
$hadPass = App\Settings::has('smtp_pass');
App\Settings::set('smtp_user', null);
$r = App\Notify\Notifier::send('t', 'b', ['email'], null, ['email' => 'you@example.com', 'telegram' => '', 'whatsapp_phone' => '', 'whatsapp_key' => '']);
$ok(!$r['email']['ok'] && str_contains($r['email']['detail'], 'No mailbox'), 'without a mailbox nothing is sent through the server mailer');
App\Settings::set('smtp_user', $hadUser);

$wu = Db::insert('users', ['email' => 'wa@example.com', 'name' => 'WA', 'role' => 'partner', 'password_hash' => '', 'created_at' => now()]);
$ok(!App\Notify\Notifier::ready(App\Notify\Notifier::recipient(Db::one('SELECT * FROM users WHERE id = ?', [$wu])))['whatsapp'], 'no WhatsApp until the person sets it up');
Db::run('UPDATE users SET whatsapp_phone = ?, callmebot_key = ? WHERE id = ?', ['+18045550123', App\Settings::seal('987654'), $wu]);
$row = Db::one('SELECT * FROM users WHERE id = ?', [$wu]);
$rec = App\Notify\Notifier::recipient($row);
$ok(App\Notify\Notifier::ready($rec)['whatsapp'] && $rec['whatsapp_key'] === '987654', 'a person’s own WhatsApp key is used');
$ok(!str_contains((string) $row['callmebot_key'], '987654'), 'the key is stored encrypted');
Db::run("UPDATE alerts SET user_id = NULL WHERE user_id = ?", [$wu]);
Db::run('UPDATE users SET is_active = 0 WHERE id = ?', [$wu]);
$ok(App\Services\Alerts::recipient(['user_id' => $wu])['email'] === '', 'a disabled person’s alerts go to nobody');
Db::run('DELETE FROM users WHERE id = ?', [$wu]);

/* ── Empty-state sketches ──────────────────────────────────────────── */
foreach (glob(APP_ROOT . '/pages/partials/sketches/*.svg') as $svgFile) {
    $svg = (string) file_get_contents($svgFile);
    $name = basename($svgFile);
    $ok(@simplexml_load_string($svg) !== false, "$name is well-formed SVG");
    $ok(!preg_match('/<script|\son[a-z]+=|href=|<foreignObject/i', $svg), "$name has no script, handlers or links");
    $ok(strlen($svg) < 14000, "$name stays small");
}
$ok(count(glob(APP_ROOT . '/pages/partials/sketches/*.svg')) === 7, 'all seven sketches are there');
$ok(str_contains(App\View::sketch('pipeline-empty'), 'id="sk-pipeline-empty"') && App\View::sketch('../config') === '', 'sketches load by name only');

/* ── 3D view (Cesium) ─────────────────────────────────────────────── */
$ok(isset(App\Quota::PROVIDERS['cesium']) && App\Quota::limit('cesium') === (int) (App\Settings::get('cesium_limit') ?? 900), '3D views are metered like the other sources');
$cz = (string) file_get_contents(APP_ROOT . '/public/assets/vendor/cesium/Cesium.js');
$ok(str_contains($cz, 'Version 1.147.0') && !str_contains($cz, '(0,eval)("this")'), 'vendored Cesium is the patched 1.147.0 (no eval on load)');
$ok(is_file(APP_ROOT . '/public/assets/vendor/cesium/Workers/createVerticesFromHeightmap.js') || count(glob(APP_ROOT . '/public/assets/vendor/cesium/Workers/*.js')) > 10, 'Cesium workers are shipped');
$ok(is_file(APP_ROOT . '/public/assets/vendor/cesium/ThirdParty/draco_decoder.wasm'), 'Draco decoder for Google tiles is shipped');
$ok(App\Providers\CesiumIon::GOOGLE_3D_ASSET === 2275207 && str_contains($cz, 'fromAssetId(2275207)'), 'token check asks for the same asset the viewer opens');

/* ── Nearby places kept from the browser, and a dropped connection ─── */
$good = array_fill_keys(array_keys(App\Providers\Overpass::KINDS), ['count' => 0, 'nearest' => null, 'name' => null]);
$good['school'] = ['count' => 2, 'nearest' => 0.41, 'name' => '<b>Zilker</b> Elementary'];
$ok(App\Providers\Overpass::store(1.2345, 2.3456, $good), 'a well-formed tally is kept');
$kept = App\Providers\Overpass::cached(1.2345, 2.3456);
$ok($kept['school']['count'] === 2 && $kept['school']['name'] === 'Zilker Elementary', 'kept tally reads back, tags stripped');
$ok(!App\Providers\Overpass::store(1.0, 1.0, ['school' => ['count' => 1]]), 'a tally missing kinds is refused');
$bad = $good; $bad['park']['count'] = -3;
$ok(!App\Providers\Overpass::store(1.0, 1.0, $bad), 'negative counts are refused');
$bad = $good; $bad['grocery']['nearest'] = 'far';
$ok(!App\Providers\Overpass::store(1.0, 1.0, $bad), 'non-numeric distances are refused');
$ok(str_contains(App\Providers\Overpass::query(37.5, -77.4), 'around:1609,37.500000,-77.400000'), 'query is built for the home');
Db::run("DELETE FROM api_cache WHERE provider = 'osm'");
// a connection the server has closed comes back by itself
Db::run('SET SESSION wait_timeout = 1');
sleep(2);
$ok((int) Db::val('SELECT 1') === 1, 'reconnects after the server closes an idle connection');

/* ── ARV from asking prices when no sales are on file ─────────────── */
Db::run("DELETE FROM properties WHERE city = 'Askville'");
$askBase = ['city' => 'Askville', 'state' => 'VA', 'zip' => '24990', 'status' => 'for_sale', 'property_type' => 'Single Family', 'beds' => 3, 'baths' => 2, 'year_built' => 1985];
foreach ([200, 210, 220, 230, 240, 250] as $i => $ppsfAsk) {
    PropertyRepo::upsert($askBase + ['address' => (100 + $i) . ' Ask St', 'sqft' => 1500, 'list_price' => 1500 * $ppsfAsk,
        'lat' => 37.30 + $i * 0.001, 'lng' => -79.90], 'manual');
}
$subj = PropertyRepo::upsert($askBase + ['address' => '1 Subject Rd', 'sqft' => 1600, 'list_price' => 260000, 'lat' => 37.302, 'lng' => -79.901], 'manual')['id'];
$sp = Db::one('SELECT * FROM properties WHERE id = ?', [$subj]);
$arvA = App\Services\Deal::arv($sp);
$ok($arvA['value'] !== null && ($arvA['from'] ?? '') === 'asking' && $arvA['confidence'] <= 30, 'with six similar listings nearby, ARV comes from asking prices at low confidence');
$ok(str_contains($arvA['basis'], 'Asking prices of 6'), 'the ARV says where it came from');
$dealA = App\Services\Deal::analyse($sp);
$ok($dealA['ok'] && $dealA['tax_estimated'] && isset($dealA['max_offer']), 'profit and max offer are worked out, with tax estimated');
$scA = App\Services\Score::refresh($subj)['score'];
$ok($scA['score'] !== null && $scA['score'] <= 69 && in_array('ARV from asking prices, not recorded sales', $scA['risks'], true), 'the home gets a score, kept under 70, and the asking-price ARV is named as a risk');
Db::run("UPDATE properties SET tax_annual = 3000 WHERE id = ?", [$subj]);
$ok(!App\Services\Deal::analyse(Db::one('SELECT * FROM properties WHERE id = ?', [$subj]))['tax_estimated'], 'a real tax figure is used when there is one');
Db::run("DELETE FROM properties WHERE city = 'Askville' AND address <> '1 Subject Rd' AND address NOT IN ('100 Ask St','101 Ask St')");
$ok(App\Services\Deal::arv(Db::one('SELECT * FROM properties WHERE id = ?', [$subj]))['value'] === null, 'two listings are not enough for an ARV');
// a recorded sale always wins over asking prices
foreach ([0, 1, 2, 3] as $i) {
    PropertyRepo::upsert($askBase + ['address' => (300 + $i) . ' Sold Ln', 'status' => 'off_market', 'sqft' => 1550, 'last_sale_price' => 1550 * 180,
        'last_sale_date' => gmdate('Y-m-d', time() - 40 * 86400), 'lat' => 37.301 + $i * 0.001, 'lng' => -79.9005], 'manual');
}
$arvS = App\Services\Deal::arv(Db::one('SELECT * FROM properties WHERE id = ?', [$subj]));
$ok(($arvS['from'] ?? '') !== 'asking' && $arvS['comps'] >= 3, 'recorded sales take over from asking prices');
Db::run("DELETE FROM properties WHERE city = 'Askville'");

/* ── Full listing fetched by the site itself, once per home ────────── */
$hadOwn = App\Settings::get('openwebninja_key');
App\Settings::set('openwebninja_key', 'check-only');
App\Settings::set('openwebninja_auto', '60');
$ld = PropertyRepo::upsert(['address' => '9 Auto Ln', 'city' => 'Autoville', 'state' => 'VA', 'zip' => '24980', 'status' => 'for_sale', 'list_price' => 200000], 'manual')['id'];
$ldp = PropertyRepo::find($ld);
$ok(App\Services\ListingDetails::wanted($ldp), 'a home without its listing is fetched when first opened');
$ldp['details']['zillow'] = true;
$ok(App\Services\ListingDetails::wanted($ldp), 'an older full listing can acquire its newly supported school data within the automatic cap');
$ldp['details']['schools'] = [];
$ok(!App\Services\ListingDetails::wanted($ldp), 'a home already fetched is not fetched again');
$ldp['details'] = ['zillow_tried_at' => now()];
$ok(!App\Services\ListingDetails::wanted($ldp), 'a home Zillow had nothing for is left alone for a month');
$ldp['details'] = ['zillow_tried_at' => gmdate('Y-m-d H:i:s', time() - 2 * 86400), 'zillow_error' => true];
$ok(App\Services\ListingDetails::wanted($ldp), 'a failed fetch is tried again the next day');
$ldp['details'] = ['zillow_tried_at' => gmdate('Y-m-d H:i:s', time() - 40 * 86400)];
$ok(App\Services\ListingDetails::wanted($ldp), '...and asked again after that');
App\Settings::set('openwebninja_auto', '0');
$ok(!App\Services\ListingDetails::wanted(PropertyRepo::find($ld)), 'setting the monthly number to 0 turns it off');
App\Settings::set('openwebninja_auto', '1');
App\Quota::log('openwebninja', '/property-details-address', 200, 10, null, 'auto');
$ok(!App\Services\ListingDetails::wanted(PropertyRepo::find($ld)) && App\Services\ListingDetails::autoUsed() >= 1, 'the monthly cap stops automatic fetches');
Db::run("DELETE FROM api_log WHERE provider = 'openwebninja' AND context = 'auto'");
Db::run("DELETE FROM properties WHERE city = 'Autoville'");
App\Settings::set('openwebninja_auto', '60');
App\Settings::set('openwebninja_key', $hadOwn);

/* ── Listing e-mails ───────────────────────────────────────────────── */
$qp = "From: Zillow <instant-updates@mail.zillow.com>\r\nSubject: =?UTF-8?Q?New_listing_in_Fairfax?=\r\nAuthentication-Results: mx.hostinger.com; dkim=pass header.d=mail.zillow.com; dmarc=pass (p=reject) header.from=zillow.com\r\n"
    . "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"b1\"\r\n\r\n--b1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nplain\r\n--b1\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
    . quoted_printable_encode('<table><tr><td><a href="https://www.zillow.com/homedetails/5863-Monticello-Rd-Alexandria-VA-22303/12345_zpid/?utm_source=email"><img src="x.jpg"></a></td></tr>'
        . '<tr><td><b>$489,000</b> 3 bds | 2 ba | 1,350 sqft</td></tr><tr><td><a href="https://www.zillow.com/homedetails/5863-Monticello-Rd-Alexandria-VA-22303/12345_zpid/?utm_medium=x">5863 Monticello Rd, Alexandria, VA 22303</a></td></tr>'
        . '<tr><td><a href="https://www.zillow.com/homedetails/100-Oak-St-Fairfax-VA-22030/999_zpid/">100 Oak St</a> Pending · $615K · 4 beds · 3.5 baths · 2,100 sq ft · Est. $3,900/mo</td></tr>'
        . '<tr><td><a href="https://www.zillow.com/myzillow/unsubscribe?x=1">Unsubscribe</a></td></tr></table>')
    . "\r\n--b1--\r\n";
$mm = App\Notify\MimeMessage::parse($qp);
$ok($mm->fromDomain === 'mail.zillow.com' && $mm->subject === 'New listing in Fairfax', 'e-mail sender and encoded subject are read');
$ok($mm->verified() === true && str_contains($mm->html, '5863-Monticello'), 'DMARC pass is recognised and the quoted-printable HTML part decoded');
$ok(App\Services\MailListings::senderName($mm->fromDomain) === 'Zillow' && App\Services\MailListings::senderName('evil-zillow.com') === null, 'only listing-site domains (and their subdomains) are trusted');
$homes = App\Services\MailListings::listings($mm->html);
$ok(count($homes) === 2, 'two homes found: links to the same home are merged, the unsubscribe link ignored');
$ok($homes[0]['url'] === 'https://www.zillow.com/homedetails/5863-Monticello-Rd-Alexandria-VA-22303/12345_zpid/' && $homes[0]['price'] === 489000
    && $homes[0]['beds'] === 3.0 && $homes[0]['baths'] === 2.0 && $homes[0]['sqft'] === 1350 && $homes[0]['status'] === 'for_sale', 'price, beds, baths and size are read next to the link; tracking removed');
$ok($homes[1]['price'] === 615000 && $homes[1]['baths'] === 3.5 && $homes[1]['sqft'] === 2100 && $homes[1]['status'] === 'pending', '$615K, a pending status, and the monthly payment is not taken as the price');
$fake = App\Notify\MimeMessage::parse(str_replace('dmarc=pass (p=reject)', 'dmarc=fail', str_replace('dkim=pass', 'dkim=fail', $qp)));
$ok($fake->verified() === false && str_starts_with((string) App\Services\MailListings::handle($fake)['note'], 'Skipped'), 'a message the mail server could not authenticate is skipped');
$ok(App\Services\MailListings::handle(App\Notify\MimeMessage::parse("From: someone@gmail.com\r\nSubject: hi\r\n\r\nhello")) === null, 'mail from other senders is left alone');
$sold = App\Services\MailListings::facts('Sold for $500,000 · 3 bds');
$ok($sold['status'] === 'sold', 'a "sold" notice is recognised (and not imported as a listing)');
$ok(App\Http::finalUrl('https://127.0.0.1/x', static fn ($u) => false) === null && App\Http::finalUrl('http://example.com/', static fn ($u) => false) === null, 'link unwrapping refuses local addresses and plain http');

/* ── Fairfax County records ───────────────────────────────────────── */
$ok(App\Providers\Fairfax::covers(['state' => 'VA', 'county' => 'Fairfax', 'zip' => '22030']) && !App\Providers\Fairfax::covers(['state' => 'VA', 'county' => 'Fairfax city', 'zip' => '22030'])
    && !App\Providers\Fairfax::covers(['state' => 'VA', 'county' => 'Loudoun', 'zip' => '20176']) && App\Providers\Fairfax::covers(['state' => 'VA', 'county' => null, 'zip' => '22303']), 'Fairfax County homes are recognised; Fairfax City and other counties are not');
$shape = new ReflectionMethod(App\Providers\Fairfax::class, 'shape');
$shape->setAccessible(true);
$dw = ['PARID' => '0604 16F 0014', 'STYLE_DESC' => 'Split Level 2-Levels', 'YRBLT' => 1960, 'EFFYR' => null, 'YRREMOD' => 2012, 'RMBED' => 4, 'FIXBATH' => 3, 'FIXHALF' => 1, 'RECROMAREA' => 378, 'WBFP_PF' => 2,
    'SFLA' => 1670, 'BSMT_DESC' => 'Full', 'CDU_DESC' => 'Good', 'EXTWALL_DESC' => 'Brick', 'HEAT_DESC' => 'Central A/C', 'USER13_DESC' => 'Composition Shingle', 'USER9_DESC' => 'Daylight', 'GRADE_DESC' => 'Average 10'];
$val = ['APRLAND' => 316000, 'APRBLDG' => 469630, 'APRTOT' => 785630, 'PRITOT' => 775630, 'TAXYR' => 2026, 'FLAG4_DESC' => 'No Exemption'];
$sales = [['SALEDT' => 1353024000000, 'PRICE' => 0, 'SALEVAL_DESC' => 'No consideration'], ['SALEDT' => 1033516800000, 'PRICE' => 359000, 'SALEVAL_DESC' => 'Valid and verified sale'], ['SALEDT' => 614995200000, 'PRICE' => 230000, 'SALEVAL_DESC' => 'Valid and verified sale']];
$rec = $shape->invoke(null, '0604 16F 0014', ['lat' => 38.8392, 'lng' => -77.181, 'address' => '', 'city' => '', 'zip' => '', 'parid' => '0604 16F 0014'], $dw, $val, ['SF' => 12677, 'ACRES' => 0.291], ['LUC_DESC' => 'Single-family, Detached ', 'ZONING_DESC' => 'R-3'], $sales);
$f = $rec['facts'];
$ok($f['beds'] === 4 && $f['baths'] === 3.5 && $f['sqft'] === 1670 && $f['year_built'] === 1960 && $f['lot_sqft'] === 12677 && $f['has_basement'] === true, 'county dwelling record becomes beds, baths (3 full + 1 half), size, year, lot, basement');
$ok($f['assessed_value'] === 785630 && $f['tax_annual'] === (int) round(785630 * 1.12 / 100), 'assessed value, and tax at $1.12 per $100');
$ok($f['last_sale_price'] === 359000 && $f['last_sale_date'] === '2002-10-02', 'last sale is the latest fair-market one, not a $0 transfer');
$ok($rec['county']['year_remodeled'] === 2012 && $rec['county']['condition'] === 'Good' && $rec['county']['land_use'] === 'Single-family, Detached' && count($rec['county']['sales']) === 3, 'remodel year, condition, land use and the full sale history are kept');
$val['FLAG4_DESC'] = 'Elderly/Disabled Exemption';
$ok(!isset($shape->invoke(null, 'x', ['lat' => 1, 'lng' => 1], $dw, $val, null, null, [])['facts']['tax_annual']), 'no tax figure when the home has an exemption');
$cw = PropertyRepo::upsert(['address' => '1 Countyline Ct', 'city' => 'Fairfax', 'state' => 'VA', 'zip' => '22030', 'county' => 'Fairfax', 'status' => 'for_sale', 'list_price' => 500000], 'manual')['id'];
$cwp = PropertyRepo::find($cw);
$ok(App\Services\CountyRecords::wanted($cwp), 'a Fairfax home without its county record is looked up');
$cwp['details']['county_tried_at'] = now();
$ok(!App\Services\CountyRecords::wanted($cwp), 'an address the county did not know is not asked again for a month');
$cwp['details'] = ['county_record' => ['parid' => 'x']];
$ok(!App\Services\CountyRecords::wanted($cwp), 'a home with its record is not looked up again');
Db::run("DELETE FROM properties WHERE id = ?", [$cw]);

/* ── Arlington County records ─────────────────────────────────────── */
$A = App\Providers\County\Arlington::class;
$ok($A::covers(['state' => 'VA', 'county' => 'Arlington', 'zip' => '22213']) && !$A::covers(['state' => 'TX', 'county' => 'Arlington', 'zip' => '76010'])
    && App\Services\CountyRecords::sourceFor(['state' => 'VA', 'county' => 'Arlington', 'zip' => '22213']) === $A
    && App\Services\CountyRecords::sourceFor(['state' => 'VA', 'county' => 'Fairfax', 'zip' => '22030']) === App\Providers\Fairfax::class, 'each home goes to its own county source; Arlington, Texas is not Arlington County');
$ok($A::fairSale('', 735000) && !$A::fairSale('5-Not Market Sale', 500000) && !$A::fairSale('2-Sale or Gift to Relative', 300000) && !$A::fairSale('', 0), 'Arlington market sales are told from gifts and non-market transfers');
$ashape = new ReflectionMethod($A, 'shape');
$ashape->setAccessible(true);
$arl = $ashape->invoke(null,
    ['realEstatePropertyCode' => '01001009', 'propertyClassTypeDsc' => '511-Single Family Detached', 'lotSizeQty' => 10065, 'propertyYearBuilt' => 1950, 'zoningDescListText' => 'R-10', 'latitudeCrd' => '38.89976183', 'longitudeCrd' => '-77.15943186', 'taxExemptionTypeDsc' => null],
    ['dwellingYearBuiltDate' => 2012, 'basementFinishedRecRoomSquareFeetQty' => 1485, 'basementRecRoomTypeDsc' => 'Flr,ceil,wall fin,ptn', 'coolingTypeDsc' => 'Central air', 'heatingTypeDsc' => 'Forced hot air', 'dwellingTypeDsc' => '33 - 2 1/2 sty', 'storiesQuantityDsc' => '2.5', 'fireplaceCnt' => 1],
    [['bedroomCnt' => 0, 'twoFixtureBathroomCnt' => 1, 'threeFixtureBathroomCnt' => 0, 'fourFixtureBathroomCnt' => 0, 'fiveFixtureBathroomCnt' => 0, 'finishedAreaSquareFeetQty' => 1747],
     ['bedroomCnt' => 3, 'twoFixtureBathroomCnt' => 0, 'threeFixtureBathroomCnt' => 2, 'fourFixtureBathroomCnt' => 0, 'fiveFixtureBathroomCnt' => 1, 'finishedAreaSquareFeetQty' => 1604]],
    [['assessmentDate' => '2026-01-01T00:00:00.000Z', 'landValueAmt' => 700000, 'improvementValueAmt' => 500000, 'totalValueAmt' => 1200000], ['assessmentDate' => '2025-01-01T00:00:00.000Z', 'totalValueAmt' => 1150000]],
    [['saleAmt' => 735000, 'saleDate' => '2014-04-16T00:00:00.000Z', 'salesTypeDsc' => null], ['saleAmt' => 0, 'saleDate' => '1999-11-01T00:00:00.000Z', 'salesTypeDsc' => '2-Sale or Gift to Relative']]);
$af = $arl['facts'];
$ok($af['beds'] === 3 && $af['baths'] === 3.5 && $af['sqft'] === 3351 && $af['year_built'] === 2012 && $af['lot_sqft'] === 10065 && $af['property_type'] === 'Single Family' && $af['lat'] === 38.899762, 'Arlington rooms by floor add up: 3 beds, 3 full + 1 half bath, 3,351 sq ft');
$ok($af['assessed_value'] === 1200000 && $af['tax_annual'] === (int) round(1200000 * 1.053 / 100) && $af['last_sale_price'] === 735000 && $af['last_sale_date'] === '2014-04-16', 'Arlington assessment, tax at $1.053, and last market sale');
$ok($arl['county']['source'] === 'arlington' && $arl['county']['assessment_year'] === 2026 && $arl['county']['sales'][1]['type'] === 'Sale or Gift to Relative', 'the record carries its source and the sale history with readable types');

/* ── Maryland and DC records ──────────────────────────────────────── */
$M = App\Providers\County\Maryland::class;
$D = App\Providers\County\DistrictOfColumbia::class;
$ok(App\Services\CountyRecords::sourceFor(['state' => 'MD', 'zip' => '20910']) === $M && App\Services\CountyRecords::sourceFor(['state' => 'DC', 'zip' => '20007']) === $D
    && App\Services\CountyRecords::sourceFor(['state' => 'VA', 'county' => 'District of Columbia', 'zip' => '22209']) === App\Providers\County\Arlington::class
    && App\Services\CountyRecords::sourceFor(['state' => 'VA', 'county' => 'District of Columbia', 'zip' => '22101']) === App\Providers\Fairfax::class && App\Services\CountyRecords::sourceFor(['state' => 'TX', 'zip' => '78704']) === null, 'Maryland and DC homes find their sources; Virginia homes mislabelled “District of Columbia” go by their ZIP; places without one get none');
$ok($M::fairSale('1', 369500) && !$M::fairSale('4', 369500) && !$M::fairSale('2', 50000) && $D::fairSale('Q', 900000) && !$D::fairSale('U', 900000), 'only arm’s-length (MD code 1) and qualified (DC Q) sales count');
$ms = new ReflectionMethod($M, 'shape');
$ms->setAccessible(true);
$md = $ms->invoke(null, ['attributes' => ['ACCTID' => '0101088920', 'PREMSNAM' => 'M V SMITH', 'YEARBLT' => '1998', 'SQFTSTRC' => 1848, 'LANDAREA' => 13.51, 'LUOM' => 'A', 'RESITYP' => 'SF',
    'DESCSTYL' => 'STRY 1 Story With Basement', 'DESCCNST' => 'CNST Siding', 'NFMTTLVL' => 291200, 'NFMLNDVL' => 76300, 'NFMIMPVL' => 214900, 'TRADATE' => '20251107', 'CONSIDR1' => 369500, 'CONVEY1' => 1,
    'SDATWEBADR' => 'https://sdat.dat.maryland.gov/RealProperty/Pages/viewdetails.aspx?County=01&SearchType=ACCT&District=01&AccountNumber=088920', 'JURSCODE' => 'ALLE'], 'geometry' => ['x' => -78.4, 'y' => 39.7]]);
$ok($md['facts']['year_built'] === 1998 && $md['facts']['sqft'] === 1848 && $md['facts']['lot_sqft'] === 588496 && $md['facts']['property_type'] === 'Single Family' && $md['facts']['has_basement'] === true
    && $md['facts']['assessed_value'] === 291200 && $md['facts']['last_sale_price'] === 369500 && $md['facts']['last_sale_date'] === '2025-11-07' && !isset($md['facts']['tax_annual']), 'Maryland parcel: year, size, acres to sq ft, basement from the style, value, last arm’s-length sale; no invented tax');
$ok($md['county']['style'] === '1 Story With Basement' && str_starts_with($md['county']['record_url'], 'https://sdat.dat.maryland.gov/'), 'Maryland record keeps the style and the official SDAT link');
$ds = new ReflectionMethod($D, 'shape');
$ds->setAccessible(true);
$dc = $ds->invoke(null, ['SSL' => '1302    0033', 'PROPERTY_ADDRESS' => '3548 T ST NW WASHINGTON DC 20007', 'LAND_USE_DESCRIPTION' => 'Residential-Row-Single-Family', 'LANDAREA' => 2084, 'TAXRATE' => 0.85,
    'APPRAISED_VALUE_CURRENT_TOTAL' => 1088710.0, 'APPRAISED_VALUE_CURRENT_LAND' => 635220, 'APPRAISED_VALUE_CURRENT_BLDG' => 453490, 'APPRAISED_VALUE_PRIOR_TOTAL' => 1069260, 'HOMESTEAD_DESCRIPTION' => 'SENIOR CREDIT', 'OTR_NEIGHBORHOOD_NAME' => 'Burleith'],
    ['BATHRM' => 1, 'HF_BATHRM' => 1, 'BEDRM' => 3, 'AYB' => 1909, 'YR_RMDL' => null, 'EYB' => 1975, 'STORIES' => 2, 'GBA' => 1316, 'STYLE_D' => '2 Story', 'STRUCT_D' => 'Row Inside', 'GRADE_D' => 'Above Average', 'CNDTN_D' => 'Good', 'HEAT_D' => 'Forced Air', 'AC' => 'Y', 'LANDAREA' => 1523],
    [['SALE_DATE' => -2208970800000, 'SALE_PRICE' => null, 'QUALIFIED' => 'U'], ['SALE_DATE' => 1405569600000, 'SALE_PRICE' => 850000, 'QUALIFIED' => 'Q']], ['LATITUDE' => 38.91, 'LONGITUDE' => -77.07]);
$ok($dc['facts']['beds'] === 3 && $dc['facts']['baths'] === 1.5 && $dc['facts']['sqft'] === 1316 && $dc['facts']['year_built'] === 1909 && $dc['facts']['property_type'] === 'Townhouse' && $dc['facts']['lat'] === 38.91, 'DC residential record: 3 beds, 1.5 baths, 1,316 sq ft, row house');
$ok($dc['facts']['assessed_value'] === 1088710 && $dc['facts']['tax_annual'] === 9254 && $dc['facts']['last_sale_price'] === 850000 && count($dc['county']['sales']) === 1 && $dc['county']['parid'] === '1302 0033', 'DC value, tax at $0.85, qualified sale kept and the 1900 placeholder dropped');

/* ── Lee and Miami-Dade records ───────────────────────────────────── */
$L = App\Providers\County\LeeCounty::class;
$MD = App\Providers\County\MiamiDade::class;
$ok(App\Services\CountyRecords::sourceFor(['state' => 'FL', 'county' => 'Lee', 'zip' => '33904']) === $L && App\Services\CountyRecords::sourceFor(['state' => 'FL', 'county' => 'Miami-Dade', 'zip' => '33131']) === $MD
    && App\Services\CountyRecords::sourceFor(['state' => 'FL', 'county' => 'Leon', 'zip' => '32301']) === null, 'Lee and Miami-Dade homes find their sources; Leon County is not Lee');
$ok($L::fairSale('01', 300000) && !$L::fairSale('05', 300000) && $MD::fairSale('Q', 500000) && !$MD::fairSale('U', 500000), 'Florida qualified sales: Lee code 01, Miami-Dade flag Q');
$ok(App\Providers\County\Street::same(App\Providers\County\Street::split('16 SE 2nd St'), 'SE 2 ST', 'SE 2 ST'), '“2nd St” matches Miami-Dade’s “2 ST”');
$ls = new ReflectionMethod($L, 'shape');
$ls->setAccessible(true);
$lee = $ls->invoke(null, ['STRAP' => '01-44-24-C1-00012.0010', 'SITENUMBER' => '1234', 'SITESTREET' => 'SE 15TH TER', 'SITEZIP' => '33904', 'JUST' => 412000, 'LAND' => 120000, 'BUILDING' => 292000, 'TAXABLE' => 350000,
    'MINBUILTY' => 1987, 'HEATEDAREA' => 1850, 'BEDROOMS' => 3, 'BATHROOMS' => 2, 'POOL' => 1, 'GARAGE' => 2, 'GISACRES' => 0.25, 'LANDUSEDES' => 'SINGLE FAMILY RESIDENTIAL', 'LATITUDE' => 26.57, 'LONGITUDE' => -81.95,
    'S_1DATE' => 1735689600000, 'S_1AMOUNT' => 389000, 'S_1VI' => 'I', 'S_1TC' => '01', 'S_2DATE' => 1420070400000, 'S_2AMOUNT' => 100, 'S_2VI' => 'I', 'S_2TC' => '11', 'Property_URL' => 'https://www.leepa.org/Display/DisplayParcel.aspx?FolioID=1']);
$ok($lee['facts']['beds'] === 3 && $lee['facts']['sqft'] === 1850 && $lee['facts']['lot_sqft'] === 10890 && $lee['facts']['property_type'] === 'Single Family' && $lee['facts']['assessed_value'] === 412000
    && $lee['facts']['last_sale_price'] === 389000 && $lee['facts']['last_sale_date'] === '2025-01-01' && count($lee['county']['sales']) === 2 && $lee['county']['pool'] === 'Yes', 'Lee parcel: specs, just value, qualified last sale, a $100 transfer kept in history but not used');
$mds = new ReflectionMethod($MD, 'shape');
$mds->setAccessible(true);
$mdr = $mds->invoke(null, ['FOLIO' => '3530190670540', 'DOR_DESC' => 'RESIDENTIAL - SINGLE FAMILY : 1 UNIT', 'BEDROOM_COUNT' => 4, 'BATHROOM_COUNT' => 2, 'HALF_BATHROOM_COUNT' => 1, 'BUILDING_HEATED_AREA' => 2100, 'LOT_SIZE' => 7500,
    'YEAR_BUILT' => 1972, 'TOTAL_VAL_CUR' => 650000, 'LAND_VAL_CUR' => 400000, 'BUILDING_VAL_CUR' => 250000, 'TOTAL_VAL_PRI' => 610000, 'ASSESSMENT_YEAR_CUR' => 2026, 'HSTEAD_EX_VAL_CUR' => 50000,
    'DOS_1' => '20240630', 'PRICE_1' => 600000, 'QU_FLG_1' => 'Q', 'DOS_2' => '2010-01-15', 'PRICE_2' => 0, 'QU_FLG_2' => 'U'], ['x' => -80.3, 'y' => 25.7]);
$ok($mdr['facts']['beds'] === 4 && $mdr['facts']['baths'] === 2.5 && $mdr['facts']['property_type'] === 'Single Family' && $mdr['facts']['assessed_value'] === 650000 && $mdr['facts']['last_sale_price'] === 600000
    && $mdr['facts']['last_sale_date'] === '2024-06-30' && $mdr['facts']['lat'] === 25.7 && $mdr['county']['exemption'] === 'Homestead', 'Miami-Dade record: 4 beds, 2.5 baths, market value, qualified sale dated from “20240630”, homestead noted');

/* ── Counties without a source ────────────────────────────────────── */
$mx = PropertyRepo::upsert(['address' => '7 Gap Rd', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78704', 'county' => 'Travis', 'status' => 'for_sale', 'list_price' => 400000], 'manual')['id'];
$miss = App\Services\CountyRecords::missing(50);
$ok(in_array('Travis', array_column($miss, 'county'), true) && !in_array('Fairfax', array_column($miss, 'county'), true), 'a county with homes but no records source is listed as missing; connected ones are not');
Db::run('DELETE FROM properties WHERE id = ?', [$mx]);

/* ── Flips and days on market ─────────────────────────────────────── */
$sf = App\Providers\County\Sales::fields([['date' => '2024-01-10', 'price' => 300000, 'fair' => false], ['date' => '2024-08-01', 'price' => 420000, 'fair' => true], ['date' => null, 'price' => 5, 'fair' => true]]);
$ok($sf['last_sale_price'] === 420000 && $sf['last_sale_date'] === '2024-08-01' && count($sf['events']) === 2, 'sale lists keep every sale as history and the latest fair one as the last sale');
$fz = '99901';
$d = static fn (int $daysAgo) => gmdate('Y-m-d', time() - $daysAgo * 86400);
$mk = static function (string $addr, array $sales) use ($fz) {
    $r = ['address' => $addr, 'city' => 'Flipton', 'state' => 'VA', 'zip' => $fz, 'status' => 'off_market'] + App\Providers\County\Sales::fields($sales);
    return PropertyRepo::upsert($r, 'county')['id'];
};
$ids = [];
foreach ([[300, 150, 250000, 330000], [400, 220, 200000, 280000], [500, 330, 310000, 400000], [600, 420, 180000, 260000], [700, 520, 220000, 300000]] as $i => [$buy, $sell, $bp, $sp]) {
    $ids[] = $mk(($i + 1) . ' Flip St', [['date' => $d($buy), 'price' => $bp, 'fair' => false], ['date' => $d($sell), 'price' => $sp, 'fair' => true]]);
}
$ids[] = $mk('9 Hold St', [['date' => $d(1200), 'price' => 200000, 'fair' => true], ['date' => $d(100), 'price' => 260000, 'fair' => true]]); // held 3 years: not a flip
$ids[] = $mk('8 Flat St', [['date' => $d(300), 'price' => 300000, 'fair' => true], ['date' => $d(200), 'price' => 310000, 'fair' => true]]); // +3%: not a flip
$pace = App\Services\AreaPace::forZip($fz);
$ok($pace['flips']['count'] === 5 && $pace['flips']['months'] !== null && abs($pace['flips']['months'] - 180 / 30.44) < 0.2 && $pace['flips']['gain_pct'] >= 29, 'five flips found (median 180 days ≈ 5.9 months), a 3-year hold and a 3% resale left out');
$hm = App\Services\AreaPace::holdingMonths($fz);
$ok($hm !== null && $hm['months'] === 6.0 && $hm['count'] === 5, 'five flips are enough to set the calculator’s months held (rounded to half months)');
$sub = PropertyRepo::upsert(['address' => '10 Deal St', 'city' => 'Flipton', 'state' => 'VA', 'zip' => $fz, 'status' => 'for_sale', 'list_price' => 250000, 'sqft' => 1500], 'manual')['id'];
$ids[] = $sub;
$an = App\Services\Deal::analyse(PropertyRepo::find($sub));
$ok($an['inputs']['months'] === 6.0 && $an['months_from'] === 'flips' && $an['months_flips'] === 5, 'the deal calculator takes months held from the area’s flips');
Db::run('DELETE FROM properties WHERE zip = ?', [$fz]);

/* ── Verdict ──────────────────────────────────────────────────────── */
$vz = '99905';
$vd = static fn (int $daysAgo) => gmdate('Y-m-d', time() - $daysAgo * 86400);
foreach (range(1, 8) as $i) { // recorded sales nearby: about $300/sqft
    PropertyRepo::upsert(['address' => "$i Comp Way", 'city' => 'Verdictville', 'state' => 'VA', 'zip' => $vz, 'property_type' => 'Single Family', 'sqft' => 1500, 'lot_sqft' => 6000,
        'lat' => 38.80 + $i / 2000, 'lng' => -77.10, 'status' => 'off_market', 'last_sale_price' => 450000 + $i * 1000, 'last_sale_date' => $vd(40 + $i * 10)], 'county');
}
$good = PropertyRepo::upsert(['address' => '1 Bargain Rd', 'city' => 'Verdictville', 'state' => 'VA', 'zip' => $vz, 'property_type' => 'Single Family', 'sqft' => 1500, 'lot_sqft' => 6000,
    'beds' => 3, 'baths' => 1, 'year_built' => 1985, 'lat' => 38.8021, 'lng' => -77.10, 'status' => 'for_sale', 'list_price' => 250000, 'tax_annual' => 2500], 'manual')['id'];
$bad = PropertyRepo::upsert(['address' => '2 Pricey Rd', 'city' => 'Verdictville', 'state' => 'VA', 'zip' => $vz, 'property_type' => 'Single Family', 'sqft' => 1500, 'lot_sqft' => 6000,
    'beds' => 3, 'baths' => 2, 'year_built' => 1985, 'lat' => 38.8022, 'lng' => -77.10, 'status' => 'for_sale', 'list_price' => 520000, 'tax_annual' => 5200], 'manual')['id'];
$none = PropertyRepo::upsert(['address' => '3 Blank Rd', 'city' => 'Nowhere', 'state' => 'VA', 'zip' => '99906', 'status' => 'for_sale', 'list_price' => 300000], 'manual')['id'];
$vg = App\Services\Verdict::for(PropertyRepo::find($good));
$vb = App\Services\Verdict::for(PropertyRepo::find($bad));
$vn = App\Services\Verdict::for(PropertyRepo::find($none));
$ok(in_array($vg['verdict'], ['buy', 'offer'], true) && $vg['offer'] > 250000, 'a home well under its comparable sales is worth buying, with a most-to-offer above the asking price');
$ok(in_array($vb['verdict'], ['lower', 'pass'], true) && $vb['offer'] < 520000, 'a home priced at its after-repair value is not worth buying at that price');
$ok($vn['verdict'] === 'unknown' && $vn['confidence'] === 'low', 'without sales or size the verdict says there is not enough data');
$ok((bool) array_filter($vg['factors'], static fn ($f) => $f['group'] === 'building' && $f['tone'] > 0 && str_contains($f['text'], 'adding a bathroom')), 'a 3-bed, 1-bath home is flagged as a value-add');
$ok(count(array_unique(array_column($vg['factors'], 'group'))) >= 3 && $vg['checks'], 'the verdict covers several groups and lists checks before an offer');
Db::run('DELETE FROM properties WHERE zip IN (?, ?)', [$vz, '99906']);

/* ── .env ─────────────────────────────────────────────────────────── */
$envFile = sys_get_temp_dir() . '/plat-test-' . getmypid() . '.env';
file_put_contents($envFile, "# comment\nRENTCAST_API_KEY=rc_live_123\nSMTP_PASSWORD=\"p@ss \\\"word\\\" #1\"\nTELEGRAM_CHAT_ID='99 88' # trailing\nexport SMTP_PORT=587\nlowercase=x\n");
putenv('REALESTATE_ENV=' . $envFile);
App\Env::reset();
$ok(App\Env::get('RENTCAST_API_KEY') === 'rc_live_123' && App\Env::get('SMTP_PASSWORD') === 'p@ss "word" #1' && App\Env::get('TELEGRAM_CHAT_ID') === '99 88' && App\Env::get('SMTP_PORT') === '587' && !isset(App\Env::all()['lowercase']), '.env lines are read with quotes, escapes, comments and export');
$ok(App\Env::parse('X=' . App\Env::quote('a "b" $c #d')) === ['X' => 'a "b" $c #d'], 'a value written by the exporter reads back exactly');
$hadRc = App\Db::val("SELECT v FROM settings WHERE k = 'rentcast_key'");
Settings::set('rentcast_key', 'from-the-form');
$ok(Settings::get('rentcast_key') === 'rc_live_123' && Settings::inEnv('rentcast_key') && Settings::fromEnv('smtp_host') && !Settings::fromEnv('rentcast_limit'), 'a key in .env wins, and the form cannot overwrite it; limits stay in Settings');
$ok(Settings::get('google_client_id') === null || !Settings::inEnv('google_client_id'), 'a key missing from .env falls back to what was saved before');
putenv('REALESTATE_ENV');
App\Env::reset();
@unlink($envFile);

/* ── Deleting several at once ─────────────────────────────────────── */
$asUser = static function (?array $u): void { (new ReflectionProperty(App\Auth::class, 'user'))->setValue(null, $u); };
$adminRow = Db::one("SELECT id, email, name, role, is_active, session_epoch, telegram_chat FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1");
$asUser($adminRow);
$tmpU = [];
foreach (['bulk1@example.com', 'bulk2@example.com'] as $em) {
    $tmpU[] = Db::insert('users', ['email' => $em, 'name' => 'Bulk', 'role' => 'viewer', 'password_hash' => '', 'created_at' => now()]);
}
$aid = Db::insert('alerts', ['user_id' => $tmpU[0], 'name' => 'gone', 'kind' => 'new_listing', 'location' => '22030', 'criteria' => '{}', 'channels' => 'email', 'every_hours' => 24, 'enabled' => 1, 'created_at' => now()]);
$r = App\Services\Bulk::delete('users', [$tmpU[0], $tmpU[1], (int) $adminRow['id']], false);
$ok($r['count'] === 2 && $r['skipped'] === 1 && !Db::val('SELECT COUNT(*) FROM users WHERE id IN (?, ?)', $tmpU) && !Db::val('SELECT COUNT(*) FROM alerts WHERE id = ?', [$aid]) && Db::val('SELECT COUNT(*) FROM users WHERE id = ?', [$adminRow['id']]), 'ticked team members are deleted with their alerts; you are kept');
$h = [];
foreach ([1, 2, 3] as $i) {
    $h[] = PropertyRepo::upsert(['address' => "$i Bulk Ln", 'city' => 'Bulkton', 'state' => 'VA', 'zip' => '99907', 'status' => 'for_sale', 'list_price' => 100000], 'manual')['id'];
}
App\Services\Pipeline::add($h[0], null);
App\Services\Pipeline::add($h[1], null);
$r = App\Services\Bulk::delete('pipeline', [$h[0]], false);
$ok($r['count'] === 1 && Db::val('SELECT COUNT(*) FROM properties WHERE id = ?', [$h[0]]) && Db::val('SELECT COUNT(*) FROM watchlist WHERE property_id = ?', [$h[1]]), 'removing from the pipeline keeps the home on file and the other entries');
$r = App\Services\Bulk::delete('homes', [], true, [$h[1], $h[2]]);
$ok($r['count'] === 2 && Db::val('SELECT COUNT(*) FROM properties WHERE zip = ?', ['99907']) === 1 && !Db::val('SELECT COUNT(*) FROM watchlist WHERE property_id = ?', [$h[1]]), '"all" deletes exactly the homes of the results, with their pipeline entries');
$viewer = Db::one("SELECT id, email, name, role, is_active, session_epoch, telegram_chat FROM users WHERE role = 'viewer' LIMIT 1");
if ($viewer) {
    $asUser($viewer);
    $ok(!App\Services\Bulk::allowed('homes') && !App\Services\Bulk::allowed('users') && App\Services\Bulk::allowed('alerts'), 'a viewer can delete only their own alerts and profiles');
}
$asUser(null);
Db::run('DELETE FROM properties WHERE zip = ?', ['99907']);

// Listing text translation: pieces fit the free API's size limit; a stored translation is used only for the same text.
$long = str_repeat('This updated home has a new roof and a renovated kitchen. ', 30);
$pieces = App\Services\Translate::pieces($long, 450);
$ok(count($pieces) > 1 && max(array_map('strlen', $pieces)) <= 450 && implode(' ', $pieces) === trim(preg_replace('/\s+/', ' ', $long)), 'long listing text is cut at sentence ends into pieces the free translator accepts');
$ok(App\Services\Translate::pieces(str_repeat('word ', 200), 100) !== [] && max(array_map('strlen', App\Services\Translate::pieces(str_repeat('word ', 200), 100))) <= 100, 'a sentence longer than a piece is cut at spaces');
$tp = ['id' => 1, 'description' => 'Nice home.', 'details' => ['description_fa' => ['text' => 'خانه‌ی خوب.', 'source_hash' => sha1('Nice home.')]]];
$ok(App\Services\Translate::stored($tp, 'fa') === 'خانه‌ی خوب.' && App\Services\Translate::stored(['description' => 'Changed text.'] + $tp, 'fa') === null, 'a stored translation is shown only while the listing text is unchanged');

$trId = (int) Db::val('SELECT id FROM properties WHERE description IS NOT NULL AND description <> "" ORDER BY id LIMIT 1');
if ($trId) {
    $trP = PropertyRepo::find($trId);
    App\Services\Translate::store($trId, 'fa', 'متن ترجمه‌شده', 'test', (string) $trP['description']);
    $trP = PropertyRepo::find($trId);
    $ok(App\Services\Translate::stored($trP, 'fa') === 'متن ترجمه‌شده' && $trP['details'] !== [], 'a translation is kept with the home and read back; the other details stay');
    $d = $trP['details']; unset($d['description_fa']);
    Db::run('UPDATE properties SET details = ? WHERE id = ?', [$d ? json_encode($d) : null, $trId]);
}

/* Regional evidence must preserve geography, distinguish missing data from zero, and match real sales. */
$geoid = '05000US51059';
$acsFixture = ['release' => ['id' => 'acs2024_5yr'], 'geography' => [$geoid => ['name' => 'Fairfax County, VA']], 'data' => [$geoid => []]];
foreach (['B01003001' => 10000, 'B11001001' => 4000, 'B25001001' => 4500, 'B19013001' => 100000, 'B25077001' => 400000,
    'B07003010' => 400, 'B07003013' => 300, 'B07403010' => 200, 'B07403013' => 100] as $col => $value) {
    $table = substr($col, 0, 6);
    $acsFixture['data'][$geoid][$table]['estimate'][$col] = $value;
    $acsFixture['data'][$geoid][$table]['error'][$col] = 10;
}
$parsedAcs = App\Providers\RegionalData::decodeAcs($acsFixture, $geoid);
$eq([$parsedAcs['net_domestic'], $parsedAcs['net_moe'], $parsedAcs['rent']], [400, 20, null], 'regional ACS migration and derived uncertainty, suppressed rent stays unknown');
$eq(App\Providers\RegionalData::decodeAcs($acsFixture, '05000US48209'), null, 'ACS statistics from another county are rejected');
$seriesIds = ['LAUCN510590000000003', 'LAUCN510590000000005'];
$blsFixture = ['status' => 'REQUEST_SUCCEEDED', 'Results' => ['series' => [
    ['seriesID' => $seriesIds[0], 'data' => [['year' => '2025', 'period' => 'M09', 'value' => '4'], ['year' => '2025', 'period' => 'M13', 'value' => '99']]],
    ['seriesID' => $seriesIds[1], 'data' => [['year' => '2025', 'period' => 'M09', 'value' => '1020'], ['year' => '2024', 'period' => 'M09', 'value' => '1000']]],
]]];
$parsedBls = App\Providers\RegionalData::decodeBls($blsFixture, $seriesIds);
$ok($parsedBls['month'] === '2025-09' && abs($parsedBls['employment_change'] - 2) < 0.001, 'BLS year-on-year same-month comparison excludes annual-average M13');
$unknownRegional = App\Services\RegionalDemand::for(['id' => 0, 'zip' => '', 'state' => '', 'lat' => null, 'lng' => null]);
$eq([$unknownRegional['coverage'], $unknownRegional['score']], [0, null], 'unknown regional evidence produces no score or assumed facts');
$eq(array_sum(array_column($unknownRegional['groups'], 'weight')), 100, 'regional factors use the requested weights totaling 100');
$regionalIds = [];
$regionalKeys = [];
try {
    $subjectId = PropertyRepo::upsert(['address' => '11 Regional Test Subject', 'city' => 'Fixture', 'state' => 'VA', 'zip' => '20998',
        'lat' => 38.855, 'lng' => -77.298, 'property_type' => 'Single Family', 'list_price' => 350000, 'tax_annual' => 3000, 'status' => 'for_sale'], 'manual')['id'];
    $regionalIds[] = $subjectId;
    foreach (range(1, 9) as $i) {
        $fixtureId = PropertyRepo::upsert(['address' => ($i + 20) . ' Regional Test Sale', 'city' => 'Fixture', 'state' => $i === 9 ? 'MD' : 'VA',
            'zip' => '20998', 'property_type' => 'Single Family', 'status' => 'off_market', 'last_sale_price' => 400000,
            'last_sale_date' => gmdate('Y-m-d', time() - 10 * 86400)], 'manual')['id'];
        $regionalIds[] = $fixtureId;
        PropertyRepo::addEvent($fixtureId, 'listed', 400000, gmdate('Y-m-d', time() - 50 * 86400), 'manual');
    }
    $cacheItems = [
        ['census', 'tract', ['lat' => 38.855, 'lng' => -77.298], ['state' => '51', 'county' => '059', 'tract' => '100100']],
        ['regional', 'acs', ['fips' => '51059'], $parsedAcs],
        ['regional', 'bls', ['fips' => '51059'], $parsedBls],
    ];
    foreach ($cacheItems as [$provider, $what, $paramsCache, $payload]) {
        $key = App\Cache::key($provider, $what, $paramsCache);
        $regionalKeys[$key] = Db::one('SELECT * FROM api_cache WHERE cache_key = ?', [$key]);
        App\Cache::put($key, $provider, $payload, 3600);
    }
    $regional = App\Services\RegionalDemand::for(PropertyRepo::find($subjectId));
    $ok($regional['county'] === 'Fairfax County, VA' && $regional['score'] !== null && $regional['coverage'] >= 50,
        'verified county statistics and sufficient linked sales produce a regional score');
    $eq($regional['groups']['market']['metrics']['Sales with a linked listing history'], '8', 'regional market statistics exclude other states');
    $eq($regional['groups']['market']['metrics']['Median days from listing to closing'], '40', 'regional days measure listing through actual closing');
    $ok($regional['score'] >= 0 && $regional['score'] <= 100 && $regional['groups']['safety']['points'] === null
        && $regional['groups']['development']['points'] === null, 'scores stay bounded and unavailable safety and projects remain unscored');
} finally {
    foreach ($regionalIds as $id) {
        Db::run('DELETE FROM properties WHERE id = ?', [$id]);
    }
    foreach ($regionalKeys as $key => $row) {
        Db::run('DELETE FROM api_cache WHERE cache_key = ?', [$key]);
        if ($row) {
            Db::insert('api_cache', $row);
        }
    }
}

/* Individual AI reports validate model output and isolate location evidence. */
$analysisPacket = ['property' => ['id' => 123], 'facts' => [['id' => 'f1'], ['id' => 'f2']]];
$modelAnswer = ['property_id' => 123];
foreach (['fa', 'en'] as $language) {
    $modelAnswer[$language] = ['summary' => 'Evidence needs careful review.', 'sections' => []];
    foreach (['social','economic','housing','location','development','risk'] as $section) {
        $modelAnswer[$language]['sections'][] = ['key' => $section, 'text' => 'Review the stored evidence before an offer.', 'evidence_ids' => ['f1']];
    }
}
$ok(App\Providers\OpenRouter::validate($modelAnswer, $analysisPacket) !== null, 'AI narrative accepts complete bilingual prose with valid evidence citations');
$bad = $modelAnswer; $bad['property_id'] = 124;
$eq(App\Providers\OpenRouter::validate($bad, $analysisPacket), null, 'AI report for another property is rejected');
$bad = $modelAnswer; $bad['fa']['summary'] = 'Income is 999999.';
$eq(App\Providers\OpenRouter::validate($bad, $analysisPacket), null, 'AI numerical claims cannot replace calculated evidence');
$bad = $modelAnswer; $bad['en']['sections'][0]['evidence_ids'] = ['invented'];
$eq(App\Providers\OpenRouter::validate($bad, $analysisPacket), null, 'unknown AI evidence citations are rejected');
$bad = $modelAnswer; $bad['fa']['sections'][0]['evidence_ids'] = [['nested']];
$eq(App\Providers\OpenRouter::validate($bad, $analysisPacket), null, 'malformed model citations fail validation cleanly');
$bad = $modelAnswer; $bad['en']['sections'][0]['key'] = 'risk';
$eq(App\Providers\OpenRouter::validate($bad, $analysisPacket), null, 'duplicated AI sections are rejected');
$placeRows = [
    ['id'=>1, 'lat'=>38.85, 'lon'=>-77.30, 'tags'=>['amenity'=>'school','name'=>'Nearby school']],
    ['id'=>2, 'lat'=>39.50, 'lon'=>-77.30, 'tags'=>['amenity'=>'school','name'=>'Distant school']],
    ['id'=>3, 'lat'=>38.85, 'lon'=>-77.30, 'tags'=>['construction'=>'office','name'=>'Mapped site']],
];
$locationParsed = App\Providers\LocationEvidence::parse($placeRows, 38.85, -77.30);
$ok($locationParsed['places']['school']['osm_id'] === 1 && $locationParsed['construction'][0]['approval'] === null,
    'property-local places exclude distant sites and mapped construction has no assumed approval');
$analysisId = PropertyRepo::upsert(['address'=>'71 Intelligence Check Ct','city'=>'Fixture','state'=>'VA','zip'=>'20997',
    'status'=>'for_sale','list_price'=>250000,'sqft'=>1500,'property_type'=>'Single Family'], 'manual')['id'];
try {
    App\Services\PropertyAnalysis::enqueue($analysisId);
    App\Services\PropertyAnalysis::enqueue($analysisId);
    $eq((int) Db::val('SELECT COUNT(*) FROM property_analyses WHERE property_id=?',[$analysisId]), 1, 'multiple visits deduplicate the individual analysis queue');
    $packet = App\Services\InvestmentEvidence::collect(PropertyRepo::find($analysisId), false);
    $ok($packet['property']['id'] === $analysisId && $packet['scores']['final_score'] === null
        && $packet['scores']['infrastructure'] === null && count($packet['missing_data']) > 0,
        'missing evidence produces null scores in the subject-specific packet');
    $eq(array_sum($packet['scores']['weights']), 100, 'individual investment weights total 100');
    $callsBefore = (int) Db::val('SELECT COUNT(*) FROM api_log');
    $preview = App\Services\PropertyAnalysis::publicReport($analysisId);
    $ok(!empty($preview['preview']) && $preview['evidence']['property']['id'] === $analysisId
        && $preview['evidence']['scores']['final_score'] === null && $preview['narrative'] === null,
        'pending report immediately exposes subject-specific saved evidence without inventing missing scores or prose');
    $eq((int) Db::val('SELECT COUNT(*) FROM api_log'), $callsBefore, 'cache-only preview makes no metered provider calls');
    Db::run("UPDATE property_analyses SET status='writing' WHERE property_id=?", [$analysisId]);
    App\Services\PropertyAnalysis::enqueue($analysisId, true);
    $eq(Db::val('SELECT status FROM property_analyses WHERE property_id=?', [$analysisId]), 'writing', 'refresh cannot restart a worker writing its interpretation');
    $chooseWriting = new ReflectionMethod(App\Services\PropertyAnalysis::class, 'due');
    $eq($chooseWriting->invoke(null, $analysisId), null, 'writing reports are not selected as duplicate work');
    Db::run("UPDATE property_analyses SET status='pending' WHERE property_id=?", [$analysisId]);
    $ok(Db::val('SELECT opened_at FROM property_analyses WHERE property_id=?', [$analysisId]) !== null, 'only a specifically requested property is marked as opened');
    $otherId = PropertyRepo::upsert(['address'=>'72 Intelligence Check Ct','city'=>'Fixture','state'=>'VA','zip'=>'20997',
        'status'=>'for_sale','list_price'=>260000,'sqft'=>1500,'property_type'=>'Single Family'], 'manual')['id'];
    try {
        $searchCount = (int) Db::val('SELECT COUNT(*) FROM property_analyses');
        Search::run(Search::fromRequest(['q'=>'20997']), ['fetch'=>false]);
        $eq((int) Db::val('SELECT COUNT(*) FROM property_analyses'), $searchCount, 'searching an area creates no additional analysis requests');
        $eq(App\Services\PropertyAnalysis::get($otherId), null, 'a second house in the same search remains unanalyzed until opened');
        Db::run('INSERT INTO property_analyses(property_id, requested_at,updated_at,next_attempt_at) VALUES(?,?,?,?)', [$otherId,now(),now(),now()]);
        $choose = new ReflectionMethod(App\Services\PropertyAnalysis::class, 'due');
        $eq($choose->invoke(null, $otherId), null, 'legacy bulk work remains dormant');
        $eq((int) $choose->invoke(null, $analysisId)['property_id'], $analysisId, 'targeted worker selection returns only the opened property');
    } finally {
        Db::run('DELETE FROM properties WHERE id=?',[$otherId]);
    }
} finally {
    Db::run('DELETE FROM properties WHERE id=?',[$analysisId]);
}

/* School ratings retain their source scale and remain specific to the opened home. */
$schoolData = App\Providers\SchoolEvidence::normalize([
    ['name'=>'Demo Primary School','rating'=>8.5,'distance'=>0.7,'level'=>'Primary','isAssigned'=>false,'link'=>'https://www.greatschools.org/virginia/example/'],
    ['name'=>'Demo Unrated School','rating'=>null,'distance'=>1.1],
    ['name'=>'Demo Invalid School','rating'=>99,'distance'=>-5,'link'=>'javascript:alert(1)'],
    ['name'=>'Demo Distant School','rating'=>10,'distance'=>20],
]);
$eq([$schoolData[0]['rating'],$schoolData[0]['rating_scale'],$schoolData[0]['rating_source'],$schoolData[0]['assigned']],
    [8.5,10,'GreatSchools via Zillow',false], 'school ratings preserve original ten-point scale, attribution and assignment flag');
$eq([$schoolData[2]['rating'],$schoolData[2]['distance_miles'],$schoolData[2]['source_url']], [null,null,null], 'invalid ratings, negative distances and unsafe source URLs are rejected');
$schoolSubject = ['address'=>'Test House', 'city'=>'Test', 'state'=>'VA','zip'=>'22030','details'=>['schools'=>$schoolData]];
$nearSchools = App\Providers\SchoolEvidence::forProperty($schoolSubject,[]);
$eq(count($nearSchools),3,'a distant highly rated school is excluded from the nearby list');
$eq(App\Providers\SchoolEvidence::forProperty(['address'=>'Another House','city'=>'Test','state'=>'VA','zip'=>'22030','details'=>[]],[]),
    [],'school ratings for one home never become ratings for another home');
$facilities = App\Providers\LocationEvidence::parse([
    ['type'=>'node','id'=>11,'lat'=>38.85,'lon'=>-77.3,'tags'=>['amenity'=>'clinic','name'=>'Demo clinic']],
    ['type'=>'node','id'=>12,'lat'=>38.851,'lon'=>-77.3,'tags'=>['amenity'=>'hospital','name'=>'Demo hospital']],
    ['type'=>'node','id'=>13,'lat'=>38.852,'lon'=>-77.3,'tags'=>['amenity'=>'pharmacy','name'=>'Demo pharmacy']],
    ['type'=>'node','id'=>14,'lat'=>38.853,'lon'=>-77.3,'tags'=>['amenity'=>'school','name'=>'Demo school one']],
    ['type'=>'node','id'=>15,'lat'=>38.854,'lon'=>-77.3,'tags'=>['amenity'=>'school','name'=>'Demo school two']],
],38.85,-77.3);
$ok(isset($facilities['places']['clinic'],$facilities['places']['hospital'],$facilities['places']['pharmacy']) && count($facilities['schools'])===2,
    'clinics, hospitals and pharmacies are distinguished and multiple nearby schools are collected');
$mappedOnly = App\Providers\SchoolEvidence::forProperty($schoolSubject,$facilities);
$ok(count(array_filter($mappedOnly,static fn($s)=>$s['rating']===null))>=3,'map-only schools remain unrated');
$mapDetails = new ReflectionMethod(App\Providers\OpenWebNinja::class,'mapDetails');
$schoolMapped = $mapDetails->invoke(null, ['zpid'=>12345,'address'=>['streetAddress'=>'1 Demo St','city'=>'Test','state'=>'VA','zipcode'=>'22030'],
    'homeStatus'=>'FOR_SALE','schools'=>[['name'=>'Demo Source School','rating'=>9,'distance'=>0.8]]]);
$eq($schoolMapped['details']['schools'][0]['rating'],9.0,'school ratings survive normal listing import');

/* Exact addresses, apartment identity and named buildings. All provider/map data here are fixtures. */
$pastedHome = Address::parseLocation("5501 Seminary Rd\nApartment 1703 S\n22041 Falls Church VA");
$eq([$pastedHome['address'], $pastedHome['city'], $pastedHome['state'], $pastedHome['zip']],
    ['5501 Seminary Rd Apartment 1703 S','Falls Church','VA','22041'], 'multiline postal address with ZIP before city retains the apartment');
$flatHome = Address::parseLocation('5501 Seminary Rd Apt 1703 S Falls Church VA 22041');
$eq($flatHome['key'], $pastedHome['key'], 'a street suffix disambiguates the same full address without commas');
$eq(Address::parseLocation('5501 Seminary Rd Apt 1703 S, Falls Church VA 22041')['key'], $pastedHome['key'], 'two-part address is supported');
$eq(Address::parseLocation('123 Unclear Roadless Somewhere TX')['type'] ?? null, null, 'an ambiguous incomplete street address stays unresolved');
$ok(Address::sameProperty($pastedHome, ['address'=>'5501 Seminary Road #1703S','city'=>'Falls Church','state'=>'VA','zip'=>'22041']), 'source unit spelling and separated tower letter match');
$ok(!Address::sameProperty($pastedHome, ['address'=>'5501 Seminary Road Apt 1703N','city'=>'Falls Church','state'=>'VA','zip'=>'22041']), 'another tower is a different apartment');
$ok(!Address::sameProperty($pastedHome, ['address'=>'5501 Seminary Road','city'=>'Falls Church','state'=>'VA','zip'=>'22041']), 'building-level data do not become apartment-level facts');
$ok(!Address::sameProperty($pastedHome, ['address'=>'5501 Seminary Rd #1703S','city'=>'Falls Church','state'=>'MD','zip'=>'22041']), 'same street and apartment in a different state cannot match');
$buildingLoc = App\Services\BuildingSearch::location('Demo Towers, Fixtureville, VA 24979');
$eq([$buildingLoc['name'],$buildingLoc['city'],$buildingLoc['state'],$buildingLoc['zip']], ['Demo Towers','Fixtureville','VA','24979'], 'building name has an explicit city, state and ZIP');
$eq(App\Services\BuildingSearch::location('Demo Towers, Virginia')['state'], 'VA', 'full state name is supported for buildings');
$eq(Search::location(Search::fromRequest(['q'=>$pastedHome['label'],'search_kind'=>'building']))['type'], 'address', 'a full address is recognized even after a building-name search');
$eq(Search::compact(Search::fromRequest(['q'=>'Demo Towers','search_kind'=>'building']))['search_kind'], 'building', 'saved searches preserve the building search mode');
$features = [
    ['properties'=>['name'=>'Demo Towers South','housenumber'=>'71','street'=>'Fixture Road','city'=>'Fixtureville','state'=>'Virginia','postcode'=>'24979','countrycode'=>'US','osm_type'=>'W','osm_id'=>123]],
    ['properties'=>['name'=>'Different Towers','housenumber'=>'72','street'=>'Fixture Road','city'=>'Fixtureville','state'=>'Virginia','postcode'=>'24979','countrycode'=>'US']],
    ['properties'=>['name'=>'Demo Towers','housenumber'=>'71','street'=>'Fixture Road','city'=>'Different City','state'=>'Virginia','postcode'=>'24979','countrycode'=>'US']],
    ['properties'=>['name'=>'Demo Towers','housenumber'=>'71','street'=>'Fixture Road','city'=>'Fixtureville','state'=>'Maryland','postcode'=>'24979','countrycode'=>'US']],
];
$buildingLoc['buildings'] = App\Services\BuildingSearch::map($features, $buildingLoc);
$eq(count($buildingLoc['buildings']),1,'named map matches exclude unrelated names, cities and states');
$eq($buildingLoc['buildings'][0]['source_url'],'https://www.openstreetmap.org/way/123','building address keeps an actual map citation');
$buildingIds = [];
try {
    $unitId = PropertyRepo::upsert(['address'=>'71 Fixture Rd Apt 1703 S','city'=>'Fixtureville','state'=>'VA','zip'=>'24979',
        'status'=>'for_sale','list_price'=>250000,'property_type'=>'Condo'], 'manual')['id'];
    $buildingIds[] = $unitId;
    $otherUnitId = PropertyRepo::upsert(['address'=>'71 Fixture Rd Apt 1703 N','city'=>'Fixtureville','state'=>'VA','zip'=>'24979',
        'status'=>'for_sale','list_price'=>280000,'property_type'=>'Condo'], 'manual')['id'];
    $buildingIds[] = $otherUnitId;
    $unrelatedId = PropertyRepo::upsert(['address'=>'72 Fixture Rd','city'=>'Fixtureville','state'=>'VA','zip'=>'24979',
        'status'=>'for_sale','list_price'=>200000], 'manual')['id'];
    $buildingIds[] = $unrelatedId;
    $legacyHash = sha1('71 fixture rd unit 1703 s|24979');
    Db::run('UPDATE properties SET addr_hash=? WHERE id=?',[$legacyHash,$unitId]);
    $eq(PropertyRepo::upsert(['address'=>'71 Fixture Rd Apt 1703 S','city'=>'Fixtureville','state'=>'VA','zip'=>'24979'], 'manual')['id'],
        $unitId,'legacy apartment hashes are upgraded without creating duplicate properties');
    $eq(PropertyRepo::findAddress(['address'=>'71 Fixture Road #1703S','city'=>'Fixtureville','state'=>'VA','zip'=>'']), $unitId, 'saved unit is reused when a pasted address omits the ZIP');
    $eq(PropertyRepo::findAddress(['address'=>'71 Fixture Road #1704S','city'=>'Fixtureville','state'=>'VA','zip'=>'24979']), null, 'unknown apartment never opens a neighbouring unit');
    $matches = PropertyRepo::search(['loc'=>$buildingLoc,'mode'=>'sale']);
    $matchedIds = array_column($matches['rows'],'id');
    sort($matchedIds);
    $wantIds = [$unitId,$otherUnitId];
    sort($wantIds);
    $eq(array_map('intval',$matchedIds),$wantIds,'building search returns units at its verified address and excludes the neighbouring building');
    $noMap = array_replace($buildingLoc,['name'=>'Missing Building','buildings'=>[]]);
    $eq(PropertyRepo::search(['loc'=>$noMap])['total'],0,'an unresolved building never broadens to the whole city');
    $namedId = PropertyRepo::upsert(['address'=>'73 Fixture Rd','city'=>'Fixtureville','state'=>'VA','zip'=>'24979','status'=>'for_sale','list_price'=>220000,
        'details'=>['building_name'=>'Missing Building']], 'manual')['id'];
    $buildingIds[] = $namedId;
    $eq(PropertyRepo::search(['loc'=>$noMap])['total'],1,'a verified provider building name can match without a map result');
    $eq(PropertyRepo::search(['loc'=>array_replace($noMap,['name'=>"%' OR 1=1 --"])])['total'],0,'building name wildcard and SQL characters remain literal');
    $rawUnit = $mapDetails->invoke(null,['zpid'=>9988,'address'=>['streetAddress'=>'71 Fixture Road','city'=>'Fixtureville','state'=>'VA','zipcode'=>'24979'],
        'unitNumber'=>'1703N','buildingName'=>'Demo Towers South','homeStatus'=>'FOR_SALE','price'=>280000,'schools'=>[]]);
    $eq($rawUnit['address'],'71 Fixture Road Unit 1703N','source unitNumber is retained when it is separate from streetAddress');
    $ok(!empty($rawUnit['details']['zillow']) && $rawUnit['details']['building_name']==='Demo Towers South' && isset($rawUnit['details']['schools']),
        'building-name import preserves full listing facts and school data');
    $ownKey = Settings::get('openwebninja_key');
    Settings::set('openwebninja_key','test-cached-only');
    $subject = PropertyRepo::find($unitId);
    $cacheKey = App\Cache::key('openwebninja','/property-details-address',['address'=>Address::full($subject)]);
    App\Cache::put($cacheKey,'openwebninja',['zpid'=>9988,'address'=>['streetAddress'=>'71 Fixture Road Apt 1703N','city'=>'Fixtureville','state'=>'VA','zipcode'=>'24979'],
        'homeStatus'=>'FOR_SALE','price'=>999999,'imgSrc'=>'https://example.test/wrong-apartment.jpg'],3600);
    try {
        $detailResult = App\Services\ListingDetails::fetch($subject);
        $after = PropertyRepo::find($unitId);
        $ok($detailResult['status']==='none' && (int)$after['list_price']===250000 && empty($after['photo_url']), 'mismatched provider apartment cannot overwrite this home price or photos');
    } finally {
        Settings::set('openwebninja_key',$ownKey);
        Db::run('DELETE FROM api_cache WHERE cache_key=?',[$cacheKey]);
    }
} finally {
    foreach ($buildingIds as $id) Db::run('DELETE FROM properties WHERE id=?',[$id]);
}

/* Homes.com public-page integration: fixture pages, cached responses and actual shared search flow. */
$homesGateway = App\Providers\HomesCom::failure(['status'=>503,'headers'=>[]]);
$ok($homesGateway['retryable'] && $homesGateway['delay']===60, 'a temporary Homes.com gateway failure allows a bounded retry without a one-hour ban');
$homesDenied = App\Providers\HomesCom::failure(['status'=>403,'headers'=>[]]);
$ok(!$homesDenied['retryable'] && $homesDenied['code']==='access_denied' && $homesDenied['delay']===300,
    'Homes.com access refusal is explicit and is not immediately retried');
$homesLimited = App\Providers\HomesCom::failure(['status'=>429,'headers'=>['retry-after'=>'1800']]);
$ok(!$homesLimited['retryable'] && $homesLimited['delay']===1800 && $homesLimited['code']==='rate_limit',
    'Homes.com rate-limit cooldown honors Retry-After instead of hiding the reason');
$homesMaintenance = App\Providers\HomesCom::failure(['status'=>503,'headers'=>['retry-after'=>'120']]);
$ok(!$homesMaintenance['retryable'] && $homesMaintenance['delay']===120, 'server-requested waiting suppresses immediate Homes.com retry');
$homesDateLimit = App\Providers\HomesCom::failure(['status'=>429,'headers'=>['retry-after'=>gmdate('D, d M Y H:i:s',time()+900).' GMT']]);
$ok($homesDateLimit['delay']>=898 && $homesDateLimit['delay']<=900, 'Homes.com also honors HTTP-date Retry-After');
$homesCooldownKey=App\Cache::key('homes',App\Providers\HomesCom::COOLDOWN_KEY);
$homesCooldownPrior=App\Cache::get($homesCooldownKey);
try {
    $blocked=['error'=>$homesDenied['message'],'error_code'=>'access_denied','http_status'=>403,'retry_at'=>gmdate('Y-m-d H:i:s',time()+300)];
    App\Cache::put($homesCooldownKey,'homes',$blocked,300);
    $blockedResult=App\Providers\HomesCom::search(['type'=>'city','city'=>'Cooldown Fixture','state'=>'VA','key'=>'homes-cooldown-test']);
    $ok(!$blockedResult['ok'] && $blockedResult['error_code']==='access_denied' && $blockedResult['http_status']===403
        && $blockedResult['retry_at']===$blocked['retry_at'] && $blockedResult['items']===[],
        'cached host refusal preserves the actual reason and retry time without returning invented listings');
} finally {
    Db::run('DELETE FROM api_cache WHERE cache_key=?',[$homesCooldownKey]);
    if($homesCooldownPrior!==null)App\Cache::put($homesCooldownKey,'homes',$homesCooldownPrior,300);
}
$homesLoc = Address::parseLocation('24979');
$homesUrl = 'https://www.homes.com/fixtureville-va/24979/';
$homesPropertyUrl = 'https://www.homes.com/property/1-provider-fixture-rd-fixtureville-va-unit-1s/testhome1/';
$homesAddress = ['@type'=>'PostalAddress','streetAddress'=>'1 Provider Fixture Rd Unit 1 S','addressLocality'=>'Fixtureville','addressRegion'=>'VA','postalCode'=>'24979','addressCountry'=>'US'];
$homesRecord = ['@type'=>['RealEstateListing','Product'],'url'=>$homesPropertyUrl,'potentialAction'=>['@type'=>'BuyAction'],
    'offers'=>['priceCurrency'=>'USD','price'=>275000,'availability'=>'https://schema.org/InStock'],
    'mainEntity'=>['@type'=>'Apartment','accommodationCategory'=>'Condominium','address'=>$homesAddress,'image'=>'https://images.homes.com/listings/test-house.jpg',
        'numberOfBedrooms'=>3,'numberOfBathroomsTotal'=>2,'floorSize'=>['value'=>1500,'unitCode'=>'FTK']],
    'subjectOf'=>[['location'=>['address'=>$homesAddress,'geo'=>['latitude'=>38.8,'longitude'=>-77.1]]]]];
$homesCard = '<article class="search-placard for-sale-placard" data-est="for sale" data-pk="testhome1"><address>1 Provider Fixture Rd Unit 1 S, Fixtureville, VA 24979</address><ul class="detailed-info-container"><li>3 Beds</li><li>2.5 Baths</li><li>1,500 Sq Ft</li></ul></article>';
$homesHtml = static function (array $rows, string $url, string $card) : string {
    return '<html><head><link rel="canonical" href="'.htmlspecialchars($url,ENT_QUOTES).'"></head><body>'.$card
        .'<script type="application/ld&#x2B;json">'.json_encode(['@graph'=>[['@type'=>'CollectionPage','url'=>$url,'mainEntity'=>['@type'=>'ItemList','itemListElement'=>$rows]]]]) .'</script></body></html>';
};
$homesParsed = App\Providers\HomesCom::parse($homesHtml([$homesRecord],$homesUrl,$homesCard),$homesLoc,$homesUrl,now());
$eq([$homesParsed['ok'],count($homesParsed['items'])],[true,1],'public Homes.com collection requires a matching visible for-sale card');
$homesItem = $homesParsed['items'][0];
$eq([$homesItem['property_type'],$homesItem['beds'],$homesItem['baths'],$homesItem['sqft']],['Condo',3.0,2.5,1500.0],'visible fractional bathrooms override rounded source schema');
$eq([$homesItem['photo_url'],$homesItem['source_url']],['https://images.homes.com/listings/test-house.jpg',$homesPropertyUrl],'photo and property link remain attached to the same home');
$eq([$homesItem['lat'],$homesItem['lng']],[38.8,-77.1],'coordinates come from an event for exactly the same apartment');
$eq(App\Providers\HomesCom::parse($homesHtml([$homesRecord],$homesUrl,$homesCard),$homesLoc,'https://www.homes.com/other-city-va/',now())['ok'],false,'a redirected Homes.com area is rejected');
$eq(App\Providers\HomesCom::parse('<html>challenge page</html>',$homesLoc,$homesUrl,now())['ok'],false,'challenge or redesigned pages fail closed');
$eq(count(App\Providers\HomesCom::parse($homesHtml([$homesRecord],$homesUrl,str_replace('Unit 1 S','Unit 1 N',$homesCard)),$homesLoc,$homesUrl,now())['items']),0,'JSON-LD for one unit cannot acquire another unit visible in the card');
$eq(count(App\Providers\HomesCom::parse($homesHtml([$homesRecord],$homesUrl,$homesCard),Address::parseLocation('24978'),$homesUrl,now())['items']),0,'another ZIP is never relabelled as the requested ZIP');
$changedRecord = $homesRecord;
$changedRecord['potentialAction']['@type']='RentAction';
$eq(count(App\Providers\HomesCom::parse($homesHtml([$changedRecord],$homesUrl,$homesCard),$homesLoc,$homesUrl,now())['items']),0,'rental cards never become for-sale listings');
$changedRecord = $homesRecord;
$changedRecord['subjectOf'][0]['location']['address']['streetAddress']='1 Provider Fixture Rd Unit 1 N';
$geoRejected = App\Providers\HomesCom::parse($homesHtml([$changedRecord],$homesUrl,$homesCard),$homesLoc,$homesUrl,now())['items'][0];
$ok(!isset($geoRejected['lat'],$geoRejected['lng']),'coordinates for a neighbouring apartment are discarded');
$soldPage = $homesUrl.'sold/';
$eq(App\Providers\HomesCom::parse($homesHtml([$homesRecord],$soldPage,$homesCard),$homesLoc,$soldPage,now())['ok'],false,'sold pages do not supply asking prices as recorded transactions');
$eq(App\Providers\HomesCom::propertyUrl('https://www.homes.com.evil.test/property/a/id/'),null,'lookalike source domains are rejected');
$eq(App\Providers\HomesCom::pageUrl(Address::parseLocation('Fairfax County, VA')),'https://www.homes.com/fairfax-county-va/','county source uses its own county page');
$exactHomes = Address::parseLocation('1 Provider Fixture Rd Apt 1S, Fixtureville, VA 24979');
$ok(App\Services\HomesSearch::matches($homesItem,$exactHomes) && !App\Services\HomesSearch::matches($homesItem,array_replace($exactHomes,['address'=>'1 Provider Fixture Rd Apt 1N'])), 'exact-address enrichment imports only the requested apartment');
$homesIds = [];
$homesCacheKey = App\Cache::key('homes',App\Providers\HomesCom::CACHE_VERSION,['key'=>$homesLoc['key']]);
$homesPriorCache = App\Cache::get($homesCacheKey);
$primaryKeys = [Settings::get('rentcast_key'),Settings::get('openwebninja_key')];
try {
    $primaryId = PropertyRepo::upsert(['address'=>$homesItem['address'],'city'=>$homesItem['city'],'state'=>'VA','zip'=>'24979',
        'status'=>'for_sale','list_price'=>300000,'beds'=>4,'photo_url'=>'https://example.test/rentcast-house.jpg'], 'rentcast')['id'];
    $homesIds[] = $primaryId;
    $beforeCount = (int) Db::val('SELECT COUNT(*) FROM properties');
    $savedHome = App\Services\HomesSearch::save($homesItem);
    $combinedHome = PropertyRepo::find($primaryId);
    $eq([$savedHome['id'],(int)Db::val('SELECT COUNT(*) FROM properties')],[$primaryId,$beforeCount],'RentCast and Homes.com deduplicate into one property');
    $eq([(int)$combinedHome['list_price'],(int)$combinedHome['beds'],$combinedHome['photo_url'],$combinedHome['source']],
        [300000,4,'https://example.test/rentcast-house.jpg','rentcast'],'Homes.com never silently overwrites known RentCast price, beds or photo');
    $eq([(int)$combinedHome['details']['homes']['price'],(float)$combinedHome['baths']],[275000,2.5],'source quote stays separate while missing facts can be filled');
    $ok(!App\Services\HomesSearch::save($homesItem)['changed'],'cached source observations do not repeatedly invalidate property reports');
    $countyObservation = $homesItem;
    $countyObservation['county'] = 'Fixture County';
    $countyObservation['details']['homes']['observed_at'] = gmdate('Y-m-d H:i:s',time()-3600);
    $countyObservation['details']['homes']['price'] = 250000;
    App\Services\HomesSearch::save($countyObservation);
    $withCounty = PropertyRepo::find($primaryId);
    $eq($withCounty['county'],'Fixture County','an older cached county page can fill missing county membership');
    $eq((int)$withCounty['details']['homes']['price'],275000,'filling county context does not replace a newer Homes.com quote');
    $nextUnit = $homesItem;
    $nextUnit['address']='1 Provider Fixture Rd Unit 1 N';
    $nextUnit['details']['homes']['address_hash']=Address::hash($nextUnit['address'],$nextUnit['city'],$nextUnit['state'],$nextUnit['zip']);
    $secondHome = App\Services\HomesSearch::save($nextUnit);
    $homesIds[] = $secondHome['id'];
    $ok($secondHome['created'] && $secondHome['id']!==$primaryId,'another apartment remains a separate property');
    PropertyRepo::upsert(['address'=>$nextUnit['address'],'city'=>$nextUnit['city'],'state'=>'VA','zip'=>'24979','list_price'=>280000], 'rentcast');
    $eq(PropertyRepo::find($secondHome['id'])['source'],'rentcast','API enrichment takes primary ownership of an originally Homes.com record');
    Settings::set('homes_on','1');
    Settings::set('rentcast_key',null);
    Settings::set('openwebninja_key',null);
    $cachedHomes = ['ok'=>true,'items'=>[$homesItem],'observed_at'=>now(),'cached'=>false,'error'=>null,'url'=>$homesUrl];
    App\Cache::put($homesCacheKey,'homes',$cachedHomes,3600);
    $usedBefore = Quota::used('rentcast');
    $reportCount = (int)Db::val('SELECT COUNT(*) FROM property_analyses');
    $combined = Search::run(Search::fromRequest(['q'=>'24979']));
    $ok($combined['homes']['available'] && $combined['homes']['cached'] && $combined['total']>=1,'ordinary search automatically includes cached Homes.com alongside primary-provider results');
    $combinedRow = array_values(array_filter($combined['rows'],static fn($row)=>(int)$row['id']===$primaryId))[0];
    $eq(json_decode($combinedRow['homes_quote'],true)['url'],$homesPropertyUrl,'normal search projection exposes the source quote and link for its result badge');
    $eq($combinedRow['photo_url'],$homesItem['photo_url'],'RentCast search results prefer the matched Homes.com photo even when a primary photo exists');
    $galleryProperty = PropertyRepo::find($primaryId);
    $galleryProperty['details']['photos'] = ['https://example.test/zillow-photo.jpg'];
    $eq(App\Services\PropertyPhotos::gallery($galleryProperty),$homesItem['details']['homes']['photos'],'the opened property uses the Homes.com gallery already collected during search');
    $wrongGallery = array_replace($galleryProperty,['address'=>'1 Provider Fixture Rd Unit 1 N']);
    $eq(App\Services\PropertyPhotos::homes($wrongGallery),[],'another apartment cannot display this unit Homes.com gallery');
    $badObservation = $homesItem;
    $badObservation['address']='1 Provider Fixture Rd Unit 9';
    try {
        App\Services\HomesSearch::save($badObservation);
        $ok(false,'a source identity mismatch is rejected before saving');
    } catch (InvalidArgumentException) {
        $ok(true,'a source identity mismatch is rejected before saving');
    }
    Db::run("UPDATE properties SET details=JSON_REMOVE(details,'$.homes.photos','$.homes.address_hash') WHERE id=?",[$primaryId]);
    $backfilled = App\Services\HomesSearch::save($homesItem);
    $ok($backfilled['changed'] && count(App\Services\PropertyPhotos::homes(PropertyRepo::find($primaryId)))===1,'a cached observation backfills older records with source-specific photos and verified identity');
    $ok(!App\Services\HomesSearch::save($homesItem)['changed'],'photo backfill runs once without repeated source or report refreshes');
    $noZipItem = $homesItem;
    $noZipItem['address']='2 Provider Fixture Rd';
    $noZipItem['details']['homes']['address_hash']=Address::hash($noZipItem['address'],$noZipItem['city'],$noZipItem['state'],$noZipItem['zip']);
    $noZipId = PropertyRepo::upsert(['address'=>$noZipItem['address'],'city'=>$noZipItem['city'],'state'=>'VA','zip'=>'','status'=>'for_sale','list_price'=>220000], 'rentcast')['id'];
    $homesIds[] = $noZipId;
    $noZipSaved = App\Services\HomesSearch::save($noZipItem);
    $ok($noZipSaved['id']===$noZipId && count(App\Services\PropertyPhotos::homes(PropertyRepo::find($noZipId)))===1,'a saved RentCast address without a ZIP acquires its verified photo without creating a duplicate');
    $eq(Quota::used('rentcast'),$usedBefore,'Homes.com adds no RentCast quota usage');
    $eq((int)Db::val('SELECT COUNT(*) FROM property_analyses'),$reportCount,'new source searches do not start AI analysis for unopened homes');
    App\Cache::put($homesCacheKey,'homes',array_replace($cachedHomes,['ok'=>false,'items'=>[],'error'=>'fixture unavailable']),900);
    $unavailable = Search::run(Search::fromRequest(['q'=>'24979']));
    $ok(!$unavailable['homes']['available'] && $unavailable['total']>=1,'Homes.com failure keeps other saved results available');
    $noFetch = Search::run(Search::fromRequest(['q'=>'24979']),['fetch'=>false]);
    $eq($noFetch['homes'],null,'exports and deletion scopes do not contact Homes.com');
} finally {
    Settings::set('rentcast_key',$primaryKeys[0]);
    Settings::set('openwebninja_key',$primaryKeys[1]);
    foreach($homesIds as $id) Db::run('DELETE FROM properties WHERE id=?',[$id]);
    Db::run('DELETE FROM api_cache WHERE cache_key=?',[$homesCacheKey]);
    if ($homesPriorCache!==null) App\Cache::put($homesCacheKey,'homes',$homesPriorCache,3600);
}
/* Automatic intent detection; fixtures are geographic evidence, not invented listing facts. */
$smartFeatures = [['properties'=>['name'=>'Demo Towers South','housenumber'=>'71','street'=>'Fixture Road','city'=>'Fixtureville','state'=>'Virginia',
    'postcode'=>'24979','countrycode'=>'US','osm_type'=>'W','osm_id'=>123]]];
$autoBuilding = App\Services\SmartLocation::fromFeatures('Demo Towers, Fixtureville, VA',$smartFeatures);
$eq([$autoBuilding['type'],$autoBuilding['city']],['building','Fixtureville'],'a building name is detected without a search mode');
$eq(App\Services\SmartLocation::fromFeatures('Demo Towers Fixtureville VA',$smartFeatures)['type'],'building','building names without commas are recognized against map evidence');
$eq(App\Services\SmartLocation::fromFeatures('Demo Towers',$smartFeatures)['type'],'building','a bare building name can be recognized in the home state');
$shortAddress = App\Services\SmartLocation::fromFeatures('71 Fixture Rd Apt 1703 S',$smartFeatures);
$eq([$shortAddress['type'],$shortAddress['address'],$shortAddress['zip']],['address','71 Fixture Road Unit 1703 S','24979'],'an incomplete street lookup fills only verified locality and preserves the requested unit');
$secondStreet = $smartFeatures[0];
$secondStreet['properties']['city']='Another City';
$secondStreet['properties']['postcode']='24978';
$eq(App\Services\SmartLocation::fromFeatures('71 Fixture Rd Apt 1703 S',[$smartFeatures[0],$secondStreet]),null,'ambiguous street addresses do not select an arbitrary home');
$foreignBuilding = $smartFeatures[0];
$foreignBuilding['properties']['state']='Maryland';
$eq(App\Services\SmartLocation::fromFeatures('Demo Towers, Fixtureville, VA',[$foreignBuilding]),null,'a matching building name in another state is rejected');
$cityFeature = [['properties'=>['name'=>'Richmond','osm_key'=>'place','osm_value'=>'city','state'=>'Virginia','countrycode'=>'US']]];
$eq(App\Services\SmartLocation::fromFeatures('Richmond VA',$cityFeature)['type'],'city','verified city names remain city searches');
$eq(Search::location(Search::fromRequest(['q'=>'۲۲۰۴۱']))['key'],'zip:22041','Persian digits in a ZIP are understood automatically');
$eq(Search::location(Search::fromRequest(['q'=>'22041','search_kind'=>'building']))['type'],'zip','legacy mode parameters do not override an unambiguous ZIP');
$ok(!isset(Search::compact(Search::fromRequest(['q'=>'Demo Towers']))['search_kind']),'new smart searches need no mode parameter in URLs or profiles');
$smartKey = App\Services\SmartLocation::key('Demo Towers, Fixtureville, VA');
$priorSmart = App\Cache::get($smartKey);
App\Cache::put($smartKey,'smart_location',['location'=>$autoBuilding],3600);
$eq(Search::location(Search::fromRequest(['q'=>'Demo Towers, Fixtureville, VA']))['type'],'building','cached automatic classification is reused by saved profiles and exports without network');
Db::run('DELETE FROM api_cache WHERE cache_key=?',[$smartKey]);
if($priorSmart!==null) App\Cache::put($smartKey,'smart_location',$priorSmart,3600);
$galleryCard = str_replace('</article>', '<img class="embla__slide__img" src="https://images.homes.com/listings/test-house.jpg">'
    .'<img class="embla__slide__img" src="/assets/spacer.gif" data-defer-src="https://images.homes.com/listings/test-kitchen.jpg">'
    .'<img class="embla__slide__img" src="https://images.homes.com/listings/test-bath.jpg">'
    .'<img class="agent-photo" src="https://images.homes.com/listings/not-a-house.jpg"></article>',$homesCard);
$galleryParsed = App\Providers\HomesCom::parse($homesHtml([$homesRecord],$homesUrl,$galleryCard),$homesLoc,$homesUrl,now())['items'][0];
$eq($galleryParsed['details']['homes']['photos'],['https://images.homes.com/listings/test-house.jpg','https://images.homes.com/listings/test-kitchen.jpg','https://images.homes.com/listings/test-bath.jpg'],
    'same-card gallery photos are collected during search, deduplicated, and agent portraits are excluded');
$eq(App\Providers\HomesCom::photoUrl('https://images.homes.com.evil.test/listings/fake.jpg'),null,'lookalike photo hosts are rejected');
$presenceUser = Db::insert('users',['email'=>'presence-check@example.test','password_hash'=>'','created_at'=>now(),'name'=>'Presence fixture','role'=>'viewer','is_active'=>1,'session_epoch'=>0]);
try {
    Db::run('UPDATE users SET last_active_at=?,last_login_at=? WHERE id=?',[now(),'2020-01-01 00:00:00',$presenceUser]);
    Db::insert('user_presence',['session_key'=>hash('sha256','presence-fixture-1'),'user_id'=>$presenceUser,'session_epoch'=>0,'last_active_at'=>now()]);
    $ok(App\Services\Presence::statuses()[$presenceUser]['online'],'recent authenticated activity shows online independently of an old login');
    Db::run('UPDATE user_presence SET last_active_at=? WHERE user_id=?',[gmdate('Y-m-d H:i:s',time()-120),$presenceUser]);
    $ok(!App\Services\Presence::statuses()[$presenceUser]['online'],'an abandoned page becomes offline after the presence window');
    Db::run('UPDATE user_presence SET last_active_at=? WHERE user_id=?',[now(),$presenceUser]);
    Db::run('UPDATE users SET session_epoch=1 WHERE id=?',[$presenceUser]);
    $ok(!App\Services\Presence::statuses()[$presenceUser]['online'],'sign out everywhere immediately invalidates old presence sessions');
    Db::insert('user_presence',['session_key'=>hash('sha256','presence-fixture-2'),'user_id'=>$presenceUser,'session_epoch'=>1,'last_active_at'=>now()]);
    $ok(App\Services\Presence::statuses()[$presenceUser]['online'],'another valid device can keep a member online');
    Db::run('UPDATE users SET is_active=0 WHERE id=?',[$presenceUser]);
    $ok(!App\Services\Presence::statuses()[$presenceUser]['online'],'disabled accounts never show online');
    $eq(Db::val('SELECT last_login_at FROM users WHERE id=?',[$presenceUser]),'2020-01-01 00:00:00','presence does not overwrite the actual login date');
} finally { Db::run('DELETE FROM users WHERE id=?',[$presenceUser]); }
$savedFilters=Search::fromRequest(['scope'=>'saved']);
$apiBefore=(int)Db::val('SELECT COUNT(*) FROM api_log');
$savedResults=Search::run($savedFilters);
$eq($savedResults['total'],(int)Db::val("SELECT COUNT(*) FROM properties WHERE status IN ('for_sale','pending')"),'dashboard saved-home list matches its global count including unpriced homes');
$goodFilters=Search::fromRequest(['scope'=>'saved','view'=>'opportunities']);
$goodResults=Search::run($goodFilters);
$eq($goodResults['total'],(int)Db::val("SELECT COUNT(*) FROM properties WHERE status='for_sale' AND score>=70"),'dashboard opportunities list matches its count across all states');
$eq((int)Db::val('SELECT COUNT(*) FROM api_log'),$apiBefore,'dashboard inventory cards make no external data requests');
$ok($savedResults['homes']===null && !$savedResults['fetched'] && !$savedResults['needs'],'saved inventory bypasses provider planning and Homes.com');
$eq(Search::fromRequest(Search::compact($goodFilters))['scope'],'saved','inventory scope survives pagination, export and saved-profile parameters');
$countyCsv="month_date_yyyymm,county_fips,county_name,median_days_on_market,active_listing_count,pending_listing_count,quality_flag\n".gmdate('Ym').",51059,Fixture County,37,2479,962,0\n";
$countyData=App\Providers\CountyHousing::parse($countyCsv);
$eq($countyData['51059']['score'],92,'published county days-on-market can score housing when linked local sales are unavailable');
$eq(array_keys($countyData),[51059],'county data remains keyed to its exact verified FIPS');
$eq(App\Providers\CountyHousing::parse(str_replace(',962,0',',962,1',$countyCsv))['51059']['score'],null,'flagged market data does not produce a housing score');
$eq(App\Providers\CountyHousing::parse(str_replace(gmdate('Ym'),gmdate('Ym',strtotime('-6 months')),$countyCsv))['51059']['score'],null,'old market observations are displayed without scoring');
$accessPlaces=[];
foreach([0.3,0.5,0.7,0.9] as $i=>$distance)$accessPlaces['kind'.$i]=['lat'=>38.8+$i*.001,'lng'=>-77.2,'straight_miles'=>$distance,'road_miles'=>null];
$access=App\Services\InvestmentEvidence::accessScore(['places'=>$accessPlaces]);
$eq([$access['score'],$access['basis']],[80,'Mapped proximity (straight-line)'],'measured mapped distances provide an explicitly labelled proximity score without invented routes');
$duplicates=array_fill(0,4,$accessPlaces['kind0']);
$eq(App\Services\InvestmentEvidence::accessScore(['places'=>$duplicates])['score'],null,'repeated observations of one facility do not count as four places');
foreach($accessPlaces as &$place)$place['road_miles']=2;
unset($place);
$eq(App\Services\InvestmentEvidence::accessScore(['places'=>$accessPlaces])['basis'],'Verified road routes','complete road evidence retains priority over direct distances');
$placeRows=App\Providers\NearbyPlaces::parse([
 ['score'=>100,'location'=>['y'=>38.8,'x'=>-77.2],'attributes'=>['PlaceName'=>'Fixture pharmacy','Addr_type'=>'POI']],
 ['score'=>100,'location'=>['y'=>40,'x'=>-77.2],'attributes'=>['PlaceName'=>'Far away place','Addr_type'=>'POI']],
 ['score'=>100,'location'=>['y'=>38.8,'x'=>-77.2],'attributes'=>['PlaceName'=>'Street candidate','Addr_type'=>'StreetAddress']],
],38.8,-77.2);
$eq(count($placeRows),1,'fallback map evidence rejects far-away and non-place candidates');
$scorer=new ReflectionMethod(App\Services\InvestmentEvidence::class,'scores');
$testRegion=['groups'=>['jobs'=>['points'=>10,'weight'=>15],'income'=>['points'=>10,'weight'=>15],'population'=>['points'=>5,'weight'=>15],'cost'=>['points'=>4,'weight'=>5]]];
$complete=$scorer->invoke(null,['ok'=>true,'base'=>['profit'=>40000,'roi'=>25],'target'=>40000],$testRegion,['score'=>92],['places'=>$accessPlaces],['risk'=>'low'],[]);
$ok($complete['coverage']===100&&is_int($complete['final_score']),'real market and mapped-access inputs allow the final weighted calculation when all six components exist');
$incomplete=$scorer->invoke(null,['ok'=>false],$testRegion,['score'=>92],['places'=>$accessPlaces],['risk'=>'low'],[]);
$eq($incomplete['final_score'],null,'missing financial evidence is never replaced by an invented final score');
Settings::set('homes_on',$homesWasOn);

/* ── Smart search: typed filters, any address shape, streets, mixed lists ── */
$u = App\Services\SearchQuery::understand('3 bed townhouse in Falls Church under 500k');
$eq([$u['q'], $u['filters']['beds_min'] ?? null, $u['filters']['price_max'] ?? null, $u['filters']['types'] ?? null], ['Falls Church', 3.0, 500000.0, ['Townhouse']], 'typed beds, type and price become filters; the rest is the place');
$u = App\Services\SearchQuery::understand('۳ خوابه زیر ۵۰۰ هزار در Fairfax County');
$eq([$u['q'], $u['filters']['beds_min'] ?? null, $u['filters']['price_max'] ?? null], ['Fairfax County', 3.0, 500000.0], 'Persian filters and digits are understood');
$u = App\Services\SearchQuery::understand('3-4 bedrooms 2 bath condos near Reston $300k-$450k');
$eq([$u['q'], $u['filters']['beds_min'], $u['filters']['beds_max'], $u['filters']['baths_min'], $u['filters']['price_min'], $u['filters']['price_max'], $u['filters']['types']],
    ['Reston', 3.0, 4.0, 2.0, 300000.0, 450000.0, ['Condo']], 'ranges for beds and price');
foreach (['5501 Seminary Rd', '3 Bath St, Bath, ME 04530', '22041 22042', 'Park Place Condominiums', 'Fairfax County, Loudoun County'] as $kept) {
    $eq(App\Services\SearchQuery::understand($kept)['filters'], [], 'left alone: ' . $kept);
}
$eq(App\Services\SearchQuery::understand('sold homes in 22041 built after 1990')['filters'], ['year_min' => 1990.0, 'mode' => 'sold'], 'sold and year built are understood');
$typedF = Search::fromRequest(['q' => '3 bed under 500k Fairfax County', 'beds_min' => '2', 'price_min' => '600,000', 'price_max' => '400000']);
$eq([$typedF['q'], $typedF['beds_min'], $typedF['price_min'], $typedF['price_max']], ['Fairfax County', 2.0, 400000.0, 600000.0], 'the form wins over typed filters; a reversed price range is swapped');
$ok(!isset(Search::compact($typedF)['understood']) && Search::compact($typedF)['q'] === 'Fairfax County', 'saved searches keep the clean place and the filters');

$pl = static fn (string $q) => Address::parseLocation($q);
$eq([$pl('Seminary Rd')['type'], $pl('Seminary Road Falls Church')['city'] ?? null, $pl('Columbia Pike, Arlington')['street'] ?? null],
    ['street', 'Falls Church', 'Columbia Pike'], 'street searches, with or without the town');
$loose = $pl('5501 seminary road falls church va');
$eq([$loose['type'], $loose['address'], $loose['city'], $loose['state']], ['address', '5501 Seminary Road', 'Falls Church', 'VA'], 'an address without commas or ZIP');
$eq([$pl('5501 Seminary Rd')['type'], $pl('5501 Seminary Rd')['partial']], ['address', true], 'a house number and street with no town is a partial address');
$eq($pl('123 Main St St Petersburg FL')['city'], 'St Petersburg', '"St" before a town name is Saint');
$eq($pl('123 Oak Ct')['state'], '', 'a street type that is also a state code is not taken as the state');
$eq(array_column($pl('Falls Church, Arlington, Alexandria')['parts'], 'type'), ['city', 'city', 'city'], 'several cities separated by commas');
$eq(array_column($pl('Fairfax County and Arlington County')['parts'], 'key'), ['county:VA:fairfax', 'county:VA:arlington'], '"and" separates places');
$eq($pl('King and Queen County')['type'], 'county', 'a county named with "and" stays one county');
$eq($pl('Fairfax, VA 22030')['key'], 'zip:22030', 'a town with its ZIP searches the ZIP');
$eq($pl('22041 Falls Church VA')['key'], 'zip:22041', 'ZIP before the town');
$eq($pl('City of Fairfax')['city'], 'Fairfax City', 'independent city spelled "City of"');
$mixAddr = $pl('5501 Seminary Rd, Falls Church, VA 22041; 1600 Pennsylvania Ave NW, Washington, DC 20500; Fairfax County');
$eq(array_column($mixAddr['parts'], 'type'), ['address', 'address', 'county'], 'several addresses and an area together');
$eq(Address::parseLocation('Skyline Square, Falls Church, VA')['type'], 'street', 'a name ending in a street word in a town reads as a street until a building is found');
$ok(App\Services\SmartLocation::ambiguous(Address::parseLocation('Skyline Square')) && !App\Services\SmartLocation::ambiguous(Address::parseLocation('Seminary Rd')), 'names like "Square" still get a building lookup');

// Street, partial address and mixed lists against the database.
$stIds = [];
foreach ([['8801 Testhill Rd Unit 1', 450000, 3, 2], ['8801 Testhill Road Unit 2', 520000, 2, 1], ['8803 Testhill Rd', 610000, 4, 3], ['17 Elsewhere Ln', 300000, 3, 2]] as [$line, $price, $bd, $ba]) {
    $stIds[] = PropertyRepo::upsert(['address' => $line, 'city' => 'Testville', 'state' => 'VA', 'zip' => '99908', 'status' => 'for_sale', 'list_price' => $price, 'beds' => $bd, 'baths' => $ba, 'property_type' => 'Condo'], 'manual')['id'];
}
$soldId = PropertyRepo::upsert(['address' => '9 Gone Ct', 'city' => 'Testville', 'state' => 'VA', 'zip' => '99908', 'status' => 'off_market', 'list_price' => 0], 'manual')['id'];
$street = PropertyRepo::search(['loc' => Address::parseLocation('Testhill Road, Testville'), 'limit' => 50]);
$eq($street['total'], 3, 'a street search finds every home on it, whichever way "Road" was written');
$eq(PropertyRepo::search(['loc' => Address::parseLocation('Testhill Rd Testville'), 'beds_min' => 3, 'limit' => 50])['total'], 2, 'filters narrow a street search');
$partial = Search::location(Search::fromRequest(['q' => '8801 Testhill Rd']));
$eq(count($partial['ids'] ?? []), 2, 'a house number with no town finds every unit at that address on file');
$unit = Search::location(Search::fromRequest(['q' => '8801 Testhill Rd #2 Testville VA 99908']));
$eq($unit['ids'] ?? null, [$stIds[1]], 'a unit typed differently ("#2" for "Unit 2") still finds that one apartment');
$mixed = Search::location(Search::fromRequest(['q' => '9 Gone Ct, Testville, VA 99908; 8803 Testhill Rd, Testville, VA 99908']));
$mixedRes = PropertyRepo::search(Search::fromRequest(['q' => 'x', 'beds_min' => 5]) + ['loc' => $mixed, 'limit' => 50]);
$eq(count($mixedRes['rows']), 2, 'homes asked for by address are listed even when off the market or outside the filters');
$eq(PropertyRepo::findAddress(['address' => '8803 Testhill Rd', 'city' => '', 'state' => '', 'zip' => '99908']), $stIds[2], 'an address with only a ZIP is found (and the check no longer mixes SQL collations)');
Db::run('DELETE FROM properties WHERE zip = ?', ['99908']);

$circle = App\Providers\CountyArea::pick([['NAME' => 'Fairfax city', 'AREALAND' => 16e6, 'INTPTLAT' => '+38.85', 'INTPTLON' => '-077.30'],
    ['NAME' => 'Fairfax County', 'AREALAND' => 1.0e9, 'INTPTLAT' => '+38.84', 'INTPTLON' => '-077.27']], false);
$ok($circle && $circle['name'] === 'Fairfax County' && $circle['radius'] > 10 && $circle['radius'] < 20 && abs($circle['lat'] - 38.84) < 0.001, 'a county becomes a circle of about its own size, not its same-named city');
$eq(App\Providers\CountyArea::pick([['NAME' => 'Huge County', 'AREALAND' => 5.0e10, 'INTPTLAT' => '40', 'INTPTLON' => '-110']], false), null, 'a very large county falls back to the state pull');

$eq(Search::fetchArea(Address::parseLocation('Fairfax City'))['key'], 'city:VA:fairfax', 'an independent city is pulled by its postal name');
$eq(Search::fetchArea(Address::parseLocation('Kansas City, MO'))['key'], 'city:MO:kansas city', 'a city whose name ends in "City" keeps it');

$fc = [];
PropertyRepo::locSql(Address::parseLocation('Fairfax City'), $fc);
$fx = [];
PropertyRepo::locSql(Address::parseLocation('Fairfax'), $fx);
$ok(count($fc) === 3 && count($fx) === 2, 'an independent city also matches its county-level name; a plain city does not take in the county');


/* ⓘ explanations */
$t1 = tip_open('Bedrooms') . 'None ticked = any type.' . tip_close();
$t2 = tip(e('Second <b>'), '', 'my-help');
$ok(preg_match('/<button type="button" class="tip-btn" aria-expanded="false" aria-controls="(tip-\d+)" aria-label="[^"]+"/', $t1, $tm) === 1
    && str_contains($t1, 'id="' . $tm[1] . '" role="note">None ticked = any type.</span></span>'), 'an explanation sits behind a labelled button that controls it');
$ok(str_contains($t2, 'id="my-help"') && str_contains($t2, 'Second &lt;b&gt;') && !str_contains($t1, 'my-help'), 'an explanation can carry a fixed id for aria-describedby; content is passed already escaped');

echo $fail === 0 ? "All $n checks passed.\n" : "$fail of $n checks failed.\n";
exit($fail === 0 ? 0 : 1);

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
$ok(str_contains(PropertyRepo::locSql($mix, $a2), ' OR ') && count($a2) === 7, 'several areas search with OR');

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

echo $fail === 0 ? "All $n checks passed.\n" : "$fail of $n checks failed.\n";
exit($fail === 0 ? 0 : 1);

<?php
declare(strict_types=1);

/*
 * A stand-in for the RentCast API, for local load tests only. Answers like RentCast does
 * (same field names), with up to 500 made-up homes in whatever city/state/ZIP is asked for.
 *
 *   php -S 127.0.0.1:8099 tests/fake_rentcast.php
 *   then in the LOCAL config.php: 'rentcast_base' => 'http://127.0.0.1:8099/v1'
 *
 * FAKE_DELAY=8 makes every answer wait 8 seconds, like a slow day at the provider.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY), $q);
header('Content-Type: application/json');
if (empty($_SERVER['HTTP_X_API_KEY'])) {
    http_response_code(401);
    echo json_encode(['status' => 401, 'error' => 'auth/api-key-invalid', 'message' => 'No API key provided in request.']);
    return;
}
if ($d = (int) getenv('FAKE_DELAY')) {
    sleep($d);
}
file_put_contents(sys_get_temp_dir() . '/fake_rentcast.log', gmdate('c') . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . "\n", FILE_APPEND);

$city = (string) ($q['city'] ?? 'Richmond');
$state = (string) ($q['state'] ?? 'VA');
$zip0 = (string) ($q['zipCode'] ?? '23220');
$limit = min(500, (int) ($q['limit'] ?? 500));
mt_srand(crc32($city . $state . $zip0 . $path));
$streets = ['Grove Ave', 'Monument Ave', 'Floyd Ave', 'Hanover Ave', 'Park Ave', 'Patterson Ave', 'Broad St', 'Leigh St', 'Clay St', 'Marshall St', 'Chamberlayne Ave', 'Brook Rd', 'Forest Hill Ave', 'Semmes Ave', 'Jahnke Rd'];
$types = ['Single Family', 'Single Family', 'Single Family', 'Townhouse', 'Condo'];
$zips = isset($q['zipCode']) ? [$zip0] : array_map(static fn ($n) => (string) (23000 + crc32($city) % 900 + $n), range(0, 7));
$out = [];
for ($i = 0; $i < $limit; $i++) {
    $type = $types[mt_rand(0, 4)];
    $sqft = mt_rand(800, 3200);
    $price = (int) round($sqft * mt_rand(140, 330), -3);
    $zip = $zips[$i % count($zips)];
    $row = [
        'id' => 'fake-' . md5($city . $state . $zip0 . $path) . '-' . $i,
        'formattedAddress' => '', 'addressLine1' => (100 + $i * 7 + (crc32($city) % 50) * 1000) . ' ' . $streets[$i % count($streets)], 'addressLine2' => null,
        'city' => $city, 'state' => $state, 'zipCode' => $zip, 'county' => 'Richmond City',
        'latitude' => 37.54 + mt_rand(-400, 400) / 10000, 'longitude' => -77.46 + mt_rand(-500, 500) / 10000,
        'propertyType' => $type, 'bedrooms' => max(1, intdiv($sqft, 600)), 'bathrooms' => max(1, intdiv($sqft, 900)) + (mt_rand(0, 1) ? 0.5 : 0),
        'squareFootage' => $sqft, 'lotSize' => $type === 'Condo' ? null : mt_rand(2500, 12000), 'yearBuilt' => mt_rand(1910, 2022),
    ];
    if (str_contains((string) $path, '/listings/sale')) {
        $row += [
            'status' => 'Active', 'price' => $price, 'listingType' => mt_rand(0, 12) === 0 ? 'Foreclosure' : 'Standard',
            'listedDate' => gmdate('Y-m-d\TH:i:s.000\Z', time() - mt_rand(0, 90) * 86400), 'daysOnMarket' => mt_rand(0, 90),
            'mlsName' => 'CVR MLS', 'mlsNumber' => (string) (2400000 + $i),
            'listingAgent' => ['name' => 'Agent ' . ($i % 30), 'phone' => '8045550' . str_pad((string) ($i % 1000), 3, '0', STR_PAD_LEFT)],
            'listingOffice' => ['name' => 'Office ' . ($i % 12)],
            'history' => [gmdate('Y-m-d') => ['event' => 'Sale Listing', 'price' => $price, 'listedDate' => gmdate('Y-m-d\TH:i:s.000\Z')]],
        ];
    } else {
        $sold = gmdate('Y-m-d', time() - mt_rand(10, 170) * 86400);
        $row += [
            'lastSaleDate' => $sold . 'T00:00:00.000Z', 'lastSalePrice' => (int) round($price * mt_rand(90, 110) / 100, -3),
            'history' => [$sold => ['event' => 'Sale', 'date' => $sold . 'T00:00:00.000Z', 'price' => $price]],
            'propertyTaxes' => [(string) gmdate('Y') => ['year' => (int) gmdate('Y'), 'total' => (int) ($price * 0.012)]],
        ];
    }
    $out[] = $row;
}
echo json_encode($out);

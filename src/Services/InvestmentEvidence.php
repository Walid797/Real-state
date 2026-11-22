<?php
declare(strict_types=1);

namespace App\Services;

use App\Cache;
use App\Db;
use App\Geo;
use App\Providers\Census;
use App\Providers\Fema;
use App\Providers\DevelopmentEvidence;
use App\Providers\LocationEvidence;
use App\Providers\RegionalData;
use App\Providers\SchoolEvidence;

final class InvestmentEvidence
{
    public const VERSION = 2;
    public const WEIGHTS = ['profitability' => 40, 'housing' => 20, 'economic' => 15, 'social' => 10, 'infrastructure' => 10, 'risk_safety' => 5];

    public static function collect(array $p, bool $fetch = true): array
    {
        $condition = Condition::get((int) $p['id']);
        $deal = Deal::analyse($p, $condition);
        $existingScore = Score::compute($p, $deal, $condition);
        $region = RegionalDemand::for($p, $fetch);
        $geo = Geo::valid($p['lat'], $p['lng']);
        $tract = $geo ? ($fetch ? Census::tractAt((float) $p['lat'], (float) $p['lng']) : Census::cachedTract((float) $p['lat'], (float) $p['lng'])) : null;
        $tractData = $tract ? RegionalData::tract($tract, $fetch) : [];
        $location = $geo && $fetch ? LocationEvidence::at((float) $p['lat'], (float) $p['lng']) : [];
        $flood = $geo ? ($fetch ? Fema::zoneAt((float) $p['lat'], (float) $p['lng']) : Cache::get(Cache::key('fema', 'zone', [
            'lat' => round((float) $p['lat'], 5), 'lng' => round((float) $p['lng'], 5),
        ]))) : null;
        $facts = [];
        $add = static function (string $section, string $label, mixed $value, string $scope, string $source, ?string $period = null) use (&$facts): void {
            if ($value === null || $value === '' || $value === []) {
                return;
            }
            $facts[] = ['id' => 'f' . (count($facts) + 1), 'section' => $section, 'label' => $label, 'value' => $value,
                'scope' => $scope, 'source' => $source, 'period' => $period];
        };
        $propertyScope = 'property:' . $p['id'];
        foreach (['list_price' => 'Asking price', 'est_arv' => 'After-repair value', 'tax_annual' => 'Annual property tax on file',
            'hoa_fee' => 'HOA per month', 'beds' => 'Bedrooms', 'baths' => 'Bathrooms', 'sqft' => 'Sqft living area',
            'year_built' => 'Year built'] as $field => $label) {
            $factValue = $field === 'est_arv' ? ($deal['inputs']['arvValue'] ?? null) : ($p[$field] ?? null);
            $factSource = $field === 'est_arv' ? 'Investment calculator' : ($p['source_url'] ?: 'Property record');
            $add('risk', $label, $factValue, $propertyScope, $factSource, $p['updated_at']);
        }
        foreach (['profit' => 'Est. net profit', 'roi' => 'Return on investment'] as $field => $label) {
            $add('housing', $label, $deal['base'][$field] ?? null, $propertyScope, 'Investment calculator', now());
        }
        $add('risk', 'Renovation', $deal['inputs']['reno'] ?? null, $propertyScope, 'Investment calculator', now());
        $add('risk', 'Cautious scenario profit', $deal['scenarios']['pessimistic']['profit'] ?? null, $propertyScope, 'Investment calculator', now());
        $add('risk', 'Max offer', $deal['ok'] ? $deal['max_offer'] : null, $propertyScope, 'Investment calculator', now());
        $map = ['population' => 'social', 'jobs' => 'economic', 'income' => 'economic', 'market' => 'housing', 'cost' => 'risk'];
        foreach ($map as $group => $section) {
            foreach ($region['groups'][$group]['metrics'] as $label => $value) {
                $scope = $group === 'market' ? 'ZIP:' . $p['zip'] . ', type:' . $p['property_type'] : ($region['county'] ?? 'County unverified');
                $source = match ($group) { 'market' => 'Stored property records', 'jobs' => 'BLS LAUS', default => 'Census ACS via Census Reporter' };
                $period = $region['acs_period'];
                if ($group === 'market') { $period = now(); }
                if ($group === 'jobs') { $period = $region['groups']['jobs']['metrics']['BLS observation month'] ?? null; }
                if ($group === 'cost' && $label === 'U.S. 30-year fixed mortgage rate') { $source = 'Freddie Mac PMMS via FRED'; $scope = 'United States'; $period = null; }
                if ($group === 'cost' && in_array($label, ['Annual property tax on file','Tax as a share of asking price','Estimated monthly rent for this home'], true)) {
                    $source = 'Property record'; $scope = $propertyScope; $period = $p['updated_at'];
                }
                $add($section, $label, $value, $scope, $source, $period);
            }
        }
        $tractScope = $tract ? 'tract:' . $tract['state'] . $tract['county'] . $tract['tract'] : 'unknown';
        foreach (['population' => 'Residents', 'households' => 'Households', 'income' => 'Median household income',
            'rent' => 'Median rent', 'median_age' => 'Median age', 'families_with_children' => 'Family households with children',
            'families' => 'Family households', 'college_graduates' => 'College graduates aged 25 and over',
            'adults_25_plus' => 'Residents aged 25 and over'] as $field => $label) {
            $add('social', $label, $tractData[$field] ?? null, $tractScope, 'Census ACS via Census Reporter', isset($tractData['year']) ? ($tractData['year'] - 4) . '–' . $tractData['year'] : null);
        }
        $market = self::nearbyMarket($p);
        foreach ($market['metrics'] as $label => $value) {
            $add('housing', $label, $value, 'Within 1 mile, same type, size within 35%', 'Stored property transactions', now());
        }
        foreach ($location['places'] ?? [] as $kind => $place) {
            $add('location', 'Road access to ' . $kind, $place, $propertyScope, 'OpenStreetMap / OSRM', $location['observed_at'] ?? null);
        }
        $schools = SchoolEvidence::forProperty($p, $location);
        if (!empty($p['details']['homes']['price'])) {
            $add('housing', 'Homes.com source asking price', $p['details']['homes']['price'], $propertyScope, 'Homes.com', $p['details']['homes']['observed_at'] ?? null);
        }
        $add('location', 'Nearby schools and source ratings', $schools, $propertyScope, 'Zillow school data / OpenStreetMap', $p['details']['schools_checked_at'] ?? $location['observed_at'] ?? null);
        $add('development', 'Mapped construction awaiting official verification', $location['construction'] ?? null, 'Within 3 miles', 'OpenStreetMap', $location['observed_at'] ?? null);
        $development = $geo && $fetch ? DevelopmentEvidence::nearby((float) $p['lat'], (float) $p['lng'], $p['state']) : [];
        $add('development', 'Nearby VDOT approved-program projects', $development['projects'] ?? null, 'Within 3 miles', 'VDOT SYIP', $development['collected_at'] ?? null);
        $record = $p['details']['county_record'] ?? [];
        $add('development', 'Zoning', $record['zoning'] ?? null, $propertyScope, $record['record_url'] ?? 'County record', $p['updated_at']);
        $floodDate = $geo ? Db::val('SELECT created_at FROM api_cache WHERE cache_key=?', [Cache::key('fema', 'zone', [
            'lat' => round((float) $p['lat'], 5), 'lng' => round((float) $p['lng'], 5),
        ])]) : null;
        $add('risk', 'FEMA flood assessment', $flood, $propertyScope, 'FEMA NFHL', $floodDate);
        $scores = self::scores($deal, $region, $market, $location, $flood, $p);
        $missing = [];
        foreach (['social', 'economic', 'housing', 'location', 'development', 'risk'] as $section) {
            if (!array_filter($facts, static fn ($f) => $f['section'] === $section)) {
                $missing[] = $section;
            }
        }
        $missing = array_merge($missing, ['Verified local crime rate', 'School attendance eligibility and current boundaries',
            'Approved development and completion dates', 'Property insurance quote', 'Utility and maintenance quotes', 'Major employers and business growth', 'New housing permits and completions']);
        if (!isset($region['groups']['population']['metrics']['Population change across survey periods'])) {
            $missing[] = 'Five-year population and household growth when history is unavailable';
        }
        if (!array_filter($schools, static fn ($school) => $school['rating'] !== null)) {
            $missing[] = 'Verified school ratings';
        }
        if (!$tractData) {
            $missing[] = 'Census tract statistics';
        }
        return [
            'version' => self::VERSION, 'collected_at' => now(),
            'property' => array_intersect_key($p, array_flip(['id','address','city','county','state','zip','lat','lng','property_type','list_price','sqft','beds','baths','year_built','updated_at'])),
            'scores' => $scores, 'property_score' => $existingScore['score'],
            'financial_verdict' => Verdict::for($p, $deal)['verdict'],
            'recommendation' => match (true) {
                $deal['ok'] && $deal['base']['profit'] <= 0 => 'Measured financial risk is high',
                $scores['final_score'] === null => 'More verified evidence is needed',
                $scores['final_score'] >= 75 && $deal['ok'] && $deal['scenarios']['pessimistic']['profit'] >= 0 => 'Potentially attractive investment',
                $scores['final_score'] >= 55 => 'Further investment review required',
                default => 'Measured investment risk is high',
            },
            'facts' => $facts, 'comparable_sales' => $market['comps'], 'missing_data' => array_values(array_unique($missing)),
            'data_sources' => array_values(array_unique(array_column($facts, 'source'))),
            'limitations' => ['Counts represent the records on file.', 'County, tract and property evidence have distinct scopes.',
                'Demographic age, education and family composition are descriptive and excluded from scoring.',
                'Observed construction has no verified approval or completion date.', 'AI interprets this evidence and cannot alter scores.'],
        ];
    }

    private static function scores(array $deal, array $region, array $market, array $location, ?array $flood, array $p): array
    {
        $score = array_fill_keys(array_keys(self::WEIGHTS), null);
        if ($deal['ok']) {
            $profit = max(0, min(100, $deal['base']['profit'] / max(1, $deal['target']) * 100));
            $roi = max(0, min(100, $deal['base']['roi'] / 25 * 100));
            $score['profitability'] = (int) round(($profit + $roi) / 2);
        }
        $score['housing'] = $market['score'];
        $g = $region['groups'];
        $economic = [];
        foreach (['jobs','income'] as $key) {
            if ($g[$key]['points'] !== null) {
                $economic[] = 100 * $g[$key]['points'] / $g[$key]['weight'];
            }
        }
        $score['economic'] = count($economic) === 2 ? (int) round(array_sum($economic) / 2) : null;
        $score['social'] = $g['population']['points'] === null ? null : (int) round(100 * $g['population']['points'] / $g['population']['weight']);
        // Access can be quantified; announced future projects remain unknown.
        $routes = array_filter($location['places'] ?? [], static fn ($place) => isset($place['road_miles']));
        if (count($routes) >= 4) {
            $score['infrastructure'] = (int) round(array_sum(array_map(static fn ($place) =>
                max(0, min(100, 100 * (8 - $place['road_miles']) / 7)), $routes)) / count($routes));
        }
        if (is_array($flood) && in_array($flood['risk'] ?? '', ['low','moderate','high'], true) && $g['cost']['points'] !== null) {
            $floodPoints = ['low' => 100, 'moderate' => 50, 'high' => 0][$flood['risk']];
            $score['risk_safety'] = (int) round(($floodPoints + 100 * $g['cost']['points'] / $g['cost']['weight']) / 2);
        }
        $covered = $weighted = 0;
        foreach (self::WEIGHTS as $key => $weight) {
            if ($score[$key] !== null) {
                $covered += $weight;
                $weighted += $score[$key] * $weight;
            }
        }
        $score['coverage'] = $covered;
        $score['final_score'] = $covered === 100 ? (int) round($weighted / 100) : null;
        $score['weights'] = self::WEIGHTS;
        return $score;
    }

    private static function nearbyMarket(array $p): array
    {
        $empty = ['metrics' => [], 'comps' => [], 'score' => null];
        if (!Geo::valid($p['lat'], $p['lng']) || empty($p['property_type']) || empty($p['sqft'])) {
            return $empty;
        }
        $b = Geo::box((float) $p['lat'], (float) $p['lng'], 1);
        $rows = Db::all(
            "SELECT p.id, p.address, p.lat, p.lng, p.list_price, p.listed_date, p.status, p.last_sale_price, p.last_sale_date, p.sqft, p.listing_type,
                (SELECT e.price FROM price_events e WHERE e.property_id=p.id AND e.event IN ('listed','relisted','price_change')
                    AND e.event_date <= p.last_sale_date AND e.event_date >= DATE_SUB(p.last_sale_date, INTERVAL 365 DAY)
                    AND e.price > 0 ORDER BY e.event_date DESC,e.id DESC LIMIT 1) AS asking,
                (SELECT e.event_date FROM price_events e WHERE e.property_id=p.id AND e.event IN ('listed','relisted')
                    AND e.event_date <= p.last_sale_date AND e.event_date >= DATE_SUB(p.last_sale_date, INTERVAL 365 DAY)
                    ORDER BY e.event_date DESC,e.id DESC LIMIT 1) AS started
             FROM properties p WHERE p.id <> ? AND p.state = ? AND p.property_type = ?
             AND p.sqft BETWEEN ? AND ? AND p.lat BETWEEN ? AND ? AND p.lng BETWEEN ? AND ?",
            [$p['id'], $p['state'], $p['property_type'], $p['sqft'] * .65, $p['sqft'] * 1.35, $b['lat1'], $b['lat2'], $b['lng1'], $b['lng2']]
        );
        $rows = array_values(array_filter($rows, static fn ($r) => Geo::miles((float) $p['lat'], (float) $p['lng'], (float) $r['lat'], (float) $r['lng']) <= 1));
        $sales = array_values(array_filter($rows, static fn ($r) => $r['last_sale_price'] > 0 && $r['last_sale_date'] <= today()));
        $metrics = [];
        foreach ([3,6,12] as $months) {
            $cutoff = gmdate('Y-m-d', strtotime("-$months months"));
            $metrics['Recorded similar sales in ' . $months . ' months'] = count(array_filter($sales, static fn ($r) => $r['last_sale_date'] >= $cutoff));
        }
        $recent = array_values(array_filter($sales, static fn ($r) => $r['last_sale_date'] >= gmdate('Y-m-d', strtotime('-12 months'))));
        $paired = array_values(array_filter($recent, static fn ($r) => $r['started'] && $r['asking'] > 0));
        $days = array_map(static fn ($r) => (strtotime($r['last_sale_date'] . ' UTC') - strtotime($r['started'] . ' UTC')) / 86400, $paired);
        $ratios = array_map(static fn ($r) => $r['last_sale_price'] / $r['asking'] * 100, $paired);
        $dom = median($days);
        $ratio = median($ratios);
        $metrics['Linked sales sample size'] = count($paired);
        $metrics['Median listing to closing days'] = $dom;
        $metrics['Median sale to asking percent'] = $ratio;
        $metrics['Similar active listings nearby'] = count(array_filter($rows, static fn ($r) => $r['status'] === 'for_sale'));
        $metrics['Distressed similar listings on file'] = count(array_filter($rows, static fn ($r) =>
            $r['status'] === 'for_sale' && preg_match('/foreclosure|short sale|reo|bank owned/i', (string) $r['listing_type'])));
        foreach ([12,36] as $months) {
            $a = gmdate('Y-m-d', strtotime('-6 months'));
            $oldStart = gmdate('Y-m-d', strtotime('-' . ($months + 6) . ' months'));
            $oldEnd = gmdate('Y-m-d', strtotime("-$months months"));
            $current = array_filter($sales, static fn ($r) => $r['last_sale_date'] >= $a);
            $old = array_filter($sales, static fn ($r) => $r['last_sale_date'] >= $oldStart && $r['last_sale_date'] < $oldEnd);
            $before = count($old) >= 8 ? median(array_map(static fn ($r) => $r['last_sale_price'] / $r['sqft'], $old)) : null;
            $after = count($current) >= 8 ? median(array_map(static fn ($r) => $r['last_sale_price'] / $r['sqft'], $current)) : null;
            $metrics['Similar sale price per sqft change over ' . $months . ' months'] = $before && $after ? ($after / $before - 1) * 100 : null;
        }
        $housing = count($paired) >= 8 ? (int) round((max(0, min(100, (100 - $dom) / 60 * 100))
            + max(0, min(100, ($ratio - 90) / 10 * 100))) / 2) : null;
        usort($recent, static fn ($a, $b) => strcmp($b['last_sale_date'], $a['last_sale_date']));
        return ['metrics' => $metrics, 'comps' => array_slice($recent, 0, 12), 'score' => $housing];
    }
}

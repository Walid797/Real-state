<?php
declare(strict_types=1);

namespace App\Services;

use App\Cache;
use App\Db;
use App\Http;
use App\Geo;
use App\Providers\Census;
use App\Providers\Fema;
use App\Providers\DevelopmentEvidence;
use App\Providers\LocationEvidence;
use App\Providers\RegionalData;
use App\Providers\SchoolEvidence;
use App\Providers\CountyHousing;
use App\Providers\NearbyPlaces;

final class InvestmentEvidence
{
    public const VERSION = 4;
    public const WEIGHTS = ['profitability' => 40, 'housing' => 20, 'economic' => 15, 'social' => 10, 'infrastructure' => 10, 'risk_safety' => 5];

    public static function collect(array $p, bool $fetch = true): array
    {
        $condition = Condition::get((int) $p['id']);
        $deal = Deal::analyse($p, $condition);
        $existingScore = Score::compute($p, $deal, $condition);
        $geo = Geo::valid($p['lat'], $p['lng']);
        $lat=(float)$p['lat'];$lng=(float)$p['lng'];
        $tract = $geo ? ($fetch ? Census::tractAt($lat,$lng) : Census::cachedTract($lat,$lng)) : null;
        $fips=($tract['state']??'').($tract['county']??'');
        $cachedLocation=$geo?LocationEvidence::cached($lat,$lng):[];
        $cachedNearby=$geo?NearbyPlaces::cached($lat,$lng):[];
        $jobs=[
            'region'=>static fn()=>RegionalDemand::for($p,$fetch),
            'tract'=>static fn()=>$tract?RegionalData::tract($tract,$fetch):[],
            'countyMarket'=>static fn()=>CountyHousing::get($fips,$fetch),
            'location'=>static fn()=>!$geo?[]:($fetch?LocationEvidence::at($lat,$lng):$cachedLocation),
            'nearby'=>static fn()=>!$geo||self::accessScore($cachedLocation)['score']!==null?[]:($fetch?NearbyPlaces::at($lat,$lng):$cachedNearby),
            'flood'=>static fn()=>!$geo?null:($fetch?Fema::zoneAt($lat,$lng):Cache::get(Cache::key('fema','zone',['lat'=>round($lat,5),'lng'=>round($lng,5)]))),
            'development'=>static fn()=>$geo?DevelopmentEvidence::nearby($lat,$lng,$p['state'],$fetch):[],
        ];
        $data=$fetch?Http::concurrent($jobs,40):array_map(static fn($job)=>$job(),$jobs);
        $region=$data['region']??RegionalDemand::for($p,false);
        $tractData=$data['tract']??[];$countyMarket=$data['countyMarket']??[];
        $location=$data['location']??[];$alternative=$data['nearby']??[];
        if(self::accessScore($location)['score']===null){
            foreach($alternative['places']??[] as $kind=>$place){
                if(!isset($location['places'][$kind]))$location['places'][$kind]=$place+['source'=>'ArcGIS World Geocoding'];
            }
            $location['observed_at']??=$alternative['observed_at']??null;
        }
        $flood=$data['flood']??null;$development=$data['development']??[];
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
        foreach($countyMarket['metrics']??[] as $label=>$value) {
            $add('housing',$label,$value,'County FIPS '.$fips.' · '.($countyMarket['county']??''),'Realtor.com monthly inventory',$countyMarket['period']);
        }
        $housingBasis=$market['score']===null ? 'Insufficient verified housing evidence' : 'Matched nearby sales';
        if($market['score']===null && ($countyMarket['score']??null)!==null) {
            $market['score']=$countyMarket['score'];$housingBasis='County inventory (Realtor.com)';
        }
        foreach ($location['places'] ?? [] as $kind => $place) {
            $add('location', 'Road access to ' . $kind, $place, $propertyScope, $place['source']??'OpenStreetMap / OSRM', $location['observed_at'] ?? null);
        }
        $schools = SchoolEvidence::forProperty($p, $location);
        if (!empty($p['details']['homes']['price'])) {
            $add('housing', 'Homes.com source asking price', $p['details']['homes']['price'], $propertyScope, 'Homes.com', $p['details']['homes']['observed_at'] ?? null);
        }
        $add('location', 'Nearby schools and source ratings', $schools, $propertyScope, 'Zillow school data / OpenStreetMap', $p['details']['schools_checked_at'] ?? $p['details']['zillow_search_schools_at'] ?? $location['observed_at'] ?? null);
        $add('development', 'Mapped construction awaiting official verification', $location['construction'] ?? null, 'Within 3 miles', 'OpenStreetMap', $location['observed_at'] ?? null);
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
            'score_basis'=>['housing'=>$housingBasis,'infrastructure'=>self::accessScore($location)['basis']],
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
        $score['infrastructure']=self::accessScore($location)['score'];
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

    public static function accessScore(array $location): array
    {
        $places=[];
        foreach($location['places']??[] as $p) {
            if(Geo::valid($p['lat']??null,$p['lng']??null)&&is_numeric($p['straight_miles']??null)&&$p['straight_miles']>=0&&$p['straight_miles']<=3)
                $places[$p['lat'].','.$p['lng']]=$p;
        }
        $routes=array_filter($places,static fn($p)=>isset($p['road_miles'])&&is_numeric($p['road_miles'])&&$p['road_miles']>=0);
        $road=count($routes)>=4;$sample=$road?$routes:$places;
        if(count($sample)<4)return ['score'=>null,'basis'=>'Insufficient verified nearby places'];
        $values=array_map(static fn($p)=>max(0,min(100,100*(($road?8:3)-$p[$road?'road_miles':'straight_miles'])/($road?7:3))),$sample);
        return ['score'=>(int)round(array_sum($values)/count($values)),'basis'=>$road?'Verified road routes':'Mapped proximity (straight-line)'];
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

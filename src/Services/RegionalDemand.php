<?php
declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Geo;
use App\Providers\Census;
use App\Providers\Overpass;
use App\Providers\RegionalData;

/** Evidence for the resale market around one home, alongside its purchase verdict. */
final class RegionalDemand
{
    private const GROUPS = [
        'population' => ['Population and migration', 15],
        'jobs' => ['Employment and economy', 15],
        'income' => ['Income and purchasing power', 15],
        'amenities' => ['Amenities and quality of life', 10],
        'development' => ['Infrastructure and future development', 10],
        'market' => ['Housing supply and demand', 20],
        'cost' => ['Taxes and cost of living', 5],
        'safety' => ['Neighborhood safety and stability', 10],
    ];

    public static function for(array $p, bool $fetch = false): array
    {
        $geo = Geo::valid($p['lat'] ?? null, $p['lng'] ?? null);
        $lat = (float) ($p['lat'] ?? 0);
        $lng = (float) ($p['lng'] ?? 0);
        $tract = !$geo ? null : ($fetch ? Census::tractAt($lat, $lng) : Census::cachedTract($lat, $lng));
        $fips = ($tract['state'] ?? '') . ($tract['county'] ?? '');
        $county = RegionalData::county($fips, $fetch);
        $mortgage = RegionalData::mortgage($fetch);
        $acs = $county['acs'] ?? [];
        $history = $county['history'] ?? [];
        $jobs = $county['employment'] ?? [];
        $places = $geo ? Overpass::cached($lat, $lng) : null;
        $zip = (string) ($p['zip'] ?? '');
        $state = (string) ($p['state'] ?? '');
        $price = in_array($p['status'] ?? '', ['for_sale', 'pending'], true) ? (float) ($p['list_price'] ?? 0) : 0.0;
        $year = $acs['year'] ?? null;
        $range = $year ? ($year - 4) . '–' . $year : null;
        $profile = $fips !== '' ? 'https://censusreporter.org/profiles/05000US' . $fips . '/' : 'https://www.census.gov/programs-surveys/acs';
        $groups = [];
        foreach (self::GROUPS as $key => [$label, $weight]) {
            $groups[$key] = ['label' => $label, 'weight' => $weight, 'points' => null, 'metrics' => [], 'note' => '', 'links' => []];
        }
        $add = static function (string $key, string $label, ?string $value) use (&$groups): void {
            if ($value !== null) {
                $groups[$key]['metrics'][$label] = $value;
            }
        };
        $score = static function (string $key, ?float ...$signals) use (&$groups): void {
            $signals = array_filter($signals, static fn ($signal) => $signal !== null);
            if ($signals) {
                $groups[$key]['points'] = round($groups[$key]['weight'] * array_sum($signals) / count($signals), 1);
            }
        };
        $number = static fn (?int $value) => $value === null ? null : number_format($value);
        $dollars = static fn (?int $value) => $value === null || $value <= 0 ? null : money($value);

        $add('population', 'Residents', $number($acs['population'] ?? null));
        $add('population', 'Households', $number($acs['households'] ?? null));
        $add('population', 'Housing units', $number($acs['housing_units'] ?? null));
        $add('population', 'Arrivals from other U.S. states', $number($acs['arrivals_other_states'] ?? null));
        $net = $acs['net_domestic'] ?? null;
        $moe = $acs['net_moe'] ?? null;
        if ($net !== null) {
            $add('population', 'Estimated net domestic migration', sprintf('%+d', $net) . ($moe === null ? '' : ' ± ' . number_format($moe)));
        }
        $growth = self::change($history['population'] ?? null, $acs['population'] ?? null);
        $households = self::change($history['households'] ?? null, $acs['households'] ?? null);
        $homes = self::change($history['housing_units'] ?? null, $acs['housing_units'] ?? null);
        $add('population', 'Population change across survey periods', $growth === null ? null : pct($growth, 1));
        $add('population', 'Household change across survey periods', $households === null ? null : pct($households, 1));
        $add('population', 'Housing unit change across survey periods', $homes === null ? null : pct($homes, 1));
        if ($households !== null) {
            $add('population', 'ACS periods compared', ($history['year'] - 4) . '–' . $history['year'] . ' / ' . $range);
        }
        $migrationSignal = $net === null || $moe === null ? null : (abs($net) <= $moe ? 0.5 : ($net > 0 ? 1.0 : 0.0));
        $score('population', $migrationSignal, self::band($growth, 0, 5),
            $households === null || $homes === null ? null : self::band($households - $homes, -2, 2));
        $groups['population']['note'] = 'ACS estimates cover a five-year survey period. Check population and household growth against housing growth and verify the origin of new residents.';
        $groups['population']['links'] = ['Census / ACS' => $profile];

        $rate = $jobs['unemployment'] ?? null;
        $jobChange = $jobs['employment_change'] ?? null;
        $add('jobs', 'County unemployment', $rate === null ? null : number_format($rate, 1) . '%');
        $add('jobs', 'Employed residents, year-over-year change', $jobChange === null ? null : pct($jobChange));
        $add('jobs', 'BLS observation month', $jobs['month'] ?? null);
        $score('jobs', self::band($rate, 7, 4), self::band($jobChange, -2, 2));
        $groups['jobs']['note'] = 'Check major employers, new businesses and stability of public and private employment. BLS employment here counts employed residents.';
        $groups['jobs']['links'] = ['BLS Local Area Unemployment Statistics' => RegionalData::BLS_SOURCE, 'BLS Quarterly Census of Employment and Wages' => 'https://www.bls.gov/cew/'];

        $income = (int) ($acs['income'] ?? 0);
        $value = (int) ($acs['home_value'] ?? 0);
        $ratio = $income > 0 && $value > 0 ? $value / $income : null;
        $add('income', 'County median household income', $dollars($income));
        $add('income', 'County median owner-reported home value', $dollars($value));
        $add('income', 'County home value to annual income', $ratio === null ? null : number_format($ratio, 1) . '×');
        $add('income', 'Target resale value to county annual income', !empty($p['est_arv']) && $income > 0 ? number_format($p['est_arv'] / $income, 1) . '×' : null);
        $add('income', 'Asking price to county annual income', $price > 0 && $income > 0 ? number_format($price / $income, 1) . '×' : null);
        $score('income', self::band($ratio, 6, 3));
        $groups['income']['note'] = 'Compare target resale prices with local purchasing power and financing costs.';
        $groups['income']['links'] = ['Census / ACS' => $profile];

        foreach (Overpass::KINDS as $kind => $label) {
            if (is_array($places[$kind] ?? null)) {
                $radius = Overpass::RADIUS[$kind] ?? 1;
                $add('amenities', $label, t('%1$d mapped within %2$d mi', $places[$kind]['count'], $radius));
            }
        }
        $groups['amenities']['note'] = 'Verify school quality and boundaries, health care, parks, retail, public transport and local services. Mapped counts measure access only.';
        $groups['amenities']['links'] = [
            'OpenStreetMap' => $geo ? 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lng . '#map=14/' . $lat . '/' . $lng : 'https://www.openstreetmap.org/',
            'NCES School Search' => 'https://nces.ed.gov/ccd/schoolsearch/',
            'Virginia Department of Health' => $state === 'VA' ? 'https://www.vdh.virginia.gov/data/' : 'https://data.cms.gov/provider-data/',
        ];
        if ($state !== 'VA') {
            $groups['amenities']['links']['CMS Provider Data'] = $groups['amenities']['links']['Virginia Department of Health'];
            unset($groups['amenities']['links']['Virginia Department of Health']);
        }

        $groups['development']['note'] = 'Verify highways, transit, commercial projects, schools and medical facilities. Check funding, approval, start and expected completion dates.';
        $groups['development']['links'] = $state === 'VA'
            ? ['Virginia DOT Projects' => 'https://www.vdot.virginia.gov/projects/', 'Virginia DRPT' => 'https://drpt.virginia.gov/']
            : ['Federal Highway Administration' => 'https://www.fhwa.dot.gov/'];
        $add('development', 'Local planning jurisdiction', $acs['name'] ?? (($p['county'] ?? null)));

        $market = self::market($zip, $state, (string) ($p['property_type'] ?? ''));
        if ($market) {
            $add('market', 'Active similar listings on file', $number($market['active']));
            $add('market', 'Similar sales on file in the last 90 days', $number($market['sales']));
            $add('market', 'Sales with a linked listing history', $number($market['matched']));
            $add('market', 'Median days from listing to closing', $number($market['days']));
            $add('market', 'Median sale price to last asking price', $market['ratio'] === null ? null : number_format($market['ratio'], 1) . '%');
            $add('market', 'Inventory relative to recent sales on file (months)', $market['inventory'] === null ? null : number_format($market['inventory'], 1));
            if ($market['matched'] >= 8) {
                $score('market', self::band($market['days'], 100, 40), self::band($market['ratio'], 90, 100));
            }
        }
        $groups['market']['note'] = 'Sample from this ZIP and property type. Counts cover records on file. Check active buyer demand and current inventory with a local agent.';
        $groups['market']['links'] = ['Redfin Data Center' => 'https://www.redfin.com/news/data-center/', 'Property market evidence' => '#pace'];

        $tax = (int) ($p['tax_annual'] ?? 0);
        $taxRate = $price > 0 && $tax > 0 ? $tax / $price * 100 : null;
        $add('cost', 'Annual property tax on file', $dollars($tax));
        $add('cost', 'Tax as a share of asking price', $taxRate === null ? null : number_format($taxRate, 2) . '%');
        $add('cost', 'County median gross monthly rent', $dollars($acs['rent'] ?? null));
        $add('cost', 'Estimated monthly rent for this home', $dollars((int) ($p['est_rent'] ?? 0)));
        if ($mortgage) {
            $add('cost', 'U.S. 30-year fixed mortgage rate', number_format($mortgage['rate'], 2) . '% (' . $mortgage['date'] . ')');
        }
        $score('cost', self::band($taxRate, 2, 0.75));
        $groups['cost']['note'] = 'Verify insurance, utilities, maintenance, property tax and comparable rents. The national mortgage rate is a reference for financing.';
        $groups['cost']['links'] = ['Freddie Mac PMMS via FRED' => RegionalData::MORTGAGE_SOURCE, 'Census / ACS' => $profile];

        $groups['safety']['note'] = 'Check reported crime and reporting coverage for the local police jurisdiction. Verify neighborhood conditions and local services on site.';
        $groups['safety']['links'] = ['FBI Crime Data Explorer' => 'https://cde.ucr.cjis.gov/'];

        $scored = array_filter($groups, static fn ($group) => $group['points'] !== null);
        $covered = array_sum(array_column($scored, 'weight'));
        $points = array_sum(array_column($scored, 'points'));
        return [
            'groups' => $groups, 'coverage' => $covered, 'points' => $points,
            'score' => $covered >= 50 && isset($scored['market']) ? (int) round(100 * $points / $covered) : null,
            'county' => $acs['name'] ?? null, 'acs_period' => $range, 'has_coordinates' => $geo,
            'has_public_data' => (bool) ($acs || $jobs || $mortgage),
            'county_data_complete' => (bool) ($acs && $jobs),
            'method' => [
                'Population and migration' => 'Migration outside its estimated error range scores 0 or 100%. Growth scores from 0% at 0% population growth to 100% at 5%. Household growth minus housing growth scores from 0% at −2 points to 100% at +2 points.',
                'Employment and economy' => 'Unemployment scores from 0% at 7% to 100% at 4%. Employment growth scores from 0% at −2% to 100% at +2%.',
                'Income and purchasing power' => 'County home value divided by income scores from 0% at 6 times income to 100% at 3 times.',
                'Housing supply and demand' => 'At least 8 linked sales are required. Listing to closing scores from 0% at 100 days to 100% at 40. Sale to asking price scores from 0% at 90% to 100% at 100%.',
                'Taxes and cost of living' => 'Tax divided by asking price scores from 0% at 2% tax to 100% at 0.75%.',
            ],
        ];
    }

    /** Directional 0–1 scale with explicit endpoints. */
    private static function band(?float $value, float $zero, float $full): ?float
    {
        return $value === null ? null : max(0, min(1, ($value - $zero) / ($full - $zero)));
    }

    private static function change(?int $before, ?int $after): ?float
    {
        return $before > 0 && $after !== null ? ($after / $before - 1) * 100 : null;
    }

    private static function market(string $zip, string $state, string $type): array
    {
        if ($zip === '' || $state === '' || $type === '') {
            return [];
        }
        $active = (int) Db::val("SELECT COUNT(*) FROM properties WHERE zip = ? AND state = ? AND property_type = ? AND status = 'for_sale'", [$zip, $state, $type]);
        $sales = Db::all(
            "SELECT p.last_sale_price, p.last_sale_date,
                (SELECT l.event_date FROM price_events l WHERE l.property_id = p.id AND l.event IN ('listed','relisted')
                     AND l.event_date BETWEEN DATE_SUB(p.last_sale_date, INTERVAL 365 DAY) AND p.last_sale_date
                     ORDER BY l.event_date DESC, l.id DESC LIMIT 1) AS listing_date,
                (SELECT a.price FROM price_events a WHERE a.property_id = p.id AND a.event IN ('listed','relisted','price_change')
                     AND a.event_date BETWEEN DATE_SUB(p.last_sale_date, INTERVAL 365 DAY) AND p.last_sale_date AND a.price > 0
                     ORDER BY a.event_date DESC, a.id DESC LIMIT 1) AS ask
             FROM properties p WHERE p.zip = ? AND p.state = ? AND p.property_type = ? AND p.last_sale_price > 0
             AND p.last_sale_date BETWEEN DATE_SUB(UTC_DATE(), INTERVAL 90 DAY) AND UTC_DATE()",
            [$zip, $state, $type]
        );
        $days = $ratios = [];
        foreach ($sales as $sale) {
            if ($sale['listing_date'] && (int) $sale['ask'] > 0) {
                $days[] = (strtotime($sale['last_sale_date'] . ' UTC') - strtotime($sale['listing_date'] . ' UTC')) / 86400;
                $ratios[] = $sale['last_sale_price'] / $sale['ask'] * 100;
            }
        }
        return [
            'active' => $active, 'sales' => count($sales), 'matched' => count($days),
            'days' => $days ? (int) round(median($days)) : null, 'ratio' => median($ratios),
            'inventory' => $sales ? $active * 3 / count($sales) : null,
        ];
    }
}

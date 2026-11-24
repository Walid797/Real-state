<?php
declare(strict_types=1);
namespace App\Providers;

use App\Cache;
use App\Geo;
use App\Http;

/** Public ArcGIS place candidates are location observations, not service-quality ratings. */
final class NearbyPlaces
{
    public static function cached(float $lat,float $lng): array
    {
        return Cache::get(Cache::key('arcgis_places','v1',['lat'=>round($lat,5),'lng'=>round($lng,5)]))??[];
    }
    public static function at(float $lat,float $lng): array
    {
        $key=Cache::key('arcgis_places','v1',['lat'=>round($lat,5),'lng'=>round($lng,5)]);
        $kept=Cache::get($key);if(is_array($kept))return $kept;
        $places=[];
        foreach(['health'=>'Hospital','pharmacy'=>'Pharmacy','grocery'=>'Grocery','park'=>'Park'] as $kind=>$category){
            $r=Http::get('https://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/findAddressCandidates?'.http_build_query([
                'SingleLine'=>$category,'category'=>$category,'location'=>$lng.','.$lat,'distance'=>4828,'outFields'=>'PlaceName,StAddr,City,RegionAbbr,Addr_type','maxLocations'=>5,'f'=>'json',
            ]),[],4);
            $candidates=json_decode($r['body'],true)['candidates']??[];
            $valid=self::parse($candidates,$lat,$lng);
            if($r['status']===200&&$valid)$places[$kind]=$valid[0];
        }
        $out=['places'=>$places,'construction'=>[],'schools'=>[],'observed_at'=>now(),'source'=>'ArcGIS World Geocoding','unavailable'=>!$places];
        Cache::put($key,'arcgis_places',$out,count($places)>=4?86400*7:900);return $out;
    }
    public static function parse(array $rows,float $lat,float $lng): array
    {
        $out=[];
        foreach($rows as $r){
            $a=$r['attributes']??[];$x=$r['location']['x']??null;$y=$r['location']['y']??null;
            if(!Geo::valid($y,$x)||($r['score']??0)<90||($a['Addr_type']??'')!=='POI'||empty($a['PlaceName']))continue;
            $miles=Geo::miles($lat,$lng,(float)$y,(float)$x);
            if($miles>3)continue;
            $out[]=['name'=>mb_substr(strip_tags($a['PlaceName']),0,120),'lat'=>(float)$y,'lng'=>(float)$x,'straight_miles'=>round($miles,2),'road_miles'=>null,'driving_minutes'=>null];
        }
        usort($out,static fn($a,$b)=>$a['straight_miles']<=>$b['straight_miles']);return $out;
    }
}

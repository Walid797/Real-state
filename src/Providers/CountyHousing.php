<?php
declare(strict_types=1);
namespace App\Providers;

use App\Cache;
use App\Http;

/** Realtor.com published monthly county inventory, matched only by verified Census FIPS. */
final class CountyHousing
{
    public const URL='https://econdata.s3-us-west-2.amazonaws.com/Reports/Core/RDC_Inventory_Core_Metrics_County.csv';
    public static function get(string $fips, bool $fetch=true): array
    {
        if (!preg_match('/^\d{5}$/',$fips)) return [];
        $key=Cache::key('realtor','county-inventory-v1');
        $all=Cache::get($key);
        if (!is_array($all) && $fetch) {
            $r=Http::get(self::URL,[],12);
            $all=$r['status']===200 ? self::parse($r['body']) : [];
            Cache::put($key,'realtor',$all,$all?86400:900);
        }
        return $all[$fips]??[];
    }
    public static function parse(string $csv): array
    {
        if(strlen($csv)>6000000) return [];
        $f=fopen('php://temp','r+');fwrite($f,$csv);rewind($f);
        $header=fgetcsv($f,0,',','"','');$out=[];
        while(($values=fgetcsv($f,0,',','"',''))!==false){
            if(count($values)!==count($header?:[]))continue;
            $r=array_combine($header,$values);$id=str_pad($r['county_fips']??'',5,'0',STR_PAD_LEFT);
            if(!preg_match('/^\d{5}$/',$id)||!preg_match('/^20\d{2}(0[1-9]|1[0-2])$/',$r['month_date_yyyymm']??'')||$r['month_date_yyyymm']>gmdate('Ym'))continue;
            $data=['fips'=>$id,'county'=>$r['county_name'],'period'=>substr($r['month_date_yyyymm'],0,4).'-'.substr($r['month_date_yyyymm'],4,2),'observed_at'=>now(),'source'=>self::URL,'metrics'=>[],'score'=>null];
            foreach(['median_days_on_market'=>'County median days on market','active_listing_count'=>'County active listings','pending_listing_count'=>'County pending listings','median_listing_price'=>'County median asking price','new_listing_count'=>'County new listings','pending_ratio'=>'County pending to active ratio'] as $field=>$label){
                if(is_numeric($r[$field]??null)&&$r[$field]>=0)$data['metrics'][$label]=(float)$r[$field];
            }
            $dom=$data['metrics']['County median days on market']??null;
            $active=$data['metrics']['County active listings']??null;
            $age=(int)substr($r['month_date_yyyymm'],0,4)*12+(int)substr($r['month_date_yyyymm'],4,2);
            $current=(int)gmdate('Y')*12+(int)gmdate('m');
            if(($r['quality_flag']??'')==='0'&&$dom!==null&&$dom<=730&&$active>=30&&$current-$age<=3){
                // Published active-listing DOM is not listing-to-closing time or a sales-price estimate.
                $data['score']=(int)round(max(0,min(100,(120-$dom)/90*100)));
            }
            if(!isset($out[$id])||$data['period']>$out[$id]['period'])$out[$id]=$data;
        }
        fclose($f);return $out;
    }
}

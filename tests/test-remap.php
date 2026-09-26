<?php
define('ABSPATH','/'); define('GSUP_META_OPTION','_gsup_ae_option');
function remove_accents($s){return $s;} function apply_filters($h,$v){return $v;}
$GLOBALS['meta']=[];
function get_post_meta($id,$k,$s){return $GLOBALS['meta'][$id][$k]??'';}
class WC_Product { public $id,$attrs; function __construct($id,$attrs){$this->id=$id;$this->attrs=$attrs;} function get_id(){return $this->id;} function is_type($t){return $t==='variation';} function get_variation_attributes($p=true){return $this->attrs;} }
class GSUP_Creator { static function option_text($sku){ $p=[]; foreach($sku['props'] as $x) if(!$x['is_ship']) $p[]=$x['name'].': '.$x['value']; return implode(' · ',$p);} }
require __DIR__ . '/../givsen-supplier/includes/class-gsup-remap.php';
function sku($id,$vals){ $p=[]; foreach($vals as $n=>$v) $p[]=['name'=>$n,'value'=>$v,'is_ship'=>false]; $p[]=['name'=>'Ships From','value'=>'Australia','is_ship'=>true]; return ['sku_id'=>$id,'props'=>$p]; }
$fail=0; function ok($c,$m){global $fail; echo ($c?'PASS ':'FAIL ').$m."\n"; if(!$c)$fail++;}

// Store renamed values ("Red" from "1PC-RED"), old option text kept.
$items=[new WC_Product(11,['attribute_color'=>'Red','attribute_size'=>'M']), new WC_Product(12,['attribute_color'=>'Red','attribute_size'=>'L']), new WC_Product(13,['attribute_color'=>'Navy Blue','attribute_size'=>'M'])];
$GLOBALS['meta'][13]['_gsup_ae_option']='Color: Dark Blue · Size: M · Ships From: Australia';
$skus=[ sku('a',['Colour'=>'RED','Size'=>'L']), sku('b',['Colour'=>'RED','Size'=>'M']), sku('c',['Colour'=>'Dark Blue','Size'=>'M']), sku('d',['Colour'=>'Black','Size'=>'M']) ];
$m=GSUP_Remap::auto_match($items,$skus);
ok(($m[11]['sku']??'')==='b','Red/M → RED/M ('.json_encode($m[11]??null).')');
ok(($m[12]['sku']??'')==='a','Red/L → RED/L');
ok(($m[13]['sku']??'')==='c','Navy Blue (was Dark Blue)/M → Dark Blue/M via old option text');

// Shoe sizes: numbers matter.
$items=[new WC_Product(21,['attribute_size'=>'42']), new WC_Product(22,['attribute_size'=>'43'])];
$skus=[sku('x',['Shoe Size'=>'43']),sku('y',['Shoe Size'=>'42'])];
$m=GSUP_Remap::auto_match($items,$skus);
ok(($m[21]['sku']??'')==='y' && ($m[22]['sku']??'')==='x','shoe sizes 42/43');

// Nothing in common → no guess.
$items=[new WC_Product(31,['attribute_scent'=>'Lavender'])];
$m=GSUP_Remap::auto_match($items,[sku('p',['Scent'=>'Vanilla']),sku('q',['Scent'=>'Rose'])]);
ok(!isset($m[31]),'no false match');
// One-to-one: two store items can't take the same option.
$items=[new WC_Product(41,['attribute_color'=>'Red']),new WC_Product(42,['attribute_color'=>'Red'])];
$m=GSUP_Remap::auto_match($items,[sku('r',['Color'=>'Red'])]);
ok(count($m)===1,'one-to-one');
echo $fail ? "$fail FAILED\n" : "ALL PASSED\n"; exit( $fail ? 1 : 0 );

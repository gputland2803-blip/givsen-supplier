<?php
namespace WpOrg\Requests { class Requests { public static $log=[]; public static function request_multiple($reqs,$opts){ self::$log[]=count($reqs); $out=[]; foreach($reqs as $k=>$r){ parse_str(parse_url($r['url'],PHP_URL_QUERY),$q); $fn=$GLOBALS['fake']; $out[$k]=(object)['status_code'=>200,'body'=>$fn($q)]; } return $out; } } }
namespace {
define('WPINC','wp-includes');
function get_bloginfo(){return '6.8';} function home_url(){return 'https://x/';}
$GLOBALS['fake']=function($q){
  if($q['method']==='aliexpress.ds.product.get') return json_encode(['aliexpress_ds_product_get_response'=>['rsp_code'=>'200','result'=>['ae_store_info'=>['store_id'=>(int)$q['product_id']*10,'store_name'=>'S'],'ae_item_base_info_dto'=>['subject'=>'P'.$q['product_id'],'product_status_type'=>'onSelling'],'ae_item_sku_info_dtos'=>['ae_item_sku_info_d_t_o'=>[['sku_id'=>'9'.$q['product_id'],'offer_sale_price'=>'5.00','sku_available_stock'=>3,'sku_attr'=>'14:1']]]]]]);
  if($q['method']==='aliexpress.trade.ds.order.get'){ $id=json_decode($q['single_order_query'],true)['order_id']; return json_encode(['aliexpress_trade_ds_order_get_response'=>['result'=>['order_status'=>'WAIT_BUYER_ACCEPT_GOODS','logistics_info_list'=>['aeop_order_logistics_info'=>[['logistics_no'=>'LP'.$id,'logistics_service'=>'CAINIAO']]]]]]); }
  if($q['method']==='aliexpress.ds.freight.query') return json_encode(['aliexpress_ds_freight_query_response'=>['result'=>['success'=>true,'delivery_options'=>['delivery_option_d_t_o'=>[['code'=>'STD','shipping_fee_format'=>'$2.50','tracking'=>true]]]]]]);
};
require __DIR__ . '/test-aliexpress.php';
}

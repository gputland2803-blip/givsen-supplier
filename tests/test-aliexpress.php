<?php
define('ABSPATH', '/');
define('DAY_IN_SECONDS', 86400); define('HOUR_IN_SECONDS', 3600); define('MINUTE_IN_SECONDS', 60);
class WP_Error { public $c,$m,$d; function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} function get_error_code(){return $this->c;} function get_error_message(){return $this->m;} function get_error_data(){return $this->d;} }
function is_wp_error($x){return $x instanceof WP_Error;}
$GLOBALS['opts'] = ['gsup_ae_app_key'=>'k','gsup_ae_app_secret'=>'s','gsup_ae_token'=>['access_token'=>'t','refresh_token'=>'r','expires_at'=>time()+86400*30,'issued_at'=>time(),'connected_at'=>time()]];
function get_option($k,$d=false){return $GLOBALS['opts'][$k] ?? $d;}
function update_option($k,$v){$GLOBALS['opts'][$k]=$v;}
function wp_json_encode($v,$f=0){return json_encode($v,$f);}
$GLOBALS['actions']=[]; function add_action($h,$cb){ $GLOBALS['actions'][$h][]=$cb; }
function untrailingslashit($s){return rtrim($s,'/');}
function apply_filters($h,$v){return $v;}
function get_woocommerce_currency(){return 'AUD';}
function wc_get_price_decimals(){return 2;}
function wc_format_decimal($n,$dp=false){ return $dp===false? (string)$n : number_format((float)$n,$dp,'.',''); }
function wc_get_base_location(){return ['country'=>'AU'];}
$GLOBALS['replies']=[]; $GLOBALS['sent']=[];
function wp_remote_post($url,$a){ parse_str(parse_url($url,PHP_URL_QUERY),$q); $GLOBALS['sent'][]=$q; return ['body'=>array_shift($GLOBALS['replies'])]; }
function wp_remote_retrieve_body($r){return $r['body'];}
function wp_remote_retrieve_response_code($r){return 200;}
function gsup_sanitize_ship_from($v){ $m=['Australia'=>'AU','China'=>'CN','United States'=>'US']; return $m[$v] ?? ''; }
function gsup_parse_product_id($v){return (string)$v;}
class WC { public $countries; }
class Countries { function get_country_calling_code($c){ return ['AU'=>'+61','US'=>'+1','GB'=>'+44'][$c] ?? ''; } }
function WC(){ static $w; if(!$w){$w=new WC; $w->countries=new Countries;} return $w; }
$dir=__DIR__ . '/../givsen-supplier/includes/';
require $dir.'class-gsup-aliexpress.php';
require $dir.'class-gsup-tidy.php'; require $dir.'class-gsup-creator.php';
require $dir.'class-gsup-orders.php';
$fail=0; function ok($c,$msg){ global $fail; echo ($c?'PASS ':'FAIL ').$msg."\n"; if(!$c)$fail++; }

// Freight (ds.freight.query, simplify=true shape)
$GLOBALS['replies'][]=json_encode(['aliexpress_ds_freight_query_response'=>['result'=>['success'=>true,'code'=>200,'delivery_options'=>['delivery_option_d_t_o'=>[
  ['code'=>'CAINIAO_STANDARD','company'=>'AliExpress Standard Shipping','shipping_fee_cent'=>'4.12','shipping_fee_format'=>'AU $4.12','shipping_fee_currency'=>'AUD','free_shipping'=>false,'min_delivery_days'=>8,'max_delivery_days'=>15,'tracking'=>true],
  ['code'=>'CAINIAO_ECONOMY','company'=>'Cainiao Saver','shipping_fee_format'=>'AU $1.50','free_shipping'=>false,'min_delivery_days'=>15,'max_delivery_days'=>30,'tracking'=>false],
  ['code'=>'AE_PREMIUM','company'=>'Premium','shipping_fee_format'=>'AU $12.00','free_shipping'=>false,'min_delivery_days'=>3,'max_delivery_days'=>6,'tracking'=>true],
]]]]]);
$f = GSUP_AliExpress::freight('1005001','12000','AU',2);
ok(is_array($f) && count($f)===3, 'freight parsed 3 options');
ok($f[0]['code']==='CAINIAO_ECONOMY' && $f[0]['fee']===1.5, 'sorted cheapest first');
$req=json_decode($GLOBALS['sent'][0]['queryDeliveryReq'],true);
ok($GLOBALS['sent'][0]['method']==='aliexpress.ds.freight.query' && $req['quantity']===2 && $req['shipToCountry']==='AU' && $req['selectedSkuId']==='12000','freight request params');
ok(GSUP_AliExpress::choose_freight($f)['code']==='CAINIAO_STANDARD','default = cheapest tracked');
update_option('gsup_ship_pref','cheapest'); ok(GSUP_AliExpress::choose_freight($f)['code']==='CAINIAO_ECONOMY','cheapest');
update_option('gsup_ship_pref','fastest'); ok(GSUP_AliExpress::choose_freight($f)['code']==='AE_PREMIUM','fastest');
update_option('gsup_ship_pref','cheapest_tracked');

// Free shipping + single (non-list) option
$GLOBALS['replies'][]=json_encode(['aliexpress_ds_freight_query_response'=>['result'=>['success'=>'true','delivery_options'=>['delivery_option_d_t_o'=>['code'=>'CAINIAO_STANDARD','company'=>'Std','free_shipping'=>'true','shipping_fee_format'=>'AU $0.00']]]]]);
$f=GSUP_AliExpress::freight('1','2','AU'); ok(is_array($f) && $f[0]['fee']===0.0,'free single option');

// Fallback to legacy when method not permitted
$GLOBALS['replies'][]=json_encode(['error_response'=>['code'=>'InsufficientIsvPermissions','msg'=>'Insufficient isv permissions']]);
$GLOBALS['replies'][]=json_encode(['aliexpress_logistics_buyer_freight_calculate_response'=>['result'=>['success'=>true,'aeop_freight_calculate_result_for_buyer_d_t_o_list'=>['aeop_freight_calculate_result_for_buyer_dto'=>[['service_name'=>'CAINIAO_STANDARD','estimated_delivery_time'=>'7-15','freight'=>['amount'=>'3.3','currency_code'=>'AUD']]]]]]]);
$f=GSUP_AliExpress::freight('1','2','AU'); ok(is_array($f) && $f[0]['fee']===3.3 && $f[0]['max_days']===15,'legacy freight fallback');

// No delivery
$GLOBALS['replies'][]=json_encode(['aliexpress_ds_freight_query_response'=>['result'=>['success'=>false,'msg'=>'no delivery']]]);
ok(is_wp_error(GSUP_AliExpress::freight('1','2','NZ')),'no delivery -> error');

// Place order success
$GLOBALS['sent']=[];
$GLOBALS['replies'][]=json_encode(['aliexpress_ds_order_create_response'=>['result'=>['is_success'=>true,'order_list'=>['number'=>[8123456789012345]]]]]);
$ids=GSUP_AliExpress::place_order(['full_name'=>'A B','country'=>'AU'],[['product_id'=>'100','qty'=>2,'sku_attr'=>'14:193#Red','service'=>'CAINIAO_STANDARD']],'1001-5');
ok($ids===['8123456789012345'],'order number kept exactly: '.json_encode($ids));
$p=json_decode($GLOBALS['sent'][0]['param_place_order_request4_open_api_d_t_o'],true);
ok($p['product_items'][0]['sku_attr']==='14:193#Red' && $p['logistics_address']['locale']==='en_US' && $p['out_order_id']==='1001-5','order request body');
ok(json_decode($GLOBALS['sent'][0]['ds_extend_request'],true)['payment']['try_to_pay']==='true','auto-pay requested');

// Place order business error
$GLOBALS['replies'][]=json_encode(['aliexpress_ds_order_create_response'=>['result'=>['is_success'=>false,'error_code'=>'B_DROPSHIPPER_DELIVERY_ADDRESS_VALIDATE_FAIL','error_msg'=>'address invalid']]]);
$e=GSUP_AliExpress::place_order([],[['product_id'=>'1','qty'=>1,'sku_attr'=>'x','service'=>'y']],'x');
ok(is_wp_error($e) && strpos($e->get_error_message(),'delivery address')!==false,'friendly address error: '.(is_wp_error($e)?$e->get_error_message():''));

// Get order with tracking
$GLOBALS['replies'][]=json_encode(['aliexpress_trade_ds_order_get_response'=>['result'=>['order_status'=>'WAIT_BUYER_ACCEPT_GOODS','logistics_status'=>'SELLER_SEND_GOODS','order_amount'=>['amount'=>'17.40','currency_code'=>'AUD'],'logistics_info_list'=>['aeop_order_logistics_info'=>[['logistics_no'=>'LP00123456789CN','logistics_service'=>'CAINIAO_STANDARD']]]]]]);
$o=GSUP_AliExpress::get_order('8123456789012345');
ok(!is_wp_error($o) && $o['tracking'][0]['number']==='LP00123456789CN' && $o['amount']===17.4,'order tracking + amount parsed');
$GLOBALS['replies'][]=json_encode(['aliexpress_trade_ds_order_get_response'=>['result'=>['order_status'=>'WAIT_SELLER_SEND_GOODS','logistics_info_list'=>[]]]]);
$o=GSUP_AliExpress::get_order('1'); ok(!is_wp_error($o) && $o['tracking']===[],'no tracking yet');

// Pricing incl. delivery
ok(GSUP_Creator::price_for(10,3)==='25.95','price_for 10+3 ×2 → 25.95 (got '.GSUP_Creator::price_for(10,3).')');
update_option('gsup_price_shipping','no'); ok(GSUP_Creator::price_for(10,3)==='19.95','delivery excluded → 19.95');

// Phone normalisation
$m=new ReflectionMethod('GSUP_Orders','phone'); $m->setAccessible(true);
ok($m->invoke(null,'0412 345 678','AU')===['+61','412345678'],'AU local mobile');
ok($m->invoke(null,'+61 412-345-678','AU')===['+61','412345678'],'AU intl mobile');
ok($m->invoke(null,'(555) 123-4567','US')===['+1','5551234567'],'US number');
ok($m->invoke(null,'+1 555 123 4567','US')===['+1','5551234567'],'US intl');
ok(GSUP_AliExpress::$last_pay_requested===true,'pay flag set on new method');
$GLOBALS['replies'][]=json_encode(['error_response'=>['code'=>'InsufficientIsvPermissions','msg'=>'Insufficient isv permissions']]);
$GLOBALS['replies'][]=json_encode(['aliexpress_trade_buy_placeorder_response'=>['result'=>['is_success'=>true,'order_list'=>['number'=>['900']]]]]);
$GLOBALS['sent']=[];
$ids=GSUP_AliExpress::place_order([],[['product_id'=>'1','qty'=>1,'sku_attr'=>'x','service'=>'y']],'x');
ok($ids===['900'] && GSUP_AliExpress::$last_pay_requested===false && !isset($GLOBALS['sent'][1]['ds_extend_request']),'legacy fallback: placed, pay flag false');
if (class_exists('\WpOrg\Requests\Requests')) {
  $pairs=[]; for($i=1;$i<=12;$i++) $pairs[]=[(string)(100000+$i),'AU'];
  $r=GSUP_AliExpress::get_products($pairs);
  ok(count($r)===12 && $r['100005|AU']['title']==='P100005' && $r['100005|AU']['skus'][0]['sku_id']==='9100005','parallel products mapped to right keys');
  ok(\WpOrg\Requests\Requests::$log===[5,5,2],'sent in chunks of 5: '.json_encode(\WpOrg\Requests\Requests::$log));
  $o=GSUP_AliExpress::get_orders(['8123456789012345','8123456789012346','8123456789012345']);
  ok(count($o)===2 && $o['8123456789012346']['tracking'][0]['number']==='LP8123456789012346','parallel orders deduped & mapped');
  $f=GSUP_AliExpress::freights(['freight|1|AU'=>['1','91','AU'],'freight|2|US'=>['2','92','US']]);
  ok($f['freight|2|US'][0]['fee']===2.5,'parallel freight mapped');
}
foreach (['Delivered'=>true,'Your item was delivered in or at the mailbox'=>true,'Signed for by: J SMITH'=>true,'Picked up by recipient'=>true,'Delivery attempted - no one home'=>false,'Out for delivery'=>false,'Arrived at destination country'=>false,'Not delivered, returned to sender'=>false,'已签收'=>true,'Assigned to courier'=>false,'Delivered to airline'=>false,'Consigned to carrier'=>false,'Signed for by: J SMITH'=>true,'Delivered to local courier'=>false,'Your parcel has been delivered to your mailbox'=>true] as $t=>$want) ok(GSUP_AliExpress::is_delivered_text($t)===$want,"delivered? '$t'");
$m=new ReflectionMethod('GSUP_AliExpress','parse_parcel'); $m->setAccessible(true);
$p=$m->invoke(null,['aliexpress_ds_order_tracking_get_response'=>['result'=>['ret'=>true,'data'=>['tracking_detail_line_list'=>['tracking_detail'=>[['mail_no'=>'LP1','detail_node_list'=>['detail_node'=>[
  ['time_stamp'=>'1790000000000','tracking_detail_desc'=>'Accepted by carrier'],
  ['time_stamp'=>'1790600000000','tracking_detail_desc'=>'Out for delivery'],
  ['time_stamp'=>'1790700000000','tracking_detail_desc'=>'Delivered to recipient'],
]]]]]]]]]);
ok($p['delivered'] && $p['delivered_at']===1790700000 && $p['last_text']==='Delivered to recipient' && $p['events']===3,'parcel parsed, delivered: '.json_encode($p));
$p=$m->invoke(null,['result'=>['data'=>[['desc'=>'In transit','time'=>'2026-09-20 10:00:00']]]]);
ok(!$p['delivered'] && $p['last_text']==='In transit' && $p['last_time']>0,'string dates, not delivered');
// Diagnostics log: saved at shutdown, secrets and customer addresses never kept.
GSUP_AliExpress::save_log();
$log = get_option('gsup_ae_log_entries');
ok(is_array($log) && count($log) > 0 && count($log) <= 30, 'diagnostics log kept (last 30): '.(is_array($log)?count($log):0));
$all = json_encode($log);
ok(strpos($all,'"session"')===false && strpos($all,'"sign"')===false && strpos($all,'"app_key"')===false,'no token, signature or app key in the log');
$orders = array_values(array_filter($log,function($e){return $e['api']==='aliexpress.ds.order.create';}));
ok($orders && $orders[0]['params']['param_place_order_request4_open_api_d_t_o']['address']==='[hidden]' && strpos(json_encode($orders[0]),'full_name')===false,'customer address hidden in logged orders');
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n"; exit( $fail ? 1 : 0 );

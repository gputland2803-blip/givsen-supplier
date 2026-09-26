<?php
define('ABSPATH','/'); define('DAY_IN_SECONDS',86400); define('HOUR_IN_SECONDS',3600); define('MINUTE_IN_SECONDS',60);
define('GSUP_VERSION','0.6.0'); define('GSUP_URL','/');
class WP_Error { public $c,$m,$d; function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} function get_error_code(){return $this->c;} function get_error_message(){return $this->m;} function get_error_data(){return $this->d;} }
function is_wp_error($x){return $x instanceof WP_Error;}
$GLOBALS['opts']=['gsup_secret'=>'abcDEF1234567890abcDEF1234567890abcdEFGH','gsup_ae_app_key'=>'512345','gsup_ae_app_secret'=>'x','gsup_ae_token'=>['access_token'=>'t','refresh_token'=>'r','expires_at'=>time()+86400*25,'issued_at'=>time(),'connected_at'=>time(),'account'=>'givsen_store','refresh_expires_at'=>0],'gsup_auto_order'=>'yes','gsup_combine_seller'=>'yes','gsup_combine_log'=>[
 ['at'=>time(),'order_id'=>0,'order_no'=>'1201','store_id'=>'1102345678','result'=>'one','error'=>'','lines'=>[['name'=>'Pearl Necklace','qty'=>1,'product'=>'1005006000000001','method'=>'AliExpress Standard','fee'=>4.5,'cost'=>16.2,'orders'=>['8190000000001']],['name'=>'Pearl Earrings','qty'=>2,'product'=>'1005006000000002','method'=>'AliExpress Standard','fee'=>4.5,'cost'=>21.0,'orders'=>['8190000000001']]],'orders'=>['8190000000001'=>['amount'=>32.7,'currency'=>'AUD','products'=>['1005006000000001','1005006000000002']]]],
 ['at'=>time()-86400,'order_id'=>0,'order_no'=>'1188','store_id'=>'1102345678','result'=>'split','error'=>'','lines'=>[['name'=>'Ring','qty'=>1,'product'=>'1','method'=>'Standard','fee'=>3,'cost'=>9,'orders'=>['81','82']]],'orders'=>['81'=>null,'82'=>['amount'=>9.0,'currency'=>'AUD','products'=>['1']]]],
 ['at'=>time()-2*86400,'order_id'=>0,'order_no'=>'1150','store_id'=>'1','result'=>'error','error'=>'That shipping method isn’t available','lines'=>[],'orders'=>[]],
],'gsup_cbr_enabled'=>'no','admin_email'=>'gp@example.com',
 'gsup_sync_last'=>['started'=>time()-7200,'finished'=>time()-7000,'manual'=>false,'total'=>42,'report'=>['checked'=>42,'stock_changes'=>5,'cost_changes'=>3,'price_changes'=>0,'ship_changes'=>2,'removed'=>[],'options_gone'=>[],'back'=>[],'missing'=>[],'low_margin'=>[],'errors'=>[]]]];
function get_option($k,$d=false){return $GLOBALS['opts'][$k]??$d;}
foreach(['esc_html','esc_attr','esc_url','esc_textarea','wp_kses_post','wp_strip_all_tags','sanitize_key','wp_unslash','sanitize_text_field','untrailingslashit'] as $f) eval("function $f(\$s){return is_string(\$s)?\$s:\$s;}");
function esc_html_($s){return $s;}
function selected($a,$b,$e=true){return $a==$b?' selected':'';} function checked($a,$b=true,$e=true){return $a==$b?' checked':'';}
function wp_nonce_field(){} function wp_nonce_url($u){return $u;} function admin_url($p=''){return '/wp-admin/'.$p;} function home_url($p=''){return 'https://givsen.com/';}
function add_query_arg($a,$u){return $u.(strpos($u,'?')===false?'?':'&').http_build_query($a);}
function current_user_can(){return true;} function get_current_user_id(){return 1;} function get_transient(){return false;} function set_transient(){} function wp_generate_password(){return 'x';} function current_time(){return '';} function wp_get_current_user(){} function delete_transient(){}
function wp_date($f,$t=null){return date($f,$t??time());} function human_time_diff($t){return '2 hours';}
function wc_format_decimal($n,$dp=false){return $dp===false?(string)$n:number_format((float)$n,$dp,'.','');}
function wc_price($n){return '$'.number_format((float)$n,2);} function get_woocommerce_currency(){return 'AUD';} function wc_get_price_decimals(){return 2;}
function wc_get_order($id){return null;} function wc_get_orders($a){ return !empty($a['paginate']) ? (object)['total'=>2,'orders'=>[],'max_num_pages'=>0] : []; } class WP_Query{ public $found_posts=4; function __construct($a){} } function wc_get_order_statuses(){return ['wc-processing'=>'Processing'];} function get_posts($a){return [];}
function get_user_meta(){return [];} function rest_url($p){return 'https://givsen.com/wp-json/'.$p;} function wp_json_encode($v){return json_encode($v);}
function wc_get_base_location(){return ['country'=>'AU'];} function apply_filters($h,$v){return $v;} function function_exists_(){}

class GSUP_REST{const NS='givsen-supplier/v1';}
class GSUP_Import{const STATUSES=['new','linked','dismissed']; static function counts(){return ['new'=>3,'linked'=>10,'dismissed'=>0,'all'=>13];}}
class GSUP_Install{}
class GSUP_Sync{const OPT_LAST='gsup_sync_last'; static function enabled(){return true;} static function running(){return null;} static function update_prices(){return false;} static function backup_auto(){return true;} static function price_mode(){return 'low';} static function backup_rise(){return 15;}}
class WC_Countries{function get_countries(){return ['AU'=>'Australia','US'=>'United States (US)','NZ'=>'New Zealand'];}}
class WCx{public $countries; function __construct(){$this->countries=new WC_Countries;}} function WC(){static $w; return $w??=new WCx;}
$d=__DIR__ . '/../givsen-supplier/includes/';
require $d.'functions.php'; require $d.'class-gsup-aliexpress.php'; require $d.'class-gsup-tidy.php'; require $d.'class-gsup-creator.php'; require $d.'class-gsup-profit.php'; require $d.'class-gsup-cbr.php'; require $d.'class-gsup-orders.php'; require $d.'class-gsup-givsen.php'; require $d.'class-gsup-parcels.php'; require $d.'class-gsup-remap.php'; require $d.'class-gsup-report.php'; require $d.'class-gsup-eta.php'; require $d.'class-gsup-bulk-tidy.php'; require $d.'class-gsup-ai.php'; require $d.'class-gsup-admin-page.php'; function wp_timezone(){return new DateTimeZone('Australia/Sydney');} define('WEEK_IN_SECONDS',604800); function get_the_title(){return '';} function get_post(){return null;}

$sec=$argv[1]; $_GET=$sec==='reports'?['page'=>'gsup','tab'=>'reports']:['page'=>'gsup','tab'=>'settings','section'=>$sec];
ob_start(); GSUP_Admin_Page::render(); $html=ob_get_clean();
$css=file_get_contents(__DIR__ . '/../givsen-supplier/assets/admin.css');
echo "<!doctype html><html><head><meta charset=utf-8><style>body{font:13px -apple-system,Segoe UI,Roboto,sans-serif;background:#f0f0f1;color:#3c434a;margin:0;padding:10px 20px} a{color:#2271b1} .nav-tab-wrapper{border-bottom:1px solid #c3c4c7} .nav-tab{display:inline-block;padding:5px 10px;margin-right:4px;border:1px solid #c3c4c7;border-bottom:0;background:#dcdcde;text-decoration:none;color:#50575e;font-weight:600;font-size:14px} .nav-tab-active{background:#f0f0f1;color:#000} .button{display:inline-block;padding:0 10px;line-height:28px;border:1px solid #2271b1;color:#2271b1;border-radius:3px;background:#f6f7f7;text-decoration:none;font-size:13px} .button-primary{background:#2271b1;color:#fff} .button-small{line-height:24px;padding:0 8px;font-size:11px} .form-table{border-collapse:collapse;width:100%} .form-table th{text-align:left;vertical-align:top;padding:15px 10px 15px 0;font-weight:600;color:#1d2327} .form-table td{padding:12px 10px} .description{color:#646970;font-size:13px} ul{list-style:none;padding:0} code{background:#f0f0f1;padding:3px 5px} h1{font-size:23px;font-weight:400}</style><style>$css</style></head><body>$html</body></html>";

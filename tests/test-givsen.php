<?php
define('ABSPATH','/');
$GLOBALS['opts']=['gsup_fee_percent'=>1.75,'gsup_fee_fixed'=>0.30,'gsup_fallback_phone'=>'+61 400 000 000'];
function get_option($k,$d=false){return $GLOBALS['opts'][$k]??$d;}
function givsen_is_gift_order($o){return in_array($o->m['_givsen_mode']??'',['share','phone','address','username','corporate'],true);}
function givsen_safe_sender_name($s){return $s;} function givsen_greeting_first_name($s){return $s;}
function get_bloginfo(){return 'Givsen';} function wp_specialchars_decode($s){return $s;} function sanitize_email($s){return $s;} function is_email($s){return (bool)filter_var($s,FILTER_VALIDATE_EMAIL);} function esc_html($s){return $s;}
class Item{public $q,$t;function __construct($q,$t){$this->q=$q;$this->t=$t;} function get_quantity(){return $this->q;} function get_total(){return $this->t;}}
class WC_Order{public $m,$d,$items; function __construct($m,$d=[],$items=[]){$this->m=$m;$this->d=$d;$this->items=$items;}
 function get_meta($k){return $this->m[$k]??'';} function get_items(){return $this->items;} function get_total_refunded_for_item($i){return 0;}
 function __call($n,$a){ $k=substr($n,4); return $this->d[$k]??''; } }
$orders=[];
function wc_get_order($id){return $GLOBALS['orders'][$id]??null;}
require __DIR__ . '/../givsen-supplier/includes/class-gsup-profit.php';
require __DIR__ . '/../givsen-supplier/includes/class-gsup-givsen.php';
$fail=0; function ok($c,$m){global $fail; echo ($c?'PASS ':'FAIL ').$m."\n"; if(!$c)$fail++;}
$parent=new WC_Order(['_givsen_mode'=>'corporate','_givsen_org_name'=>'Acme Pty Ltd'],['shipping_total'=>30,'total'=>330,'billing_phone'=>'+61 2 9999 0000'],[new Item(3,300)]);
$GLOBALS['orders'][10]=$parent;
$child=new WC_Order(['_givsen_mode'=>'corporate_child','_givsen_parent_order'=>10],['billing_email'=>'sam@example.com','shipping_first_name'=>'Sam','billing_phone'=>'']);
$s=GSUP_Givsen::child_share($child);
ok(abs($s['unit']-100)<.001 && abs($s['shipping']-10)<.001 && abs($s['revenue']-110)<.001,'child share: $100 item + $10 shipping');
ok(abs($s['fees']-((330*0.0175+0.30)/3))<.001,'fees split per recipient');
ok(GSUP_Givsen::fallback_phone($child)==='+61 2 9999 0000','child phone falls back to the business');
ok(GSUP_Givsen::is_corporate_parent($parent) && !GSUP_Givsen::hide_from_buyer($child),'parent detected; child not hidden');
$e=GSUP_Givsen::delivered_email($child); ok($e['to']===['sam@example.com'] && strpos($e['body'],'from Acme Pty Ltd')!==false,'child email to recipient, from the business');
$gift=new WC_Order(['_givsen_mode'=>'share','_givsen_recipient_first_name'=>'Jo'],['billing_email'=>'buyer@example.com','billing_first_name'=>'Pat','order_number'=>'1234']);
ok(GSUP_Givsen::hide_from_buyer($gift),'share gift: tracking hidden from buyer');
$e=GSUP_Givsen::delivered_email($gift); ok($e['to']===['buyer@example.com'] && strpos($e['subject'],'to Jo')!==false && strpos($e['body'],'address')===false,'buyer told who, not where: '.$e['subject']);
$own=new WC_Order(['_givsen_mode'=>'address','_givsen_address_by'=>'sender']); ok(!GSUP_Givsen::hide_from_buyer($own),'sender typed the address: tracking shown');
ok(GSUP_Givsen::delivered_email($parent)['to']===[],'parent: no delivered email');
$nomail=new WC_Order(['_givsen_mode'=>'corporate_child','_givsen_parent_order'=>10],['billing_email'=>'']); ok(GSUP_Givsen::delivered_email($nomail)['to']===[],'recipient without email: nobody emailed');
ok(GSUP_Givsen::delivered_email(new WC_Order([]))===null,'normal order untouched');
echo $fail ? "$fail FAILED\n" : "ALL PASSED\n"; exit( $fail ? 1 : 0 );

<?php
// Trial: items from the same seller sent to AliExpress in one request — grouping, matching order numbers back to
// items, falling back to one-by-one when refused, and the trial log.
define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'GSUP_ITEM_AE_ORDER', '_gsup_ae_order_no' );
define( 'GSUP_ITEM_PROBLEM', '_gsup_auto_problem' );
define( 'GSUP_ITEM_AE_COST', '_gsup_ae_cost' );
class WP_Error { public $c, $m, $d; function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; $this->d = $d; } function get_error_code() { return $this->c; } function get_error_message() { return $this->m; } function get_error_data() { return $this->d; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['opts'] = array( 'gsup_combine_seller' => 'yes' );
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function apply_filters( $h, $v ) { return $v; }
function wc_format_decimal( $n, $dp = 2 ) { return number_format( (float) $n, $dp, '.', '' ); }
function gsup_money( $n ) { return '$' . number_format( (float) $n, 2 ); }
function add_action() {}

class Item {
	public $id, $name, $meta = array();
	function __construct( $id, $name ) { $this->id = $id; $this->name = $name; }
	function get_id() { return $this->id; } function get_name() { return $this->name; }
	function get_meta( $k ) { return $this->meta[ $k ] ?? ''; }
	function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; } function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); } function save() {}
}
class WC_Order {
	public $notes = array();
	function get_id() { return 55; } function get_order_number() { return '1201'; }
	function add_order_note( $n ) { $this->notes[] = $n; }
}
class GSUP_Parcels { static function mark_placed( $item, $days ) { $item->meta['_gsup_placed_at'] = 1; } }
class GSUP_AliExpress {
	public static $last_pay_requested = true;
	public static $replies = array(), $sent = array(), $orders = array(), $lookups = array();
	static function place_order( $address, $lines, $out_id ) { self::$sent[] = array( $lines, $out_id ); return array_shift( self::$replies ); }
	static function get_orders( array $ids ) { self::$lookups[] = $ids; $o = array(); foreach ( $ids as $id ) { $o[ $id ] = self::$orders[ $id ] ?? new WP_Error( 'x', 'not found' ); } return $o; }
}
require __DIR__ . '/../givsen-supplier/includes/class-gsup-orders.php';

$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }
function plan( $id, $name, $pid, $store, $ship = 'AU', $cost = 10.0 ) {
	return array( 'item' => new Item( $id, $name ), 'product_id' => $pid, 'qty' => 1, 'sku_attr' => '14:' . $id, 'freight' => array( 'code' => 'STD', 'name' => 'AliExpress Standard', 'fee' => 4.5, 'max_days' => 9 ), 'cost' => $cost, 'store_id' => $store, 'ship_from' => $ship );
}
$submit = new ReflectionMethod( 'GSUP_Orders', 'submit' );
$submit->setAccessible( true );
function reset_ae() { GSUP_AliExpress::$sent = array(); GSUP_AliExpress::$lookups = array(); GSUP_AliExpress::$replies = array(); }

// Grouping.
$plans = array( plan( 1, 'Necklace', '101', 'S1' ), plan( 2, 'Earrings', '102', 'S1' ), plan( 3, 'Ring', '201', 'S2' ), plan( 4, 'Bracelet', '103', 'S1', 'CN' ), plan( 5, 'Anklet', '301', '' ), plan( 6, 'Chain', '302', '' ) );
$g = GSUP_Orders::group( $plans );
$sizes = array_map( 'count', $g );
ok( array( 2, 1, 1, 1, 1 ) === $sizes && 1 === $g[0][0]['item']->id && 2 === $g[0][1]['item']->id, 'on: same seller + warehouse grouped; other seller, other warehouse and unknown seller each alone' );
$GLOBALS['opts']['gsup_combine_seller'] = 'no';
ok( 6 === count( GSUP_Orders::group( $plans ) ), 'off (default): one request per item' );
$GLOBALS['opts']['gsup_combine_seller'] = 'yes';

// One request, one order number back: both items get it; trial logged with what AliExpress charged.
reset_ae();
GSUP_AliExpress::$replies[]   = array( '8190001' );
GSUP_AliExpress::$orders      = array( '8190001' => array( 'products' => array( '101', '102' ), 'store_id' => 'S1', 'amount' => 15.5, 'currency' => 'AUD' ) );
$order                        = new WC_Order();
$pair                         = array( plan( 1, 'Necklace', '101', 'S1' ), plan( 2, 'Earrings', '102', 'S1' ) );
$errors                       = $submit->invoke( null, $order, $pair, array( 'country' => 'AU' ) );
ok( array() === $errors && 1 === count( GSUP_AliExpress::$sent ) && 2 === count( GSUP_AliExpress::$sent[0][0] ), 'one AliExpress request with both items' );
ok( '1201-1-x2' === GSUP_AliExpress::$sent[0][1], 'our reference covers the group' );
ok( '8190001' === $pair[0]['item']->meta[ GSUP_ITEM_AE_ORDER ] && '8190001' === $pair[1]['item']->meta[ GSUP_ITEM_AE_ORDER ] && ! isset( $pair[0]['item']->meta['_gsup_placing'] ), 'both items carry the order number' );
ok( '10.00' === $pair[1]['item']->meta[ GSUP_ITEM_AE_COST ], 'each item keeps its own quoted cost' );
$log = GSUP_Orders::combine_log();
ok( 1 === count( $log ) && 'one' === $log[0]['result'] && 15.5 === $log[0]['orders']['8190001']['amount'] && 2 === count( $log[0]['lines'] ) && 4.5 === $log[0]['lines'][0]['fee'], 'trial logged: one order, charged 15.50 vs quoted 20.00' );
ok( (bool) preg_grep( '/Combined order trial: 2 items .* one AliExpress order/', $order->notes ), 'order note says what happened' );

// Split into two orders: matched back by the products in each order.
reset_ae();
GSUP_AliExpress::$replies[] = array( '8190011', '8190012' );
GSUP_AliExpress::$orders    = array(
	'8190011' => array( 'products' => array( '102' ), 'store_id' => 'S1', 'amount' => 10.0, 'currency' => 'AUD' ),
	'8190012' => array( 'products' => array( '101' ), 'store_id' => 'S1', 'amount' => 10.0, 'currency' => 'AUD' ),
);
$pair = array( plan( 1, 'Necklace', '101', 'S1' ), plan( 2, 'Earrings', '102', 'S1' ) );
$submit->invoke( null, new WC_Order(), $pair, array() );
ok( '8190012' === $pair[0]['item']->meta[ GSUP_ITEM_AE_ORDER ] && '8190011' === $pair[1]['item']->meta[ GSUP_ITEM_AE_ORDER ], 'split: each item gets its own order number' );
ok( 'split' === GSUP_Orders::combine_log()[0]['result'], 'split: logged as split' );

// Split but one order couldn't be looked up: that item keeps every number (tracking checks each).
reset_ae();
GSUP_AliExpress::$replies[] = array( '8190021', '8190022' );
GSUP_AliExpress::$orders    = array( '8190021' => array( 'products' => array( '101' ), 'store_id' => 'S1', 'amount' => null, 'currency' => '' ) );
$pair = array( plan( 1, 'Necklace', '101', 'S1' ), plan( 2, 'Earrings', '102', 'S1' ) );
$submit->invoke( null, new WC_Order(), $pair, array() );
ok( '8190021' === $pair[0]['item']->meta[ GSUP_ITEM_AE_ORDER ] && '8190021, 8190022' === $pair[1]['item']->meta[ GSUP_ITEM_AE_ORDER ], 'unmatched item keeps all order numbers' );

// Refused: nothing was ordered, so each item is placed on its own.
reset_ae();
GSUP_AliExpress::$replies = array( new WP_Error( 'gsup_ae_order', 'That shipping method isn’t available' ), array( '8190031' ), new WP_Error( 'gsup_ae_order', 'Not enough stock on AliExpress' ) );
$order  = new WC_Order();
$pair   = array( plan( 1, 'Necklace', '101', 'S1' ), plan( 2, 'Earrings', '102', 'S1' ) );
$errors = $submit->invoke( null, $order, $pair, array() );
ok( 3 === count( GSUP_AliExpress::$sent ) && 1 === count( GSUP_AliExpress::$sent[1][0] ) && '1201-1' === GSUP_AliExpress::$sent[1][1] && '1201-2' === GSUP_AliExpress::$sent[2][1], 'refused: retried one by one with the usual references' );
ok( '8190031' === $pair[0]['item']->meta[ GSUP_ITEM_AE_ORDER ] && array( 2 ) === array_keys( $errors ) && 'Not enough stock on AliExpress' === $errors[2]->get_error_message(), 'refused: first placed alone, second reports its own problem' );
ok( 'error' === GSUP_Orders::combine_log()[0]['result'] && (bool) preg_grep( '/each is placed on its own/', $order->notes ), 'refused: logged and noted' );

// No clear answer: may have gone through — never retried, every item flagged.
reset_ae();
GSUP_AliExpress::$replies = array( new WP_Error( 'gsup_ae_network', 'AliExpress didn’t answer.' ) );
$pair   = array( plan( 1, 'Necklace', '101', 'S1' ), plan( 2, 'Earrings', '102', 'S1' ) );
$errors = $submit->invoke( null, new WC_Order(), $pair, array() );
ok( 1 === count( GSUP_AliExpress::$sent ) && 2 === count( $errors ) && 'gsup_unknown' === $errors[1]->get_error_code() && isset( $pair[0]['item']->meta['_gsup_placing'] ), 'no answer: not retried, both flagged as maybe placed' );
ok( 'unknown' === GSUP_Orders::combine_log()[0]['result'], 'no answer: logged as unknown' );

// A single item is placed exactly as before: no lookup, no trial log entry.
reset_ae();
$before = count( GSUP_Orders::combine_log() );
GSUP_AliExpress::$replies[] = array( '8190041' );
$one = array( plan( 3, 'Ring', '201', 'S2' ) );
$submit->invoke( null, new WC_Order(), $one, array() );
ok( '8190041' === $one[0]['item']->meta[ GSUP_ITEM_AE_ORDER ] && array() === GSUP_AliExpress::$lookups && $before === count( GSUP_Orders::combine_log() ) && '1201-3' === GSUP_AliExpress::$sent[0][1], 'single item: unchanged behaviour' );

// Log keeps the last 20.
for ( $i = 0; $i < 25; $i++ ) {
	reset_ae();
	GSUP_AliExpress::$replies[] = array( '9' . $i );
	$submit->invoke( null, new WC_Order(), array( plan( 1, 'A', '101', 'S1' ), plan( 2, 'B', '102', 'S1' ) ), array() );
}
ok( 20 === count( GSUP_Orders::combine_log() ), 'log keeps the last 20 tries' );

// Profit: an order shared by two items is split by their quoted costs, not counted twice.
class Shared_Order { public $items; function __construct( $items ) { $this->items = $items; } function get_items() { return $this->items; } }
$a = new Item( 1, 'A' ); $a->meta = array( GSUP_ITEM_AE_ORDER => '777', GSUP_ITEM_AE_COST => '30.00' );
$b = new Item( 2, 'B' ); $b->meta = array( GSUP_ITEM_AE_ORDER => '777', GSUP_ITEM_AE_COST => '10.00' );
$c = new Item( 3, 'C' ); $c->meta = array( GSUP_ITEM_AE_ORDER => '888', GSUP_ITEM_AE_COST => '5.00' );
$d = new Item( 4, 'D' ); $d->meta = array( GSUP_ITEM_AE_ORDER => '91, 92', GSUP_ITEM_AE_COST => '5.00' );
$sh = GSUP_Orders::cost_shares( new Shared_Order( array( 1 => $a, 2 => $b, 3 => $c, 4 => $d ) ) );
ok( array( '777' ) === array_map( 'strval', array_keys( $sh ) ) && 0.75 === $sh['777'][1] && 0.25 === $sh['777'][2], 'shared order split 75/25 by quoted cost; unshared numbers left alone' );

echo $fail ? "$fail FAILED\n" : "ALL PASSED\n";
exit( $fail ? 1 : 0 );

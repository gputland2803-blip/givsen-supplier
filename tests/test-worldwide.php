<?php
// Sold worldwide, phase 3: routing orders to a warehouse, gifts, removing this plugin's country restrictions, and
// merging warehouse versions (with redirects).
define( 'ABSPATH', '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'GSUP_META_PRODUCT', '_gsup_ae_product_id' ); define( 'GSUP_META_SKU', '_gsup_ae_sku_id' ); define( 'GSUP_META_SHIP', '_gsup_ship_from' );
define( 'GSUP_META_OPTION', '_gsup_ae_option' ); define( 'GSUP_META_COST', '_gsup_cost' ); define( 'GSUP_ITEM_AE_ORDER', '_gsup_ae_order_no' ); define( 'GSUP_ITEM_PROBLEM', '_gsup_auto_problem' ); define( 'GSUP_ITEM_AE_COST', '_gsup_ae_cost' );
class WP_Error { public $c, $m; function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; } function get_error_code() { return $this->c; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['meta'] = array(); $GLOBALS['opts'] = array(); $GLOBALS['status'] = array();
function get_post_meta( $id, $k = '', $s = true ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['meta'][ $id ][ $k ] ); return true; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; }
function add_action() {} function add_filter() {} function apply_filters( $h, $v ) { return $v; }
function wc_delete_product_transients() {} function wc_format_decimal( $n, $d = 2 ) { return number_format( (float) $n, $d, '.', '' ); }
function gsup_money( $n ) { return '$' . number_format( (float) $n, 2 ); } function gsup_admin_url( $a = array() ) { return '/wp-admin/?' . http_build_query( $a ); }
function gsup_ship_from_label( $c ) { return array( 'AU' => 'Australia', 'US' => 'United States', 'CN' => 'China', 'TR' => 'Turkey', 'NZ' => 'New Zealand' )[ $c ] ?? $c; }
function get_permalink( $id ) { return 'https://givsen.test/product/p-' . $id . '/'; }
function get_post_status( $id ) { return $GLOBALS['status'][ $id ] ?? 'publish'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_unslash( $s ) { return $s; } function absint( $n ) { return abs( (int) $n ); }
$GLOBALS['is404'] = false; function is_404() { return $GLOBALS['is404']; }
class Redirected extends Exception {}
function wp_safe_redirect( $u, $s = 302 ) { throw new Redirected( $s . ' ' . $u ); }
$GLOBALS['queued'] = array(); function as_enqueue_async_action( $h, $a, $g ) { $GLOBALS['queued'][] = $a; }

class WC_Product {
	public $id, $type, $kids = array(), $status = 'publish', $name;
	function __construct( $id, $type = 'simple', $kids = array() ) { $this->id = $id; $this->type = $type; $this->kids = $kids; $this->name = 'Product ' . $id; }
	function get_id() { return $this->id; } function is_type( $t ) { return $t === $this->type; } function get_children() { return $this->kids; }
	function get_name() { return $this->name; } function get_status() { return $this->status; } function set_status( $s ) { $this->status = $s; $GLOBALS['status'][ $this->id ] = $s; } function save() {}
	function get_parent_id() { return 0; }
}
$GLOBALS['products'] = array();
function wc_get_product( $id ) { return $GLOBALS['products'][ $id ] ?? null; }
function product( $id, $type = 'simple', $kids = array() ) { return $GLOBALS['products'][ $id ] = new WC_Product( $id, $type, $kids ); }

class GSUP_Install { static function reach_table() { return 'wp_gsup_reach'; } }
class Fake_WPDB { public $postmeta = 'wp_postmeta', $posts = 'wp_posts', $rows = array(); function prepare( $q, ...$a ) { return array( $q, $a ); } function get_results( $p, $o = null ) { return $this->rows; } function delete() {} function query() {} function get_var() { return 0; } function get_col() { return array(); } }
$wpdb = new Fake_WPDB(); $GLOBALS['wpdb'] = $wpdb;
class GSUP_AliExpress {
	public static $last_pay_requested = true, $listing = null, $freight = array(), $placed = array();
	static function default_ship_to() { return 'AU'; }
	static function find_sku( $p, $id ) { foreach ( $p['skus'] as $s ) { if ( (string) $s['sku_id'] === (string) $id ) { return $s; } } return null; }
	static function get_product( $id, $c ) { return self::$listing; }
	static function freight( $pid, $sku, $c, $q ) { return self::$freight[ $sku ] ?? new WP_Error( 'gsup_ae_freight', 'No delivery method for ' . $sku ); }
	static function choose_freight( $o ) { return $o[0]; }
	static function place_order( $addr, $lines, $out ) { self::$placed[] = $lines; return array( '8100' . count( self::$placed ) ); }
}
class GSUP_Sync { const M_STATUS = '_gsup_sync_status'; const M_GONE = '_gsup_option_gone'; }
class GSUP_Parcels { static function mark_placed() {} }
class GSUP_Remap { static function auto_match() { return array(); } }
// Reach for routing days and the claim check.
$GLOBALS['reach'] = array();
require __DIR__ . '/../givsen-supplier/includes/class-gsup-sources.php';
class GSUP_Shop { static function options( $pid, $item, $c ) { return $GLOBALS['shop_opts'][ $item . '|' . $c ] ?? null; } }
class GSUP_Visitor { static function name( $c ) { return gsup_ship_from_label( $c ) === $c ? ( array( 'GB' => 'United Kingdom', 'FR' => 'France' )[ $c ] ?? $c ) : gsup_ship_from_label( $c ); } }
require __DIR__ . '/../givsen-supplier/includes/class-gsup-givsen.php';
require __DIR__ . '/../givsen-supplier/includes/class-gsup-orders.php';
require __DIR__ . '/../givsen-supplier/includes/class-gsup-cbr.php';
require __DIR__ . '/../givsen-supplier/includes/class-gsup-cleanup.php';

$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }
function sku( $id, $ship, $stock = 5, $price = '5.00' ) { return array( 'sku_id' => $id, 'sku_attr' => 'a:' . $id, 'ship_from' => $ship, 'stock' => $stock, 'price' => $price, 'option' => '' ); }
function src( $sku ) { return array( 'sku' => $sku ); }
$link = array( 'product_id' => '1005', 'sku_id' => 'au', 'level' => 'simple' );
$srcs = array( 'AU' => src( 'au' ), 'US' => src( 'us' ), 'TR' => src( 'tr' ), 'CN' => src( 'cn' ) );
$ids  = function ( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : array_column( $r, 'wh' ); };

// ------------------------------------------------------------------ routing rules
// Delivery to the US: every warehouse is offered.
$us_listing = array( 'skus' => array( sku( 'au', 'AU' ), sku( 'us', 'US' ), sku( 'tr', 'TR' ), sku( 'cn', '' ) ) );
$reach_days = function ( $country, array $days ) { $rows = array(); foreach ( $days as $wh => $d ) { $rows[] = array( 'item_id' => 10, 'country' => $country, 'warehouse' => $wh, 'deliverable' => 1, 'days_min' => $d - 2, 'days_max' => $d ); } $GLOBALS['wpdb']->rows = $rows; };
$wpdb->rows = array();
ok( array( 'AU', 'US', 'TR', 'CN' ) === $ids( GSUP_Orders::route( 10, $us_listing, $link, $srcs, 'US', 1, 'AU' ) ), '0. the customer’s choice (Australia) first when it delivers there with stock' );
ok( array( 'US', 'AU', 'TR', 'CN' ) === $ids( GSUP_Orders::route( 10, $us_listing, $link, $srcs, 'US', 1, '' ) ), '1. no choice: the warehouse in the destination country (US) first' );
$uk_listing = array( 'skus' => array( sku( 'au', 'AU', 0 ), sku( 'tr', 'TR' ), sku( 'cn', '' ) ) );
$reach_days( 'GB', array( 'TR' => 14, 'CN' => 9 ) );
ok( array( 'TR', 'CN' ) === $ids( GSUP_Orders::route( 10, $uk_listing, $link, $srcs, 'GB', 1, 'AU' ) ), 'UK: Australia out of stock, none in the UK → fastest other (Turkey), China last even when faster' );
$reach_days( 'GB', array( 'TR' => 14, 'AU' => 8 ) );
$uk2 = array( 'skus' => array( sku( 'au', 'AU' ), sku( 'tr', 'TR' ), sku( 'cn', '' ) ) );
ok( array( 'AU', 'TR', 'CN' ) === $ids( GSUP_Orders::route( 10, $uk2, $link, $srcs, 'GB', 1, '' ) ), '2. among the others, the fastest by the reach table (Australia 8 days before Turkey 14)' );
$wpdb->rows = array();
ok( array( 'CN' ) === $ids( GSUP_Orders::route( 10, array( 'skus' => array( sku( 'cn', '' ) ) ), $link, $srcs, 'FR', 1, 'AU' ) ), '3. China when it’s the only one that reaches' );
ok( array( 'AU', 'CN' ) === $ids( GSUP_Orders::route( 10, array( 'skus' => array( sku( 'au', 'AU', 1 ), sku( 'cn', '', 3 ) ) ), $link, array( 'AU' => src( 'au' ), 'CN' => src( 'cn' ) ), 'AU', 1, '' ) ) && array( 'CN' ) === $ids( GSUP_Orders::route( 10, array( 'skus' => array( sku( 'au', 'AU', 1 ), sku( 'cn', '', 3 ) ) ), $link, array( 'AU' => src( 'au' ), 'CN' => src( 'cn' ) ), 'AU', 2, '' ) ), 'a warehouse without enough stock for the quantity is skipped' );
$r = GSUP_Orders::route( 10, array( 'skus' => array( sku( 'au', 'AU', 1 ) ) ), $link, array( 'AU' => src( 'au' ) ), 'AU', 3, '' );
ok( 'gsup_stock' === $r->get_error_code() && false !== strpos( $r->get_error_message(), '1 left, need 3' ), 'not enough anywhere → stock error' );
$r = GSUP_Orders::route( 10, array( 'skus' => array() ), $link, $srcs, 'FR', 1, '' );
ok( 'gsup_no_route' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'can deliver to FR' ), 'nothing reaches → “None of this option’s warehouses … can deliver to FR”' );
ok( array( 'CN' ) === $ids( GSUP_Orders::route( 10, array( 'skus' => array( sku( 'x', '' ) ) ), array( 'product_id' => '1', 'sku_id' => '', 'level' => 'simple' ), array(), 'AU', 1, '' ) ), 'single-option listing never linked to a SKU still orders (as before)' );

// ------------------------------------------------------------------ prepare_line + submit: fallback, loss guard, notes
class WC_Order_Item_Product {}
class Item extends WC_Order_Item_Product {
	public $id, $meta = array(), $total = 60;
	function __construct( $id ) { $this->id = $id; }
	function get_id() { return $this->id; } function get_name() { return 'Pearl Necklace'; } function get_total() { return $this->total; }
	function get_meta( $k ) { return $this->meta[ $k ] ?? ''; } function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; } function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); } function save() {}
	function get_product_id() { return 10; } function get_variation_id() { return 0; } function get_quantity() { return 1; }
}
class WC_Order {
	public $meta = array(), $notes = array(), $items = array();
	function get_meta( $k ) { return $this->meta[ $k ] ?? ''; } function get_order_number() { return '1201'; } function get_id() { return 55; }
	function add_order_note( $n ) { $this->notes[] = $n; } function get_total_refunded_for_item( $id ) { return 0; } function get_qty_refunded_for_item( $id ) { return 0; } function get_items() { return $this->items; }
}
product( 10 );
$GLOBALS['meta'][10] = array( GSUP_META_PRODUCT => '1005', GSUP_META_SKU => 'au', GSUP_META_SHIP => 'AU', GSUP_Sources::META => array( 'AU' => src( 'au' ), 'CN' => src( 'cn' ) ) );
$prep   = new ReflectionMethod( 'GSUP_Orders', 'prepare_line' ); $prep->setAccessible( true );
$submit = new ReflectionMethod( 'GSUP_Orders', 'submit' ); $submit->setAccessible( true );
GSUP_AliExpress::$listing = array( 'product_id' => '1005', 'on_sale' => true, 'skus' => array( sku( 'au', 'AU', 5, '9.00' ), sku( 'cn', '', 5, '4.00' ) ) );
GSUP_AliExpress::$freight = array( 'cn' => array( array( 'code' => 'CN_STD', 'name' => 'China Post', 'fee' => 3.0, 'max_days' => 20 ) ) ); // No method from Australia today.
$order = new WC_Order(); $item = new Item( 7 ); $item->meta['_gsup_wh'] = 'AU';
$fetched = array();
$plan = $prep->invokeArgs( null, array( $order, $item, $GLOBALS['products'][10], $link, 'NZ', &$fetched ) );
ok( is_array( $plan ) && 'CN' === $plan['wh'] && 7.0 === $plan['cost'] && 'AU' === $plan['wanted'], 'Australia has no delivery method to NZ today → next warehouse (China) used; cost from China’s price + fee' );
$submit->invoke( null, $order, array( $plan ), array() );
ok( 'CN' === $item->meta[ GSUP_Orders::I_WH_USED ] && (bool) preg_grep( '/ordered from China, not Australia as chosen at checkout: Australia can’t deliver it to NZ/', $order->notes ), 'warehouse used saved on the line; order note because it differs from the customer’s choice' );
$item2 = new Item( 8 ); $item2->total = 5;
$plan  = $prep->invokeArgs( null, array( new WC_Order(), $item2, $GLOBALS['products'][10], $link, 'NZ', &$fetched ) );
ok( is_wp_error( $plan ) && 'gsup_loss' === $plan->get_error_code(), 'loss guard unchanged, with the chosen warehouse’s real cost and fee' );
GSUP_AliExpress::$freight['au'] = array( array( 'code' => 'AU_POST', 'name' => 'Australia Post', 'fee' => 0.0, 'max_days' => 6 ) );
$order = new WC_Order(); $item = new Item( 9 ); $item->meta['_gsup_wh'] = 'AU';
$plan  = $prep->invokeArgs( null, array( $order, $item, $GLOBALS['products'][10], $link, 'AU', &$fetched ) );
$submit->invoke( null, $order, array( $plan ), array() );
ok( 'AU' === $plan['wh'] && ! preg_grep( '/not Australia as chosen/', $order->notes ), 'customer’s choice works → used, no extra note' );

// ------------------------------------------------------------------ gifts
$gift = new WC_Order(); $gift->meta['_givsen_mode'] = 'share';
$gi   = new Item( 11 ); $gi->meta['_gsup_wh'] = 'AU';
GSUP_AliExpress::$listing = array( 'product_id' => '1005', 'on_sale' => true, 'skus' => array( sku( 'au', 'AU' ), sku( 'cn', '' ) ) );
GSUP_AliExpress::$freight['cn'] = array( array( 'code' => 'CN_STD', 'name' => 'China Post', 'fee' => 3.0, 'max_days' => 20 ) );
$wpdb->rows = array( array( 'item_id' => 10, 'country' => 'GB', 'warehouse' => 'CN', 'deliverable' => 1, 'days_min' => 9, 'days_max' => 12 ), array( 'item_id' => 10, 'country' => 'GB', 'warehouse' => 'AU', 'deliverable' => 1, 'days_min' => 12, 'days_max' => 16 ) );
$plan = $prep->invokeArgs( null, array( $gift, $gi, $GLOBALS['products'][10], $link, 'GB', &$fetched ) );
ok( is_array( $plan ) && '' === $plan['wanted'] && 'AU' === $plan['wh'], 'gift claimed to the UK: sender’s choice ignored, rules applied for the recipient’s country (no UK warehouse → fastest other; China last)' );
$submit->invoke( null, $gift, array( $plan ), array() );
ok( ! preg_grep( '/not Australia/', $gift->notes ), 'same warehouse as the sender picked → no note' );
$gi2 = new Item( 12 ); $gi2->meta['_gsup_wh'] = 'CN';
$plan = $prep->invokeArgs( null, array( $gift, $gi2, $GLOBALS['products'][10], $link, 'GB', &$fetched ) );
$submit->invoke( null, $gift, array( $plan ), array() );
ok( (bool) preg_grep( '/this is a gift, so the warehouse was chosen for the recipient’s country \(GB\)/', $gift->notes ), 'different from the sender’s pick → note says why' );
GSUP_AliExpress::$listing = array( 'product_id' => '1005', 'on_sale' => true, 'skus' => array() );
$plan = $prep->invokeArgs( null, array( $gift, new Item( 13 ), $GLOBALS['products'][10], $link, 'FR', &$fetched ) );
ok( is_wp_error( $plan ) && 'gsup_no_route' === $plan->get_error_code(), 'nothing reaches the recipient’s country → not placed (reason goes on the order, noted and emailed)' );
ok( GSUP_Givsen::is_gift( $gift ) && ! GSUP_Givsen::is_gift( new WC_Order() ), 'gift orders recognised by Givsen’s mode' );

// Claim-address check (for the Givsen filter proposed in SPEC.md).
$claim = new WC_Order();
$ci = new Item( 20 ); $claim->items = array( $ci );
$GLOBALS['shop_opts'] = array( '10|GB' => array( array( 'wh' => 'CN' ) ), '10|FR' => array() );
ok( true === GSUP_Givsen::claim_deliverable( true, $claim, array( 'country' => 'GB' ), 'recipient' ), 'claim to the UK: a warehouse reaches → accepted' );
$e = GSUP_Givsen::claim_deliverable( true, $claim, array( 'country' => 'FR' ), 'recipient' );
ok( is_wp_error( $e ) && false !== strpos( $e->get_error_message(), 'Pearl Necklace can’t be delivered to France' ) && false !== strpos( $e->get_error_message(), 'let the person who sent it know' ), 'claim to France: refused with a clear message before the claim completes' );
ok( true === GSUP_Givsen::claim_deliverable( true, $claim, array( 'country' => 'NZ' ), 'recipient' ), 'unknown for that country (not checked) → left to Givsen' );
$prev = new WP_Error( 'givsen', 'zone' );
ok( $prev === GSUP_Givsen::claim_deliverable( $prev, $claim, array( 'country' => 'FR' ), 'recipient' ), 'Givsen’s own refusal is kept' );

// ------------------------------------------------------------------ country restrictions: removal scope
$GLOBALS['opts']['gsup_cbr_map'] = array( 'AU' => array( 'AU' ), 'US' => array( 'US' ) );
$cbr = function ( $id, $ship, $countries, $recorded = null ) {
	product( $id );
	$GLOBALS['meta'][ $id ] = array( GSUP_META_PRODUCT => 'x', GSUP_META_SHIP => $ship, '_fz_country_restriction_type' => 'specific', '_restricted_countries' => $countries );
	if ( $recorded ) {
		$GLOBALS['meta'][ $id ][ GSUP_CBR::M_SET ] = array( 'type' => 'specific', 'countries' => $recorded );
	}
};
$cbr( 31, 'AU', array( 'AU' ), array( 'AU' ) );        // Recorded by this plugin.
$cbr( 32, 'US', array( 'US' ) );                       // Older: exactly what this plugin sets for a US warehouse.
$cbr( 33, 'AU', array( 'AU', 'NZ' ) );                 // Set by hand (different countries).
$cbr( 34, 'AU', array( 'AU', 'NZ' ), array( 'AU' ) );  // Recorded, then changed by hand.
ok( 'plugin' === GSUP_CBR::who_set( 31 ) && 'likely' === GSUP_CBR::who_set( 32 ) && 'hand' === GSUP_CBR::who_set( 33 ) && 'hand' === GSUP_CBR::who_set( 34 ), 'who set it: recorded / matches the plugin’s mapping / by hand / changed by hand after' );
ok( GSUP_CBR::remove( 31 ) && GSUP_CBR::remove( 32 ) && '' === get_post_meta( 31, '_restricted_countries' ) && '' === get_post_meta( 32, '_fz_country_restriction_type' ) && '' === get_post_meta( 31, GSUP_CBR::M_SET ), 'plugin’s restrictions removed (and the record)' );
ok( ! GSUP_CBR::remove( 33 ) && ! GSUP_CBR::remove( 34 ) && array( 'AU', 'NZ' ) === get_post_meta( 33, '_restricted_countries' ) && array( 'AU', 'NZ' ) === get_post_meta( 34, '_restricted_countries' ), 'hand-set restrictions never removed, even if asked' );
$GLOBALS['opts']['gsup_cbr_enabled'] = 'yes';
ok( GSUP_CBR::enabled(), 'CBR mapping on while the shop isn’t selling worldwide' );
$GLOBALS['opts']['gsup_worldwide_shop'] = 'yes';
ok( ! GSUP_CBR::enabled() && 'off' === GSUP_CBR::apply( 31 ), 'selling worldwide: warehouse → country mapping off, nothing new restricted' );

// ------------------------------------------------------------------ merge warehouse versions + redirect
product( 40, 'variable', array( 41, 42 ) ); product( 41, 'variation' ); product( 42, 'variation' );
product( 50, 'variable', array( 51, 52, 53 ) ); product( 51, 'variation' ); product( 52, 'variation' ); product( 53, 'variation' );
$GLOBALS['meta'][40] = array( GSUP_META_PRODUCT => '1005', 'total_sales' => 30 );
$GLOBALS['meta'][41] = array( GSUP_META_SKU => 'au-w', GSUP_META_SHIP => 'AU', GSUP_META_OPTION => 'Color: White · Ships From: Australia' );
$GLOBALS['meta'][42] = array( GSUP_META_SKU => 'au-g', GSUP_META_SHIP => 'AU', GSUP_META_OPTION => 'Color: Gold · Ships From: Australia' );
$GLOBALS['meta'][50] = array( GSUP_META_PRODUCT => '1005', 'total_sales' => 4 );
$GLOBALS['meta'][51] = array( GSUP_META_SKU => 'us-w', GSUP_META_SHIP => 'US', GSUP_META_OPTION => 'Color: white · Ships From: United States' );
$GLOBALS['meta'][52] = array( GSUP_META_SKU => 'us-g', GSUP_META_SHIP => 'US', GSUP_META_OPTION => 'Color: Gold · Ships From: United States' );
$GLOBALS['meta'][53] = array( GSUP_META_SKU => 'us-s', GSUP_META_SHIP => 'US', GSUP_META_OPTION => 'Color: Silver · Ships From: United States' );
$wpdb->rows = array( array( 'ae' => '1005', 'id' => 50 ), array( 'ae' => '1005', 'id' => 40 ), array( 'ae' => '2002', 'id' => 60 ) );
$groups = GSUP_Cleanup::groups();
ok( 1 === count( $groups ) && 40 === $groups[0]['keep'] && array( 50 ) === $groups[0]['merge'], 'preview: products sharing a listing found; the one with more sales (30 vs 4) kept' );
ok( 'color:white' === GSUP_Cleanup::option_key( 'Color: White · Ships From: Australia' ) && 'color:white' === GSUP_Cleanup::option_key( 'Color: white · Ships From: United States' ), 'options matched by their values without Ships From' );
$GLOBALS['queued'] = array();
$r = GSUP_Cleanup::merge( 40, array( 50 ) );
ok( array( 'AU', 'US' ) === array_keys( get_post_meta( 41, GSUP_Sources::META ) ) && 'us-w' === get_post_meta( 41, GSUP_Sources::META )['US']['sku'] && 'us-g' === get_post_meta( 42, GSUP_Sources::META )['US']['sku'], 'US warehouse moved across as a source of the matching options' );
ok( 2 === $r['moved'] && 1 === count( $r['unmatched'] ) && false !== strpos( $r['unmatched'][0], 'color:silver' ), 'Silver (only on the US version) reported, not lost silently' );
ok( 'draft' === $GLOBALS['products'][50]->status && '' === get_post_meta( 50, GSUP_META_PRODUCT ) && '1005' === get_post_meta( 50, '_gsup_merged_ae' ) && 40 === get_post_meta( 50, GSUP_Cleanup::M_MERGED_INTO ), 'merged product: draft, unlinked (no more syncing or ordering), old link kept for reference' );
ok( array( "/product/p-50/" => 40 ) === get_option( GSUP_Cleanup::OPT_REDIRECTS ) && array( array( array( 40 ) ) ) === $GLOBALS['queued'], 'redirect recorded; kept product’s reach refreshed in the background' );
$GLOBALS['is404'] = true;
$_SERVER['REQUEST_URI'] = '/product/P-50?utm=video';
try { GSUP_Cleanup::redirect(); $got = ''; } catch ( Redirected $e ) { $got = $e->getMessage(); }
ok( '301 https://givsen.test/product/p-40/' === $got, 'old address (from a video, any case, with tracking parameters) → 301 to the kept product' );
$GLOBALS['status'][40] = 'draft';
try { GSUP_Cleanup::redirect(); $got = ''; } catch ( Redirected $e ) { $got = $e->getMessage(); }
ok( '' === $got, 'no redirect to a kept product that isn’t published' );
$GLOBALS['is404'] = false; $GLOBALS['status'][40] = 'publish';
try { GSUP_Cleanup::redirect(); $got = ''; } catch ( Redirected $e ) { $got = $e->getMessage(); }
ok( '' === $got, 'pages that exist are never redirected' );

echo $fail ? "$fail FAILED\n" : "ALL PASSED\n";
exit( $fail ? 1 : 0 );

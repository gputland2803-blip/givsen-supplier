<?php
// Save as backup supplier from the extension: matching, replace/confirm, same-as-main refusal, and request signing.
define( 'ABSPATH', '/' );
define( 'GSUP_META_PRODUCT', '_gsup_ae_product_id' );
define( 'GSUP_META_SKU', '_gsup_ae_sku_id' );
define( 'GSUP_META_OPTION', '_gsup_ae_option' );
define( 'GSUP_META_SHIP', '_gsup_ship_from' );
class WP_Error { public $c, $m, $d; function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; $this->d = $d; } function get_error_code() { return $this->c; } function get_error_message() { return $this->m; } function get_error_data() { return $this->d; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['meta'] = array(); $GLOBALS['opts'] = array( 'gsup_secret' => 'k3y' ); $GLOBALS['tr'] = array();
function get_post_meta( $id, $k, $s = true ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t ) { $GLOBALS['tr'][ $k ] = $v; }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function get_woocommerce_currency() { return 'AUD'; }
function remove_accents( $s ) { return $s; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( $s ) ); }
function wc_get_formatted_variation( $v ) { return implode( ', ', $v->attrs ); }
class WC_Product {
	public $id, $attrs, $kids, $type;
	function __construct( $id, $type, $attrs = array(), $kids = array() ) { $this->id = $id; $this->type = $type; $this->attrs = $attrs; $this->kids = $kids; }
	function get_id() { return $this->id; } function is_type( $t ) { return $t === $this->type; } function get_name() { return 'Pearl Choker'; }
	function get_children() { return $this->kids; } function get_variation_attributes( $p = true ) { return $this->attrs; }
}
$GLOBALS['products'] = array(
	10 => new WC_Product( 10, 'variable', array(), array( 11, 12, 13 ) ),
	11 => new WC_Product( 11, 'variation', array( 'attribute_colour' => 'White' ) ),
	12 => new WC_Product( 12, 'variation', array( 'attribute_colour' => 'Gold' ) ),
	13 => new WC_Product( 13, 'variation', array( 'attribute_colour' => 'Rose Pink' ) ),
);
function wc_get_product( $id ) { return $GLOBALS['products'][ $id ] ?? null; }
class GSUP_Creator {
	static function option_text( $sku ) { $p = array(); foreach ( $sku['props'] as $x ) { if ( ! $x['is_ship'] ) { $p[] = $x['name'] . ': ' . $x['value']; } } return implode( ' · ', $p ); }
	static function by_warehouse( $product ) { $g = array(); foreach ( $product['skus'] as $s ) { $g[ $s['ship_from'] ][] = $s; } return $g; }
	static function warehouse_label( $c ) { return '' === $c ? 'Not stated' : $c; }
	static function quote() { return array( 'fee' => 2.0 ); }
}
class GSUP_AliExpress { static function find_sku( $p, $id ) { foreach ( $p['skus'] as $s ) { if ( $s['sku_id'] === (string) $id ) { return $s; } } return null; } }
class GSUP_Profit { static function unit_cost( $id ) { return array( 11 => 9.0, 12 => 9.5, 13 => 9.0 )[ $id ] ?? null; } }
require __DIR__ . '/../givsen-supplier/includes/class-gsup-remap.php';
require __DIR__ . '/../givsen-supplier/includes/class-gsup-rest.php';
$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }
function sku( $id, $colour, $price, $ship = 'AU' ) { return array( 'sku_id' => $id, 'price' => $price, 'ship_from' => $ship, 'props' => array( array( 'name' => 'Metal Color', 'value' => $colour, 'is_ship' => false ), array( 'name' => 'Ships From', 'value' => 'Australia', 'is_ship' => true ) ) ); }
$listing = array( 'product_id' => '2002', 'skus' => array( sku( 'a', 'WHITE', '7.50' ), sku( 'b', 'Gold', '8.00' ), sku( 'c', 'Silver', '8.00' ), sku( 'd', 'White', '6.00', 'CN' ) ) );
$GLOBALS['meta'][10][ GSUP_META_PRODUCT ] = '1001';

// Matching: White and Gold match (AU warehouse only); Rose Pink has no match but the backup is still saved.
$r = GSUP_Remap::save_backup_from_listing( 10, $listing, 'AU', false );
ok( is_array( $r ) && 2 === $r['matched'] && 3 === $r['total'], 'matched 2 of 3 options' );
ok( is_array( $r ) && array( 'Rose Pink' ) === $r['unmatched'], 'unmatched option named' );
$b = $GLOBALS['meta'][10]['_gsup_backup'] ?? null;
ok( is_array( $b ) && '2002' === $b['product_id'] && 'AU' === $b['ship'] && array( 11 => 'a', 12 => 'b' ) === $b['map'] && isset( $b['saved_at'] ) && 4 === count( $b ), 'saved in the same shape as Change supplier (product_id, ship, map, saved_at)' );
ok( is_array( $r ) && 18.5 === $r['cost']['current'] && 19.5 === $r['cost']['backup'], 'cost comparison: now 18.50 vs backup 19.50 (with delivery)' );
ok( '1001' === $GLOBALS['meta'][10][ GSUP_META_PRODUCT ], 'main supplier untouched' );
ok( array( 'Rose Pink' ) === GSUP_Remap::backup_unmatched( 10 ), 'Supplier tab can list the unmatched option' );

// Replace needs confirmation.
$listing2 = array( 'product_id' => '3003', 'skus' => array( sku( 'x', 'White', '5.00' ) ) );
$e = GSUP_Remap::save_backup_from_listing( 10, $listing2, 'AU', false );
ok( is_wp_error( $e ) && 'gsup_backup_exists' === $e->get_error_code() && '2002' === $e->get_error_data()['current_backup'], 'existing backup: refused without confirmation, current ID returned' );
ok( '2002' === $GLOBALS['meta'][10]['_gsup_backup']['product_id'], 'existing backup kept until confirmed' );
$r = GSUP_Remap::save_backup_from_listing( 10, $listing2, 'AU', true );
ok( is_array( $r ) && '2002' === $r['replaced'] && '3003' === $GLOBALS['meta'][10]['_gsup_backup']['product_id'], 'confirmed: replaced' );

// Same as main, wrong warehouse, nothing matches.
$e = GSUP_Remap::save_backup_from_listing( 10, array( 'product_id' => '1001', 'skus' => $listing['skus'] ), 'AU', true );
ok( is_wp_error( $e ) && 'gsup_same_as_main' === $e->get_error_code(), 'refused: listing is already the main supplier' );
$e = GSUP_Remap::save_backup_from_listing( 10, $listing, 'US', true );
ok( is_wp_error( $e ) && 'gsup_no_warehouse' === $e->get_error_code(), 'refused: no options from that warehouse' );
$e = GSUP_Remap::save_backup_from_listing( 10, array( 'product_id' => '4004', 'skus' => array( sku( 'z', 'Black', '5' ) ) ), 'AU', true );
ok( is_wp_error( $e ) && 'gsup_no_match' === $e->get_error_code(), 'refused: nothing matches' );

// Signing: the new endpoints need signature version 2, the right key, and each request works once.
class WP_REST_Request {
	public $h, $body, $route, $method;
	function __construct( $method, $route, $body, $h ) { $this->method = $method; $this->route = $route; $this->body = $body; $this->h = $h; }
	function get_header( $k ) { return $this->h[ $k ] ?? ''; } function get_body() { return $this->body; } function get_route() { return $this->route; } function get_method() { return $this->method; }
}
function signed( $method, $endpoint, $body, $key = 'k3y', $v2 = true ) {
	$ts  = (string) time();
	$msg = $v2 ? $ts . '.' . $method . '.' . $endpoint . '.' . $body : $ts . '.' . $body;
	$h   = array( 'x-gsup-timestamp' => $ts, 'x-gsup-signature' => hash_hmac( 'sha256', $msg, $key ) );
	if ( $v2 ) { $h['x-gsup-sig-version'] = '2'; }
	return new WP_REST_Request( $method, '/givsen-supplier/v1' . $endpoint, $body, $h );
}
$body = '{"wc_product_id":10,"product_id":"2002","ship_from":"AU","replace":true}';
$req  = signed( 'POST', '/backup', $body );
ok( true === GSUP_REST::verify( $req ), 'correctly signed /backup accepted' );
$again = GSUP_REST::verify( $req );
ok( is_wp_error( $again ) && 'gsup_replayed' === $again->get_error_code(), 'the same request replayed: refused' );
$e = GSUP_REST::verify( signed( 'POST', '/backup', $body, 'wrong' ) );
ok( is_wp_error( $e ) && 'gsup_bad_key' === $e->get_error_code(), 'wrong key: refused' );
$e = GSUP_REST::verify( signed( 'POST', '/backup', $body, 'k3y', false ) );
ok( is_wp_error( $e ) && 'gsup_old_extension' === $e->get_error_code(), 'old signing (v1) on the new endpoint: refused' );
$import_sig = signed( 'POST', '/import', $body );
$import_sig->route = '/givsen-supplier/v1/backup';
$e = GSUP_REST::verify( $import_sig );
ok( is_wp_error( $e ) && 'gsup_bad_key' === $e->get_error_code(), 'a request signed for /import can’t be used on /backup' );
ok( true === GSUP_REST::verify( signed( 'GET', '/linked-products', '' ) ), 'signed /linked-products accepted' );

echo $fail ? "$fail FAILED\n" : "ALL PASSED\n";
exit( $fail ? 1 : 0 );

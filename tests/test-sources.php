<?php
// Sold worldwide, phase 1: sources across warehouses, reach refresh, stock rules, Add to store with all warehouses.
define( 'ABSPATH', '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HOUR_IN_SECONDS', 3600 ); define( 'DAY_IN_SECONDS', 86400 ); define( 'WEEK_IN_SECONDS', 604800 );
class WP_Error { public $c, $m, $d; function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; $this->d = $d; } function get_error_code() { return $this->c; } function get_error_message() { return $this->m; } function get_error_data() { return $this->d; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['meta'] = array(); $GLOBALS['opts'] = array(); $GLOBALS['tr'] = array(); $GLOBALS['queued'] = array();
function get_post_meta( $id, $k = '', $s = true ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['meta'][ $id ][ $k ] ); return true; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; }
function current_time( $t, $g = false ) { return '2026-09-26 10:00:00'; }
function remove_accents( $s ) { return $s; }
function absint( $n ) { return abs( (int) $n ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wc_format_decimal( $n, $dp = 2 ) { return number_format( (float) $n, (int) $dp, '.', '' ); }
function wc_get_price_decimals() { return 2; }
function wc_get_base_location() { return array( 'country' => 'AU' ); }
function get_woocommerce_currency() { return 'AUD'; }
function apply_filters( $h, $v ) { return $v; }
function add_action() {} function add_filter() {}
function as_enqueue_async_action( $h, $a, $g ) { $GLOBALS['queued'][] = array( $h, $a ); }
function wc_delete_product_transients() {}
function html_entity_decode_x( $s ) { return $s; }
function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $s ) ); }
function gsup_ship_from_label( $c ) { return array( 'AU' => 'Australia', 'CN' => 'China', 'US' => 'United States', 'TR' => 'Turkey' )[ $c ] ?? $c; }
function gsup_parse_product_id( $v ) { return preg_replace( '/\D/', '', (string) $v ); }
function gsup_quote_country( $ship ) { return in_array( $ship, array( 'AU', 'US' ), true ) ? $ship : 'AU'; }
function gsup_find_option_links() { return array(); }
function gsup_clean_category_ids( $c ) { return array(); }
define( 'GSUP_META_PRODUCT', '_gsup_ae_product_id' ); define( 'GSUP_META_SKU', '_gsup_ae_sku_id' ); define( 'GSUP_META_SHIP', '_gsup_ship_from' );
define( 'GSUP_META_OPTION', '_gsup_ae_option' ); define( 'GSUP_META_COST', '_gsup_cost' ); define( 'GSUP_META_SHIP_COST', '_gsup_ship_cost' );
define( 'GSUP_META_SHIP_METHOD', '_gsup_ship_method' ); define( 'GSUP_META_SHIP_DAYS', '_gsup_ship_days' );

/** Products and variations whose meta lives in $GLOBALS['meta']. */
class WC_Product {
	public $id, $type, $kids = array(), $attrs = array(), $manage = false, $qty = null, $status = 'instock', $regular = '', $parent = 0, $name = 'Pearl Necklace';
	function __construct( $id = 0, $type = 'simple' ) { $this->id = $id; $this->type = $type; }
	function get_id() { return $this->id; } function is_type( $t ) { return $t === $this->type; } function get_children() { return $this->kids; }
	function get_name() { return $this->name; } function set_name( $n ) { $this->name = $n; }
	function get_variation_attributes( $p = true ) { return $this->attrs; }
	function update_meta_data( $k, $v ) { $GLOBALS['meta'][ $this->id ][ $k ] = $v; } function delete_meta_data( $k ) { unset( $GLOBALS['meta'][ $this->id ][ $k ] ); }
	function get_manage_stock() { return $this->manage; } function set_manage_stock( $b ) { $this->manage = $b; }
	function get_stock_quantity() { return $this->qty; } function set_stock_quantity( $q ) { $this->qty = $q; $this->status = $q > 0 ? 'instock' : 'outofstock'; }
	function get_stock_status() { return $this->status; } function set_stock_status( $s ) { $this->status = $s; }
	function get_regular_price() { return $this->regular; } function set_regular_price( $p ) { $this->regular = $p; }
	function save() { if ( ! $this->id ) { $this->id = ++$GLOBALS['next_id']; foreach ( $this->pending as $k => $v ) { $GLOBALS['meta'][ $this->id ][ $k ] = $v; } } $GLOBALS['products'][ $this->id ] = $this; return $this->id; }
	public $pending = array();
	function get_status() { return 'draft'; } function set_status( $s ) {}
	function get_parent_id() { return $this->parent; } function set_parent_id( $p ) { $this->parent = $p; }
	function set_attributes( $a ) { $this->attrs = $a; } function set_description( $d ) {} function set_short_description( $d ) {} function set_category_ids( $c ) {}
	function set_image_id( $i ) {} function set_gallery_image_ids( $i ) {} function get_image_id() { return 0; }
}
$GLOBALS['next_id'] = 900; $GLOBALS['products'] = array();
// New products get their ID on save(); meta set before that is kept until then.
class WC_Product_Simple extends WC_Product { function __construct() { parent::__construct( 0, 'simple' ); } function update_meta_data( $k, $v ) { if ( $this->id ) { parent::update_meta_data( $k, $v ); } else { $this->pending[ $k ] = $v; } } }
class WC_Product_Variable extends WC_Product_Simple { function __construct() { parent::__construct(); $this->type = 'variable'; } static function sync( $id ) {} }
class WC_Product_Variation extends WC_Product_Simple { function __construct() { parent::__construct(); $this->type = 'variation'; } function save() { $id = parent::save(); $GLOBALS['products'][ $this->parent ]->kids[] = $id; return $id; } }
class WC_Product_Attribute { public $n, $o, $v; function set_name( $n ) { $this->n = $n; } function get_name() { return $this->n; } function set_options( $o ) { $this->o = $o; } function set_position( $p ) {} function set_visible( $v ) {} function set_variation( $v ) { $this->v = $v; } }
function wc_get_product( $id ) { return $GLOBALS['products'][ $id ] ?? null; }
function wc_get_formatted_variation( $v ) { return implode( ', ', $v->attrs ); }

/** Reach table rows in memory: understands the REPLACE / DELETE / SELECT the class sends. */
class Fake_WPDB {
	public $prefix = 'wp_', $rows = array();
	function prepare( $q, ...$a ) { if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; } return array( $q, $a ); }
	function query( $p ) {
		list( $q, $a ) = $p;
		if ( 0 === strpos( $q, 'DELETE' ) ) {
			$pid = array_shift( $a );
			$this->rows = array_values( array_filter( $this->rows, fn( $r ) => ! ( $r['product_id'] === $pid && in_array( $r['country'], $a, true ) ) ) );
			return 1;
		}
		preg_match_all( '/\(([^()]*)\)/', substr( $q, strpos( $q, 'VALUES' ) ), $m );
		foreach ( $m[1] as $tpl ) {
			$row = array();
			foreach ( array( 'product_id', 'item_id', 'country', 'warehouse', 'deliverable', 'in_stock', 'stock', 'cost', 'ship_cost', 'method', 'days_min', 'days_max', 'checked_at' ) as $i => $col ) {
				$ph          = explode( ',', $tpl )[ $i ];
				$row[ $col ] = 'NULL' === $ph ? null : array_shift( $a );
			}
			$this->rows = array_values( array_filter( $this->rows, fn( $r ) => ! ( $r['item_id'] === $row['item_id'] && $r['country'] === $row['country'] && $r['warehouse'] === $row['warehouse'] ) ) );
			$this->rows[] = $row;
		}
		return 1;
	}
	function delete( $t, $w ) { $this->rows = array_values( array_filter( $this->rows, fn( $r ) => $r['product_id'] !== $w['product_id'] ) ); }
	function get_results( $p, $o = null ) { list( $q, $a ) = $p; return array_values( array_filter( $this->rows, fn( $r ) => false !== strpos( $q, 'item_id = %d' ) ? $r['item_id'] === $a[0] : $r['product_id'] === $a[0] ) ); }
	function get_var( $p ) { if ( ! is_array( $p ) ) { return 0; } list( $q, $a ) = $p; return count( array_filter( $this->rows, fn( $r ) => $r['item_id'] === $a[0] ) ); }
	function get_col( $p ) { list( $q, $a ) = $p; $item = array_shift( $a ); return array_values( array_unique( array_column( array_filter( $this->rows, fn( $r ) => $r['item_id'] === $item && $r['deliverable'] && in_array( $r['country'], $a, true ) ), 'warehouse' ) ) ); }
}
$wpdb = new Fake_WPDB(); $GLOBALS['wpdb'] = $wpdb;
class GSUP_Install { static function reach_table() { return 'wp_gsup_reach'; } static function table() { return 'wp_gsup_import'; } }

/** AliExpress: listings per delivery country, quotes per (SKU, country). */
class GSUP_AliExpress {
	static $listings = array(), $quotes = array(), $calls = array();
	static function default_ship_to() { return 'AU'; }
	static function is_connected() { return true; }
	static function get_products( array $pairs ) { $out = array(); foreach ( $pairs as $p ) { self::$calls[] = 'product|' . $p[1]; $out[ $p[0] . '|' . $p[1] ] = self::$listings[ $p[1] ] ?? new WP_Error( 'gsup_ae_network', 'timeout' ); } return $out; }
	static function get_product( $id, $c ) { return self::$listings[ $c ] ?? new WP_Error( 'gsup_ae_network', 'timeout' ); }
	static function freights( array $specs ) { $out = array(); foreach ( $specs as $k => $f ) { self::$calls[] = 'freight|' . $f[1] . '|' . $f[2]; $out[ $k ] = self::$quotes[ $f[1] . '|' . $f[2] ] ?? new WP_Error( 'gsup_ae_freight', 'No delivery to that country.' ); } return $out; }
	static function choose_freight( $o ) { return $o[0]; }
	static function find_sku( $p, $id ) { foreach ( $p['skus'] as $s ) { if ( (string) $s['sku_id'] === (string) $id ) { return $s; } } return null; }
}
class GSUP_Profit { public static $paused = false; static function refresh_flag() { return false; } }
class GSUP_Tidy { static function description_text( $d ) { return 'Words.'; } static function specs( $s ) { return array(); } }
class GSUP_Import { static function set_status() {} }
require __DIR__ . '/../givsen-supplier/includes/class-gsup-remap.php';
require __DIR__ . '/../givsen-supplier/includes/class-gsup-creator.php';
require __DIR__ . '/../givsen-supplier/includes/class-gsup-sources.php';
require __DIR__ . '/../givsen-supplier/includes/class-gsup-sync.php';
function update_meta_cache() {} function wp_mail() { return true; }
function wc_get_order() {}
$GLOBALS['wpdb_get_results_default'] = true;

$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }
function sku( $id, $colour, $ship, $price, $stock = 5 ) {
	$props = array( array( 'name' => 'Color', 'value' => $colour, 'image' => '', 'is_ship' => false ) );
	if ( '' !== $ship ) {
		$props[] = array( 'name' => 'Ships From', 'value' => gsup_ship_from_label( $ship ), 'image' => '', 'is_ship' => true );
	}
	return array( 'sku_id' => $id, 'sku_attr' => 'a:' . $id, 'option' => 'Color: ' . $colour . ( $ship ? ' · Ships From: ' . gsup_ship_from_label( $ship ) : '' ), 'ship_from' => $ship, 'price' => $price, 'currency' => 'AUD', 'stock' => $stock, 'props' => $props );
}
function listing( array $skus ) { return array( 'product_id' => '1005', 'title' => 'Pearl Necklace', 'image' => '', 'images' => array(), 'description' => '', 'specs' => array(), 'on_sale' => true, 'status' => 'onSelling', 'skus' => $skus ); }
function quote( $fee, $min, $max ) { return array( array( 'code' => 'STD', 'name' => 'Standard', 'fee' => $fee, 'min_days' => $min, 'max_days' => $max, 'tracked' => true ) ); }

// ------------------------------------------------------------------ matching across warehouses
// Store product: variations White (11) and Gold (12), linked to the AU warehouse; Rose (13) only exists from China
// under a slightly different name.
$GLOBALS['products'][10] = new WC_Product( 10, 'variable' );
$GLOBALS['products'][10]->kids = array( 11, 12, 13 );
foreach ( array( 11 => array( 'White', 'au-w' ), 12 => array( 'Gold', 'au-g' ), 13 => array( 'Rose Pink', '' ) ) as $id => $v ) {
	$GLOBALS['products'][ $id ] = new WC_Product( $id, 'variation' );
	$GLOBALS['products'][ $id ]->attrs = array( 'attribute_color' => $v[0] );
	if ( $v[1] ) {
		$GLOBALS['meta'][ $id ] = array( GSUP_META_SKU => $v[1], GSUP_META_SHIP => 'AU', GSUP_META_OPTION => 'Color: ' . $v[0] . ' · Ships From: Australia', GSUP_META_COST => '9.00' );
	}
}
$GLOBALS['meta'][10][ GSUP_META_PRODUCT ] = '1005';
$au = array( sku( 'au-w', 'White', 'AU', '9.00' ), sku( 'au-g', 'Gold', 'AU', '9.50', 0 ) );
$cn = array( sku( 'cn-w', 'white', '', '4.00' ), sku( 'cn-g', 'Gold', '', '4.20' ), sku( 'cn-r', 'Rose Pink Colour', '', '4.10' ) );
$us = array( sku( 'us-w', 'White', 'US', '6.00' ) );
$items = GSUP_Sources::items( $GLOBALS['products'][10] );
$m     = GSUP_Sources::match( $items, listing( array_merge( $au, $cn, $us ) ) );
ok( 'cn-w' === $m[11]['CN']['sku_id'] && 'us-w' === $m[11]['US']['sku_id'] && 'au-w' === $m[11]['AU']['sku_id'], 'White: same option found in China and US warehouses (case ignored, Ships From left out)' );
ok( 'cn-g' === $m[12]['CN']['sku_id'] && ! isset( $m[12]['US'] ), 'Gold: China found; the US warehouse doesn’t have it' );
ok( isset( $m[13]['CN'] ) && 'cn-r' === $m[13]['CN']['sku_id'], 'Rose Pink (no primary): matched in China by its words' );
ok( 'CN' === GSUP_Sources::wh( '' ) && 0 === GSUP_Sources::rank( 'AU' ) && GSUP_Sources::rank( 'US' ) < GSUP_Sources::rank( 'TR' ) && GSUP_Sources::rank( 'TR' ) < GSUP_Sources::rank( 'CN' ), 'warehouse preference: AU, store country, US, others, China last; unstated = China' );

// Union: each SKU kept from its own warehouse's home country.
$u = GSUP_Sources::union( array( 'AU' => listing( array( sku( 'us-w', 'White', 'US', '9.99' ) ) ), 'US' => listing( array( sku( 'us-w', 'White', 'US', '6.00' ) ) ) ) );
ok( '6.00' === $u['skus'][0]['price'], 'union: US warehouse priced as fetched for the US' );

// Legacy item without the sources meta reads as primary only.
$src = GSUP_Sources::get( 11 );
ok( array( 'AU' ) === array_keys( $src ) && 'au-w' === $src['AU']['sku'], 'no sources meta yet: reads as the primary only' );

// ------------------------------------------------------------------ reach refresh
$GLOBALS['opts']['gsup_sell_countries'] = array( 'AU', 'US', 'GB' );
GSUP_AliExpress::$listings = array(
	'AU' => listing( array_merge( $au, $cn ) ),            // US warehouse doesn't deliver to Australia.
	'US' => listing( array_merge( $us, array( $cn[0] ) ) ), // Only White from China reaches the US.
	'GB' => listing( $cn ),
);
GSUP_AliExpress::$quotes = array(
	'au-w|AU' => quote( 0, 3, 6 ), 'cn-w|AU' => quote( 2.5, 10, 18 ), 'us-w|US' => quote( 3, 4, 7 ), 'cn-w|US' => quote( 4, 12, 20 ),
	// No quote for China → GB: AliExpress can't deliver that way.
);
$r = GSUP_Sources::refresh( array( 10 ) );
ok( ! is_wp_error( $r[10] ) && array( 'AU', 'US', 'CN' ) === $r[10]['warehouses'], 'refresh: warehouses AU, US, CN found' );
ok( array( 'AU', 'US', 'CN' ) === array_keys( get_post_meta( 11, GSUP_Sources::META ) ) && 'cn-r' === get_post_meta( 13, GSUP_Sources::META )['CN']['sku'], 'refresh: sources saved on each option (primary first)' );
$row = function ( $item, $c, $wh ) use ( $wpdb ) { foreach ( $wpdb->rows as $x ) { if ( $x['item_id'] === $item && $x['country'] === $c && $x['warehouse'] === $wh ) { return $x; } } return null; };
$a = $row( 11, 'AU', 'AU' );
ok( $a && 1 === $a['deliverable'] && 1 === $a['in_stock'] && 3 === $a['days_min'] && 6 === $a['days_max'] && 0.0 === (float) $a['ship_cost'], 'reach: White from Australia to Australia, 3–6 days, free' );
$b = $row( 11, 'AU', 'US' );
ok( $b && 0 === $b['deliverable'], 'reach: US warehouse can’t deliver to Australia (not in AliExpress’s reply)' );
$c = $row( 12, 'AU', 'AU' );
ok( $c && 1 === $c['deliverable'] && 0 === $c['in_stock'] && 0 === $c['stock'], 'reach: Gold from Australia deliverable but out of stock' );
$d = $row( 12, 'US', 'CN' );
ok( $d && 0 === $d['deliverable'], 'reach: Gold from China not offered for the US' );
$e = $row( 11, 'GB', 'CN' );
ok( $e && 0 === $e['deliverable'] && null === $e['ship_cost'], 'reach: in the UK reply but no delivery quote → not deliverable' );
ok( ! $row( 11, 'NZ', 'AU' ), 'reach: only selling countries are stored' );
ok( 1 === count( array_filter( GSUP_AliExpress::$calls, fn( $x ) => 'freight|cn-w|AU' === $x ) ), 'one delivery quote per warehouse and country' );

// Soft failure for one country: that country's rows are kept as they were.
$before = $row( 11, 'GB', 'CN' );
GSUP_AliExpress::$listings['GB'] = new WP_Error( 'gsup_ae_network', 'timeout' );
unset( GSUP_AliExpress::$listings['GB'] );
$r = GSUP_Sources::refresh( array( 10 ) );
ok( array( 'GB' ) === $r[10]['skipped'] && $before === $row( 11, 'GB', 'CN' ), 'no answer for GB: its rows kept, reported as skipped' );
ok( isset( get_post_meta( 11, GSUP_Sources::META )['US'] ), 'no answer for one country: no source dropped' );

// A warehouse gone from every reply is dropped (never the primary).
GSUP_AliExpress::$listings = array( 'AU' => listing( array_merge( $au, $cn ) ), 'US' => listing( array( $cn[0] ) ), 'GB' => listing( $cn ) );
$r = GSUP_Sources::refresh( array( 10 ) );
ok( ! isset( get_post_meta( 11, GSUP_Sources::META )['US'] ) && 1 === $r[10]['removed'], 'US warehouse gone from every reply: source dropped' );

// ------------------------------------------------------------------ stock rules
$srcs = array( 'AU' => array( 'sku' => 'au', 'stock' => 0 ), 'CN' => array( 'sku' => 'cn', 'stock' => 7 ), 'US' => array( 'sku' => 'us', 'stock' => 40 ) );
$s1   = GSUP_Sources::any_stock( $srcs, array( 'AU', 'CN' ) );
ok( $s1['in_stock'] && 7 === $s1['qty'], 'any rule: AU out, China 7 → in stock, 7 (US doesn’t reach a selling country)' );
$s2 = GSUP_Sources::any_stock( $srcs, array( 'AU' ) );
ok( ! $s2['in_stock'] && 0 === $s2['qty'], 'any rule: only the out-of-stock warehouse reaches → out of stock' );
$s3 = GSUP_Sources::any_stock( array( 'AU' => array( 'stock' => 3 ), 'CN' => array( 'stock' => null ) ), array( 'AU', 'CN' ) );
ok( $s3['in_stock'] && null === $s3['qty'], 'any rule: a warehouse with unknown amount → in stock, not counted' );
$s4 = GSUP_Sources::any_stock( array( 'AU' => array( 'stock' => 3 ), 'CN' => array( 'stock' => 9 ) ), array( 'AU', 'CN' ) );
ok( 9 === $s4['qty'], 'any rule: amounts never added across warehouses (max, one parcel ships from one place)' );
ok( 'US' === GSUP_Sources::best( $srcs, array( 'AU', 'CN', 'US' ), 'AU' ) && 'CN' === GSUP_Sources::best( $srcs, array( 'AU', 'CN' ) ) && 'US' === GSUP_Sources::best( $srcs, array( 'US' ) ) && null === GSUP_Sources::best( $srcs, array() ), 'best replacement: preferred warehouse that reaches and has stock' );
ok( array( 'AU', 'CN' ) === array_values( array_intersect( array( 'AU', 'CN' ), GSUP_Sources::reachable( 11 ) ) ) && ! in_array( 'US', GSUP_Sources::reachable( 11 ), true ), 'reachable warehouses read from the reach table' );
ok( 'primary' === GSUP_Sources::stock_rule(), 'default stock rule is the primary warehouse (shop unchanged)' );

// ------------------------------------------------------------------ Add to store: all warehouses
GSUP_AliExpress::$listings = array(
	'AU' => listing( array( sku( 'au-w', 'White', 'AU', '9.00' ), sku( 'cn-w', 'White', '', '4.00' ), sku( 'cn-b', 'Black', '', '4.40' ), sku( 'au-b', 'Black', 'AU', '9.40', 0 ) ) ),
	'US' => listing( array( sku( 'us-w', 'White', 'US', '6.00' ) ) ),
	'GB' => listing( array( sku( 'cn-w', 'White', '', '4.00' ) ) ),
);
GSUP_AliExpress::$quotes = array( 'au-w|AU' => quote( 0, 3, 6 ), 'cn-w|AU' => quote( 2.5, 10, 18 ), 'us-w|US' => quote( 3, 4, 7 ) );
$all = GSUP_Creator::all_warehouses( '1005' );
ok( ! is_wp_error( $all ) && 2 === count( $all['groups'] ), 'all warehouses: options grouped by value (White, Black), Ships From left out' );
$white = $all['groups']['color:white'];
ok( array( 'AU', 'US', 'CN' ) === array_keys( $white ), 'White offered from Australia, United States and China (in preference order)' );
ok( 'AU' === GSUP_Creator::primary_of( $white ) && 'CN' === GSUP_Creator::primary_of( $all['groups']['color:black'] ), 'main warehouse: preferred one with stock (Black is out in Australia → China)' );
ok( 0.0 === (float) $all['freights']['AU']['fee'] && 3.0 === (float) $all['freights']['US']['fee'], 'one delivery quote per warehouse, to its home country' );
$GLOBALS['queued'] = array();
$id = GSUP_Creator::create(
	$all['listing'], '*', array( 'au-w', 'cn-b' ), 'Pearl Necklace', array(),
	array( 'sources' => $all['groups'], 'freights' => $all['freights'], 'photos' => 0, 'desc_photos' => false, 'specs' => false, 'description' => 'empty' )
);
$made = wc_get_product( $id );
ok( ! is_wp_error( $id ) && $made->is_type( 'variable' ) && 2 === count( $made->kids ), 'created one product with two options' );
list( $vw, $vb ) = $made->kids;
ok( 'au-w' === get_post_meta( $vw, GSUP_META_SKU ) && 'AU' === get_post_meta( $vw, GSUP_META_SHIP ) && 'cn-b' === get_post_meta( $vb, GSUP_META_SKU ) && '' === get_post_meta( $vb, GSUP_META_SHIP ), 'each option’s main warehouse is its primary (White: AU, Black: China)' );
ok( array( 'AU', 'US', 'CN' ) === array_keys( get_post_meta( $vw, GSUP_Sources::META ) ) && array( 'AU', 'CN' ) === array_keys( get_post_meta( $vb, GSUP_Sources::META ) ), 'every warehouse saved as a source on each option' );
ok( '0.00' === get_post_meta( $vw, GSUP_META_SHIP_COST ) && '2.50' === get_post_meta( $vb, GSUP_META_SHIP_COST ), 'delivery fee from each option’s own main warehouse' );
ok( '17.95' === $GLOBALS['products'][ $vw ]->regular && '13.95' === $GLOBALS['products'][ $vb ]->regular, 'price from the main warehouse’s cost + delivery (9.00 × 2 → 17.95; (4.40 + 2.50) × 2 → 13.95)' );
ok( array( 'gsup_sources_refresh', array( array( $id ) ) ) === $GLOBALS['queued'][0], 'reach checked in the background after creating' );

// ------------------------------------------------------------------ daily sync with sources
// Simple product 20: primary AU (a1), China source (c1). Reach says both reach a selling country.
$GLOBALS['products'][20] = new WC_Product( 20, 'simple' );
$GLOBALS['meta'][20]     = array( GSUP_META_PRODUCT => '2005', GSUP_META_SKU => 'a1', GSUP_META_SHIP => 'AU', GSUP_META_COST => '9.00', GSUP_META_SHIP_COST => '0.00', GSUP_Sync::M_QUOTED => time() );
GSUP_Sources::save( 20, array( 'AU' => array( 'sku' => 'a1', 'option' => '', 'cost' => '9.00', 'stock' => 3, 'seen_at' => 0 ), 'CN' => array( 'sku' => 'c1', 'option' => '', 'cost' => '4.00', 'stock' => 10, 'seen_at' => 0 ) ) );
$wpdb->rows = array_merge(
	array_values( array_filter( $wpdb->rows, fn( $r ) => 20 !== $r['item_id'] ) ),
	array(
		array( 'product_id' => 20, 'item_id' => 20, 'country' => 'AU', 'warehouse' => 'AU', 'deliverable' => 1, 'in_stock' => 1, 'stock' => 3, 'cost' => 9, 'ship_cost' => 0, 'method' => 'STD', 'days_min' => 3, 'days_max' => 6, 'checked_at' => '' ),
		array( 'product_id' => 20, 'item_id' => 20, 'country' => 'AU', 'warehouse' => 'CN', 'deliverable' => 1, 'in_stock' => 1, 'stock' => 10, 'cost' => 4, 'ship_cost' => 2.5, 'method' => 'CN_STD', 'days_min' => 10, 'days_max' => 18, 'checked_at' => '' ),
	)
);
$sync = function ( array $skus ) {
	$report = array( 'checked' => 0, 'stock_changes' => 0, 'cost_changes' => 0, 'price_changes' => 0, 'ship_changes' => 0, 'low_margin' => array(), 'switched' => array(), 'backup_failed' => array(), 'removed' => array(), 'options_gone' => array(), 'back' => array(), 'missing' => array(), 'promoted' => array(), 'errors' => array() );
	$cache  = array( '2005|AU' => array_merge( listing( $skus ), array( 'product_id' => '2005' ) ) );
	GSUP_Sync::sync_product( 20, $report, $cache );
	return $report;
};
$p = $GLOBALS['products'][20];

// Primary rule (default): AU sold out, China has 12 → the shop still follows Australia; China's figures recorded.
$rep = $sync( array( sku( 'a1', 'White', 'AU', '9.20', 0 ), sku( 'c1', 'White', '', '4.10', 12 ) ) );
$src = get_post_meta( 20, GSUP_Sources::META );
ok( 0 === $p->qty && 'outofstock' === $p->status && array() === $rep['promoted'], 'primary rule: shop stock follows the main warehouse (sold out) as before' );
ok( '4.10' === $src['CN']['cost'] && 12 === $src['CN']['stock'] && '9.20' === $src['AU']['cost'] && 0 === $src['AU']['stock'], 'daily sync: every source’s cost and stock updated' );

// Any rule: AU sold out, China 12 → in stock with 12.
$GLOBALS['opts']['gsup_stock_rule'] = 'any';
$sync( array( sku( 'a1', 'White', 'AU', '9.20', 0 ), sku( 'c1', 'White', '', '4.10', 12 ) ) );
ok( 12 === $p->qty && 'instock' === $p->status, 'any rule: in stock from China while Australia is sold out' );
ok( 'a1' === get_post_meta( 20, GSUP_META_SKU ), 'any rule: main warehouse unchanged while its option still exists' );

// Any rule: AU option gone from the listing → China becomes the main warehouse, not "option gone".
$rep = $sync( array( sku( 'c1', 'White', '', '4.10', 12 ) ) );
ok( 'c1' === get_post_meta( 20, GSUP_META_SKU ) && '' === get_post_meta( 20, GSUP_META_SHIP ) && array( 20 ) === $rep['promoted'] && array() === $rep['options_gone'], 'any rule: AU gone → China promoted to main warehouse, option not gone' );
ok( '2.50' === get_post_meta( 20, GSUP_META_SHIP_COST ) && '10-18' === get_post_meta( 20, GSUP_META_SHIP_DAYS ), 'promoted: delivery fee and days from the reach table (China → Australia)' );
ok( 12 === $p->qty, 'promoted: stock from China' );

// No source left → option gone.
$rep = $sync( array( sku( 'zz', 'Black', 'AU', '9.00' ) ) );
ok( array( 20 ) === $rep['options_gone'] && 'outofstock' === $p->status, 'no source left anywhere → option gone, out of stock' );
$GLOBALS['opts']['gsup_stock_rule'] = 'primary';

echo $fail ? "$fail FAILED\n" : "ALL PASSED\n";
exit( $fail ? 1 : 0 );

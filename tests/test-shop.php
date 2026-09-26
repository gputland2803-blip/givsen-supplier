<?php
// Sold worldwide, phase 2: visitor country and switcher, delivery choice per country, hiding and the "Shipping from"
// filter, checkout re-check, and cache safety.
define( 'ABSPATH', '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HOUR_IN_SECONDS', 3600 ); define( 'DAY_IN_SECONDS', 86400 );
define( 'GSUP_URL', '/p/' ); define( 'GSUP_VERSION', 't' );
define( 'GSUP_META_PRODUCT', '_gsup_ae_product_id' ); define( 'GSUP_META_SKU', '_gsup_ae_sku_id' ); define( 'GSUP_META_SHIP', '_gsup_ship_from' );
define( 'GSUP_META_OPTION', '_gsup_ae_option' ); define( 'GSUP_META_COST', '_gsup_cost' ); define( 'GSUP_META_SHIP_DAYS', '_gsup_ship_days' );
class WP_Error { public $c, $m; function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; } function get_error_code() { return $this->c; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['meta'] = array(); $GLOBALS['opts'] = array( 'gsup_worldwide_shop' => 'yes', 'gsup_sell_countries' => array( 'AU', 'GB', 'US' ) ); $GLOBALS['tr'] = array(); $GLOBALS['notices'] = array();
function get_post_meta( $id, $k = '', $s = true ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; }
function add_action() {} function add_filter() {} function add_shortcode() {}
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } function esc_attr( $s ) { return esc_html( $s ); } function esc_url( $s ) { return (string) $s; } function esc_url_raw( $s ) { return (string) $s; }
function selected( $a, $b, $e = true ) { return $a === $b ? ' selected' : ''; } function checked( $a, $b, $e = true ) { return $a === $b ? ' checked' : ''; }
function wp_unslash( $s ) { return $s; } function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $s ) ); } function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_rand( $a, $b ) { return 1234; } function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_SLASHES ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } function wc_price( $n ) { return '<span>$' . number_format( (float) $n, 2 ) . '</span>'; }
function wc_get_base_location() { return array( 'country' => 'AU' ); }
function is_admin() { return false; } function wp_doing_ajax() { return false; } function wp_doing_cron() { return false; }
function is_ssl() { return true; }
function remove_query_arg( $k, $u = '/shop/?orderby=price&paged=2' ) { foreach ( (array) $k as $x ) { $u = preg_replace( '/([?&])' . preg_quote( $x, '/' ) . '=[^&]*&?/', '$1', $u ); } return rtrim( $u, '?&' ); }
function add_query_arg( $k, $v, $u ) { return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . $k . '=' . $v; }
function get_object_taxonomies() { return array( 'product_cat' ); }
function wc_add_notice( $m, $t ) { $GLOBALS['notices'][] = array( $t, $m ); } function wc_has_notice( $m, $t ) { return in_array( array( $t, $m ), $GLOBALS['notices'], true ); }
function get_the_title( $id ) { return 'Product ' . $id; }
function is_cart() { return false; } function is_checkout() { return false; } function is_account_page() { return false; } function is_product_taxonomy() { return false; }
function rest_url( $p ) { return 'https://givsen.test/wp-json/' . $p; }
class WC_Countries { function get_countries() { return array( 'AU' => 'Australia', 'GB' => 'United Kingdom (UK)', 'US' => 'United States (US)', 'NZ' => 'New Zealand', 'FR' => 'France' ); } }
class Customer { public $ship = '', $bill = ''; function set_shipping_country( $c ) { $this->ship = $c; } function get_shipping_country() { return $this->ship; } function get_billing_country() { return $this->bill; } function set_billing_country( $c ) { $this->bill = $c; } function save() {} }
class Cart { public $cart_contents = array(), $saved = 0; function get_cart() { return $this->cart_contents; } function set_session() { ++$this->saved; } }
class WCx { public $countries, $customer, $cart; function __construct() { $this->countries = new WC_Countries(); $this->customer = new Customer(); $this->cart = new Cart(); } }
function WC() { static $w; return $w ??= new WCx(); }
class WC_Geolocation { static $answer = ''; static function geolocate_ip() { return array( 'country' => self::$answer ); } }
class WC_Product { public $id, $type, $price, $parent = 0; function __construct( $id, $type = 'simple', $price = 20 ) { $this->id = $id; $this->type = $type; $this->price = $price; } function get_id() { return $this->id; } function is_type( $t ) { return $t === $this->type; } function get_parent_id() { return $this->parent; } function get_price() { return $this->price; } function get_name() { return 'Product ' . $this->id; } function set_price( $p ) { $this->price = $p; } }
$GLOBALS['products'] = array( 10 => new WC_Product( 10 ), 20 => new WC_Product( 20 ), 30 => new WC_Product( 30 ), 40 => new WC_Product( 40 ) );
function wc_get_product( $id ) { return $GLOBALS['products'][ $id ] ?? null; }
class GSUP_Install { static function reach_table() { return 'wp_gsup_reach'; } }
class GSUP_AliExpress { static function default_ship_to() { return 'AU'; } }
class GSUP_Eta { static function span( $r ) { return $r[0] . '–' . $r[1] . ' days (dates)'; } }
class GSUP_Sources {
	static function countries() { return get_option( 'gsup_sell_countries' ); }
	static function sell_others() { return 'no' !== get_option( 'gsup_sell_others', 'yes' ); }
	static function label( $w ) { return array( 'AU' => 'Australia', 'CN' => 'China', 'US' => 'United States' )[ $w ] ?? $w; }
	static function rank( $w ) { return array( 'AU' => 0, 'US' => 2, 'CN' => 4 )[ $w ] ?? 3; }
	static function sort_warehouses( $a ) { usort( $a, fn( $x, $y ) => self::rank( $x ) <=> self::rank( $y ) ); return $a; }
	static function reach( $pid ) { return array_values( array_filter( $GLOBALS['wpdb']->rows, fn( $r ) => $r['product_id'] === $pid ) ); }
	static $live = array();
	static function live( $pid, $c ) { return self::$live[ $pid . '|' . $c ] ?? new WP_Error( 'x', 'no' ); }
}
function row( $pid, $item, $c, $wh, $ok = 1, $stock = 1, $min = 3, $max = 6 ) { return array( 'product_id' => $pid, 'item_id' => $item, 'country' => $c, 'warehouse' => $wh, 'deliverable' => $ok, 'in_stock' => $stock, 'days_min' => $min, 'days_max' => $max, 'ship_cost' => 0 ); }
class Fake_WPDB {
	public $rows = array(), $posts = 'wp_posts', $status = array( 10 => 'publish', 20 => 'publish', 30 => 'publish', 40 => 'draft' ), $queries = 0;
	function prepare( $q, ...$a ) { if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; } return array( $q, $a ); }
	function get_col( $p ) {
		++$this->queries; list( $q, $a ) = $p;
		$rows = array_filter( $this->rows, fn( $r ) => $r['country'] === $a[0] );
		if ( false !== strpos( $q, 'HAVING' ) ) {
			$ok = array(); foreach ( $rows as $r ) { $ok[ $r['product_id'] ] = ( $ok[ $r['product_id'] ] ?? false ) || ( $r['deliverable'] && $r['in_stock'] ); }
			return array_keys( array_filter( $ok, fn( $x ) => ! $x ) );
		}
		return array_values( array_unique( array_column( array_filter( $rows, fn( $r ) => $r['warehouse'] === $a[1] && $r['deliverable'] && $r['in_stock'] ), 'product_id' ) ) );
	}
	function get_results( $p, $o = null ) {
		list( $q, $a ) = $p; $c = array_shift( $a );
		$n = array();
		foreach ( $this->rows as $r ) {
			if ( $r['country'] === $c && $r['deliverable'] && $r['in_stock'] && 'publish' === ( $this->status[ $r['product_id'] ] ?? '' ) && ( ! $a || in_array( $r['product_id'], $a, true ) ) ) {
				$n[ $r['warehouse'] ][ $r['product_id'] ] = true;
			}
		}
		$out = array(); foreach ( $n as $wh => $ids ) { $out[] = array( 'warehouse' => $wh, 'n' => count( $ids ) ); } return $out;
	}
}
$wpdb = new Fake_WPDB(); $GLOBALS['wpdb'] = $wpdb;
require __DIR__ . '/../givsen-supplier/includes/class-gsup-visitor.php';
require __DIR__ . '/../givsen-supplier/includes/class-gsup-shop.php';

$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }
function fresh( $get = array(), $cookie = array(), $server = array() ) { $_GET = $get; $_COOKIE = $cookie; $_SERVER = $server; GSUP_Visitor::reset(); GSUP_Shop::set_rendering( true ); }

// ------------------------------------------------------------------ country detection
fresh( array(), array(), array( 'HTTP_CF_IPCOUNTRY' => 'gb' ) );
ok( 'GB' === GSUP_Visitor::country(), 'Cloudflare CF-IPCountry header used when there’s no cookie' );
fresh( array(), array( 'gsup_country' => 'NZ' ), array( 'HTTP_CF_IPCOUNTRY' => 'GB' ) );
ok( 'NZ' === GSUP_Visitor::country(), 'the visitor’s saved choice beats the header' );
fresh( array( 'gsup_c' => 'US' ), array( 'gsup_country' => 'NZ' ) );
ok( 'US' === GSUP_Visitor::country(), 'a cached page variant (?gsup_c=US) is rendered for its own country' );
fresh( array(), array( 'gsup_country' => 'XX' ), array( 'HTTP_CF_IPCOUNTRY' => 'T1' ) );
WC_Geolocation::$answer = 'FR';
ok( 'FR' === GSUP_Visitor::country(), 'bad cookie and Tor header ignored → WooCommerce geolocation' );
WC_Geolocation::$answer = '';
fresh();
ok( 'AU' === GSUP_Visitor::country(), 'nothing known → the store’s country' );
ok( 'GB' === GSUP_Visitor::clean( 'uk' ) && '' === GSUP_Visitor::clean( 'ZZ' ), 'UK means GB; unknown codes refused' );
ok( '🇬🇧' === GSUP_Visitor::flag( 'GB' ) && 'United Kingdom' === GSUP_Visitor::name( 'GB' ), 'flag and name without “(UK)”' );

// ------------------------------------------------------------------ switcher
fresh( array( 's' => 'pearl' ), array( 'gsup_country' => 'GB' ) );
$html = GSUP_Visitor::switcher();
ok( false !== strpos( $html, 'Deliver to: <span class="gsup-flag" aria-hidden="true">🇬🇧</span> <strong>United Kingdom</strong>' ), 'switcher: “Deliver to: 🇬🇧 United Kingdom — change”' );
ok( false !== strpos( $html, '<option value="GB" selected>' ) && strpos( $html, 'value="AU"' ) < strpos( $html, '──' ) && strpos( $html, '──' ) < strpos( $html, 'value="FR"' ), 'switcher: current country selected, selling countries first, then the rest' );
ok( false !== strpos( $html, 'name="s" value="pearl"' ), 'switcher keeps the search the visitor was on' );
$GLOBALS['opts']['gsup_sell_others'] = 'no';
ok( false === strpos( GSUP_Visitor::switcher(), 'value="FR"' ), 'not selling elsewhere: only selling countries offered' );
unset( $GLOBALS['opts']['gsup_sell_others'] );
GSUP_Visitor::set( 'GB' );
ok( 'GB' === $_COOKIE['gsup_country'] && 'GB' === WC()->customer->ship && 'GB' === WC()->customer->bill && 'GB' === GSUP_Visitor::country(), 'choosing a country saves it and sets the customer’s shipping country (gift for someone in the UK)' );

// ------------------------------------------------------------------ delivery choice per country
// Product 10: simple, AU warehouse 3–6 days, China 10–18 (AU); only China reaches the UK; nothing reaches the US.
$wpdb->rows = array(
	row( 10, 10, 'AU', 'AU', 1, 1, 3, 6 ), row( 10, 10, 'AU', 'CN', 1, 1, 10, 18 ),
	row( 10, 10, 'GB', 'AU', 0, 0 ), row( 10, 10, 'GB', 'CN', 1, 1, 12, 20 ),
	row( 10, 10, 'US', 'AU', 0, 0 ), row( 10, 10, 'US', 'CN', 1, 0, 12, 20 ),
	row( 20, 20, 'US', 'US', 1, 1, 2, 5 ), row( 20, 20, 'AU', 'CN', 1, 1, 10, 18 ), row( 20, 20, 'GB', 'CN', 0, 0 ),
	row( 40, 40, 'US', 'US', 1, 1, 2, 5 ),
);
$GLOBALS['meta'] = array( 10 => array( GSUP_META_PRODUCT => '1' ), 20 => array( GSUP_META_PRODUCT => '2' ), 40 => array( GSUP_META_PRODUCT => '4' ) );
GSUP_Shop::set_rendering( true );
$au = GSUP_Shop::options( 10, 10, 'AU' );
ok( array( 'AU', 'CN' ) === array_column( $au, 'wh' ), 'Australia: Australian and Chinese warehouses, fastest first' );
$html = GSUP_Shop::choice_html( array( array( 'wh' => 'AU', 'label' => 'Australia', 'days' => '4–7 days (dates)', 'extra' => '' ), array( 'wh' => 'CN', 'label' => 'China', 'days' => '11–19 days (dates)', 'extra' => '' ) ) );
ok( false !== strpos( $html, 'value="AU" checked' ) && false !== strpos( $html, 'Australia</span> — <span class="gsup-delivery-days">4–7 days (dates)' ) && 1 === substr_count( $html, 'Fastest' ), 'choice: “Australia — 4–7 days (dates)”, fastest ticked' );
$uk = GSUP_Shop::options( 10, 10, 'GB' );
ok( array( 'CN' ) === array_column( $uk, 'wh' ) && false !== strpos( GSUP_Shop::choice_html( array( array( 'wh' => 'CN', 'label' => 'China', 'days' => '12–20 days', 'extra' => '' ) ) ), 'Delivery: 12–20 days' ), 'UK: only China reaches → single delivery line, no choice' );
GSUP_Visitor::reset( 'US' );
ok( array() === GSUP_Shop::options( 10, 10, 'US' ) && false === GSUP_Shop::available( 10, 'US' ) && false === GSUP_Shop::purchasable( true, $GLOBALS['products'][10] ), 'US: China reaches but is out of stock → not available, can’t be added to the cart' );
ok( null === GSUP_Shop::options( 30, 30, 'AU' ) && true === GSUP_Shop::purchasable( true, $GLOBALS['products'][30] ), 'product never checked (or not from AliExpress): shown and sold as usual' );
GSUP_Shop::set_rendering( false );
ok( true === GSUP_Shop::purchasable( true, $GLOBALS['products'][10] ), 'while the cart loads from the session, nothing is made unbuyable (no silent removal)' );
GSUP_Shop::set_rendering( true );

// Live country (not listed, selling to others): one live check.
GSUP_Sources::$live['10|NZ'] = array( 10 => array( 'CN' => array( 'deliverable' => true, 'in_stock' => true, 'days_min' => 9, 'days_max' => 15, 'ship_cost' => 3 ), 'AU' => array( 'deliverable' => true, 'in_stock' => false, 'days_min' => 4, 'days_max' => 8, 'ship_cost' => 5 ) ) );
ok( array( 'CN' ) === array_column( GSUP_Shop::options( 10, 10, 'NZ' ), 'wh' ), 'unlisted country checked live: China only (Australia out of stock)' );
$GLOBALS['opts']['gsup_sell_others'] = 'no';
GSUP_Shop::set_rendering( true );
ok( false === GSUP_Shop::available( 10, 'FR' ) && false === GSUP_Shop::sells_to( 'FR' ) && array() === GSUP_Shop::hidden_ids( 'FR' ), 'not selling to France: products unavailable there, but lists aren’t emptied (a notice says so)' );
unset( $GLOBALS['opts']['gsup_sell_others'] );

// Local warehouse surcharge.
$GLOBALS['opts']['gsup_wh_pricing'] = 'local'; $GLOBALS['opts']['gsup_local_pct'] = 10; $GLOBALS['opts']['gsup_local_fixed'] = 1;
GSUP_Shop::set_rendering( true );
$au = GSUP_Shop::options( 10, 10, 'AU' );
ok( 3.0 === $au[0]['extra'] && 0.0 === $au[1]['extra'], 'local warehouses cost extra: Australia +10% + $1 = $3 on $20; China nothing' );
$cart = WC()->cart;
$cart->cart_contents = array( 'k' => array( 'product_id' => 10, 'variation_id' => 0, 'gsup_wh' => 'AU', 'gsup_c' => 'AU', 'data' => new WC_Product( 10 ) ) );
GSUP_Shop::surcharges( $cart ); GSUP_Shop::surcharges( $cart );
ok( 23.0 === (float) $cart->cart_contents['k']['data']->price, 'surcharge added in the cart once, however often totals are worked out' );
$GLOBALS['opts']['gsup_wh_pricing'] = 'same';

// Cart item and order line.
GSUP_Visitor::reset( 'AU' );
$_POST = array( 'gsup_wh' => 'cn' );
$data  = GSUP_Shop::cart_item_data( array(), 10, 0 );
ok( 'CN' === $data['gsup_wh'] && 'AU' === $data['gsup_c'], 'chosen warehouse goes into the cart item' );
$_POST = array( 'gsup_wh' => 'US' );
ok( 'AU' === GSUP_Shop::cart_item_data( array(), 10, 0 )['gsup_wh'], 'a warehouse that can’t deliver is replaced by the fastest' );
$rows = GSUP_Shop::item_data( array(), array( 'product_id' => 10, 'variation_id' => 0, 'gsup_wh' => 'AU', 'gsup_c' => 'AU' ) );
ok( array( array( 'key' => 'Ships from', 'value' => 'Australia · 3–6 days' ) ) === $rows, 'cart shows “Ships from: Australia · 3–6 days”' );
class Line { public $meta = array(); function add_meta_data( $k, $v, $u ) { $this->meta[ $k ] = $v; } }
$line = new Line();
GSUP_Shop::order_line( $line, 'k', array( 'product_id' => 10, 'variation_id' => 0, 'gsup_wh' => 'CN', 'gsup_c' => 'AU' ) );
ok( array( '_gsup_wh' => 'CN', 'Ships from' => 'China · 10–18 days' ) === $line->meta, 'order line keeps the warehouse (for ordering) and shows where it ships from' );
GSUP_Visitor::reset( 'US' );
ok( false === GSUP_Shop::validate_add( true, 10, 1, 0 ) && 'error' === end( $GLOBALS['notices'] )[0], 'adding a product that can’t reach the visitor is refused with a message' );

// ------------------------------------------------------------------ hiding and filter
$GLOBALS['tr'] = array();
ok( array( 10 ) === GSUP_Shop::hidden_ids( 'US' ) && array() === GSUP_Shop::hidden_ids( 'AU' ), 'hidden: product 10 for the US (nothing in stock reaches it)' );
ok( array( 20 ) === GSUP_Shop::hidden_ids( 'GB' ), 'UK: product 10 not hidden (China reaches it), 20 hidden; products never checked for a country (30) never hidden' );
$wpdb->queries = 0;
GSUP_Shop::hidden_ids( 'US' ); GSUP_Shop::hidden_ids( 'US' );
ok( 0 === $wpdb->queries, 'hidden list cached per country' );
$GLOBALS['opts']['gsup_reach_ver'] = 7;
GSUP_Shop::hidden_ids( 'US' );
ok( 1 === $wpdb->queries, 'cache rebuilt after a reach refresh' );
ok( array( 20, 30 ) === GSUP_Shop::filter_ids( array( 10, 20, 30 ) ), 'related products / up-sells / cross-sells: undeliverable ones left out' );

class Q {
	public $v = array(), $main = false, $singular = false, $search = false, $archive = false;
	function get( $k ) { return $this->v[ $k ] ?? ''; } function set( $k, $x ) { $this->v[ $k ] = $x; }
	function is_main_query() { return $this->main; } function is_singular() { return $this->singular; } function is_search() { return $this->search; }
	function is_tax( $t = '' ) { return false; } function is_post_type_archive( $t = '' ) { return $this->archive; }
}
$q = new Q(); $q->main = true; $q->archive = true; $q->v['post_type'] = 'product';
GSUP_Shop::filter_query( $q );
ok( array( 10 ) === $q->get( 'post__not_in' ), 'shop page (US): product 10 left out of the query' );
$q = new Q(); $q->main = true; $q->search = true;
GSUP_Shop::filter_query( $q );
ok( array( 10 ) === $q->get( 'post__not_in' ), 'search results: left out too' );
$q = new Q(); $q->v['post_type'] = 'product'; $q->v['post__not_in'] = array( 99 );
GSUP_Shop::filter_query( $q );
ok( array( 99, 10 ) === $q->get( 'post__not_in' ), 'blocks / shortcodes / widgets (any product query): merged with their own exclusions' );
$q = new Q(); $q->main = true; $q->singular = true; $q->v['post_type'] = 'product';
GSUP_Shop::filter_query( $q );
ok( '' === $q->get( 'post__not_in' ), 'a product opened directly is still shown (with “Not available in …”)' );
$q = new Q(); $q->v['post_type'] = 'product'; $q->v['gsup_all'] = true;
GSUP_Shop::filter_query( $q );
ok( '' === $q->get( 'post__not_in' ), 'queries that ask for everything are left alone' );

// "Shipping from" filter: counts per warehouse, published and in stock only.
ok( array( 'US' => 1 ) === GSUP_Shop::counts( 'US' ), 'US: “United States (1)” (draft product 40 not counted, China out of stock)' );
ok( array( 'AU' => 1, 'CN' => 2 ) === GSUP_Shop::counts( 'AU' ), 'Australia: Australia (1), China (2), in preference order' );
ok( array( 'CN' => 1 ) === GSUP_Shop::counts( 'AU', array( 20 ) ), 'counts limited to the category being viewed' );
ok( array() === GSUP_Shop::counts( 'NZ' ), 'countries checked live: no filter (no stored data)' );
GSUP_Visitor::reset( 'AU' );
$html = GSUP_Shop::filter_html();
ok( false !== strpos( $html, '>All</a>' ) && false !== strpos( $html, 'ships_from=AU">Australia <span class="count">(1)</span>' ) && false !== strpos( $html, 'China <span class="count">(2)</span>' ) && false === strpos( $html, 'paged' ), 'filter: “All · Australia (1) · China (2)”, back to page 1' );
$_GET = array( 'ships_from' => 'au' );
$q = new Q(); $q->main = true; $q->archive = true; $q->v['post_type'] = 'product';
GSUP_Shop::filter_query( $q );
ok( array( 10 ) === $q->get( 'post__in' ), 'choosing Australia shows only products shipping from Australia (in stock)' );
$_GET = array();

// ------------------------------------------------------------------ checkout re-check
$GLOBALS['notices'] = array();
$cart->cart_contents = array(
	'a' => array( 'product_id' => 10, 'variation_id' => 0, 'gsup_wh' => 'AU', 'gsup_c' => 'AU', 'data' => new WC_Product( 10 ) ),
	'b' => array( 'product_id' => 20, 'variation_id' => 0, 'gsup_wh' => 'CN', 'gsup_c' => 'AU', 'data' => new WC_Product( 20 ) ),
	'c' => array( 'product_id' => 30, 'variation_id' => 0, 'data' => new WC_Product( 30 ) ),
);
$r = GSUP_Shop::recheck( 'GB' );
ok( 'CN' === $cart->cart_contents['a']['gsup_wh'] && 'GB' === $cart->cart_contents['a']['gsup_c'] && array( 'a' ) === $r['switched'], 'UK address: Australian warehouse can’t deliver → switched to China' );
ok( (bool) array_filter( $GLOBALS['notices'], fn( $n ) => 'notice' === $n[0] && false !== strpos( $n[1], 'will ship from China instead' ) ), 'switch explained with a notice' );
ok( array( 'b' ) === $r['blocked'] && (bool) array_filter( $GLOBALS['notices'], fn( $n ) => 'error' === $n[0] && false !== strpos( $n[1], 'can’t be delivered to United Kingdom' ) ), 'nothing reaches the UK for product 20 → error for that item' );
ok( ! isset( $cart->cart_contents['c']['gsup_wh'] ) && $cart->saved > 0, 'products without reach data untouched; cart saved' );
$count = count( $GLOBALS['notices'] );
GSUP_Shop::recheck( 'GB' );
ok( count( $GLOBALS['notices'] ) === $count, 'checking again doesn’t repeat messages' );
$_POST = array( 'country' => 'AU', 's_country' => 'US' );
$_COOKIE = array();
GSUP_Shop::order_review_changed( '' );
ok( 'US' === GSUP_Visitor::country() && 'US' === $cart->cart_contents['b']['gsup_c'] && 'US' === $cart->cart_contents['b']['gsup_wh'], 'shipping country typed at checkout: followed (shop country too) and lines re-checked' );

// ------------------------------------------------------------------ cache safety
fresh( array( 'gsup_c' => 'GB' ) );
ob_start(); GSUP_Visitor::head(); $head = ob_get_clean();
ok( false !== strpos( $head, '<meta name="gsup-country" content="GB">' ) && false !== strpos( $head, 'noindex' ), 'cached variant: page says its country and isn’t indexed' );
fresh();
ob_start(); GSUP_Visitor::head(); $head = ob_get_clean();
ok( false === strpos( $head, 'noindex' ) && false !== strpos( $head, 'wp-json/givsen-supplier/v1/country' ), 'normal page: indexable, carries the country check' );
ok( array( 'x', 'gsup_country' ) === GSUP_Visitor::litespeed_cookies( array( 'x' ) ), 'LiteSpeed Cache varies pages by the country cookie' );
file_put_contents( sys_get_temp_dir() . '/gsup-guard.js', GSUP_Visitor::guard_js( '__COUNTRY__', '/country' ) );
ok( false !== strpos( GSUP_Visitor::guard_js( 'AU', '/c' ), '"AU"' ), 'check script built for the page’s country' );

echo $fail ? "$fail FAILED\n" : "ALL PASSED\n";
exit( $fail ? 1 : 0 );

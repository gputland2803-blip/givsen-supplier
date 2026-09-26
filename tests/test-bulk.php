<?php
// Bulk import from search results / store pages: batch dedupe, in-store/in-list status, and background checking.
define( 'ABSPATH', '/' );
define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error { public $c, $m, $d; function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; $this->d = $d; } function get_error_code() { return $this->c; } function get_error_message() { return $this->m; } function get_error_data() { return $this->d; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function esc_url_raw( $u, $p = null ) { return preg_match( '~^https://~', (string) $u ) ? (string) $u : ''; }
function absint( $n ) { return abs( (int) $n ); }
function term_exists( $id, $tax ) { return in_array( (int) $id, array( 15, 16 ), true ); }
function current_time( $t, $gmt = false ) { return '2026-09-26 10:00:00'; }
function get_option( $k, $d = false ) { return $d; }
$GLOBALS['queued'] = array();
function as_enqueue_async_action( $hook, $args, $group ) { $GLOBALS['queued'][] = array( $hook, $args, $group ); return 1; }
require __DIR__ . '/../givsen-supplier/includes/functions.php';

/** In-memory import table + postmeta, answering the handful of queries GSUP_Import makes. */
class Fake_WPDB {
	public $prefix = 'wp_', $postmeta = 'wp_postmeta', $posts = 'wp_posts', $insert_id = 0;
	public $rows = array(), $links = array(), $queries = 0;
	function prepare( $q, ...$args ) { if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; } return serialize( array( $q, $args ) ); }
	private function q( $s ) { $this->queries++; return unserialize( $s ); }
	private function find( $fn ) { return array_values( array_filter( $this->rows, $fn ) ); }
	function get_row( $s, $o = null ) {
		list( $q, $a ) = $this->q( $s );
		if ( preg_match( '/WHERE id = %d/', $q ) ) { return $this->rows[ (int) $a[0] ] ?? null; }
		if ( preg_match( '/ae_product_id = %s AND ae_sku_id = %s ORDER BY/', $q ) ) {
			$r = $this->find( fn( $r ) => $r['ae_product_id'] === $a[0] && $r['ae_sku_id'] === $a[1] );
			return $r ? end( $r ) : null;
		}
		throw new Exception( 'unexpected get_row: ' . $q );
	}
	function get_var( $s ) {
		list( $q, $a ) = $this->q( $s );
		if ( preg_match( '/ae_sku_id = %s AND id <> %d/', $q ) ) {
			$r = $this->find( fn( $r ) => $r['ae_product_id'] === $a[0] && $r['ae_sku_id'] === $a[1] && $r['id'] !== (int) $a[2] );
			return $r ? $r[0]['id'] : null;
		}
		throw new Exception( 'unexpected get_var: ' . $q );
	}
	function get_col( $s ) {
		list( $q, $a ) = $this->q( $s );
		if ( false !== strpos( $q, 'wp_gsup_import' ) && false !== strpos( $q, "status <> 'dismissed'" ) ) {
			return array_values( array_unique( array_column( $this->find( fn( $r ) => 'dismissed' !== $r['status'] && in_array( $r['ae_product_id'], $a, true ) ), 'ae_product_id' ) ) );
		}
		if ( false !== strpos( $q, 'wp_postmeta' ) ) {
			$ids = array_slice( $a, 1 );
			return array_values( array_filter( $this->links, fn( $pid ) => in_array( $pid, $ids, true ) ) );
		}
		throw new Exception( 'unexpected get_col: ' . $q );
	}
	function insert( $t, $row ) { $this->insert_id = count( $this->rows ) + 1; $row['id'] = $this->insert_id; $row += array( 'api_note' => '', 'api_checked_at' => null ); $this->rows[ $this->insert_id ] = $row; return 1; }
	function update( $t, $data, $where ) { $this->rows[ $where['id'] ] = array_merge( $this->rows[ $where['id'] ], $data ); return 1; }
}
$wpdb = new Fake_WPDB();
$GLOBALS['wpdb'] = $wpdb;

class GSUP_Install { static function table() { return 'wp_gsup_import'; } }
class GSUP_AliExpress {
	static $calls = array(), $listings = array();
	static function is_connected() { return true; }
	static function default_ship_to() { return 'AU'; }
	static function get_products( array $pairs ) { self::$calls[] = $pairs; $out = array(); foreach ( $pairs as $p ) { $out[ $p[0] . '|' . $p[1] ] = self::$listings[ $p[0] ] ?? new WP_Error( 'gsup_ae', 'Product not found.' ); } return $out; }
	static function find_sku( $p, $id ) { foreach ( $p['skus'] as $s ) { if ( $s['sku_id'] === (string) $id ) { return $s; } } return null; }
}
require __DIR__ . '/../givsen-supplier/includes/class-gsup-import.php';

$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }
function item( $id, $title = 'Pearl necklace', $price = '12.50' ) { return array( 'product_id' => $id, 'title' => $title, 'image' => 'https://ae01.alicdn.com/kf/x.jpg', 'price' => $price, 'currency' => '' === $price ? '' : 'AUD' ); }
function sku( $id, $ship, $price, $stock = 5 ) { return array( 'sku_id' => $id, 'option' => 'Colour: White', 'ship_from' => $ship, 'price' => $price, 'currency' => 'AUD', 'stock' => $stock ); }

// Already in the store (linked product) and already waiting in the import list.
$wpdb->links = array( '1005001000000003' );
$wpdb->insert( 't', array( 'ae_product_id' => '1005001000000004', 'ae_sku_id' => '12000', 'status' => 'new', 'source' => 'extension', 'title' => 'Old', 'price' => '', 'ship_from' => 'AU' ) );
$wpdb->insert( 't', array( 'ae_product_id' => '1005001000000005', 'ae_sku_id' => '', 'status' => 'dismissed', 'source' => 'bulk', 'title' => 'Dismissed', 'price' => '', 'ship_from' => '' ) );

// Statuses for the extension's labels.
$st = GSUP_Import::statuses( array( '1005001000000001', '1005001000000003', '1005001000000004', '1005001000000005', 'junk' ) );
ok( array( '1005001000000001' => '', '1005001000000003' => 'store', '1005001000000004' => 'import', '1005001000000005' => '' ) === $st, 'status: in store / in import list / dismissed counts as neither / junk dropped' );
$wpdb->queries = 0;
GSUP_Import::statuses( array_map( fn( $i ) => (string) ( 1005001000000100 + $i ), range( 1, 60 ) ) );
ok( 2 === $wpdb->queries, 'status: two queries for 60 products' );

// Batch: new, in-batch duplicate, in store, in import list, dismissed (re-added), invalid.
$wpdb->queries = 0;
$r = GSUP_Import::add_batch(
	array( item( '1005001000000001' ), item( '1005001000000002', 'Silver ring', '' ), item( '1005001000000001' ), item( '1005001000000003' ), item( '1005001000000004' ), item( '1005001000000005' ), item( 'not-a-product' ), 'garbage' ),
	array( 15, 99 )
);
ok( array( '1005001000000001', '1005001000000002', '1005001000000005' ) === $r['added'], 'batch: new and dismissed products added' );
ok( array( '1005001000000001', '1005001000000003', '1005001000000004' ) === $r['already'], 'batch: duplicate in batch, in store and in import list skipped as "already there"' );
ok( 2 === count( $r['failed'] ) && 'not-a-product' === $r['failed'][0]['product_id'] && '' === $r['failed'][1]['product_id'], 'batch: invalid IDs reported as failed' );
$new = $wpdb->rows[ $r['row_ids'][0] ];
ok( '' === $new['ae_sku_id'] && 'bulk' === $new['source'] && 'new' === $new['status'] && '15' === $new['category_ids'] && 'Pearl necklace' === $new['title'], 'batch: row has no option, source bulk, only real categories kept' );
ok( 'new' === $wpdb->rows[2]['status'], 'batch: dismissed row brought back rather than duplicated' );
ok( 4 === count( $wpdb->rows ), 'batch: exactly one new row per new product (4 rows total)' );
ok( 1 === count( $GLOBALS['queued'] ) && 'gsup_enrich_rows' === $GLOBALS['queued'][0][0] && $r['row_ids'] === $GLOBALS['queued'][0][1][0] && 'givsen-supplier' === $GLOBALS['queued'][0][2], 'batch: AliExpress check queued in the background with the new row IDs' );
ok( array() === GSUP_AliExpress::$calls, 'batch: no AliExpress calls while adding' );

// Same batch again: everything is already there, nothing queued.
$GLOBALS['queued'] = array();
$r2 = GSUP_Import::add_batch( array( item( '1005001000000001' ), item( '1005001000000002' ) ) );
ok( array() === $r2['added'] && 2 === count( $r2['already'] ) && array() === $GLOBALS['queued'], 'second send: both already there, no job queued' );

// Over the limit: only the first 30 are looked at.
$many = array_map( fn( $i ) => item( (string) ( 1005002000000000 + $i ) ), range( 1, 35 ) );
$r3   = GSUP_Import::add_batch( $many );
ok( 30 === count( $r3['added'] ), 'batch: at most 30 at a time' );

// Background check: many-option listing, single-option listing, not found — one parallel batch of calls.
GSUP_AliExpress::$calls    = array();
GSUP_AliExpress::$listings = array(
	'1005001000000001' => array( 'title' => 'Freshwater Pearl Necklace', 'image' => 'https://ae01.alicdn.com/kf/p.jpg', 'on_sale' => true, 'status' => 'onSelling', 'skus' => array( sku( '1', 'AU', '11.20' ), sku( '2', 'AU', '12.00' ), sku( '3', 'CN', '6.00' ) ) ),
	'1005001000000002' => array( 'title' => 'Sterling Ring', 'image' => '', 'on_sale' => true, 'status' => 'onSelling', 'skus' => array( sku( '77', 'AU', '8.40' ) ) ),
);
$ids = $r['row_ids'];
$n   = GSUP_Import::enrich_many( $ids );
ok( 3 === $n && 1 === count( GSUP_AliExpress::$calls ) && 3 === count( GSUP_AliExpress::$calls[0] ), 'background: 3 rows checked in one parallel batch' );
$a = $wpdb->rows[ $ids[0] ];
ok( 'Freshwater Pearl Necklace' === $a['title'] && '' === $a['ae_sku_id'] && false !== strpos( $a['api_note'], '3 options — choose the warehouse and options on Add to store' ), 'background: many options — title updated, option left for Add to store' );
ok( '12.50' === $a['price'] && 'AUD' === $a['currency'], 'background: price from the card kept when it had one' );
$b = $wpdb->rows[ $ids[1] ];
ok( '77' === $b['ae_sku_id'] && 'AU' === $b['ship_from'] && '8.40' === $b['price'] && 'Checked with AliExpress.' === $b['api_note'], 'background: single option picked with warehouse and price' );
$c = $wpdb->rows[2];
ok( 'Product not found.' === $c['api_note'] && null !== $c['api_checked_at'], 'background: failure recorded on the row, never blocks' );
$wpdb->rows[ $ids[0] ]['price'] = '';
GSUP_Import::enrich_many( array( $ids[0] ) );
ok( '11.20' === $wpdb->rows[ $ids[0] ]['price'], 'background: empty card price filled from the first option' );

echo $fail ? "$fail FAILED\n" : "ALL PASSED\n";
exit( $fail ? 1 : 0 );

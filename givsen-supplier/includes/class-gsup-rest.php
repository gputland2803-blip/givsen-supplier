<?php
/**
 * Signed REST endpoints used by the Givsen Supplier Chrome extension.
 *
 * Every request carries:
 *   X-Gsup-Timestamp: unix seconds
 *   X-Gsup-Signature: hex HMAC-SHA256 of "<timestamp>.<raw body>" using the connection key
 * Requests older than 5 minutes are refused.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_REST {

	const NS     = 'givsen-supplier/v1';
	const MAXAGE = 300;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'ping' ),
				'permission_callback' => array( __CLASS__, 'verify' ),
			)
		);
		register_rest_route(
			self::NS,
			'/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'import' ),
				'permission_callback' => array( __CLASS__, 'verify' ),
			)
		);
		register_rest_route(
			self::NS,
			'/categories',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'categories' ),
				'permission_callback' => array( __CLASS__, 'verify' ),
			)
		);
		register_rest_route(
			self::NS,
			'/linked-products',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'linked_products' ),
				'permission_callback' => array( __CLASS__, 'verify' ),
			)
		);
		register_rest_route(
			self::NS,
			'/import-batch',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'import_batch' ),
				'permission_callback' => array( __CLASS__, 'verify' ),
			)
		);
		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'verify' ),
			)
		);
		register_rest_route(
			self::NS,
			'/backup',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'backup' ),
				'permission_callback' => array( __CLASS__, 'verify' ),
			)
		);
		// AliExpress sends you back here after you approve the connection. Protected by a one-time state code.
		register_rest_route(
			self::NS,
			'/ae-callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'ae_callback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function ae_callback( WP_REST_Request $request ) {
		$state = (string) $request->get_param( 'state' );
		$code  = (string) $request->get_param( 'code' );
		if ( ! GSUP_AliExpress::check_state( $state ) ) {
			$result = 'bad_state';
		} elseif ( '' === trim( $code ) ) {
			$result = 'denied';
		} else {
			$token = GSUP_AliExpress::exchange_code( $code );
			if ( is_wp_error( $token ) ) {
				set_transient( 'gsup_ae_connect_error', $token->get_error_message(), 10 * MINUTE_IN_SECONDS );
				$result = 'error';
			} else {
				$result = 'connected';
			}
		}
		wp_safe_redirect( add_query_arg( 'gsup_ae', $result, gsup_settings_url( 'aliexpress' ) ) );
		exit;
	}

	public static function verify( WP_REST_Request $request ) {
		$secret = (string) get_option( 'gsup_secret' );
		if ( '' === $secret ) {
			return new WP_Error( 'gsup_no_key', 'This site has no connection key yet. Open WooCommerce → Givsen Supplier → Settings.', array( 'status' => 500 ) );
		}
		$ts  = (string) $request->get_header( 'x-gsup-timestamp' );
		$sig = strtolower( (string) $request->get_header( 'x-gsup-signature' ) );
		if ( '' === $ts || '' === $sig || ! ctype_digit( $ts ) ) {
			return new WP_Error( 'gsup_unsigned', 'Request is not signed.', array( 'status' => 401 ) );
		}
		if ( abs( time() - (int) $ts ) > self::MAXAGE ) {
			return new WP_Error( 'gsup_expired', 'Request expired. Check that your computer’s clock is correct.', array( 'status' => 401 ) );
		}
		// Version 2 (extension 0.4.1+) also signs the method and endpoint, so a request can't be replayed
		// against a different endpoint. Version 1 (older extensions) is still accepted for these endpoints;
		// any endpoint added later requires version 2.
		$route  = '/' . ltrim( (string) preg_replace( '#^/?' . preg_quote( self::NS, '#' ) . '#', '', $request->get_route() ), '/' );
		$v2     = '2' === (string) $request->get_header( 'x-gsup-sig-version' );
		$legacy = in_array( $route, array( '/ping', '/import', '/categories' ), true );
		$signed = $v2 ? $ts . '.' . strtoupper( $request->get_method() ) . '.' . $route . '.' . $request->get_body() : $ts . '.' . $request->get_body();
		if ( ! $v2 && ! $legacy ) {
			return new WP_Error( 'gsup_old_extension', 'Update the Givsen Supplier Chrome extension to use this.', array( 'status' => 401 ) );
		}
		if ( ! hash_equals( hash_hmac( 'sha256', $signed, $secret ), $sig ) ) {
			return new WP_Error( 'gsup_bad_key', 'Connection key doesn’t match. Copy it again from WooCommerce → Givsen Supplier → Settings.', array( 'status' => 401 ) );
		}
		// Each signed request works once (kept for as long as its timestamp is valid).
		$seen = 'gsup_sig_' . substr( $sig, 0, 40 );
		if ( get_transient( $seen ) ) {
			return new WP_Error( 'gsup_replayed', 'This request was already used. Try again.', array( 'status' => 401 ) );
		}
		set_transient( $seen, 1, 2 * self::MAXAGE );
		return true;
	}

	public static function ping() {
		return rest_ensure_response(
			array(
				'ok'      => true,
				'site'    => get_bloginfo( 'name' ),
				'version' => GSUP_VERSION,
			)
		);
	}

	public static function categories() {
		$list = array();
		foreach ( gsup_category_tree() as $c ) {
			$list[] = array(
				'id'    => $c['id'],
				'name'  => $c['path'],
				'depth' => $c['depth'],
			);
		}
		return rest_ensure_response(
			array(
				'ok'         => true,
				'categories' => $list,
			)
		);
	}

	/** Bulk import from search results / store pages: rows with no option yet, checked in the background. */
	public static function import_batch( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) || empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return new WP_Error( 'gsup_bad_json', 'No products sent.', array( 'status' => 400 ) );
		}
		if ( count( $data['items'] ) > GSUP_Import::BATCH_MAX ) {
			return new WP_Error( 'gsup_too_many', 'At most ' . GSUP_Import::BATCH_MAX . ' products at a time.', array( 'status' => 400 ) );
		}
		$r = GSUP_Import::add_batch( $data['items'], isset( $data['category_ids'] ) ? (array) $data['category_ids'] : array() );
		return rest_ensure_response(
			array(
				'ok'          => true,
				'added'       => count( $r['added'] ),
				'already'     => count( $r['already'] ),
				'failed'      => $r['failed'],
				'import_list' => gsup_admin_url(),
			)
		);
	}

	/** Which of these AliExpress products are already in the store or the import list. */
	public static function status( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		$ids  = is_array( $data ) && isset( $data['product_ids'] ) && is_array( $data['product_ids'] ) ? array_map( 'strval', array_filter( $data['product_ids'], 'is_scalar' ) ) : array();
		return rest_ensure_response(
			array(
				'ok'       => true,
				'statuses' => (object) GSUP_Import::statuses( $ids ),
			)
		);
	}

	/** Linked store products matching a search, for "Use as backup for a product in your store". */
	public static function linked_products( WP_REST_Request $request ) {
		$search = mb_substr( sanitize_text_field( (string) $request->get_param( 'search' ) ), 0, 100 );
		return rest_ensure_response(
			array(
				'ok'       => true,
				'products' => GSUP_Remap::linked_products( $search ),
			)
		);
	}

	/** Save this page's listing as a store product's backup supplier. Never switches supplier or changes prices. */
	public static function backup( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'gsup_bad_json', 'Expected JSON.', array( 'status' => 400 ) );
		}
		$wc_id = isset( $data['wc_product_id'] ) ? absint( $data['wc_product_id'] ) : 0;
		$ae    = gsup_parse_product_id( isset( $data['product_id'] ) ? (string) $data['product_id'] : '' );
		$ship  = gsup_sanitize_ship_from( isset( $data['ship_from'] ) ? (string) $data['ship_from'] : '' );
		if ( ! $wc_id || '' === $ae ) {
			return new WP_Error( 'gsup_bad_request', 'Choose a product in your store first.', array( 'status' => 400 ) );
		}
		if ( ! GSUP_AliExpress::is_connected() ) {
			return new WP_Error( 'gsup_ae_not_connected', 'Connect AliExpress in the plugin’s settings first — the listing’s options are read from AliExpress.', array( 'status' => 400 ) );
		}
		$listing = GSUP_AliExpress::get_product( $ae, gsup_quote_country( $ship ) );
		if ( is_wp_error( $listing ) ) {
			return new WP_Error( $listing->get_error_code(), $listing->get_error_message(), array( 'status' => 400 ) );
		}
		$result = GSUP_Remap::save_backup_from_listing( $wc_id, $listing, $ship, ! empty( $data['replace'] ) );
		if ( is_wp_error( $result ) ) {
			$extra = $result->get_error_data();
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array_merge( array( 'status' => 409 ), is_array( $extra ) ? $extra : array() ) );
		}
		return rest_ensure_response( array_merge( array( 'ok' => true ), $result ) );
	}

	public static function import( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'gsup_bad_json', 'Expected JSON.', array( 'status' => 400 ) );
		}
		$result = GSUP_Import::add( $data, 'extension' );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		if ( GSUP_AliExpress::is_connected() ) {
			GSUP_Import::enrich( $result['id'] );
		}
		$row    = GSUP_Import::get( $result['id'] );
		$linked = array();
		foreach ( gsup_find_linked( $row['ae_product_id'], $row['ae_sku_id'] ) as $wc_id ) {
			$linked[] = array(
				'id'       => $wc_id,
				'name'     => gsup_product_label( $wc_id ),
				'edit_url' => gsup_product_edit_url( $wc_id ),
			);
		}
		return rest_ensure_response(
			array(
				'ok'          => true,
				'id'          => $result['id'],
				'duplicate'   => $result['duplicate'],
				'status'      => $result['status'],
				'product_id'  => $row['ae_product_id'],
				'sku_id'      => $row['ae_sku_id'],
				'ship_from'   => $row['ship_from'],
				'option'      => $row['option_label'],
				'title'       => $row['title'],
				'api_note'    => $row['api_note'],
				'categories'  => gsup_category_names( $row['category_ids'] ),
				'linked'      => $linked,
				'import_list' => gsup_admin_url(),
			)
		);
	}
}

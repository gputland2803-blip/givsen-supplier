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
			'/reviews',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'reviews' ),
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
		$expected = hash_hmac( 'sha256', $ts . '.' . $request->get_body(), $secret );
		if ( ! hash_equals( $expected, $sig ) ) {
			return new WP_Error( 'gsup_bad_key', 'Connection key doesn’t match. Copy it again from WooCommerce → Givsen Supplier → Settings.', array( 'status' => 401 ) );
		}
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

	public static function reviews( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) || empty( $data['reviews'] ) || ! is_array( $data['reviews'] ) ) {
			return new WP_Error( 'gsup_bad_json', 'No reviews sent.', array( 'status' => 400 ) );
		}
		$result = GSUP_Reviews::import( isset( $data['product_id'] ) ? (string) $data['product_id'] : '', $data['reviews'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
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

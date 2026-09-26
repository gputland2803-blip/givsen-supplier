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

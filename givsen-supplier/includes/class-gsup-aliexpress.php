<?php
/**
 * AliExpress Open Platform (Dropshipping API) — connection, signing, tokens, product lookup.
 *
 * Signing (HMAC-SHA256, upper-case hex): sort all parameters by name, concatenate name+value,
 * prefix the API path for REST endpoints (/auth/token/create), sign with the App Secret.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_AliExpress {

	const GATEWAY       = 'https://api-sg.aliexpress.com';
	const OPT_KEY       = 'gsup_ae_app_key';
	const OPT_SECRET    = 'gsup_ae_app_secret';
	const OPT_TOKEN     = 'gsup_ae_token';
	const STATE_PREFIX  = 'gsup_ae_state_';
	const REFRESH_AHEAD = 3 * DAY_IN_SECONDS; // At most; short-lived connections renew at a quarter of their life.

	public static function gateway() {
		return untrailingslashit( (string) apply_filters( 'gsup_ae_gateway', self::GATEWAY ) );
	}

	public static function app_key() {
		return trim( (string) get_option( self::OPT_KEY, '' ) );
	}

	private static function app_secret() {
		return trim( (string) get_option( self::OPT_SECRET, '' ) );
	}

	public static function has_app() {
		return '' !== self::app_key() && '' !== self::app_secret();
	}

	public static function token() {
		$t = get_option( self::OPT_TOKEN );
		return is_array( $t ) && ! empty( $t['access_token'] ) ? $t : null;
	}

	public static function is_connected() {
		return self::has_app() && null !== self::token();
	}

	public static function callback_url() {
		return rest_url( GSUP_REST::NS . '/ae-callback' );
	}

	/* ------------------------------------------------------------ signing */

	public static function sign( $secret, $api, array $params ) {
		ksort( $params, SORT_STRING );
		$base = ( false !== strpos( $api, '/' ) ) ? $api : '';
		foreach ( $params as $name => $value ) {
			if ( 'sign' === $name || null === $value ) {
				continue;
			}
			$base .= $name . $value;
		}
		return strtoupper( hash_hmac( 'sha256', $base, $secret ) );
	}

	/**
	 * Call an API. $api is a method name (aliexpress.ds.product.get) or a REST path (/auth/token/create).
	 *
	 * @return array|WP_Error Decoded response (large IDs kept as strings).
	 */
	public static function request( $api, array $params = array(), $with_session = true ) {
		if ( ! self::has_app() ) {
			return new WP_Error( 'gsup_ae_no_app', 'Add your AliExpress App Key and App Secret in WooCommerce → Givsen Supplier → Settings.' );
		}
		$is_rest = 0 === strpos( $api, '/' );
		$all     = array();
		foreach ( $params as $k => $v ) {
			if ( null !== $v ) {
				$all[ $k ] = is_bool( $v ) ? ( $v ? 'true' : 'false' ) : (string) $v;
			}
		}
		$all['app_key']     = self::app_key();
		$all['timestamp']   = (string) (int) round( microtime( true ) * 1000 );
		$all['sign_method'] = 'sha256';
		if ( $with_session ) {
			$token = self::access_token();
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$all['session'] = $token;
		}
		if ( ! $is_rest ) {
			$all['method']   = $api;
			$all['format']   = 'json';
			$all['v']        = '2.0';
			$all['simplify'] = 'true';
		}
		$all['sign'] = self::sign( self::app_secret(), $api, $all );
		ksort( $all, SORT_STRING );

		$url      = self::gateway() . ( $is_rest ? '/rest' . $api : '/sync' ) . '?' . http_build_query( $all, '', '&', PHP_QUERY_RFC3986 );
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 25,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded;charset=utf-8' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'gsup_ae_network', 'Couldn’t reach AliExpress: ' . $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true, 512, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'gsup_ae_bad_response', 'AliExpress sent an unreadable reply (HTTP ' . (int) $code . ').' );
		}
		if ( isset( $data['error_response'] ) ) {
			$e = $data['error_response'];
			return self::api_error( $e );
		}
		// REST endpoints report errors at the top level with a non-zero code.
		if ( $is_rest && isset( $data['code'] ) && '0' !== (string) $data['code'] && empty( $data['access_token'] ) ) {
			return self::api_error( $data );
		}
		return $data;
	}

	private static function api_error( array $e ) {
		$msg = '';
		foreach ( array( 'sub_msg', 'msg', 'message' ) as $k ) {
			if ( ! empty( $e[ $k ] ) ) {
				$msg = (string) $e[ $k ];
				break;
			}
		}
		$code = isset( $e['sub_code'] ) ? $e['sub_code'] : ( isset( $e['code'] ) ? $e['code'] : '' );
		if ( '' === $msg ) {
			$msg = 'AliExpress returned an error';
		}
		$friendly = self::friendly_error( (string) $code, $msg );
		return new WP_Error( 'gsup_ae_api', $friendly, array( 'ae_code' => (string) $code, 'ae_msg' => $msg ) );
	}

	private static function friendly_error( $code, $msg ) {
		$c = strtolower( $code . ' ' . $msg );
		if ( false !== strpos( $c, 'illegalaccesstoken' ) || ( false !== strpos( $c, 'session' ) && false !== strpos( $c, 'expire' ) ) ) {
			return 'Your AliExpress connection has expired. Click “Reconnect AliExpress” in Settings.';
		}
		if ( false !== strpos( $c, 'incompletesignature' ) || false !== strpos( $c, 'signature' ) ) {
			return 'AliExpress rejected the signature — check the App Secret in Settings. (' . $msg . ')';
		}
		if ( false !== strpos( $c, 'appkey' ) || false !== strpos( $c, 'app key' ) ) {
			return 'AliExpress didn’t accept the App Key — check it in Settings. (' . $msg . ')';
		}
		if ( false !== strpos( $c, 'insufficientisvpermissions' ) || false !== strpos( $c, 'permission' ) ) {
			return 'Your AliExpress app isn’t allowed to use this feature yet. (' . $msg . ')';
		}
		return $msg . ( $code ? ' (' . $code . ')' : '' );
	}

	/* --------------------------------------------------------------- OAuth */

	/** Where to send you to approve the connection on AliExpress. */
	public static function authorize_url() {
		$state = wp_generate_password( 32, false, false );
		set_transient( self::STATE_PREFIX . $state, get_current_user_id(), 15 * MINUTE_IN_SECONDS );
		return self::gateway() . '/oauth/authorize?' . http_build_query(
			array(
				'response_type' => 'code',
				'force_auth'    => 'true',
				'redirect_uri'  => self::callback_url(),
				'client_id'     => self::app_key(),
				'state'         => $state,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	public static function check_state( $state ) {
		$state = preg_replace( '/[^A-Za-z0-9]/', '', (string) $state );
		if ( '' === $state || false === get_transient( self::STATE_PREFIX . $state ) ) {
			return false;
		}
		delete_transient( self::STATE_PREFIX . $state );
		return true;
	}

	public static function exchange_code( $code ) {
		$data = self::request( '/auth/token/create', array( 'code' => (string) $code ), false );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return self::save_token( $data );
	}

	public static function refresh() {
		$t = self::token();
		if ( ! $t || empty( $t['refresh_token'] ) ) {
			return new WP_Error( 'gsup_ae_no_refresh', 'Your AliExpress connection has expired. Click “Reconnect AliExpress” in Settings.' );
		}
		$data = self::request( '/auth/token/refresh', array( 'refresh_token' => $t['refresh_token'] ), false );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return self::save_token( $data );
	}

	private static function ts( $ms_or_s, $fallback ) {
		$n = (float) $ms_or_s;
		if ( $n <= 0 ) {
			return $fallback;
		}
		return (int) ( $n > 9999999999 ? $n / 1000 : $n );
	}

	private static function save_token( array $d ) {
		if ( empty( $d['access_token'] ) ) {
			return new WP_Error( 'gsup_ae_no_token', 'AliExpress didn’t return an access token.' );
		}
		$now   = time();
		$exp   = isset( $d['expire_time'] ) ? self::ts( $d['expire_time'], 0 ) : 0;
		$exp   = $exp ? $exp : ( isset( $d['expires_in'] ) ? $now + (int) $d['expires_in'] : $now + DAY_IN_SECONDS );
		$r_exp = isset( $d['refresh_token_valid_time'] ) ? self::ts( $d['refresh_token_valid_time'], 0 ) : 0;
		$r_exp = $r_exp ? $r_exp : ( isset( $d['refresh_expires_in'] ) ? $now + (int) $d['refresh_expires_in'] : 0 );
		$token = array(
			'access_token'       => (string) $d['access_token'],
			'refresh_token'      => isset( $d['refresh_token'] ) ? (string) $d['refresh_token'] : '',
			'expires_at'         => $exp,
			'refresh_expires_at' => $r_exp,
			'account'            => (string) ( $d['user_nick'] ?? ( $d['account'] ?? ( $d['user_id'] ?? '' ) ) ),
			'connected_at'       => $now,
			'issued_at'          => $now,
		);
		$old = self::token();
		if ( $old && ! empty( $old['connected_at'] ) ) {
			$token['connected_at'] = (int) $old['connected_at'];
		}
		update_option( self::OPT_TOKEN, $token, false );
		return $token;
	}

	/** Background renewal (twice a day) so the connection never lapses on quiet days. */
	public static function keep_alive() {
		$t = self::token();
		if ( ! $t || ! self::has_app() || empty( $t['refresh_token'] ) ) {
			return;
		}
		if ( $t['expires_at'] - time() < 18 * HOUR_IN_SECONDS ) {
			self::refresh();
		}
	}

	public static function disconnect() {
		delete_option( self::OPT_TOKEN );
	}

	/** A usable access token, refreshing it a few days before it runs out. */
	public static function access_token() {
		$t = self::token();
		if ( ! $t ) {
			return new WP_Error( 'gsup_ae_not_connected', 'AliExpress isn’t connected yet. Click “Connect AliExpress” in WooCommerce → Givsen Supplier → Settings.' );
		}
		$now       = time();
		$can_renew = ! empty( $t['refresh_token'] ) && ( empty( $t['refresh_expires_at'] ) || $t['refresh_expires_at'] > $now );
		$issued   = ! empty( $t['issued_at'] ) ? (int) $t['issued_at'] : (int) ( $t['connected_at'] ?? $now );
		$lifetime = max( HOUR_IN_SECONDS, (int) $t['expires_at'] - $issued );
		$ahead    = min( self::REFRESH_AHEAD, (int) ( $lifetime / 4 ) );
		if ( $t['expires_at'] - $now < $ahead && $can_renew ) {
			$new = self::refresh();
			if ( ! is_wp_error( $new ) ) {
				return $new['access_token'];
			}
		}
		if ( $t['expires_at'] <= $now ) {
			return new WP_Error( 'gsup_ae_expired', 'Your AliExpress connection has expired. Click “Reconnect AliExpress” in Settings.' );
		}
		return $t['access_token'];
	}

	/* ------------------------------------------------------------- product */

	/** AliExpress sometimes wraps lists: {"x_dtos": {"x_d_t_o": [...]}} — and sometimes a single item isn't in a list. */
	private static function items( $node, $inner ) {
		if ( ! is_array( $node ) ) {
			return array();
		}
		if ( isset( $node[ $inner ] ) ) {
			$node = $node[ $inner ];
		}
		if ( ! is_array( $node ) ) {
			return array();
		}
		if ( ! array_key_exists( 0, $node ) ) {
			$node = array( $node );
		}
		return array_values( array_filter( $node, 'is_array' ) );
	}

	public static function default_ship_to() {
		$base = function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : array( 'country' => 'AU' );
		return ! empty( $base['country'] ) ? $base['country'] : 'AU';
	}

	/**
	 * Product details, normalised.
	 *
	 * @return array|WP_Error {product_id, title, image, currency, status, on_sale, skus: [{sku_id, option, ship_from, price, stock}]}
	 */
	public static function get_product( $ae_product_id, $ship_to = '' ) {
		$ae_product_id = gsup_parse_product_id( $ae_product_id );
		if ( '' === $ae_product_id ) {
			return new WP_Error( 'gsup_ae_bad_id', 'That isn’t an AliExpress product ID.' );
		}
		$ship_to = $ship_to ? strtoupper( $ship_to ) : self::default_ship_to();
		$data    = self::request(
			'aliexpress.ds.product.get',
			array(
				'product_id'      => $ae_product_id,
				'ship_to_country' => $ship_to,
				'target_currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'AUD',
				'target_language' => 'EN',
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$wrap = isset( $data['aliexpress_ds_product_get_response'] ) ? $data['aliexpress_ds_product_get_response'] : $data;
		if ( isset( $wrap['rsp_code'] ) && '200' !== (string) $wrap['rsp_code'] ) {
			$msg = isset( $wrap['rsp_msg'] ) ? (string) $wrap['rsp_msg'] : 'unknown error';
			return new WP_Error( 'gsup_ae_product', 'AliExpress couldn’t return product ' . $ae_product_id . ': ' . $msg, array( 'ae_code' => (string) $wrap['rsp_code'], 'ae_msg' => $msg ) );
		}
		$result = isset( $wrap['result'] ) && is_array( $wrap['result'] ) ? $wrap['result'] : null;
		if ( ! $result ) {
			return new WP_Error( 'gsup_ae_not_found', 'AliExpress returned no details for product ' . $ae_product_id . ' — it may have been removed.' );
		}

		$base   = isset( $result['ae_item_base_info_dto'] ) ? $result['ae_item_base_info_dto'] : array();
		$media  = isset( $result['ae_multimedia_info_dto'] ) ? $result['ae_multimedia_info_dto'] : array();
		$images = array();
		foreach ( explode( ';', (string) ( $media['image_urls'] ?? '' ) ) as $u ) {
			$u = trim( $u );
			if ( 0 === strpos( $u, '//' ) ) {
				$u = 'https:' . $u;
			}
			if ( '' !== $u ) {
				$images[] = $u;
			}
		}
		$status = (string) ( $base['product_status_type'] ?? '' );

		$skus = array();
		foreach ( self::items( $result['ae_item_sku_info_dtos'] ?? null, 'ae_item_sku_info_d_t_o' ) as $s ) {
			$parts = array();
			$props = array();
			$ship  = '';
			foreach ( self::items( $s['ae_sku_property_dtos'] ?? null, 'ae_sku_property_d_t_o' ) as $p ) {
				$name  = trim( (string) ( $p['sku_property_name'] ?? '' ) );
				$value = trim( (string) ( $p['property_value_definition_name'] ?? '' ) );
				if ( '' === $value || '0' === $value ) {
					$value = trim( (string) ( $p['sku_property_value'] ?? '' ) );
				}
				if ( '' === $name ) {
					continue;
				}
				$parts[] = $name . ': ' . $value;
				$is_ship = (bool) preg_match( '/ships?\s*from/i', $name );
				if ( $is_ship ) {
					$ship = gsup_sanitize_ship_from( $value );
				}
				$img     = trim( (string) ( $p['sku_image'] ?? '' ) );
				$props[] = array(
					'name'    => $name,
					'value'   => $value,
					'image'   => ( '' !== $img && '0' !== $img ) ? ( 0 === strpos( $img, '//' ) ? 'https:' . $img : $img ) : '',
					'is_ship' => $is_ship,
				);
			}
			$price = (string) ( $s['offer_sale_price'] ?? '' );
			if ( '' === $price || (float) $price <= 0 ) {
				$price = (string) ( $s['sku_price'] ?? '' );
			}
			if ( isset( $s['sku_available_stock'] ) && is_numeric( $s['sku_available_stock'] ) ) {
				$stock = (int) $s['sku_available_stock'];
			} elseif ( isset( $s['ipm_sku_stock'] ) && is_numeric( $s['ipm_sku_stock'] ) ) {
				$stock = (int) $s['ipm_sku_stock'];
			} else {
				$stock = ! empty( $s['sku_stock'] ) ? null : 0; // null = in stock, amount unknown.
			}
			$sku_id = (string) ( $s['sku_id'] ?? '' );
			if ( '' === $sku_id ) {
				continue;
			}
			$skus[] = array(
				'sku_id'    => $sku_id,
				'option'    => implode( ' · ', $parts ),
				'ship_from' => $ship,
				'price'     => $price,
				'currency'  => (string) ( $s['currency_code'] ?? ( $base['currency_code'] ?? '' ) ),
				'stock'     => $stock,
				'props'     => $props,
			);
		}

		return array(
			'product_id' => $ae_product_id,
			'title'      => (string) ( $base['subject'] ?? '' ),
			'image'      => isset( $images[0] ) ? $images[0] : '',
			'images'     => $images,
			'description' => (string) ( $base['detail'] ?? '' ),
			'currency'   => (string) ( $base['currency_code'] ?? '' ),
			'status'     => $status,
			'on_sale'    => '' === $status || 'onSelling' === $status,
			'ship_to'    => $ship_to,
			'skus'       => $skus,
		);
	}

	/** Find one SKU in a normalised product. */
	public static function find_sku( array $product, $sku_id ) {
		foreach ( $product['skus'] as $sku ) {
			if ( (string) $sku['sku_id'] === (string) $sku_id ) {
				return $sku;
			}
		}
		return null;
	}
}

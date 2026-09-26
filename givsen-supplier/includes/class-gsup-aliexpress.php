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

	/** Calls to add to the diagnostics log, saved once at the end of the request. */
	private static $log_queue = array();

	/** Keep a diagnostics log of the last calls (Settings → AliExpress connection). On by default. */
	public static function logging() {
		return 'no' !== get_option( 'gsup_ae_log', 'yes' );
	}

	/** Parameters safe to keep: no tokens or signatures, and no customer details from orders. */
	private static function loggable_params( array $params ) {
		foreach ( array( 'session', 'sign', 'app_key', 'access_token', 'refresh_token', 'code' ) as $k ) {
			unset( $params[ $k ] );
		}
		if ( isset( $params['param_place_order_request4_open_api_d_t_o'] ) ) {
			$d = json_decode( (string) $params['param_place_order_request4_open_api_d_t_o'], true );
			$params['param_place_order_request4_open_api_d_t_o'] = array(
				'product_items'   => isset( $d['product_items'] ) ? $d['product_items'] : null,
				'ship_to_country' => isset( $d['logistics_address']['country'] ) ? $d['logistics_address']['country'] : null,
				'address'         => '[hidden]',
			);
		}
		return $params;
	}

	private static function log( $api, array $params, $code, $body, $result, $started ) {
		if ( ! self::logging() || 0 === strpos( $api, '/auth/' ) ) {
			return; // Sign-in exchanges carry secrets; never logged.
		}
		if ( ! self::$log_queue ) {
			add_action( 'shutdown', array( __CLASS__, 'save_log' ) );
		}
		self::$log_queue[] = array(
			'at'     => time(),
			'api'    => $api,
			'ms'     => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'params' => self::loggable_params( $params ),
			'http'   => (int) $code,
			'error'  => is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '',
			'reply'  => mb_substr( (string) $body, 0, 6000 ),
		);
	}

	public static function save_log() {
		if ( ! self::$log_queue ) {
			return;
		}
		$log = get_option( 'gsup_ae_log_entries' );
		$log = is_array( $log ) ? $log : array();
		$log = array_slice( array_merge( $log, self::$log_queue ), -30 );
		update_option( 'gsup_ae_log_entries', $log, false );
		self::$log_queue = array();
	}

	/** Whether the last place_order() asked AliExpress to pay (the older order method can't). */
	public static $last_pay_requested = false;

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
	 * The signed URL for a call.
	 *
	 * @return string|WP_Error
	 */
	private static function build_url( $api, array $params, $with_session ) {
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
		return self::gateway() . ( $is_rest ? '/rest' . $api : '/sync' ) . '?' . http_build_query( $all, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Call an API. $api is a method name (aliexpress.ds.product.get) or a REST path (/auth/token/create).
	 *
	 * @return array|WP_Error Decoded response (large IDs kept as strings).
	 */
	public static function request( $api, array $params = array(), $with_session = true ) {
		$url = self::build_url( $api, $params, $with_session );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$started  = microtime( true );
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 25,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded;charset=utf-8' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			$err = new WP_Error( 'gsup_ae_network', 'Couldn’t reach AliExpress: ' . $response->get_error_message() );
			self::log( $api, $params, 0, '', $err, $started );
			return $err;
		}
		$code   = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$result = self::decode( 0 === strpos( $api, '/' ), $code, $body );
		self::log( $api, $params, $code, $body, $result, $started );
		return $result;
	}

	/** How many AliExpress calls to make at once in background jobs (filter gsup_ae_parallel; 1 = one at a time). */
	public static function parallel() {
		return max( 1, min( 10, (int) apply_filters( 'gsup_ae_parallel', 5 ) ) );
	}

	/**
	 * Several API method calls at once (a few in flight at a time), for background jobs.
	 * Falls back to one at a time when parallel requests aren't available.
	 *
	 * @param array<string,array{0:string,1:array}> $calls key => [method, params]
	 * @return array<string,array|WP_Error> key => decoded response or error
	 */
	public static function request_many( array $calls ) {
		$out   = array();
		$multi = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : ( class_exists( 'Requests' ) ? 'Requests' : '' );
		if ( '' === $multi || self::parallel() < 2 || count( $calls ) < 2 ) {
			foreach ( $calls as $key => $call ) {
				$out[ $key ] = self::request( $call[0], $call[1] );
			}
			return $out;
		}
		$options = array(
			'timeout'         => 25,
			'connect_timeout' => 10,
			'verify'          => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			'useragent'       => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
		);
		if ( class_exists( 'WP_HTTP_Proxy' ) ) {
			$proxy = new WP_HTTP_Proxy();
			if ( $proxy->is_enabled() && $proxy->send_through_proxy( self::gateway() ) ) {
				$options['proxy'] = $proxy->use_authentication()
					? array( $proxy->host() . ':' . $proxy->port(), $proxy->username(), $proxy->password() )
					: $proxy->host() . ':' . $proxy->port();
			}
		}
		foreach ( array_chunk( $calls, self::parallel(), true ) as $chunk ) {
			$requests = array();
			foreach ( $chunk as $key => $call ) {
				$url = self::build_url( $call[0], $call[1], true );
				if ( is_wp_error( $url ) ) {
					$out[ $key ] = $url;
					continue;
				}
				$requests[ $key ] = array(
					'url'     => $url,
					'type'    => 'POST',
					'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded;charset=utf-8' ),
					'data'    => array(),
				);
			}
			if ( ! $requests ) {
				continue;
			}
			$started = microtime( true );
			try {
				$responses = call_user_func( array( $multi, 'request_multiple' ), $requests, $options );
			} catch ( Exception $e ) {
				$responses = array();
			}
			foreach ( $requests as $key => $unused ) {
				$r = isset( $responses[ $key ] ) ? $responses[ $key ] : null;
				if ( is_object( $r ) && isset( $r->body ) && isset( $r->status_code ) ) {
					$out[ $key ] = self::decode( false, (int) $r->status_code, (string) $r->body );
					self::log( $chunk[ $key ][0], $chunk[ $key ][1], (int) $r->status_code, (string) $r->body, $out[ $key ], $started );
				} else {
					$msg         = $r instanceof Exception ? $r->getMessage() : 'no reply';
					$out[ $key ] = new WP_Error( 'gsup_ae_network', 'Couldn’t reach AliExpress: ' . $msg );
					self::log( $chunk[ $key ][0], $chunk[ $key ][1], 0, '', $out[ $key ], $started );
				}
			}
		}
		return $out;
	}

	/** Turn a reply into data, or the error AliExpress reported. */
	private static function decode( $is_rest, $code, $body ) {
		$data = json_decode( (string) $body, true, 512, JSON_BIGINT_AS_STRING );
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
		return self::parse_product( self::request( 'aliexpress.ds.product.get', self::product_params( $ae_product_id, $ship_to ) ), $ae_product_id, $ship_to );
	}

	/**
	 * Several products at once (background sync).
	 *
	 * @param array<int,array{0:string,1:string}> $pairs [ae product ID, ship-to country]
	 * @return array<string,array|WP_Error> "id|country" => product or error
	 */
	public static function get_products( array $pairs ) {
		$calls = array();
		foreach ( $pairs as $pair ) {
			$id   = gsup_parse_product_id( $pair[0] );
			$ship = strtoupper( $pair[1] ? $pair[1] : self::default_ship_to() );
			if ( '' !== $id ) {
				$calls[ $id . '|' . $ship ] = array( 'aliexpress.ds.product.get', self::product_params( $id, $ship ) );
			}
		}
		$out = array();
		foreach ( self::request_many( $calls ) as $key => $data ) {
			list( $id, $ship ) = explode( '|', $key );
			$out[ $key ]       = self::parse_product( $data, $id, $ship );
		}
		return $out;
	}

	private static function product_params( $ae_product_id, $ship_to ) {
		return array(
			'product_id'      => $ae_product_id,
			'ship_to_country' => $ship_to,
			'target_currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'AUD',
			'target_language' => 'EN',
		);
	}

	private static function parse_product( $data, $ae_product_id, $ship_to ) {
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
				'sku_attr'  => (string) ( $s['sku_attr'] ?? ( $s['id'] ?? '' ) ), // What AliExpress needs to place an order.
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
			'mobile_description' => (string) ( $base['mobile_detail'] ?? ( $result['ae_item_base_info_dto']['mobile_detail'] ?? '' ) ),
			'specs'      => self::specs_of( $result ),
			'currency'   => (string) ( $base['currency_code'] ?? '' ),
			'status'     => $status,
			'on_sale'    => '' === $status || 'onSelling' === $status,
			'ship_to'    => $ship_to,
			'skus'       => $skus,
		);
	}

	/** Item specifics (Material, Size…) as [[name, value], …]. */
	private static function specs_of( array $result ) {
		$out = array();
		foreach ( self::items( $result['ae_item_properties'] ?? null, 'ae_item_property' ) as $p ) {
			$name  = trim( (string) ( $p['attr_name'] ?? '' ) );
			$value = trim( (string) ( $p['attr_value'] ?? '' ) );
			if ( '' !== $name && '' !== $value ) {
				$out[] = array( $name, $value );
			}
		}
		return $out;
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

	/** First value found under any of these keys. */
	private static function pick( array $a, array $keys, $default = '' ) {
		foreach ( $keys as $k ) {
			if ( isset( $a[ $k ] ) && '' !== $a[ $k ] && null !== $a[ $k ] ) {
				return $a[ $k ];
			}
		}
		return $default;
	}

	/** The "result" node of a method's response, whatever wrapper AliExpress used. */
	private static function result_of( array $data, $response_key ) {
		$wrap = isset( $data[ $response_key ] ) ? $data[ $response_key ] : $data;
		if ( isset( $wrap['result'] ) && is_array( $wrap['result'] ) ) {
			return $wrap['result'];
		}
		return is_array( $wrap ) ? $wrap : array();
	}

	/** A money amount from "12.34", 12.34, "AU $12.34" or {amount: "12.34"}. */
	private static function money( $v ) {
		if ( is_array( $v ) ) {
			$v = self::pick( $v, array( 'amount', 'value', 'cent' ), '' );
		}
		if ( is_numeric( $v ) ) {
			return (float) $v;
		}
		if ( preg_match( '/(\d+(?:[.,]\d+)?)/', str_replace( ',', '', (string) $v ), $m ) ) {
			return (float) $m[1];
		}
		return null;
	}

	/** Errors that mean "this API method isn't available to your app", so an older equivalent is worth trying. */
	private static function method_unavailable( $e ) {
		if ( ! is_wp_error( $e ) || 'gsup_ae_api' !== $e->get_error_code() ) {
			return false;
		}
		$d = $e->get_error_data();
		$c = strtolower( ( is_array( $d ) ? $d['ae_code'] . ' ' . $d['ae_msg'] : '' ) . ' ' . $e->get_error_message() );
		return (bool) preg_match( '/invalidmethod|invalid method|permission|not\s*allowed|api\s*not\s*exist/', $c );
	}

	/* ------------------------------------------------------------ shipping */

	/**
	 * Delivery options for one option of a product to a country, cheapest first.
	 *
	 * @return array|WP_Error [{code, name, fee, currency, min_days, max_days, tracked}]
	 */
	public static function freight( $ae_product_id, $sku_id, $ship_to, $qty = 1 ) {
		$ship_to = strtoupper( (string) $ship_to );
		return self::parse_freight( self::request( 'aliexpress.ds.freight.query', self::freight_params( $ae_product_id, $sku_id, $ship_to, $qty ) ), $ae_product_id, $sku_id, $ship_to, $qty );
	}

	/**
	 * Several delivery quotes at once (background sync).
	 *
	 * @param array<string,array{0:string,1:string,2:string}> $specs key => [ae product ID, SKU ID, ship-to country]
	 * @return array<string,array|WP_Error> key => options (cheapest first) or error
	 */
	public static function freights( array $specs ) {
		$calls = array();
		foreach ( $specs as $key => $f ) {
			$calls[ $key ] = array( 'aliexpress.ds.freight.query', self::freight_params( $f[0], $f[1], strtoupper( $f[2] ), 1 ) );
		}
		$out = array();
		foreach ( self::request_many( $calls ) as $key => $data ) {
			$f           = $specs[ $key ];
			$out[ $key ] = self::parse_freight( $data, $f[0], $f[1], strtoupper( $f[2] ), 1 );
		}
		return $out;
	}

	private static function freight_params( $ae_product_id, $sku_id, $ship_to, $qty ) {
		return array(
			'queryDeliveryReq' => wp_json_encode(
				array(
					'quantity'      => max( 1, (int) $qty ),
					'shipToCountry' => $ship_to,
					'productId'     => (string) $ae_product_id,
					'selectedSkuId' => (string) $sku_id,
					'language'      => 'en_US',
					'currency'      => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'AUD',
					'locale'        => 'en_US',
				)
			),
		);
	}

	private static function parse_freight( $data, $ae_product_id, $sku_id, $ship_to, $qty ) {
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'AUD';
		if ( self::method_unavailable( $data ) ) {
			return self::freight_legacy( $ae_product_id, $sku_id, $ship_to, $qty, $currency );
		}
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$r = self::result_of( $data, 'aliexpress_ds_freight_query_response' );
		if ( isset( $r['success'] ) && ! filter_var( $r['success'], FILTER_VALIDATE_BOOLEAN ) ) {
			$msg = (string) self::pick( $r, array( 'msg', 'message', 'code' ), 'no delivery options' );
			return new WP_Error( 'gsup_ae_freight', 'AliExpress has no delivery to ' . $ship_to . ' for this option: ' . $msg );
		}
		$out = array();
		foreach ( self::items( $r['delivery_options'] ?? null, 'delivery_option_d_t_o' ) as $o ) {
			$free = ! empty( $o['free_shipping'] ) && filter_var( $o['free_shipping'], FILTER_VALIDATE_BOOLEAN );
			$fee  = $free ? 0.0 : self::money( self::pick( $o, array( 'shipping_fee_format', 'shipping_fee_cent', 'shipping_fee' ), '' ) );
			$code = (string) self::pick( $o, array( 'code', 'service_name', 'logistics_service_name' ) );
			if ( '' === $code || null === $fee ) {
				continue;
			}
			$out[] = array(
				'code'     => $code,
				'name'     => (string) self::pick( $o, array( 'company', 'service_name' ), $code ),
				'fee'      => round( $fee, 2 ),
				'currency' => (string) self::pick( $o, array( 'shipping_fee_currency' ), $currency ),
				'min_days' => (int) self::pick( $o, array( 'min_delivery_days' ), 0 ),
				'max_days' => (int) self::pick( $o, array( 'max_delivery_days', 'guaranteed_delivery_days' ), 0 ),
				'tracked'  => ! isset( $o['tracking'] ) || filter_var( $o['tracking'], FILTER_VALIDATE_BOOLEAN ),
			);
		}
		return self::sort_freight( $out, $ship_to );
	}

	/** Older freight method, for apps without aliexpress.ds.freight.query. */
	private static function freight_legacy( $ae_product_id, $sku_id, $ship_to, $qty, $currency ) {
		$data = self::request(
			'aliexpress.logistics.buyer.freight.calculate',
			array(
				'param_aeop_freight_calculate_for_buyer_d_t_o' => wp_json_encode(
					array(
						'country_code'   => $ship_to,
						'product_id'     => (string) $ae_product_id,
						'product_num'    => max( 1, (int) $qty ),
						'sku_id'         => (string) $sku_id,
						'price_currency' => $currency,
					)
				),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$r   = self::result_of( $data, 'aliexpress_logistics_buyer_freight_calculate_response' );
		$out = array();
		$list = $r['aeop_freight_calculate_result_for_buyer_d_t_o_list'] ?? null;
		$list = self::items( $list, isset( $list['aeop_freight_calculate_result_for_buyer_dto'] ) ? 'aeop_freight_calculate_result_for_buyer_dto' : 'aeop_freight_calculate_result_for_buyer_d_t_o' );
		foreach ( $list as $o ) {
			$fee = self::money( $o['freight'] ?? '' );
			if ( empty( $o['service_name'] ) || null === $fee ) {
				continue;
			}
			$days  = array_map( 'intval', explode( '-', (string) ( $o['estimated_delivery_time'] ?? '' ) ) );
			$out[] = array(
				'code'     => (string) $o['service_name'],
				'name'     => (string) $o['service_name'],
				'fee'      => round( $fee, 2 ),
				'currency' => is_array( $o['freight'] ) && ! empty( $o['freight']['currency_code'] ) ? (string) $o['freight']['currency_code'] : $currency,
				'min_days' => $days[0] ?? 0,
				'max_days' => end( $days ),
				'tracked'  => true,
			);
		}
		return self::sort_freight( $out, $ship_to );
	}

	private static function sort_freight( array $out, $ship_to ) {
		if ( ! $out ) {
			return new WP_Error( 'gsup_ae_freight', 'AliExpress has no delivery to ' . $ship_to . ' for this option.' );
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $a['fee'] === $b['fee'] ? $a['max_days'] - $b['max_days'] : ( $a['fee'] < $b['fee'] ? -1 : 1 );
			}
		);
		return $out;
	}

	/**
	 * The delivery option to use, per Settings → Automatic ordering → Shipping method.
	 * 'cheapest_tracked' (default) · 'cheapest' · 'fastest'.
	 */
	public static function choose_freight( array $options ) {
		if ( ! $options ) {
			return null;
		}
		$pref = get_option( 'gsup_ship_pref', 'cheapest_tracked' );
		if ( 'fastest' === $pref ) {
			$best = null;
			foreach ( $options as $o ) {
				$days = $o['max_days'] ? $o['max_days'] : 999;
				if ( null === $best || $days < $best[0] || ( $days === $best[0] && $o['fee'] < $best[1]['fee'] ) ) {
					$best = array( $days, $o );
				}
			}
			return $best[1];
		}
		if ( 'cheapest_tracked' === $pref ) {
			foreach ( $options as $o ) {
				if ( $o['tracked'] ) {
					return $o;
				}
			}
		}
		return $options[0];
	}

	/* -------------------------------------------------------------- orders */

	/**
	 * Place an order on AliExpress.
	 *
	 * @param array $address  logistics_address fields (full_name, contact_person, mobile_no, phone_country, country, province, city, address, address2, zip).
	 * @param array $items    [{product_id, qty, sku_attr, service, memo}]
	 * @param string $out_id  Our reference (order-item), for your records on AliExpress.
	 * @return string[]|WP_Error AliExpress order numbers.
	 */
	public static function place_order( array $address, array $items, $out_id ) {
		$lines = array();
		foreach ( $items as $it ) {
			$lines[] = array(
				'product_id'             => (string) $it['product_id'],
				'product_count'          => (int) $it['qty'],
				'sku_attr'               => (string) $it['sku_attr'],
				'logistics_service_name' => (string) $it['service'],
				'order_memo'             => (string) ( $it['memo'] ?? '' ),
			);
		}
		$request = wp_json_encode(
			array(
				'out_order_id'      => (string) $out_id,
				'logistics_address' => array_merge( array( 'locale' => 'en_US' ), $address ),
				'product_items'     => $lines,
			)
		);
		$params = array( 'param_place_order_request4_open_api_d_t_o' => $request );
		if ( 'no' !== get_option( 'gsup_auto_pay', 'yes' ) ) {
			$params['ds_extend_request'] = wp_json_encode(
				array(
					'payment' => array(
						'pay_currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'AUD',
						'try_to_pay'   => 'true',
					),
				)
			);
		}
		$method = 'aliexpress.ds.order.create';
		$data   = self::request( $method, $params );
		self::$last_pay_requested = isset( $params['ds_extend_request'] );
		if ( self::method_unavailable( $data ) ) {
			unset( $params['ds_extend_request'] );
			self::$last_pay_requested = false;
			$method = 'aliexpress.trade.buy.placeorder';
			$data   = self::request( $method, $params );
		}
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$r  = self::result_of( $data, str_replace( '.', '_', $method ) . '_response' );
		$ok = isset( $r['is_success'] ) ? filter_var( $r['is_success'], FILTER_VALIDATE_BOOLEAN ) : ! empty( $r['order_list'] );
		if ( ! $ok ) {
			$code = (string) self::pick( $r, array( 'error_code', 'code' ), '' );
			$msg  = (string) self::pick( $r, array( 'error_msg', 'msg', 'message' ), 'AliExpress didn’t accept the order' );
			return new WP_Error( 'gsup_ae_order', self::friendly_order_error( $code, $msg ), array( 'ae_code' => $code, 'ae_msg' => $msg ) );
		}
		$ids  = array();
		$list = $r['order_list'] ?? array();
		if ( is_array( $list ) && isset( $list['number'] ) ) {
			$list = $list['number'];
		}
		foreach ( (array) $list as $n ) {
			if ( is_scalar( $n ) && '' !== (string) $n ) {
				$ids[] = (string) $n;
			}
		}
		if ( ! $ids ) {
			return new WP_Error( 'gsup_ae_order_unknown', 'AliExpress accepted the order but didn’t send back an order number. Check your AliExpress orders before trying again.' );
		}
		return $ids;
	}

	private static function friendly_order_error( $code, $msg ) {
		$map = array(
			'ADDRESS'           => 'AliExpress didn’t accept the delivery address',
			'DELIVERY_METHOD'   => 'That shipping method isn’t available for this option any more',
			'INVENTORY'         => 'Not enough stock on AliExpress',
			'SKU'               => 'The option isn’t available on AliExpress any more',
			'PRICE'             => 'AliExpress couldn’t price the order in your currency',
			'BLACKLIST'         => 'AliExpress blocked this account from ordering — check your AliExpress account',
			'PRODUCT_NOT_EXIST' => 'The product isn’t on AliExpress any more',
		);
		foreach ( $map as $needle => $friendly ) {
			if ( false !== stripos( $code, $needle ) ) {
				return $friendly . ' (' . $msg . ')';
			}
		}
		return $msg . ( $code ? ' (' . $code . ')' : '' );
	}

	/**
	 * An AliExpress order's status, amount and tracking.
	 *
	 * @return array|WP_Error {status, logistics_status, amount, currency, tracking: [{number, carrier}]}
	 */
	public static function get_order( $ae_order_id ) {
		return self::parse_order( self::request( 'aliexpress.trade.ds.order.get', self::order_params( $ae_order_id ) ), $ae_order_id );
	}

	/**
	 * Several AliExpress orders at once (tracking check).
	 *
	 * @param string[] $ae_order_ids
	 * @return array<string,array|WP_Error> order ID => order or error
	 */
	public static function get_orders( array $ae_order_ids ) {
		$calls = array();
		foreach ( array_unique( array_map( 'strval', $ae_order_ids ) ) as $id ) {
			$calls[ $id ] = array( 'aliexpress.trade.ds.order.get', self::order_params( $id ) );
		}
		$out = array();
		foreach ( self::request_many( $calls ) as $id => $data ) {
			$out[ (string) $id ] = self::parse_order( $data, (string) $id );
		}
		return $out;
	}

	private static function order_params( $ae_order_id ) {
		return array( 'single_order_query' => wp_json_encode( array( 'order_id' => (string) $ae_order_id ) ) );
	}

	private static function parse_order( $data, $ae_order_id ) {
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$r = self::result_of( $data, 'aliexpress_trade_ds_order_get_response' );
		if ( ! $r || ( isset( $r['order_status'] ) && '' === (string) $r['order_status'] && empty( $r['logistics_info_list'] ) ) ) {
			return new WP_Error( 'gsup_ae_order_missing', 'AliExpress has no order ' . $ae_order_id . ' on this account.' );
		}
		$tracking = array();
		$list     = $r['logistics_info_list'] ?? null;
		foreach ( self::items( $list, isset( $list['aeop_order_logistics_info'] ) ? 'aeop_order_logistics_info' : 'ae_order_logistics_info' ) as $l ) {
			$no = trim( (string) self::pick( $l, array( 'logistics_no', 'logistics_number', 'tracking_number' ) ) );
			if ( '' !== $no ) {
				$tracking[] = array(
					'number'  => $no,
					'carrier' => (string) self::pick( $l, array( 'logistics_service', 'logistics_service_name', 'carrier_name' ) ),
				);
			}
		}
		$amount = $r['order_amount'] ?? null;
		return array(
			'status'           => (string) ( $r['order_status'] ?? '' ),
			'logistics_status' => (string) ( $r['logistics_status'] ?? '' ),
			'amount'           => null === $amount ? null : self::money( $amount ),
			'currency'         => is_array( $amount ) ? (string) self::pick( $amount, array( 'currency_code', 'currency' ) ) : '',
			'tracking'         => $tracking,
		);
	}

	/* ------------------------------------------------------------- parcels */

	/**
	 * Where parcels are, for several AliExpress orders at once.
	 *
	 * @param string[] $ae_order_ids
	 * @return array<string,array|WP_Error> order ID => {delivered, delivered_at, last_time, last_text, events}
	 */
	public static function parcels( array $ae_order_ids ) {
		$calls = array();
		foreach ( array_unique( array_map( 'strval', $ae_order_ids ) ) as $id ) {
			$calls[ $id ] = array(
				'aliexpress.ds.order.tracking.get',
				array(
					'ae_order_id' => $id,
					'language'    => 'en_US',
				),
			);
		}
		$out = array();
		foreach ( self::request_many( $calls ) as $id => $data ) {
			$out[ (string) $id ] = is_wp_error( $data ) ? $data : self::parse_parcel( $data );
		}
		return $out;
	}

	/** Every tracking event in a reply, whatever the nesting: [{time, text}], newest first. */
	private static function parse_parcel( array $data ) {
		$events = array();
		$walk   = function ( $node ) use ( &$walk, &$events ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			$text = '';
			foreach ( array( 'tracking_detail_desc', 'detail_desc', 'desc', 'standerd_desc', 'standard_desc', 'tracking_name', 'status_desc' ) as $k ) {
				if ( isset( $node[ $k ] ) && is_scalar( $node[ $k ] ) && '' !== trim( (string) $node[ $k ] ) ) {
					$text = trim( (string) $node[ $k ] );
					break;
				}
			}
			$time = 0;
			foreach ( array( 'time_stamp', 'event_time', 'time', 'gmt_time', 'date' ) as $k ) {
				if ( isset( $node[ $k ] ) && is_scalar( $node[ $k ] ) && '' !== (string) $node[ $k ] ) {
					$v    = (string) $node[ $k ];
					$time = is_numeric( $v ) ? (int) ( (float) $v > 9999999999 ? (float) $v / 1000 : $v ) : (int) strtotime( $v );
					break;
				}
			}
			if ( '' !== $text && $time ) {
				$events[] = array(
					'time' => $time,
					'text' => $text,
				);
				return;
			}
			foreach ( $node as $child ) {
				$walk( $child );
			}
		};
		$walk( $data );
		usort(
			$events,
			function ( $a, $b ) {
				return $b['time'] - $a['time'];
			}
		);
		$delivered_at = null;
		foreach ( $events as $e ) {
			if ( self::is_delivered_text( $e['text'] ) ) {
				$delivered_at = $e['time'];
				break;
			}
		}
		return array(
			'delivered'    => null !== $delivered_at,
			'delivered_at' => $delivered_at,
			'last_time'    => $events ? $events[0]['time'] : 0,
			'last_text'    => $events ? $events[0]['text'] : '',
			'events'       => count( $events ),
		);
	}

	/** "Delivered", "Signed for", "Picked up by recipient" — but not "delivery attempted / failed / to be delivered". */
	public static function is_delivered_text( $text ) {
		$t = strtolower( (string) $text );
		if ( preg_match( '/not\s+delivered|undeliver|attempt|fail|unsuccessful|to be delivered|out for delivery|will be delivered|delivering|return(ed)? to sender|delivered to (the )?(airline|carrier|courier|logistics|shipping|transport|sorting|hub|warehouse|depot|customs|flight|post office for|local (delivery|courier|carrier)|last[- ]mile)|handed over/', $t ) ) {
			return false;
		}
		return (bool) preg_match( '/\bdelivered\b|\bsigned\s+(for|by)\b|^signed\b|\bsigned\s*:|picked up by (the )?(recipient|customer|consignee|addressee)|collected by (the )?(recipient|customer|addressee)|已签收|妥投/u', $t );
	}
}

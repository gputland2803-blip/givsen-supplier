<?php
/**
 * The visitor's delivery country ("Deliver to"), and keeping cached pages right for it.
 *
 * Country, in order: `?gsup_c=XX` (a cached variant of the page, see below) → the `gsup_country` cookie (the
 * visitor's choice, or detected earlier) → Cloudflare's CF-IPCountry header → WooCommerce geolocation → your store's
 * country. Detected countries are remembered in the cookie for 30 days. "Deliver to: 🇬🇧 United Kingdom — change"
 * (shortcode `[gsup_deliver_to]`, block, or added to a menu automatically) sets the cookie and the WooCommerce
 * customer's shipping country — so someone in Australia can shop for a friend in the UK.
 *
 * Page caching (why pages vary by country rather than loading parts over REST): hiding products that can't reach
 * the visitor has to happen in the product query itself — search, categories, related products, blocks — so the
 * whole page depends on the country. So:
 *  - LiteSpeed Cache keeps one copy per `gsup_country` cookie value (vary cookie registered with the plugin, and the
 *    X-LiteSpeed-Vary header for the server-level cache);
 *  - any other cache that keys on the URL (Cloudflare "Cache Everything"/APO, Varnish…): every page carries its
 *    country in `<meta name="gsup-country">`; a few lines of script compare it with the visitor's cookie (or ask
 *    `/givsen-supplier/v1/country` once, uncached) and, if they differ, reload the page as `?gsup_c=XX` — a separate
 *    cache entry per country. The variant is noindex and its canonical is the normal address. Same idea as
 *    WooCommerce's own "Geolocate (with page caching support)".
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Visitor {

	const COOKIE = 'gsup_country';
	const PARAM  = 'gsup_c';

	private static $country = null;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_switch' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'remember' ), 5 );
		add_action( 'send_headers', array( __CLASS__, 'vary_headers' ) );
		add_filter( 'litespeed_vary_cookies', array( __CLASS__, 'litespeed_cookies' ) );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 1 );
		add_shortcode( 'gsup_deliver_to', array( __CLASS__, 'switcher' ) );
		add_filter( 'wp_nav_menu_items', array( __CLASS__, 'menu_item' ), 20, 2 );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/* ------------------------------------------------------------- country */

	/** A valid country code, or ''. */
	public static function clean( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		$code = 'UK' === $code ? 'GB' : $code;
		if ( ! preg_match( '/^[A-Z]{2}$/', $code ) ) {
			return '';
		}
		$all = self::all_countries();
		return ! $all || isset( $all[ $code ] ) ? $code : '';
	}

	public static function all_countries() {
		return function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array();
	}

	/** Country name without WooCommerce's "(US)" suffix. */
	public static function name( $code ) {
		$all = self::all_countries();
		return isset( $all[ $code ] ) ? trim( preg_replace( '/\s*\([A-Z]{2}\)$/', '', html_entity_decode( $all[ $code ], ENT_QUOTES ) ) ) : $code;
	}

	/** 🇬🇧 from GB. */
	public static function flag( $code ) {
		if ( ! preg_match( '/^[A-Z]{2}$/', $code ) ) {
			return '';
		}
		return mb_chr( 0x1F1E6 + ord( $code[0] ) - 65 ) . mb_chr( 0x1F1E6 + ord( $code[1] ) - 65 );
	}

	/** Where the visitor wants delivery (see class notes). */
	public static function country() {
		if ( null !== self::$country ) {
			return self::$country;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only choice of page variant.
		$c = isset( $_GET[ self::PARAM ] ) ? self::clean( wp_unslash( $_GET[ self::PARAM ] ) ) : '';
		// phpcs:enable
		if ( '' === $c ) {
			$c = self::cookie();
		}
		if ( '' === $c ) {
			$c = self::detect();
		}
		self::$country = $c;
		return $c;
	}

	/** Forget the remembered answer (tests; after a change). */
	public static function reset( $country = null ) {
		self::$country = $country;
	}

	public static function cookie() {
		return isset( $_COOKIE[ self::COOKIE ] ) ? self::clean( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned to a country code.
	}

	/** From the request: Cloudflare, then WooCommerce geolocation, then the store's country. */
	public static function detect() {
		$c = isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ? self::clean( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned to a country code.
		if ( '' === $c && class_exists( 'WC_Geolocation' ) ) {
			$geo = WC_Geolocation::geolocate_ip( '', true, true );
			$c   = self::clean( $geo['country'] ?? '' );
		}
		if ( '' === $c ) {
			$base = function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : array();
			$c    = self::clean( $base['country'] ?? 'AU' );
		}
		return '' === $c ? 'AU' : $c;
	}

	/** Remember a country: cookie for 30 days, and the WooCommerce customer's shipping country. */
	public static function set( $code, $customer = true ) {
		$code = self::clean( $code );
		if ( '' === $code ) {
			return false;
		}
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$code,
				array(
					'expires'  => time() + 30 * DAY_IN_SECONDS,
					'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'secure'   => is_ssl(),
					'httponly' => false, // The page-cache check reads it.
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::COOKIE ] = $code;
		self::$country           = $code;
		if ( $customer && function_exists( 'WC' ) && WC()->customer ) {
			WC()->customer->set_shipping_country( $code );
			if ( '' === (string) WC()->customer->get_billing_country() ) {
				WC()->customer->set_billing_country( $code );
			}
			WC()->customer->save();
		}
		return true;
	}

	/** First visit: remember the detected country (only when the page isn't a cached variant). */
	public static function remember() {
		if ( is_admin() || '' !== self::cookie() || isset( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		self::set( self::country(), false );
	}

	/** Switcher form: ?gsup_set_country=GB → remember, then back to the same page without the parameter. */
	public static function handle_switch() {
		if ( ! isset( $_GET['gsup_set_country'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		self::set( wp_unslash( $_GET['gsup_set_country'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a harmless preference, cleaned inside.
		wp_safe_redirect( remove_query_arg( array( 'gsup_set_country', self::PARAM ) ) );
		exit;
	}

	/* ---------------------------------------------------------------- cache */

	public static function litespeed_cookies( $cookies ) {
		$cookies   = (array) $cookies;
		$cookies[] = self::COOKIE;
		return array_values( array_unique( $cookies ) );
	}

	public static function vary_headers() {
		if ( is_admin() || headers_sent() || ! GSUP_Shop::enabled() ) {
			return;
		}
		if ( ! defined( 'LSCWP_V' ) ) {
			header( 'X-LiteSpeed-Vary: cookie=' . self::COOKIE, false ); // Server-level LiteSpeed cache without its plugin.
		}
	}

	/** Page country, noindex for cached variants, and the check that reloads the right variant. */
	public static function head() {
		if ( ! GSUP_Shop::enabled() || is_admin() ) {
			return;
		}
		$country = self::country();
		echo '<meta name="gsup-country" content="' . esc_attr( $country ) . '">' . "\n";
		if ( isset( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<meta name="robots" content="noindex,follow">' . "\n";
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return; // Never cached.
		}
		$endpoint = esc_url_raw( rest_url( 'givsen-supplier/v1/country' ) );
		echo '<script>' . self::guard_js( $country, $endpoint ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from a country code and a URL, JSON-encoded.
	}

	/** The cached-page check (kept tiny; runs before anything renders). */
	public static function guard_js( $country, $endpoint ) {
		return '(function(){var p=' . wp_json_encode( $country ) . ',k=' . wp_json_encode( self::PARAM ) . ';'
			. 'function go(c){if(!/^[A-Z]{2}$/.test(c||"")||c===p)return;var u=new URL(location.href);if(u.searchParams.get(k)===c)return;u.searchParams.set(k,c);location.replace(u.toString());}'
			. 'var m=document.cookie.match(/(?:^|; )gsup_country=([A-Z]{2})/);'
			. 'if(m){go(m[1]);}else if(window.fetch){fetch(' . wp_json_encode( $endpoint ) . ',{credentials:"same-origin",cache:"no-store"}).then(function(r){return r.json();}).then(function(d){go(d&&d.country);}).catch(function(){});}'
			. '})();';
	}

	public static function rest() {
		register_rest_route(
			'givsen-supplier/v1',
			'/country',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_country' ),
			)
		);
	}

	/** Detects and remembers the visitor's country; never cached. */
	public static function rest_country() {
		$c = self::cookie();
		if ( '' === $c ) {
			$c = self::detect();
			self::set( $c, false );
		}
		nocache_headers();
		$res = rest_ensure_response( array( 'country' => $c ) );
		$res->header( 'Cache-Control', 'no-store, private' );
		return $res;
	}

	/* ------------------------------------------------------------- switcher */

	/** Countries offered in the switcher: selling countries first, then every other one if you sell there too. */
	public static function choices() {
		$out = array();
		foreach ( GSUP_Sources::countries() as $c ) {
			$out[ $c ] = self::name( $c );
		}
		asort( $out );
		if ( GSUP_Sources::sell_others() ) {
			$rest = array();
			foreach ( array_keys( self::all_countries() ) as $c ) {
				if ( ! isset( $out[ $c ] ) ) {
					$rest[ $c ] = self::name( $c );
				}
			}
			asort( $rest );
			$out += $rest;
		}
		return $out;
	}

	/** "Deliver to: 🇬🇧 United Kingdom — change". Works without JavaScript (a small GET form). */
	public static function switcher( $atts = array() ) {
		$country = self::country();
		$id      = 'gsup-deliver-' . wp_rand( 1000, 9999 );
		$html    = '<details class="gsup-deliver-to"><summary>Deliver to: <span class="gsup-flag" aria-hidden="true">' . esc_html( self::flag( $country ) ) . '</span> <strong>' . esc_html( self::name( $country ) ) . '</strong> <span class="gsup-deliver-change">— change</span></summary>';
		$html   .= '<form method="get" action="" class="gsup-deliver-form">';
		// Keep the page's own query (search terms, filters) when the form reloads it.
		foreach ( $_GET as $k => $v ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_scalar( $v ) && ! in_array( $k, array( 'gsup_set_country', self::PARAM ), true ) ) {
				$html .= '<input type="hidden" name="' . esc_attr( sanitize_key( $k ) ) . '" value="' . esc_attr( sanitize_text_field( wp_unslash( $v ) ) ) . '">';
			}
		}
		$html .= '<label for="' . esc_attr( $id ) . '">Where should we deliver?</label> <select id="' . esc_attr( $id ) . '" name="gsup_set_country">';
		$sell    = GSUP_Sources::countries();
		$others  = false;
		foreach ( self::choices() as $code => $name ) {
			if ( ! $others && ! in_array( $code, $sell, true ) ) {
				$others = true;
				$html  .= '<option disabled>──────────</option>';
			}
			$html .= '<option value="' . esc_attr( $code ) . '"' . selected( $code, $country, false ) . '>' . esc_html( self::flag( $code ) . ' ' . $name ) . '</option>';
		}
		$html .= '</select> <button type="submit">Save</button><p class="gsup-deliver-note">Shopping for someone overseas? Choose their country.</p></form></details>';
		return $html;
	}

	/** Settings → Selling worldwide → "Add Deliver to to the menu": append to that menu location. */
	public static function menu_item( $items, $args ) {
		$where = (string) get_option( 'gsup_switcher_menu', '' );
		if ( '' === $where || ! GSUP_Shop::enabled() || empty( $args->theme_location ) || $args->theme_location !== $where ) {
			return $items;
		}
		return $items . '<li class="menu-item gsup-deliver-item">' . self::switcher() . '</li>';
	}

	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script( 'gsup-blocks', GSUP_URL . 'assets/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-block-editor' ), GSUP_VERSION, true );
		register_block_type(
			'givsen-supplier/deliver-to',
			array(
				'api_version'     => 2,
				'title'           => 'Deliver to (country)',
				'category'        => 'woocommerce',
				'editor_script'   => 'gsup-blocks',
				'render_callback' => array( __CLASS__, 'switcher' ),
			)
		);
		register_block_type(
			'givsen-supplier/shipping-from',
			array(
				'api_version'     => 2,
				'title'           => 'Shipping from (filter)',
				'category'        => 'woocommerce',
				'editor_script'   => 'gsup-blocks',
				'render_callback' => array( 'GSUP_Shop', 'filter_html' ),
			)
		);
	}

	public static function assets() {
		if ( ! GSUP_Shop::enabled() ) {
			return;
		}
		wp_enqueue_style( 'gsup-shop', GSUP_URL . 'assets/shop.css', array(), GSUP_VERSION );
		wp_enqueue_script( 'gsup-shop', GSUP_URL . 'assets/shop.js', array( 'jquery' ), GSUP_VERSION, true );
	}
}

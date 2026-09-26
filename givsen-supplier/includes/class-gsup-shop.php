<?php
/**
 * What shoppers see, by delivery country (Settings → Selling worldwide → "Show delivery by country in the shop").
 *
 * - Product lists (shop, categories, search, related products, up-sells, cross-sells, product blocks and the Store
 *   API) leave out products no warehouse can deliver in stock to the visitor's country, so nobody lands on an empty
 *   shop or a product they can't buy. Products never checked for that country (or not from AliExpress) are shown.
 * - A product opened directly anyway says "Not available for delivery to {country}", can't be added to the cart, and
 *   shows a row of similar products that are available.
 * - Product page: "Delivery" lists each warehouse that reaches the visitor with the chosen option in stock
 *   ("Australia — 3–6 days (Tue 1 Oct – Fri 4 Oct)"), fastest first and chosen by default; hidden to a single line
 *   when there's only one. Optional surcharge for local warehouses, added in the cart.
 * - The choice goes with the cart item and order line ("Ships from: Australia · 3–6 days"; `_gsup_wh` for ordering).
 * - "Shipping from" filter (block, shortcode `[gsup_shipping_from]`, optionally above the product grid): All + each
 *   warehouse reaching the visitor, with product counts; filters through the reach table, in stock only.
 * - Checkout: when the delivery country differs, every line is checked again — switched to the fastest warehouse that
 *   can deliver (with a notice), or an error for that item if none can.
 * Countries you don't list are checked live (GSUP_Sources::live()), product by product, when a page needs them.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Shop {

	const FILTER_PARAM = 'ships_from';

	/** Per request: product ID => [item ID => [warehouse => row]] for a country. */
	private static $memo = array();

	/** True once the page itself is being built (cart loading from the session comes earlier). */
	private static $rendering = false;

	public static function enabled() {
		return 'yes' === get_option( 'gsup_worldwide_shop', 'no' );
	}

	public static function init() {
		add_shortcode( 'gsup_shipping_from', array( __CLASS__, 'filter_html' ) );
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'wp', array( __CLASS__, 'start_rendering' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ), 20 );
		add_filter( 'woocommerce_related_products', array( __CLASS__, 'filter_ids' ), 20 );
		add_filter( 'woocommerce_product_get_upsell_ids', array( __CLASS__, 'filter_ids' ), 20 );
		add_filter( 'woocommerce_product_get_cross_sell_ids', array( __CLASS__, 'filter_ids' ), 20 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 20, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'purchasable' ), 20, 2 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'unavailable_notice' ), 25 );
		add_action( 'woocommerce_after_single_product_summary', array( __CLASS__, 'similar_products' ), 15 );
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'delivery_choice' ), 5 );
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'variation_data' ), 30, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add' ), 20, 4 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( __CLASS__, 'validate_store_api' ), 20, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'cart_item_data' ), 20, 3 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_line' ), 20, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'surcharges' ), 20 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'order_review_changed' ) );
		add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'auto_filter' ), 25 );
		add_action( 'wp_body_open', array( __CLASS__, 'not_selling_bar' ) );
	}

	public static function start_rendering() {
		self::$rendering = ! is_admin() && ! ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) );
	}

	/** Tests: pretend the page is being built. */
	public static function set_rendering( $on ) {
		self::$rendering = (bool) $on;
		self::$memo      = array();
	}

	/* ----------------------------------------------------------- where to */

	/** Do you sell to this country at all? */
	public static function sells_to( $country ) {
		return in_array( $country, GSUP_Sources::countries(), true ) || GSUP_Sources::sell_others();
	}

	/** Does the reach table hold this country (a selling country), or must it be checked live? */
	public static function stored( $country ) {
		return in_array( $country, GSUP_Sources::countries(), true );
	}

	/**
	 * Every item of a product that can be delivered to a country: warehouse => {days_min, days_max, ship_cost, stock},
	 * deliverable and in stock only. Empty array for a product with no reach data for that country.
	 *
	 * @return array<int,array<string,array>>|null item ID => [warehouse => row]; null = nothing known (not checked)
	 */
	public static function reach_for( $product_id, $country ) {
		$key = $product_id . '|' . $country;
		if ( array_key_exists( $key, self::$memo ) ) {
			return self::$memo[ $key ];
		}
		$out   = null;
		$known = false;
		if ( self::stored( $country ) ) {
			foreach ( GSUP_Sources::reach( $product_id ) as $r ) {
				if ( $r['country'] !== $country ) {
					continue;
				}
				$known = true;
				if ( (int) $r['deliverable'] && (int) $r['in_stock'] ) {
					$out[ (int) $r['item_id'] ][ $r['warehouse'] ] = $r;
				}
			}
		} elseif ( GSUP_Sources::sell_others() && '' !== (string) get_post_meta( $product_id, GSUP_META_PRODUCT, true ) ) {
			$live = GSUP_Sources::live( $product_id, $country );
			if ( is_array( $live ) ) {
				$known = true;
				foreach ( $live as $item_id => $by_wh ) {
					foreach ( $by_wh as $wh => $r ) {
						if ( $r['deliverable'] && $r['in_stock'] ) {
							$out[ (int) $item_id ][ $wh ] = $r;
						}
					}
				}
			}
		} elseif ( '' !== (string) get_post_meta( $product_id, GSUP_META_PRODUCT, true ) ) {
			$known = true; // You don't sell there: nothing reaches it.
		}
		self::$memo[ $key ] = $known ? (array) $out : null;
		return self::$memo[ $key ];
	}

	/**
	 * Delivery options for one item (simple product or variation), fastest first.
	 *
	 * @return array[]|null [{wh, label, min, max, ship_cost, extra}] ; null = not known (show as usual)
	 */
	public static function options( $product_id, $item_id, $country ) {
		$reach = self::reach_for( $product_id, $country );
		if ( null === $reach ) {
			return null;
		}
		$out = array();
		foreach ( $reach[ (int) $item_id ] ?? array() as $wh => $r ) {
			$out[] = array(
				'wh'        => (string) $wh,
				'label'     => GSUP_Sources::label( $wh ),
				'min'       => (int) $r['days_min'],
				'max'       => (int) $r['days_max'],
				'ship_cost' => null === $r['ship_cost'] ? null : (float) $r['ship_cost'],
				'extra'     => self::extra_for( $item_id, $wh, $country ),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				$x = ( $a['max'] ? $a['max'] : 999 ) <=> ( $b['max'] ? $b['max'] : 999 );
				return $x ? $x : ( ( $a['min'] <=> $b['min'] ) ? ( $a['min'] <=> $b['min'] ) : GSUP_Sources::rank( $a['wh'] ) <=> GSUP_Sources::rank( $b['wh'] ) );
			}
		);
		return $out;
	}

	/** Can anything of this product reach the country? null = not known. */
	public static function available( $product_id, $country ) {
		$reach = self::reach_for( $product_id, $country );
		return null === $reach ? null : (bool) $reach;
	}

	/** "3–6 days (Tue 1 Oct – Fri 4 Oct)" with your processing days added. */
	public static function days_text( array $opt ) {
		if ( ! $opt['max'] ) {
			return '';
		}
		return GSUP_Eta::span( array( max( 1, $opt['min'] ), $opt['max'] ) );
	}

	/* -------------------------------------------------------------- pricing */

	/** 'same' (default) or 'local': local warehouses (in the delivery country) cost extra. */
	public static function pricing() {
		return 'local' === get_option( 'gsup_wh_pricing', 'same' ) ? 'local' : 'same';
	}

	/** Extra charged for a warehouse in the delivery country, for one unit. */
	public static function extra_for( $item_id, $wh, $country ) {
		if ( 'local' !== self::pricing() || $wh !== $country ) {
			return 0.0;
		}
		$item  = wc_get_product( $item_id );
		$price = $item ? (float) $item->get_price() : 0.0;
		return round( $price * max( 0, (float) get_option( 'gsup_local_pct', 0 ) ) / 100 + max( 0, (float) get_option( 'gsup_local_fixed', 0 ) ), 2 );
	}

	/* ---------------------------------------------------------- hiding lists */

	/**
	 * Products that were checked for a selling country and can't be delivered there in stock (cached per country
	 * until the next reach refresh).
	 *
	 * @return int[]
	 */
	public static function hidden_ids( $country ) {
		if ( ! self::stored( $country ) ) {
			return array(); // Live countries are checked on the product page, not in lists (one call per product).
		}
		$cache = 'gsup_hidden_' . $country . '_' . (int) get_option( 'gsup_reach_ver', 0 );
		$ids   = get_transient( $cache );
		if ( is_array( $ids ) ) {
			return $ids;
		}
		global $wpdb;
		$table = GSUP_Install::reach_table();
		$ids   = array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT product_id FROM {$table} WHERE country = %s GROUP BY product_id HAVING MAX(deliverable = 1 AND in_stock = 1) = 0", $country ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);
		set_transient( $cache, $ids, HOUR_IN_SECONDS );
		return $ids;
	}

	/** Products with a warehouse delivering to the country in stock (for the "Shipping from" filter). */
	public static function ids_from( $country, $wh ) {
		global $wpdb;
		$table = GSUP_Install::reach_table();
		return array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT DISTINCT product_id FROM {$table} WHERE country = %s AND warehouse = %s AND deliverable = 1 AND in_stock = 1", $country, $wh ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);
	}

	/** Whether a query is one of the product lists shoppers see. */
	private static function shopper_query( $q ) {
		if ( ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() || $q->get( 'gsup_all' ) ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
			if ( 0 !== strpos( $route, '/wc/store' ) ) {
				return false; // Only the shop's own Store API, never admin/app APIs.
			}
		}
		$types = (array) $q->get( 'post_type' );
		$is_products = in_array( 'product', $types, true ) || ( $q->is_main_query() && ( $q->is_search() || $q->is_tax( get_object_taxonomies( 'product' ) ) || $q->is_post_type_archive( 'product' ) ) );
		if ( ! $is_products ) {
			return false;
		}
		// A product opened directly is shown (with "Not available in …").
		return ! ( $q->is_main_query() && $q->is_singular() );
	}

	public static function filter_query( $q ) {
		if ( ! self::shopper_query( $q ) ) {
			return;
		}
		$country = GSUP_Visitor::country();
		$hidden  = self::hidden_ids( $country );
		if ( $hidden ) {
			$q->set( 'post__not_in', array_values( array_unique( array_merge( array_filter( (array) $q->get( 'post__not_in' ) ), $hidden ) ) ) );
		}
		$wh = self::chosen_filter();
		if ( '' !== $wh && $q->is_main_query() ) {
			$only = self::ids_from( $country, $wh );
			$in   = array_filter( (array) $q->get( 'post__in' ) ); // WordPress gives '' when not set.
			$only = $in ? array_values( array_intersect( $in, $only ) ) : $only;
			$q->set( 'post__in', $only ? $only : array( 0 ) );
		}
	}

	/** Related products, up-sells, cross-sells. */
	public static function filter_ids( $ids ) {
		if ( ! is_array( $ids ) || ! $ids ) {
			return $ids;
		}
		$hidden = self::hidden_ids( GSUP_Visitor::country() );
		return $hidden ? array_values( array_diff( array_map( 'intval', $ids ), $hidden ) ) : $ids;
	}

	/* --------------------------------------------------------- product page */

	/** Products (and options) that can't reach the visitor can't be added to the cart. */
	public static function purchasable( $ok, $product ) {
		if ( ! $ok || ! self::$rendering || ! $product instanceof WC_Product ) {
			return $ok;
		}
		$pid     = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$country = GSUP_Visitor::country();
		if ( $product->is_type( 'variation' ) || $product->is_type( 'simple' ) ) {
			$opts = self::options( $pid, $product->get_id(), $country );
			return null === $opts ? $ok : (bool) $opts;
		}
		$av = self::available( $pid, $country );
		return null === $av ? $ok : $av;
	}

	public static function unavailable_notice() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$country = GSUP_Visitor::country();
		if ( false !== self::available( $product->get_id(), $country ) ) {
			return;
		}
		echo '<p class="gsup-unavailable">' . esc_html( sprintf( 'Not available for delivery to %s.', GSUP_Visitor::name( $country ) ) ) . ' <a href="#gsup-similar">See similar products</a> · ' . GSUP_Visitor::switcher() . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	/**
	 * Similar products that can reach the visitor: same categories, then anything, in stock, up to 4.
	 *
	 * @return int[]
	 */
	public static function similar_ids( WC_Product $product, $country, $limit = 4 ) {
		$cats   = array();
		foreach ( $product->get_category_ids() as $cat_id ) {
			$term = get_term( $cat_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$cats[] = $term->slug;
			}
		}
		$hidden = self::hidden_ids( $country );
		$out    = array();
		foreach ( array( $cats, array() ) as $in_cats ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			$args = array(
				'status'       => 'publish',
				'limit'        => 12,
				'exclude'      => array_merge( array( $product->get_id() ), $out, $hidden ),
				'stock_status' => 'instock',
				'orderby'      => 'date',
				'return'       => 'ids',
			);
			if ( $in_cats ) {
				$args['category'] = $in_cats;
			}
			foreach ( (array) wc_get_products( $args ) as $id ) {
				if ( count( $out ) >= $limit ) {
					break;
				}
				// Stored countries are already filtered; live ones are checked here (cached 6 hours).
				if ( self::stored( $country ) || false !== self::available( $id, $country ) ) {
					$out[] = (int) $id;
				}
			}
		}
		return $out;
	}

	public static function similar_products() {
		global $product;
		if ( ! $product instanceof WC_Product || false !== self::available( $product->get_id(), GSUP_Visitor::country() ) ) {
			return;
		}
		$ids = self::similar_ids( $product, GSUP_Visitor::country() );
		if ( ! $ids ) {
			return;
		}
		remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
		echo '<section id="gsup-similar" class="related products gsup-similar"><h2>' . esc_html( sprintf( 'Similar products we deliver to %s', GSUP_Visitor::name( GSUP_Visitor::country() ) ) ) . '</h2>';
		woocommerce_product_loop_start();
		foreach ( $ids as $id ) {
			$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			setup_postdata( $GLOBALS['post'] );
			wc_get_template_part( 'content', 'product' );
		}
		woocommerce_product_loop_end();
		wp_reset_postdata();
		echo '</section>';
	}

	/** Options as sent to the page (text already worked out). */
	private static function public_options( $opts ) {
		$out = array();
		foreach ( (array) $opts as $o ) {
			$out[] = array(
				'wh'    => $o['wh'],
				'label' => $o['label'],
				'days'  => self::days_text( $o ),
				'extra' => $o['extra'] > 0 ? '+' . html_entity_decode( wp_strip_all_tags( wc_price( $o['extra'] ) ), ENT_QUOTES ) : '',
			);
		}
		return $out;
	}

	/** One line for a single warehouse, radios for several. */
	public static function choice_html( array $opts, $chosen = '' ) {
		if ( ! $opts ) {
			return '';
		}
		$chosen = '' !== $chosen ? $chosen : $opts[0]['wh'];
		if ( 1 === count( $opts ) ) {
			$o = $opts[0];
			return '<p class="gsup-delivery-one">Delivery: ' . esc_html( trim( $o['days'] ) ) . '</p><input type="hidden" name="gsup_wh" value="' . esc_attr( $o['wh'] ) . '">';
		}
		$html = '<fieldset class="gsup-delivery"><legend>Delivery</legend>';
		foreach ( $opts as $i => $o ) {
			$html .= '<label class="gsup-delivery-opt"><input type="radio" name="gsup_wh" value="' . esc_attr( $o['wh'] ) . '"' . checked( $o['wh'], $chosen, false ) . '> <span class="gsup-delivery-from">' . esc_html( $o['label'] ) . '</span> — <span class="gsup-delivery-days">' . esc_html( $o['days'] ) . '</span>' . ( '' !== $o['extra'] ? ' <span class="gsup-delivery-extra">' . esc_html( $o['extra'] ) . '</span>' : '' ) . ( 0 === $i ? ' <span class="gsup-delivery-fastest">Fastest</span>' : '' ) . '</label>';
		}
		return $html . '</fieldset>';
	}

	/** Inside the add-to-cart form (so the choice is posted with it). */
	public static function delivery_choice() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$country = GSUP_Visitor::country();
		if ( $product->is_type( 'variable' ) ) {
			if ( null === self::reach_for( $product->get_id(), $country ) ) {
				return;
			}
			echo '<div class="gsup-delivery-box" data-gsup-delivery></div>'; // Filled for the chosen option (variation data).
			return;
		}
		$opts = self::options( $product->get_id(), $product->get_id(), $country );
		if ( $opts ) {
			echo '<div class="gsup-delivery-box">' . self::choice_html( self::public_options( $opts ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
	}

	/** Each option's delivery choices travel with WooCommerce's variation data. */
	public static function variation_data( $data, $product, $variation ) {
		if ( ! $variation ) {
			return $data;
		}
		$country = GSUP_Visitor::country();
		$opts    = self::options( $product->get_id(), $variation->get_id(), $country );
		if ( null === $opts ) {
			return $data;
		}
		$data['gsup_delivery'] = self::choice_html( self::public_options( $opts ) );
		if ( ! $opts ) {
			$data['is_purchasable']    = false;
			$data['availability_html'] = '<p class="stock out-of-stock gsup-unavailable">' . esc_html( sprintf( 'Not available for delivery to %s.', GSUP_Visitor::name( $country ) ) ) . '</p>';
		}
		return $data;
	}

	/** Whether this plugin shows the product's delivery (so the plain estimate isn't shown as well). */
	public static function handles( WC_Product $product ) {
		return self::enabled() && null !== self::reach_for( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(), GSUP_Visitor::country() );
	}

	/* ----------------------------------------------------------------- cart */

	/** Posted warehouse if it still works, else the fastest. '' = no delivery known for this item. */
	private static function pick( $product_id, $item_id, $country, $wanted ) {
		$opts = self::options( $product_id, $item_id, $country );
		if ( ! $opts ) {
			return null === $opts ? '' : false;
		}
		foreach ( $opts as $o ) {
			if ( $o['wh'] === $wanted ) {
				return $o['wh'];
			}
		}
		return $opts[0]['wh'];
	}

	public static function validate_add( $ok, $product_id, $qty, $variation_id = 0 ) {
		if ( ! $ok ) {
			return $ok;
		}
		$country = GSUP_Visitor::country();
		if ( false === self::pick( $product_id, $variation_id ? $variation_id : $product_id, $country, '' ) ) {
			wc_add_notice( sprintf( 'Sorry, “%1$s” can’t be delivered to %2$s.', get_the_title( $product_id ), GSUP_Visitor::name( $country ) ), 'error' );
			return false;
		}
		return $ok;
	}

	/** Block cart / product blocks (Store API) add to cart the same way. */
	public static function validate_store_api( $product, $request = null ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$pid     = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$country = GSUP_Visitor::country();
		if ( false === self::pick( $pid, $product->get_id(), $country, '' ) && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'gsup_not_deliverable', esc_html( sprintf( 'Sorry, “%1$s” can’t be delivered to %2$s.', $product->get_name(), GSUP_Visitor::name( $country ) ) ), 400 );
		}
	}

	public static function cart_item_data( $data, $product_id, $variation_id ) {
		$country = GSUP_Visitor::country();
		$wanted  = isset( $_POST['gsup_wh'] ) ? strtoupper( sanitize_key( wp_unslash( $_POST['gsup_wh'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce's add-to-cart form.
		$wh      = self::pick( $product_id, $variation_id ? $variation_id : $product_id, $country, $wanted );
		if ( is_string( $wh ) && '' !== $wh ) {
			$data['gsup_wh'] = $wh;
			$data['gsup_c']  = $country;
		}
		return $data;
	}

	/** The item's current choice, as shown to customers: "Australia · 3–6 days". */
	public static function ships_text( $product_id, $item_id, $country, $wh ) {
		foreach ( (array) self::options( $product_id, $item_id, $country ) as $o ) {
			if ( $o['wh'] === $wh ) {
				$days = $o['max'] ? ( $o['min'] === $o['max'] ? $o['max'] : max( 1, $o['min'] ) . '–' . $o['max'] ) . ' days' : '';
				return $o['label'] . ( '' !== $days ? ' · ' . $days : '' );
			}
		}
		return GSUP_Sources::label( $wh );
	}

	public static function item_data( $rows, $cart_item ) {
		if ( ! empty( $cart_item['gsup_wh'] ) ) {
			$item_id = ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : $cart_item['product_id'];
			$rows[]  = array(
				'key'   => 'Ships from',
				'value' => self::ships_text( $cart_item['product_id'], $item_id, $cart_item['gsup_c'] ?? GSUP_Visitor::country(), $cart_item['gsup_wh'] ),
			);
		}
		return $rows;
	}

	public static function order_line( $item, $key, $values ) {
		if ( empty( $values['gsup_wh'] ) ) {
			return;
		}
		$item_id = ! empty( $values['variation_id'] ) ? $values['variation_id'] : $values['product_id'];
		$item->add_meta_data( '_gsup_wh', $values['gsup_wh'], true );
		$item->add_meta_data( 'Ships from', self::ships_text( $values['product_id'], $item_id, $values['gsup_c'] ?? GSUP_Visitor::country(), $values['gsup_wh'] ), true );
	}

	/** "Local warehouses cost extra": added to the item's price in the cart. */
	public static function surcharges( $cart ) {
		if ( 'local' !== self::pricing() || ! $cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['gsup_wh'] ) || empty( $item['data'] ) ) {
				continue;
			}
			$item_id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
			$extra   = self::extra_for( $item_id, $item['gsup_wh'], $item['gsup_c'] ?? GSUP_Visitor::country() );
			if ( $extra > 0 ) {
				$fresh = wc_get_product( $item_id ); // The unchanged price, so running twice never adds it twice.
				$item['data']->set_price( (float) ( $fresh ? $fresh->get_price() : $item['data']->get_price() ) + $extra );
			}
		}
	}

	/* ------------------------------------------------------------- checkout */

	/**
	 * Check every cart line against a delivery country: keep the chosen warehouse if it still delivers there, else
	 * switch to the fastest that does (notice), else an error for that item.
	 *
	 * @return array{switched:string[],blocked:string[]}
	 */
	public static function recheck( $country ) {
		$out = array(
			'switched' => array(),
			'blocked'  => array(),
		);
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $cart || '' === $country ) {
			return $out;
		}
		$changed = false;
		foreach ( $cart->get_cart() as $key => $item ) {
			$pid     = (int) $item['product_id'];
			$item_id = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : $pid;
			$opts    = self::options( $pid, $item_id, $country );
			if ( null === $opts ) {
				continue; // Not from AliExpress, or never checked: nothing to say.
			}
			$name = wp_strip_all_tags( $item['data'] ? $item['data']->get_name() : get_the_title( $pid ) );
			if ( ! $opts ) {
				$msg = sprintf( '“%1$s” can’t be delivered to %2$s. Remove it, or choose a different delivery country.', $name, GSUP_Visitor::name( $country ) );
				if ( ! wc_has_notice( $msg, 'error' ) ) {
					wc_add_notice( $msg, 'error' );
				}
				$out['blocked'][] = $key;
				continue;
			}
			$now = $item['gsup_wh'] ?? '';
			$ok  = false;
			foreach ( $opts as $o ) {
				$ok = $ok || $o['wh'] === $now;
			}
			if ( ! $ok || ( $item['gsup_c'] ?? '' ) !== $country ) {
				$new = $ok ? $now : $opts[0]['wh'];
				$cart->cart_contents[ $key ]['gsup_wh'] = $new;
				$cart->cart_contents[ $key ]['gsup_c']  = $country;
				$changed = true;
				if ( ! $ok && '' !== $now ) {
					$msg = sprintf( '“%1$s” will ship from %2$s instead (%3$s) — %4$s can’t deliver to %5$s.', $name, $opts[0]['label'], trim( self::days_text( $opts[0] ) ), GSUP_Sources::label( $now ), GSUP_Visitor::name( $country ) );
					if ( ! wc_has_notice( $msg, 'notice' ) ) {
						wc_add_notice( $msg, 'notice' );
					}
					$out['switched'][] = $key;
				}
			}
		}
		if ( $changed ) {
			$cart->set_session();
		}
		return $out;
	}

	/** Delivery country for the cart: the customer's shipping country, else the visitor's. */
	public static function cart_country() {
		$c = function_exists( 'WC' ) && WC()->customer ? GSUP_Visitor::clean( WC()->customer->get_shipping_country() ) : '';
		return '' !== $c ? $c : GSUP_Visitor::country();
	}

	/** Cart and checkout pages, the Store API, and placing the order all run this. */
	public static function check_cart() {
		self::recheck( self::cart_country() );
	}

	/** Country typed at checkout: follow it (the shop's "Deliver to" follows too). */
	public static function order_review_changed( $posted ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checks the update_order_review nonce.
		$c = isset( $_POST['s_country'] ) && '' !== $_POST['s_country'] ? $_POST['s_country'] : ( $_POST['country'] ?? '' );
		// phpcs:enable
		$c = GSUP_Visitor::clean( is_string( $c ) ? wp_unslash( $c ) : '' );
		if ( '' !== $c && $c !== GSUP_Visitor::cookie() ) {
			GSUP_Visitor::set( $c, false );
		}
		if ( '' !== $c ) {
			self::recheck( $c );
		}
	}

	/* ----------------------------------------------------- shipping filter */

	private static function chosen_filter() {
		$wh = isset( $_GET[ self::FILTER_PARAM ] ) ? strtoupper( sanitize_key( wp_unslash( $_GET[ self::FILTER_PARAM ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return preg_match( '/^[A-Z]{2}$/', $wh ) ? $wh : '';
	}

	/**
	 * Warehouses reaching a country with in-stock product counts, limited to some products when given.
	 *
	 * @param int[]|null $within Product IDs (e.g. the current category), or null for the whole shop.
	 * @return array<string,int> warehouse => products, in preference order
	 */
	public static function counts( $country, $within = null ) {
		if ( ! self::stored( $country ) || ( is_array( $within ) && ! $within ) ) {
			return array();
		}
		global $wpdb;
		$table = GSUP_Install::reach_table();
		$args  = array( $country );
		$limit = '';
		if ( is_array( $within ) ) {
			$within = array_slice( array_map( 'intval', $within ), 0, 5000 );
			$limit  = ' AND r.product_id IN (' . implode( ',', array_fill( 0, count( $within ), '%d' ) ) . ')';
			$args   = array_merge( $args, $within );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.warehouse, COUNT(DISTINCT r.product_id) AS n FROM {$table} r JOIN {$wpdb->posts} p ON p.ID = r.product_id AND p.post_status = 'publish'
				WHERE r.country = %s AND r.deliverable = 1 AND r.in_stock = 1{$limit} GROUP BY r.warehouse",
				$args
			),
			ARRAY_A
		);
		// phpcs:enable
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (string) $r['warehouse'] ] = (int) $r['n'];
		}
		$sorted = array();
		foreach ( GSUP_Sources::sort_warehouses( array_keys( $out ) ) as $wh ) {
			$sorted[ $wh ] = $out[ $wh ];
		}
		return $sorted;
	}

	/** Products in the category/tag being viewed (and its sub-categories), or null on the main shop and search. */
	private static function current_scope() {
		if ( ! function_exists( 'is_product_taxonomy' ) || ! is_product_taxonomy() ) {
			return null;
		}
		$term = get_queried_object();
		if ( ! $term || empty( $term->term_id ) ) {
			return null;
		}
		$terms = array( (int) $term->term_id );
		if ( is_taxonomy_hierarchical( $term->taxonomy ) ) {
			$terms = array_merge( $terms, array_map( 'intval', (array) get_term_children( $term->term_id, $term->taxonomy ) ) );
		}
		return array_map( 'intval', (array) get_objects_in_term( $terms, $term->taxonomy ) );
	}

	/** "Shipping from: All · Australia (12) · China (40)". */
	public static function filter_html( $atts = array() ) {
		if ( ! self::enabled() ) {
			return '';
		}
		$country = GSUP_Visitor::country();
		$counts  = self::counts( $country, self::current_scope() );
		if ( ! $counts ) {
			return '';
		}
		$chosen = self::chosen_filter();
		$base   = remove_query_arg( array( self::FILTER_PARAM, 'paged', 'product-page' ) );
		$base   = preg_replace( '#/page/\d+/?#', '/', $base );
		$html   = '<nav class="gsup-ships-from" aria-label="Shipping from"><span class="gsup-ships-from-label">Shipping from:</span> ';
		$html  .= '<a href="' . esc_url( $base ) . '"' . ( '' === $chosen ? ' class="is-active" aria-current="true"' : '' ) . '>All</a>';
		foreach ( $counts as $wh => $n ) {
			$html .= ' <a href="' . esc_url( add_query_arg( self::FILTER_PARAM, $wh, $base ) ) . '"' . ( $wh === $chosen ? ' class="is-active" aria-current="true"' : '' ) . '>' . esc_html( GSUP_Sources::label( $wh ) ) . ' <span class="count">(' . (int) $n . ')</span></a>';
		}
		return $html . '</nav>';
	}

	/** Above the product grid, when switched on and there's a real choice. */
	public static function auto_filter() {
		if ( 'no' === get_option( 'gsup_filter_auto', 'yes' ) ) {
			return;
		}
		if ( count( self::counts( GSUP_Visitor::country(), self::current_scope() ) ) < 2 ) {
			return;
		}
		echo self::filter_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	/** Visitors from a country you don't sell to: say so once, at the top. */
	public static function not_selling_bar() {
		$country = GSUP_Visitor::country();
		if ( self::sells_to( $country ) || is_admin() ) {
			return;
		}
		echo '<div class="gsup-not-selling">' . esc_html( sprintf( 'We don’t deliver to %s yet.', GSUP_Visitor::name( $country ) ) ) . ' ' . GSUP_Visitor::switcher() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}
}

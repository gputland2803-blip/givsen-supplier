<?php
/**
 * Shared helpers. The supplier link lives on the WooCommerce product by ID:
 * - product (or variable parent): AliExpress product ID
 * - variation (or simple product): AliExpress SKU ID, ships-from country, option label
 */

defined( 'ABSPATH' ) || exit;

define( 'GSUP_META_PRODUCT', '_gsup_ae_product_id' );
define( 'GSUP_META_SKU', '_gsup_ae_sku_id' );
define( 'GSUP_META_SHIP', '_gsup_ship_from' );
define( 'GSUP_META_OPTION', '_gsup_ae_option' );
define( 'GSUP_ITEM_AE_ORDER', '_gsup_ae_order_no' );
define( 'GSUP_ITEM_TRACKING', '_gsup_tracking_no' );
define( 'GSUP_META_COST', '_gsup_cost' );             // AliExpress item cost (store currency).
define( 'GSUP_META_SHIP_COST', '_gsup_ship_cost' );   // AliExpress delivery fee for one item.
define( 'GSUP_META_SHIP_METHOD', '_gsup_ship_method' ); // Delivery method that fee is for.
define( 'GSUP_ITEM_CARRIER', '_gsup_carrier' );       // Carrier AliExpress reported with the tracking number.
define( 'GSUP_ITEM_UNIT_COST', '_gsup_unit_cost' );   // Cost of one item (incl. shipping) when the order was placed.
define( 'GSUP_ITEM_AE_COST', '_gsup_ae_cost' );       // What the AliExpress order cost (quoted, or paid once known).
define( 'GSUP_ITEM_PROBLEM', '_gsup_auto_problem' );  // Why automatic ordering couldn't place this item.

/**
 * Pull an AliExpress product ID out of an ID, a product link, or a mobile/share link.
 */
function gsup_parse_product_id( $input ) {
	$input = trim( (string) $input );
	if ( '' === $input ) {
		return '';
	}
	if ( ctype_digit( $input ) ) {
		return strlen( $input ) <= 32 ? $input : '';
	}
	$decoded = rawurldecode( rawurldecode( $input ) );
	if ( preg_match( '~/item/(?:[^/?#]*/)?(\d{6,32})\.html~i', $decoded, $m ) ) {
		return $m[1];
	}
	if ( preg_match( '~(?:productIds?|product_id|itemId|item_id)["\']?\s*[=:]\s*["\']?(\d{6,32})~i', $decoded, $m ) ) {
		return $m[1];
	}
	return '';
}

/**
 * Pull an AliExpress SKU ID out of a bare ID or a link that carries one (sku_id / skuId).
 */
function gsup_parse_sku_id( $input ) {
	$input = trim( (string) $input );
	if ( '' === $input ) {
		return '';
	}
	if ( ctype_digit( $input ) ) {
		return strlen( $input ) <= 64 ? $input : '';
	}
	$decoded = rawurldecode( rawurldecode( $input ) );
	if ( preg_match( '~sku_?id["\']?\s*[=:]\s*["\']?(\d{5,64})~i', $decoded, $m ) ) {
		return $m[1];
	}
	return '';
}

/**
 * Which delivery country to ask AliExpress about for a warehouse: Australia and United States
 * warehouses are asked about their own country, anything else about the store's country.
 */
function gsup_quote_country( $ship_from ) {
	return in_array( $ship_from, array( 'AU', 'US' ), true ) ? $ship_from : GSUP_AliExpress::default_ship_to();
}

/** Money for admin screens, without the HTML wc_price() adds. */
function gsup_money( $amount ) {
	return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES );
}

function gsup_ae_url( $ae_product_id ) {
	return 'https://www.aliexpress.com/item/' . rawurlencode( (string) $ae_product_id ) . '.html';
}

/**
 * Ships-from choices. AliExpress's common warehouses first, then the rest.
 */
function gsup_ship_from_options() {
	return array(
		''   => '— Not set —',
		'AU' => 'Australia',
		'US' => 'United States',
		'CN' => 'China',
		'GB' => 'United Kingdom',
		'NZ' => 'New Zealand',
		'CA' => 'Canada',
		'ES' => 'Spain',
		'FR' => 'France',
		'DE' => 'Germany',
		'IT' => 'Italy',
		'PL' => 'Poland',
		'CZ' => 'Czech Republic',
		'BE' => 'Belgium',
		'NL' => 'Netherlands',
		'BR' => 'Brazil',
		'MX' => 'Mexico',
		'CL' => 'Chile',
		'TR' => 'Turkey',
		'KR' => 'South Korea',
		'JP' => 'Japan',
		'SA' => 'Saudi Arabia',
		'AE' => 'United Arab Emirates',
		'IL' => 'Israel',
		'RU' => 'Russia',
	);
}

/**
 * Accepts a 2-letter code or a country name as AliExpress shows it ("Australia", "United States", "USA").
 */
function gsup_sanitize_ship_from( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}
	$upper = strtoupper( $value );
	if ( preg_match( '/^[A-Z]{2}$/', $upper ) ) {
		return 'UK' === $upper ? 'GB' : $upper;
	}
	$aliases = array(
		'USA'                => 'US',
		'UNITED STATES'      => 'US',
		'UK'                 => 'GB',
		'CZECH'              => 'CZ',
		'CZECHIA'            => 'CZ',
		'KOREA'              => 'KR',
		'UAE'                => 'AE',
		'RUSSIAN FEDERATION' => 'RU',
		'CHINA MAINLAND'     => 'CN',
	);
	if ( isset( $aliases[ $upper ] ) ) {
		return $aliases[ $upper ];
	}
	foreach ( gsup_ship_from_options() as $code => $label ) {
		if ( '' !== $code && strtoupper( $label ) === $upper ) {
			return $code;
		}
	}
	if ( function_exists( 'WC' ) && WC()->countries ) {
		foreach ( WC()->countries->get_countries() as $code => $label ) {
			if ( strtoupper( html_entity_decode( $label ) ) === $upper ) {
				return $code;
			}
		}
	}
	return '';
}

function gsup_ship_from_label( $code ) {
	$options = gsup_ship_from_options();
	if ( '' !== $code && isset( $options[ $code ] ) ) {
		return $options[ $code ];
	}
	if ( $code && function_exists( 'WC' ) && WC()->countries ) {
		$all = WC()->countries->get_countries();
		if ( isset( $all[ $code ] ) ) {
			return html_entity_decode( $all[ $code ] );
		}
	}
	return (string) $code;
}

/**
 * Ship-from options, keeping any stored value that isn't in the short list selectable.
 */
function gsup_ship_from_options_with( $current ) {
	$options = gsup_ship_from_options();
	if ( $current && ! isset( $options[ $current ] ) ) {
		$options[ $current ] = gsup_ship_from_label( $current );
	}
	return $options;
}

/**
 * The supplier link for a product, variation, or simple product.
 *
 * @return array{product_id:string,sku_id:string,ship_from:string,option:string,level:string}
 */
function gsup_get_supplier_link( $product ) {
	$link = array(
		'product_id' => '',
		'sku_id'     => '',
		'ship_from'  => '',
		'option'     => '',
		'level'      => '',
	);
	if ( ! $product instanceof WC_Product ) {
		return $link;
	}
	if ( $product->is_type( 'variation' ) ) {
		$link['product_id'] = (string) get_post_meta( $product->get_parent_id(), GSUP_META_PRODUCT, true );
		$link['level']      = 'variation';
	} else {
		$link['product_id'] = (string) get_post_meta( $product->get_id(), GSUP_META_PRODUCT, true );
		$link['level']      = $product->is_type( 'variable' ) ? 'parent' : 'simple';
	}
	if ( 'parent' !== $link['level'] ) {
		$link['sku_id']    = (string) get_post_meta( $product->get_id(), GSUP_META_SKU, true );
		$link['ship_from'] = (string) get_post_meta( $product->get_id(), GSUP_META_SHIP, true );
		$link['option']    = (string) get_post_meta( $product->get_id(), GSUP_META_OPTION, true );
	}
	return $link;
}

/**
 * WooCommerce products/variations already linked to an AliExpress product (and SKU, if given).
 *
 * @return int[]
 */
function gsup_find_linked( $ae_product_id, $ae_sku_id = '' ) {
	if ( '' === (string) $ae_product_id ) {
		return array();
	}
	$found = array();
	if ( '' !== (string) $ae_sku_id ) {
		$ids = get_posts(
			array(
				'post_type'        => array( 'product', 'product_variation' ),
				'post_status'      => 'any',
				'numberposts'      => 10,
				'fields'           => 'ids',
				'meta_key'         => GSUP_META_SKU, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => (string) $ae_sku_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'suppress_filters' => true,
			)
		);
		foreach ( $ids as $id ) {
			$post      = get_post( $id );
			$parent_id = ( $post && 'product_variation' === $post->post_type ) ? (int) $post->post_parent : (int) $id;
			if ( (string) get_post_meta( $parent_id, GSUP_META_PRODUCT, true ) === (string) $ae_product_id ) {
				$found[] = (int) $id;
			}
		}
		if ( $found ) {
			return $found;
		}
	}
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'any',
				'numberposts'      => 10,
				'fields'           => 'ids',
				'meta_key'         => GSUP_META_PRODUCT, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => (string) $ae_product_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'suppress_filters' => true,
			)
		)
	);
}

/**
 * Products/variations holding exactly this AliExpress option (no fallback to the product).
 *
 * @return int[]
 */
function gsup_find_option_links( $ae_product_id, $ae_sku_id ) {
	if ( '' === (string) $ae_sku_id ) {
		return array();
	}
	return array_values(
		array_filter(
			gsup_find_linked( $ae_product_id, $ae_sku_id ),
			function ( $id ) use ( $ae_sku_id ) {
				return (string) get_post_meta( $id, GSUP_META_SKU, true ) === (string) $ae_sku_id;
			}
		)
	);
}

/**
 * Display name for a product or variation, including the variation's options.
 */
function gsup_product_label( $wc_id ) {
	$product = wc_get_product( $wc_id );
	if ( ! $product ) {
		return '#' . (int) $wc_id . ' (deleted)';
	}
	return wp_strip_all_tags( $product->get_formatted_name() );
}

function gsup_product_edit_url( $wc_id ) {
	$post = get_post( $wc_id );
	if ( $post && 'product_variation' === $post->post_type ) {
		$wc_id = $post->post_parent;
	}
	return admin_url( 'post.php?post=' . (int) $wc_id . '&action=edit' );
}

function gsup_admin_url( $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'gsup' ), $args ), admin_url( 'admin.php' ) );
}

function gsup_flash( $message, $type = 'success' ) {
	$key     = 'gsup_flash_' . get_current_user_id();
	$queue   = get_transient( $key );
	$queue   = is_array( $queue ) ? $queue : array();
	$queue[] = array( $message, $type );
	set_transient( $key, $queue, 120 );
}

function gsup_render_flash() {
	$key   = 'gsup_flash_' . get_current_user_id();
	$queue = get_transient( $key );
	if ( ! is_array( $queue ) ) {
		return;
	}
	delete_transient( $key );
	foreach ( $queue as $notice ) {
		$type = in_array( $notice[1], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice[1] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . wp_kses_post( $notice[0] ) . '</p></div>';
	}
}

function gsup_copy_button( $value, $label = 'Copy' ) {
	return '<button type="button" class="button button-small gsup-copy" data-copy="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</button>';
}

/**
 * Store categories in tree order with their full path, e.g. "Home › Candles".
 *
 * @return array<int,array{id:int,name:string,path:string,depth:int}>
 */
function gsup_category_tree() {
	$terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'orderby'    => 'name',
		)
	);
	if ( is_wp_error( $terms ) || ! $terms ) {
		return array();
	}
	$children = array();
	foreach ( $terms as $t ) {
		$children[ (int) $t->parent ][] = $t;
	}
	$out  = array();
	$walk = function ( $parent, $depth, $prefix ) use ( &$walk, &$out, $children ) {
		if ( empty( $children[ $parent ] ) ) {
			return;
		}
		foreach ( $children[ $parent ] as $t ) {
			$name  = html_entity_decode( $t->name, ENT_QUOTES );
			$path  = '' === $prefix ? $name : $prefix . ' › ' . $name;
			$out[] = array(
				'id'    => (int) $t->term_id,
				'name'  => $name,
				'path'  => $path,
				'depth' => $depth,
			);
			$walk( (int) $t->term_id, $depth + 1, $path );
		}
	};
	$walk( 0, 0, '' );
	return $out;
}

/** Keep only IDs of real product categories. Accepts an array or "1,2,3". */
function gsup_clean_category_ids( $ids ) {
	if ( ! is_array( $ids ) ) {
		$ids = explode( ',', (string) $ids );
	}
	$clean = array();
	foreach ( $ids as $id ) {
		$id = absint( $id );
		if ( $id && term_exists( $id, 'product_cat' ) ) {
			$clean[ $id ] = $id;
		}
	}
	return array_values( $clean );
}

function gsup_category_names( $ids ) {
	$names = array();
	foreach ( gsup_clean_category_ids( $ids ) as $id ) {
		$t = get_term( $id, 'product_cat' );
		if ( $t && ! is_wp_error( $t ) ) {
			$names[] = html_entity_decode( $t->name, ENT_QUOTES );
		}
	}
	return $names;
}

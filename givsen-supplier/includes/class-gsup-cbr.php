<?php
/**
 * Country Based Restrictions (CBR) from ships-from.
 *
 * Each warehouse maps to the countries that should see its products, e.g. Australia → AU, United States → US.
 * A product whose options all ship from one warehouse gets CBR's "only these countries" setting for that warehouse.
 * Products shipping from several warehouses, or none, are left alone and reported.
 *
 * Where CBR keeps its setting is configurable (Settings → Country restrictions), with a "Look at a product"
 * tool that shows exactly what CBR saved on a product you set up by hand, so the keys and values can be matched.
 * Defaults are CBR's own: _fz_country_restriction_type = "specific", _restricted_countries = [codes].
 */

defined( 'ABSPATH' ) || exit;

class GSUP_CBR {

	const DEFAULT_TYPE_KEY      = '_fz_country_restriction_type';
	const DEFAULT_COUNTRIES_KEY = '_restricted_countries';
	const DEFAULT_TYPE_VALUE    = 'specific';

	public static function enabled() {
		return 'yes' === get_option( 'gsup_cbr_enabled', 'no' );
	}

	/** @return array{type_key:string,countries_key:string,type_value:string,format:string} */
	public static function keys() {
		$type_key      = trim( (string) get_option( 'gsup_cbr_type_key', self::DEFAULT_TYPE_KEY ) );
		$countries_key = trim( (string) get_option( 'gsup_cbr_countries_key', self::DEFAULT_COUNTRIES_KEY ) );
		$type_value    = trim( (string) get_option( 'gsup_cbr_type_value', self::DEFAULT_TYPE_VALUE ) );
		return array(
			'type_key'      => '' !== $type_key ? $type_key : self::DEFAULT_TYPE_KEY,
			'countries_key' => '' !== $countries_key ? $countries_key : self::DEFAULT_COUNTRIES_KEY,
			'type_value'    => '' !== $type_value ? $type_value : self::DEFAULT_TYPE_VALUE,
			'format'        => 'csv' === get_option( 'gsup_cbr_format', 'array' ) ? 'csv' : 'array',
		);
	}

	/** Warehouse → countries that see its products. */
	public static function map() {
		$map = get_option( 'gsup_cbr_map' );
		if ( ! is_array( $map ) ) {
			$map = array(
				'AU' => array( 'AU' ),
				'US' => array( 'US' ),
			);
		}
		return $map;
	}

	/** "AU, NZ" → ['AU','NZ'] (valid WooCommerce country codes only). */
	public static function parse_countries( $text ) {
		$all = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array();
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', strtoupper( (string) $text ) ) as $code ) {
			$code = 'UK' === $code ? 'GB' : $code;
			if ( preg_match( '/^[A-Z]{2}$/', $code ) && ( ! $all || isset( $all[ $code ] ) ) ) {
				$out[ $code ] = $code;
			}
		}
		return array_values( $out );
	}

	/** The warehouses a product ships from (its own for simple products, its variations' for variable ones). */
	public static function warehouses( WC_Product $product ) {
		$ids   = $product->is_type( 'variable' ) ? $product->get_children() : array( $product->get_id() );
		$ships = array();
		foreach ( $ids as $id ) {
			$ship = (string) get_post_meta( $id, GSUP_META_SHIP, true );
			if ( '' !== $ship ) {
				$ships[ $ship ] = true;
			}
		}
		return array_keys( $ships );
	}

	/** What CBR currently has on a product: {type, countries[]}. */
	public static function current( $product_id ) {
		$k         = self::keys();
		$countries = get_post_meta( $product_id, $k['countries_key'], true );
		if ( is_string( $countries ) ) {
			$countries = '' === $countries ? array() : self::parse_countries( $countries );
		}
		return array(
			'type'      => (string) get_post_meta( $product_id, $k['type_key'], true ),
			'countries' => is_array( $countries ) ? array_values( $countries ) : array(),
		);
	}

	/**
	 * Set CBR on a product from where it ships.
	 *
	 * @param bool $overwrite Replace a restriction that's already there (otherwise it's kept).
	 * @return string 'set' | 'same' | 'kept' | 'mixed' | 'no_ship' | 'no_rule' | 'off' | 'missing'
	 */
	public static function apply( $product_id, $overwrite = false ) {
		if ( ! self::enabled() ) {
			return 'off';
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return 'missing';
		}
		$ships = self::warehouses( $product );
		if ( ! $ships ) {
			return 'no_ship';
		}
		if ( count( $ships ) > 1 ) {
			return 'mixed';
		}
		$map       = self::map();
		$countries = isset( $map[ $ships[0] ] ) ? array_values( (array) $map[ $ships[0] ] ) : array();
		if ( ! $countries ) {
			return 'no_rule';
		}
		$k   = self::keys();
		$now = self::current( $product_id );
		if ( $now['type'] === $k['type_value'] && self::same( $now['countries'], $countries ) ) {
			return 'same';
		}
		if ( ! $overwrite && '' !== $now['type'] && 'all' !== $now['type'] ) {
			return 'kept';
		}
		update_post_meta( $product_id, $k['type_key'], $k['type_value'] );
		update_post_meta( $product_id, $k['countries_key'], 'csv' === $k['format'] ? implode( ',', $countries ) : $countries );
		wc_delete_product_transients( $product_id );
		return 'set';
	}

	private static function same( array $a, array $b ) {
		sort( $a );
		sort( $b );
		return $a === $b;
	}

	/**
	 * Apply to every linked product.
	 *
	 * @return array<string,int[]> result => product IDs
	 */
	public static function apply_all( $overwrite = false ) {
		$ids = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'meta_key'         => GSUP_META_PRODUCT, // phpcs:ignore WordPress.DB.SlowDBQuery
				'suppress_filters' => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$out[ self::apply( $id, $overwrite ) ][] = (int) $id;
		}
		return $out;
	}

	/**
	 * Everything on a product that looks like a country setting, for matching CBR's keys.
	 *
	 * @return array<string,mixed>
	 */
	public static function inspect( $product_id ) {
		$found = array();
		foreach ( get_post_meta( $product_id ) as $key => $values ) {
			if ( preg_match( '/countr|restrict|cbr|fz_|geo|visib/i', $key ) ) {
				$found[ $key ] = maybe_unserialize( $values[0] );
			}
		}
		ksort( $found );
		return $found;
	}

	/** Short label for the products list, e.g. "Shown to: AU". */
	public static function label( $product_id ) {
		$now = self::current( $product_id );
		if ( '' === $now['type'] || 'all' === $now['type'] ) {
			return '';
		}
		$verb = self::keys()['type_value'] === $now['type'] ? 'Shown to' : 'Hidden from';
		return $verb . ': ' . ( $now['countries'] ? implode( ', ', $now['countries'] ) : '—' );
	}
}

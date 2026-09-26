<?php
/**
 * "Add to store": turns an AliExpress listing into a draft WooCommerce product.
 *
 * One product per warehouse (ships-from), matching the store's country segmentation.
 * Ships-from is kept in the supplier fields, not as a customer-facing option.
 * Supplier IDs, cost and stock are set on the product/variations; the product is left
 * as a draft so prices and wording can be checked before publishing.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Creator {

	const MAX_GALLERY = 6;
	const MAX_OPTION_IMAGES = 12;

	/* ------------------------------------------------------------ pricing */

	public static function rule() {
		$mult = (float) get_option( 'gsup_price_multiplier', 2 );
		return array(
			'multiplier' => $mult > 0 ? $mult : 2,
			'add'        => (float) get_option( 'gsup_price_add', 0 ),
			'round'      => 'no' !== get_option( 'gsup_price_round', 'yes' ),
			'shipping'   => 'no' !== get_option( 'gsup_price_shipping', 'yes' ),
		);
	}

	/**
	 * Selling price from AliExpress cost using the pricing rule.
	 * The delivery fee is added to the cost first when "include shipping" is on.
	 */
	public static function price_for( $cost, $ship = 0 ) {
		$cost = (float) $cost;
		if ( $cost <= 0 ) {
			return '';
		}
		$r = self::rule();
		if ( $r['shipping'] ) {
			$cost += max( 0, (float) $ship );
		}
		$price = $cost * $r['multiplier'] + $r['add'];
		if ( $r['round'] ) {
			$price = ceil( $price ) - 0.05; // e.g. 23.40 → 23.95
			if ( $price < $cost ) {
				$price += 1;
			}
		}
		return wc_format_decimal( max( $price, 0.01 ), wc_get_price_decimals() );
	}

	/**
	 * AliExpress's delivery quote for one of a listing's options, using the shipping method preference.
	 * One quote per product and warehouse: AliExpress charges the same delivery for every option of a listing
	 * in nearly all cases, and it keeps "Add to store" to a single extra call.
	 *
	 * @return array|WP_Error {code, name, fee, currency, min_days, max_days, tracked}
	 */
	public static function quote( $ae_product_id, $sku_id, $ship ) {
		$options = GSUP_AliExpress::freight( $ae_product_id, $sku_id, gsup_quote_country( $ship ) );
		if ( is_wp_error( $options ) ) {
			return $options;
		}
		return GSUP_AliExpress::choose_freight( $options );
	}

	/* ---------------------------------------------------------- warehouses */

	/**
	 * Group a listing's options by where they ship from.
	 *
	 * @return array<string,array> ship code ('' = not stated) => list of SKUs
	 */
	public static function by_warehouse( array $product ) {
		$groups = array();
		foreach ( $product['skus'] as $sku ) {
			$groups[ $sku['ship_from'] ][] = $sku;
		}
		// Australia first, then United States, then the rest.
		uksort(
			$groups,
			function ( $a, $b ) {
				$rank = array( 'AU' => 0, 'US' => 1 );
				$ra   = isset( $rank[ $a ] ) ? $rank[ $a ] : 2;
				$rb   = isset( $rank[ $b ] ) ? $rank[ $b ] : 2;
				return $ra === $rb ? strcmp( $a, $b ) : $ra - $rb;
			}
		);
		return $groups;
	}

	public static function warehouse_label( $code ) {
		return '' === $code ? 'Not stated (usually China)' : gsup_ship_from_label( $code );
	}

	/** Option text without the ships-from part, e.g. "Color: Red · Size: M". */
	public static function option_text( array $sku ) {
		$parts = array();
		foreach ( $sku['props'] as $p ) {
			if ( ! $p['is_ship'] ) {
				$parts[] = $p['name'] . ': ' . $p['value'];
			}
		}
		return implode( ' · ', $parts );
	}

	/* ------------------------------------------------------------- create */

	/**
	 * @param array    $product  Normalised listing from GSUP_AliExpress::get_product().
	 * @param string   $ship     Warehouse code chosen ('' = not stated).
	 * @param string[] $sku_ids  Options to include.
	 * @param string   $title    Product title to use.
	 * @param int[]    $category_ids Store categories (empty = WooCommerce's default category).
	 * @param array    $opts {
	 *     @type array $names            Option name renames: AliExpress name => your name.
	 *     @type array $values           Option value renames: AliExpress name => [AliExpress value => your value].
	 *     @type bool  $tidy_description Clean AliExpress styling out of the description (default true).
	 *     @type bool  $specs            Add item specifics to "Additional information" (default true).
	 *     @type bool  $short            Short description from the first specifics (default false).
	 * }
	 * @return int|WP_Error New product ID.
	 */
	public static function create( array $product, $ship, array $sku_ids, $title, array $category_ids = array(), array $opts = array() ) {
		$opts = array_merge(
			array(
				'names'            => array(),
				'values'           => array(),
				'tidy_description' => true,
				'specs'            => true,
				'short'            => false,
			),
			$opts
		);
		$chosen = array();
		foreach ( $product['skus'] as $sku ) {
			if ( $sku['ship_from'] === $ship && in_array( (string) $sku['sku_id'], $sku_ids, true ) ) {
				$chosen[] = $sku;
			}
		}
		if ( ! $chosen ) {
			return new WP_Error( 'gsup_none', 'Tick at least one option to add.' );
		}
		$title = trim( $title ) !== '' ? trim( $title ) : $product['title'];

		// Attribute names in AliExpress order, leaving out ships-from.
		$names = array();
		foreach ( $chosen as $sku ) {
			foreach ( $sku['props'] as $p ) {
				if ( ! $p['is_ship'] && ! in_array( $p['name'], $names, true ) ) {
					$names[] = $p['name'];
				}
			}
		}

		// Drop exact duplicates (same options), keeping the first.
		$seen   = array();
		$unique = array();
		foreach ( $chosen as $sku ) {
			$key = strtolower( self::option_text( $sku ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$unique[]     = $sku;
		}
		$chosen      = $unique;
		$is_variable = count( $chosen ) > 1 && $names;
		list( $name_of, $value_of ) = self::renamer( $names, $chosen, $opts );

		$freight = self::quote( $product['product_id'], $chosen[0]['sku_id'], $ship );
		$freight = is_wp_error( $freight ) ? null : $freight;

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- image downloads can take a while.
		}

		$wc = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
		$wc->set_name( $title );
		$wc->set_status( 'draft' );
		$wc->set_description( $opts['tidy_description'] ? GSUP_Tidy::description( $product['description'], $title ) : self::clean_description( $product['description'] ) );
		$specs = $opts['specs'] || $opts['short'] ? GSUP_Tidy::specs( isset( $product['specs'] ) ? $product['specs'] : array() ) : array();
		if ( $opts['short'] && $specs ) {
			$wc->set_short_description( GSUP_Tidy::short_description( $specs ) );
		}
		$category_ids = gsup_clean_category_ids( $category_ids );
		if ( $category_ids ) {
			$wc->set_category_ids( $category_ids );
		}

		if ( $is_variable ) {
			$attributes = array();
			foreach ( $names as $i => $name ) {
				$values = array();
				foreach ( $chosen as $sku ) {
					foreach ( $sku['props'] as $p ) {
						if ( $p['name'] === $name && ! in_array( $p['value'], $values, true ) ) {
							$values[] = $p['value'];
						}
					}
				}
				$attr = new WC_Product_Attribute();
				$attr->set_name( $name_of( $name ) );
				$attr->set_options(
					array_map(
						function ( $v ) use ( $value_of, $name ) {
							return $value_of( $name, $v );
						},
						$values
					)
				);
				$attr->set_position( $i );
				$attr->set_visible( true );
				$attr->set_variation( true );
				$attributes[] = $attr;
			}
		} else {
			$attributes = array();
			$sku        = $chosen[0];
			self::apply_price_and_stock( $wc, $sku, $freight );
		}
		// Item specifics → "Additional information" tab (not used for variations).
		if ( $opts['specs'] ) {
			$taken = array();
			foreach ( $attributes as $a ) {
				$taken[ strtolower( $a->get_name() ) ] = true;
			}
			$pos = count( $attributes );
			foreach ( $specs as $spec_name => $spec_value ) {
				if ( isset( $taken[ strtolower( $spec_name ) ] ) ) {
					continue;
				}
				$attr = new WC_Product_Attribute();
				$attr->set_name( $spec_name );
				$attr->set_options( array( str_replace( '|', '/', $spec_value ) ) );
				$attr->set_position( $pos++ );
				$attr->set_visible( true );
				$attr->set_variation( false );
				$attributes[] = $attr;
			}
		}
		if ( $attributes ) {
			$wc->set_attributes( $attributes );
		}

		$wc->update_meta_data( GSUP_META_PRODUCT, (string) $product['product_id'] );
		if ( ! $is_variable ) {
			$sku = $chosen[0];
			$wc->update_meta_data( GSUP_META_SKU, (string) $sku['sku_id'] );
			$wc->update_meta_data( GSUP_META_OPTION, mb_substr( $sku['option'], 0, 255 ) );
			$wc->update_meta_data( GSUP_META_COST, (string) $sku['price'] );
			self::set_freight_meta( $wc, $freight );
			if ( '' !== $ship ) {
				$wc->update_meta_data( GSUP_META_SHIP, $ship );
			}
		}
		$product_id = $wc->save();
		if ( ! $product_id ) {
			return new WP_Error( 'gsup_save', 'WooCommerce couldn’t save the new product.' );
		}

		// Images: main + gallery.
		$image_ids = array();
		foreach ( array_slice( $product['images'], 0, self::MAX_GALLERY ) as $url ) {
			$id = self::sideload( $url, $product_id, $title );
			if ( $id ) {
				$image_ids[] = $id;
			}
		}
		if ( $image_ids ) {
			$wc->set_image_id( array_shift( $image_ids ) );
			$wc->set_gallery_image_ids( $image_ids );
			$wc->save();
		}

		GSUP_Profit::$paused = true;
		if ( $is_variable ) {
			$image_cache = array();
			foreach ( $chosen as $sku ) {
				$v     = new WC_Product_Variation();
				$attrs = array();
				$img   = '';
				foreach ( $sku['props'] as $p ) {
					if ( $p['is_ship'] ) {
						continue;
					}
					$attrs[ sanitize_title( $name_of( $p['name'] ) ) ] = $value_of( $p['name'], $p['value'] );
					if ( '' === $img && '' !== $p['image'] ) {
						$img = $p['image'];
					}
				}
				$v->set_parent_id( $product_id );
				$v->set_attributes( $attrs );
				self::apply_price_and_stock( $v, $sku, $freight );
				if ( '' !== $img ) {
					if ( ! isset( $image_cache[ $img ] ) && count( $image_cache ) < self::MAX_OPTION_IMAGES ) {
						$image_cache[ $img ] = self::sideload( $img, $product_id, $title . ' — ' . self::option_text( $sku ) );
					}
					if ( ! empty( $image_cache[ $img ] ) ) {
						$v->set_image_id( $image_cache[ $img ] );
					}
				}
				$v->update_meta_data( GSUP_META_SKU, (string) $sku['sku_id'] );
				$v->update_meta_data( GSUP_META_OPTION, mb_substr( $sku['option'], 0, 255 ) );
				$v->update_meta_data( GSUP_META_COST, (string) $sku['price'] );
				self::set_freight_meta( $v, $freight );
				if ( '' !== $ship ) {
					$v->update_meta_data( GSUP_META_SHIP, $ship );
				}
				$v->save();
			}
			WC_Product_Variable::sync( $product_id );
		}
		GSUP_Profit::$paused = false;
		wc_delete_product_transients( $product_id );

		self::mark_import_rows( $product['product_id'], $product_id );
		if ( class_exists( 'GSUP_CBR' ) ) {
			GSUP_CBR::apply( $product_id );
		}
		if ( class_exists( 'GSUP_Profit' ) ) {
			GSUP_Profit::refresh_flag( $product_id );
		}
		return $product_id;
	}

	/**
	 * Functions that give an option name/value its store name. Renamed values stay unique within an option
	 * (two values renamed alike keep the second's original), so no two variations clash.
	 *
	 * @return array{0:callable,1:callable} name_of( $name ), value_of( $name, $value )
	 */
	private static function renamer( array $names, array $chosen, array $opts ) {
		// First free choice of: what you typed, the AliExpress original, then the original numbered.
		$unique = function ( $wanted, $original, array &$taken, $key_of ) {
			$candidates = array( str_replace( '|', '/', trim( (string) $wanted ) ), str_replace( '|', '/', trim( (string) $original ) ) );
			for ( $i = 2; $i < 50; $i++ ) {
				$candidates[] = $candidates[1] . ' ' . $i;
			}
			foreach ( $candidates as $c ) {
				$k = $key_of( $c );
				if ( '' !== $c && '' !== $k && ! isset( $taken[ $k ] ) ) {
					$taken[ $k ] = true;
					return $c;
				}
			}
			return $original;
		};
		$name_key = function ( $v ) {
			return sanitize_title( $v ); // WooCommerce keys variation attributes by this.
		};
		$value_key = function ( $v ) {
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $v ) : strtolower( $v );
		};
		$name_map = array();
		$used     = array();
		foreach ( $names as $n ) {
			$name_map[ $n ] = $unique( isset( $opts['names'][ $n ] ) ? $opts['names'][ $n ] : '', $n, $used, $name_key );
		}
		$value_map = array();
		foreach ( $names as $n ) {
			$taken = array();
			foreach ( $chosen as $sku ) {
				foreach ( $sku['props'] as $p ) {
					if ( $p['name'] !== $n || isset( $value_map[ $n ][ $p['value'] ] ) ) {
						continue;
					}
					$value_map[ $n ][ $p['value'] ] = $unique( isset( $opts['values'][ $n ][ $p['value'] ] ) ? $opts['values'][ $n ][ $p['value'] ] : '', $p['value'], $taken, $value_key );
				}
			}
		}
		return array(
			function ( $n ) use ( $name_map ) {
				return isset( $name_map[ $n ] ) ? $name_map[ $n ] : $n;
			},
			function ( $n, $v ) use ( $value_map ) {
				return isset( $value_map[ $n ][ $v ] ) ? $value_map[ $n ][ $v ] : $v;
			},
		);
	}

	/** AliExpress description HTML, minus scripts, styles and anything unsafe. */
	private static function clean_description( $html ) {
		$html = preg_replace( '#<(script|style|iframe|noscript)\b[^>]*>.*?</\1\s*>#is', '', (string) $html );
		return trim( wp_kses_post( $html ) );
	}

	/** Delivery fee and method on a product/variation (removed when AliExpress gave no quote). */
	public static function set_freight_meta( $wc, $freight ) {
		if ( $freight ) {
			$wc->update_meta_data( GSUP_META_SHIP_COST, wc_format_decimal( $freight['fee'], 2 ) );
			$wc->update_meta_data( GSUP_META_SHIP_METHOD, (string) $freight['code'] );
		}
	}

	public static function apply_price_and_stock( $wc, array $sku, $freight = null ) {
		$price = self::price_for( $sku['price'], $freight ? $freight['fee'] : 0 );
		if ( '' !== $price ) {
			$wc->set_regular_price( $price );
		}
		if ( null === $sku['stock'] ) {
			$wc->set_manage_stock( false );
			$wc->set_stock_status( 'instock' );
		} else {
			$wc->set_manage_stock( true );
			$wc->set_stock_quantity( max( 0, (int) $sku['stock'] ) );
		}
	}

	/** Download an image from AliExpress into the Media Library. */
	private static function sideload( $url, $product_id, $desc ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$url = preg_replace( '/_\d+x\d+[^.]*\.(jpe?g|png|webp)$/i', '', $url ); // Full size, not a thumbnail.
		$id  = media_sideload_image( $url, $product_id, mb_substr( $desc, 0, 200 ), 'id' );
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/** Point matching import-list rows at the new product/variations. */
	private static function mark_import_rows( $ae_product_id, $product_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, ae_sku_id FROM ' . GSUP_Install::table() . ' WHERE ae_product_id = %s AND status <> %s', (string) $ae_product_id, 'dismissed' ), ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $row ) {
			$wc_id = $product_id;
			if ( '' !== (string) $row['ae_sku_id'] ) {
				$wc_id = 0;
				foreach ( gsup_find_option_links( $ae_product_id, $row['ae_sku_id'] ) as $id ) {
					if ( (int) $id === (int) $product_id || (int) wp_get_post_parent_id( $id ) === (int) $product_id ) {
						$wc_id = $id;
						break;
					}
				}
				if ( ! $wc_id ) {
					continue; // That option wasn't added to this product.
				}
			}
			GSUP_Import::set_status( (int) $row['id'], 'linked', $wc_id );
		}
	}
}

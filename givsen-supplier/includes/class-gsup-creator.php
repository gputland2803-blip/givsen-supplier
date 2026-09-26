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
	 * @return int|WP_Error New product ID.
	 */
	public static function create( array $product, $ship, array $sku_ids, $title, array $category_ids = array() ) {
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

		$freight = self::quote( $product['product_id'], $chosen[0]['sku_id'], $ship );
		$freight = is_wp_error( $freight ) ? null : $freight;

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- image downloads can take a while.
		}

		$wc = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
		$wc->set_name( $title );
		$wc->set_status( 'draft' );
		$wc->set_description( self::clean_description( $product['description'] ) );
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
				$attr->set_name( $name );
				$attr->set_options( $values );
				$attr->set_position( $i );
				$attr->set_visible( true );
				$attr->set_variation( true );
				$attributes[] = $attr;
			}
			$wc->set_attributes( $attributes );
		} else {
			$sku = $chosen[0];
			self::apply_price_and_stock( $wc, $sku, $freight );
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
					$attrs[ sanitize_title( $p['name'] ) ] = $p['value'];
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

	private static function apply_price_and_stock( $wc, array $sku, $freight = null ) {
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

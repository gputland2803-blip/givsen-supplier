<?php
/**
 * Bulk tidy-up for products you already have (Products → All Products → Bulk actions → "Tidy text").
 *
 * Preview first, then apply per product: title, description, item specifics (fetched from AliExpress for linked
 * products) and option names/values (custom options only — global attributes are shared, so they're left alone).
 * What was there before is saved on the product, and "Undo tidy-up" on its Supplier tab puts it back.
 * Supplier links are by ID, so none of this affects ordering or the sync.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Bulk_Tidy {

	const MAX    = 50;
	const M_UNDO = '_gsup_tidy_undo';

	public static function init() {
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_post_gsup_tidy_apply', array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_gsup_tidy_undo', array( __CLASS__, 'handle_undo' ) );
	}

	public static function bulk_action( $actions ) {
		$actions['gsup_tidy'] = 'Tidy text (Givsen Supplier)';
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'gsup_tidy' !== $action ) {
			return $redirect;
		}
		$ids = array_slice( array_map( 'absint', (array) $ids ), 0, self::MAX );
		return gsup_admin_url(
			array(
				'tab' => 'tidy',
				'ids' => implode( ',', $ids ),
			)
		);
	}

	private static function ids_from_request() {
		$raw = isset( $_REQUEST['ids'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ids'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array_slice( array_filter( array_map( 'absint', explode( ',', $raw ) ) ), 0, self::MAX );
	}

	/** Custom (non-global) attributes of a product, name => [values]. */
	private static function local_attributes( WC_Product $product ) {
		$out = array();
		foreach ( $product->get_attributes() as $attr ) {
			if ( $attr instanceof WC_Product_Attribute && ! $attr->is_taxonomy() ) {
				$out[ $attr->get_name() ] = $attr->get_options();
			}
		}
		return $out;
	}

	/** Old → new for each option name and value, kept unique the same way Add to store does. */
	private static function option_plan( WC_Product $product ) {
		$names  = array();
		$values = array();
		$taken  = array();
		foreach ( self::local_attributes( $product ) as $name => $opts ) {
			$new = GSUP_Tidy::option_name( $name );
			$key = sanitize_title( $new );
			if ( '' === $key || isset( $taken[ $key ] ) ) {
				$new = $name;
				$key = sanitize_title( $name );
			}
			$taken[ $key ]  = true;
			$names[ $name ] = $new;
			$used           = array();
			foreach ( $opts as $v ) {
				$nv = GSUP_Tidy::option_value( $v );
				if ( '' === $nv || isset( $used[ mb_strtolower( $nv ) ] ) ) {
					$nv = $v;
				}
				$used[ mb_strtolower( $nv ) ] = true;
				$values[ $name ][ $v ]        = $nv;
			}
		}
		return array( $names, $values );
	}

	/* -------------------------------------------------------------- screen */

	public static function render() {
		$ids = self::ids_from_request();
		if ( ! $ids ) {
			echo '<p>Choose products in <a href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">Products → All Products</a>, then pick <strong>Tidy text (Givsen Supplier)</strong> from <em>Bulk actions</em>.</p>';
			return;
		}
		// Item specifics for linked products, fetched together.
		$pairs = array();
		foreach ( $ids as $id ) {
			$ae = (string) get_post_meta( $id, GSUP_META_PRODUCT, true );
			if ( '' !== $ae ) {
				$pairs[ $id ] = array( $ae, gsup_quote_country( (string) get_post_meta( $id, GSUP_META_SHIP, true ) ) );
			}
		}
		$listings = $pairs && GSUP_AliExpress::is_connected() ? GSUP_AliExpress::get_products( array_values( $pairs ) ) : array();

		echo '<h2>Tidy text for ' . count( $ids ) . ' product(s)</h2>';
		echo '<p class="gsup-meta">Check the new titles (edit any of them), untick products to skip, then apply. Everything is saved first, so each product can be put back with <em>Undo tidy-up</em> on its Supplier tab.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="gsup_tidy_apply"><input type="hidden" name="ids" value="' . esc_attr( implode( ',', $ids ) ) . '">';
		wp_nonce_field( 'gsup_tidy_apply' );
		echo '<p><label><input type="checkbox" name="do_title" value="1" checked> Titles</label> &nbsp; ';
		echo '<label><input type="checkbox" name="do_desc" value="1"> Descriptions (remove AliExpress styling, links and seller notes; copy its images to your site) — <em>leave off for descriptions you’ve rewritten</em></label> &nbsp; ';
		echo '<label><input type="checkbox" name="do_specs" value="1" checked> Item specifics → Additional information</label> &nbsp; ';
		echo '<label><input type="checkbox" name="do_options" value="1"> Option names and values</label></p>';
		echo '<table class="widefat striped gsup-sku-table"><thead><tr><td class="check-column"><input type="checkbox" class="gsup-tidy-all" checked aria-label="Select all"></td><th>Product</th><th>New title</th><th>Also</th></tr></thead><tbody>';
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || $product->is_type( 'variation' ) ) {
				continue;
			}
			$title = GSUP_Tidy::title( $product->get_name() );
			$also  = array();
			$desc  = GSUP_Tidy::description( $product->get_description(), $title );
			if ( trim( $desc ) !== trim( $product->get_description() ) ) {
				$also[] = 'description can be cleaned';
			}
			$key = isset( $pairs[ $id ] ) ? implode( '|', $pairs[ $id ] ) : '';
			if ( $key && isset( $listings[ $key ] ) && ! is_wp_error( $listings[ $key ] ) ) {
				$specs = self::new_specs( $product, $listings[ $key ] );
				if ( $specs ) {
					$also[] = count( $specs ) . ' specifics (' . implode( ', ', array_slice( array_keys( $specs ), 0, 3 ) ) . ( count( $specs ) > 3 ? '…' : '' ) . ')';
				}
			}
			list( $names, $values ) = self::option_plan( $product );
			$changes = array();
			foreach ( $values as $name => $map ) {
				foreach ( $map as $old => $new ) {
					if ( $old !== $new ) {
						$changes[] = $old . ' → ' . $new;
					}
				}
				if ( $names[ $name ] !== $name ) {
					$changes[] = $name . ' → ' . $names[ $name ];
				}
			}
			if ( $changes ) {
				$also[] = 'options: ' . implode( ', ', array_slice( $changes, 0, 4 ) ) . ( count( $changes ) > 4 ? '…' : '' );
			}
			echo '<tr><th scope="row" class="check-column"><input type="checkbox" name="pick[]" value="' . (int) $id . '" checked></th>';
			echo '<td><a href="' . esc_url( admin_url( 'post.php?post=' . (int) $id . '&action=edit' ) ) . '">' . esc_html( $product->get_name() ) . '</a></td>';
			echo '<td><input type="text" name="title[' . (int) $id . ']" class="large-text" value="' . esc_attr( $title ) . '"></td>';
			echo '<td class="gsup-meta">' . esc_html( $also ? implode( ' · ', $also ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">Apply to ticked products</button> <a class="button-link" href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">Cancel</a></p></form>';
		echo '<script>document.addEventListener("change",function(e){if(e.target.classList.contains("gsup-tidy-all")){document.querySelectorAll("input[name=\'pick[]\']").forEach(function(b){b.checked=e.target.checked;});}});</script>';
	}

	/** Specifics not already on the product. */
	private static function new_specs( WC_Product $product, array $listing ) {
		$have = array();
		foreach ( $product->get_attributes() as $attr ) {
			$have[ strtolower( wc_attribute_label( $attr->get_name() ) ) ] = true;
		}
		$out = array();
		foreach ( GSUP_Tidy::specs( isset( $listing['specs'] ) ? $listing['specs'] : array() ) as $name => $value ) {
			if ( ! isset( $have[ strtolower( $name ) ] ) ) {
				$out[ $name ] = $value;
			}
		}
		return $out;
	}

	/* --------------------------------------------------------------- apply */

	public static function handle_apply() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( 'You do not have permission to do that.', 403 );
		}
		check_admin_referer( 'gsup_tidy_apply' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- copying description images can take a while.
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$picked = isset( $_POST['pick'] ) && is_array( $_POST['pick'] ) ? array_slice( array_map( 'absint', $_POST['pick'] ), 0, self::MAX ) : array();
		$titles = isset( $_POST['title'] ) && is_array( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per product.
		$do     = array(
			'title'   => ! empty( $_POST['do_title'] ),
			'desc'    => ! empty( $_POST['do_desc'] ),
			'specs'   => ! empty( $_POST['do_specs'] ),
			'options' => ! empty( $_POST['do_options'] ),
		);
		// phpcs:enable
		$pairs = array();
		if ( $do['specs'] && GSUP_AliExpress::is_connected() ) {
			foreach ( $picked as $id ) {
				$ae = (string) get_post_meta( $id, GSUP_META_PRODUCT, true );
				if ( '' !== $ae ) {
					$pairs[ $id ] = array( $ae, gsup_quote_country( (string) get_post_meta( $id, GSUP_META_SHIP, true ) ) );
				}
			}
		}
		$listings = $pairs ? GSUP_AliExpress::get_products( array_values( $pairs ) ) : array();
		$done     = 0;
		foreach ( $picked as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || $product->is_type( 'variation' ) || ! current_user_can( 'edit_product', $id ) ) {
				continue;
			}
			self::save_undo( $product );
			if ( $do['title'] && isset( $titles[ $id ] ) && '' !== trim( sanitize_text_field( $titles[ $id ] ) ) ) {
				$product->set_name( sanitize_text_field( $titles[ $id ] ) );
			}
			if ( $do['desc'] ) {
				$clean = GSUP_Tidy::description( $product->get_description(), $product->get_name() );
				$product->set_description( GSUP_Creator::localize_images( $clean, $id, $product->get_name(), 8 ) );
			}
			$attributes = $product->get_attributes();
			$renames    = array();
			if ( $do['options'] ) {
				list( $names, $values ) = self::option_plan( $product );
				foreach ( $attributes as $key => $attr ) {
					if ( ! $attr instanceof WC_Product_Attribute || $attr->is_taxonomy() || ! isset( $names[ $attr->get_name() ] ) ) {
						continue;
					}
					$old_name = $attr->get_name();
					$map      = $values[ $old_name ];
					$attr->set_options(
						array_map(
							function ( $v ) use ( $map ) {
								return isset( $map[ $v ] ) ? $map[ $v ] : $v;
							},
							$attr->get_options()
						)
					);
					$attr->set_name( $names[ $old_name ] );
					$renames[ sanitize_title( $old_name ) ] = array( sanitize_title( $names[ $old_name ] ), $map );
				}
			}
			$key = isset( $pairs[ $id ] ) ? implode( '|', $pairs[ $id ] ) : '';
			if ( $do['specs'] && $key && isset( $listings[ $key ] ) && ! is_wp_error( $listings[ $key ] ) ) {
				$pos = count( $attributes );
				foreach ( self::new_specs( $product, $listings[ $key ] ) as $name => $value ) {
					$attr = new WC_Product_Attribute();
					$attr->set_name( $name );
					$attr->set_options( array( str_replace( '|', '/', $value ) ) );
					$attr->set_position( $pos++ );
					$attr->set_visible( true );
					$attr->set_variation( false );
					$attributes[] = $attr;
				}
			}
			$product->set_attributes( array_values( $attributes ) );
			$product->save();
			// Variations follow renamed options.
			if ( $renames && $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $vid ) {
					$v = wc_get_product( $vid );
					if ( ! $v ) {
						continue;
					}
					$attrs = array();
					foreach ( $v->get_attributes() as $k => $val ) {
						if ( isset( $renames[ $k ] ) ) {
							$map                          = $renames[ $k ][1];
							$attrs[ $renames[ $k ][0] ] = ( '' !== $val && isset( $map[ $val ] ) ) ? $map[ $val ] : self::map_loose( $map, $val );
						} else {
							$attrs[ $k ] = $val;
						}
					}
					$v->set_attributes( $attrs );
					$v->save();
				}
				WC_Product_Variable::sync( $id );
			}
			wc_delete_product_transients( $id );
			++$done;
		}
		gsup_flash( 'Tidied ' . (int) $done . ' product(s). Each one can be put back with “Undo tidy-up” on its Supplier tab.' );
		wp_safe_redirect( admin_url( 'edit.php?post_type=product' ) );
		exit;
	}

	/** Variation values are sometimes stored in a different case or as a slug. */
	private static function map_loose( array $map, $val ) {
		foreach ( $map as $old => $new ) {
			if ( 0 === strcasecmp( $old, $val ) || sanitize_title( $old ) === $val ) {
				return $new;
			}
		}
		return $val;
	}

	/* ---------------------------------------------------------------- undo */

	private static function save_undo( WC_Product $product ) {
		$vars = array();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $vid ) {
				$meta = array();
				foreach ( get_post_meta( $vid ) as $k => $v ) {
					if ( 0 === strpos( $k, 'attribute_' ) ) {
						$meta[ $k ] = $v[0];
					}
				}
				$vars[ $vid ] = $meta;
			}
		}
		update_post_meta(
			$product->get_id(),
			self::M_UNDO,
			array(
				'at'          => time(),
				'title'       => $product->get_name(),
				'description' => $product->get_description(),
				'attributes'  => get_post_meta( $product->get_id(), '_product_attributes', true ),
				'variations'  => $vars,
			)
		);
	}

	public static function undo_url( $product_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=gsup_tidy_undo&product=' . (int) $product_id ), 'gsup_tidy_undo_' . (int) $product_id );
	}

	public static function handle_undo() {
		$id = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! current_user_can( 'edit_product', $id ) ) {
			wp_die( 'You do not have permission to do that.', 403 );
		}
		check_admin_referer( 'gsup_tidy_undo_' . $id );
		$u = get_post_meta( $id, self::M_UNDO, true );
		if ( is_array( $u ) ) {
			wp_update_post(
				array(
					'ID'           => $id,
					'post_title'   => $u['title'],
					'post_content' => $u['description'],
				)
			);
			update_post_meta( $id, '_product_attributes', $u['attributes'] );
			foreach ( (array) $u['variations'] as $vid => $meta ) {
				foreach ( get_post_meta( $vid ) as $k => $unused ) {
					if ( 0 === strpos( $k, 'attribute_' ) && ! isset( $meta[ $k ] ) ) {
						delete_post_meta( $vid, $k );
					}
				}
				foreach ( $meta as $k => $v ) {
					update_post_meta( $vid, $k, $v );
				}
				wc_delete_product_transients( $vid );
			}
			delete_post_meta( $id, self::M_UNDO );
			if ( class_exists( 'WC_Product_Variable' ) && wc_get_product( $id ) && wc_get_product( $id )->is_type( 'variable' ) ) {
				WC_Product_Variable::sync( $id );
			}
			wc_delete_product_transients( $id );
			clean_post_cache( $id );
			gsup_flash( 'Tidy-up undone: title, description and options are back as they were.' );
		}
		wp_safe_redirect( admin_url( 'post.php?post=' . $id . '&action=edit' ) );
		exit;
	}
}

<?php
/**
 * "Order on AliExpress" panel on the order edit screen (HPOS and legacy):
 * readiness, each item's AliExpress link and exact option, a copy-ready address,
 * and per-item fields for the AliExpress order number and tracking number.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Order_Panel {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ), 30 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save' ), 50 );
	}

	public static function register() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'gsup-order', 'Order on AliExpress', array( __CLASS__, 'render' ), $screen, 'normal', 'high' );
		}
	}

	private static function order_from( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}
		if ( $post_or_order instanceof WP_Post ) {
			return wc_get_order( $post_or_order->ID );
		}
		return false;
	}

	/**
	 * Readiness banner. Givsen gifts sit in "Awaiting Givsen Address" until claimed — never order those.
	 */
	private static function readiness( WC_Order $order ) {
		$status = $order->get_status();
		if ( 'givsen-pending' === $status ) {
			return array( 'stop', 'Don’t order yet — waiting for the gift recipient’s address.' );
		}
		if ( 'processing' === $status ) {
			return array( 'go', 'Ready to order on AliExpress.' );
		}
		if ( 'completed' === $status ) {
			return array( 'done', 'Order is completed.' );
		}
		return array( 'stop', 'Not ready to order — this order is “' . wc_get_order_status_name( $status ) . '”.' );
	}

	/**
	 * Address rows in the order AliExpress asks for them.
	 *
	 * @return array<int,array{0:string,1:string,2:string}> label, value, note
	 */
	private static function address_rows( WC_Order $order ) {
		$use  = $order->has_shipping_address() ? 'shipping' : 'billing';
		$get  = function ( $field ) use ( $order, $use ) {
			$method = 'get_' . $use . '_' . $field;
			return is_callable( array( $order, $method ) ) ? trim( (string) $order->$method() ) : '';
		};
		$note = 'billing' === $use ? 'from billing' : '';

		$country_code = $get( 'country' );
		$countries    = WC()->countries->get_countries();
		$country      = isset( $countries[ $country_code ] ) ? html_entity_decode( $countries[ $country_code ] ) : $country_code;
		$country      = preg_replace( '/\s*\([A-Z]{2,3}\)$/', '', $country ); // "United States (US)" → "United States", as AliExpress lists it.
		$state_code   = $get( 'state' );
		$states       = $country_code ? WC()->countries->get_states( $country_code ) : array();
		$state        = ( is_array( $states ) && isset( $states[ $state_code ] ) ) ? html_entity_decode( $states[ $state_code ] ) : $state_code;

		$phone      = 'shipping' === $use ? trim( (string) $order->get_shipping_phone() ) : '';
		$phone_note = $note;
		if ( '' === $phone ) {
			$phone      = trim( (string) $order->get_billing_phone() );
			$phone_note = 'shipping' === $use ? 'buyer’s phone (no recipient phone on the order)' : $note;
		}

		$rows = array(
			array( 'Contact name', trim( $get( 'first_name' ) . ' ' . $get( 'last_name' ) ), $note ),
			array( 'Mobile number', $phone, $phone_note ),
			array( 'Country/region', $country, $note ),
			array( 'Street address', $get( 'address_1' ), $note ),
			array( 'Apartment, suite, unit', $get( 'address_2' ), $note ),
			array( 'State/province', $state, $note ),
			array( 'City/suburb', $get( 'city' ), $note ),
			array( 'Postcode', $get( 'postcode' ), $note ),
		);
		$company = $get( 'company' );
		if ( '' !== $company ) {
			$rows[] = array( 'Company (add to street line if needed)', $company, $note );
		}
		return $rows;
	}

	public static function render( $post_or_order ) {
		$order = self::order_from( $post_or_order );
		if ( ! $order ) {
			return;
		}
		wp_nonce_field( 'gsup_order_save', 'gsup_order_nonce' );

		list( $state, $message ) = self::readiness( $order );
		echo '<div class="gsup-ready gsup-ready--' . esc_attr( $state ) . '">' . esc_html( $message ) . '</div>';

		echo '<div class="gsup-order">';
		echo '<div class="gsup-order-items"><h4>Items to buy</h4>';
		echo '<table class="widefat gsup-items"><tbody>';

		foreach ( $order->get_items() as $item_id => $item ) {
			/** @var WC_Order_Item_Product $item */
			$product  = $item->get_product();
			$link     = $product ? gsup_get_supplier_link( $product ) : null;
			$qty      = $item->get_quantity() + $order->get_qty_refunded_for_item( $item_id );
			$woo_opts = wp_strip_all_tags(
				wc_display_item_meta(
					$item,
					array(
						'echo'      => false,
						'before'    => '',
						'after'     => '',
						'separator' => ', ',
						'autop'     => false,
					)
				)
			);

			echo '<tr><td>';
			echo '<div class="gsup-item-head"><strong>' . esc_html( $item->get_name() ) . '</strong> <span class="gsup-qty">× ' . (int) $qty . '</span></div>';
			if ( '' !== trim( $woo_opts ) ) {
				echo '<div class="gsup-meta">In your store: ' . esc_html( $woo_opts ) . '</div>';
			}

			if ( ! $product ) {
				echo '<div class="gsup-warn">This product no longer exists in your store, so its supplier link can’t be read.</div>';
			} elseif ( '' === $link['product_id'] ) {
				echo '<div class="gsup-warn">Not linked to AliExpress. <a href="' . esc_url( gsup_product_edit_url( $product->get_id() ) ) . '">Link it on the product</a>.</div>';
			} else {
				echo '<div class="gsup-supplier">';
				echo '<a class="button button-primary" href="' . esc_url( gsup_ae_url( $link['product_id'] ) ) . '" target="_blank" rel="noopener noreferrer">Open on AliExpress ↗</a> ';
				echo '<span class="gsup-meta">Product ID ' . esc_html( $link['product_id'] ) . '</span>';
				echo '<dl class="gsup-pick">';
				if ( '' !== $link['option'] ) {
					echo '<dt>Pick this option</dt><dd><strong>' . esc_html( $link['option'] ) . '</strong> ' . gsup_copy_button( $link['option'] ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				if ( '' !== $link['ship_from'] ) {
					echo '<dt>Ships from</dt><dd><strong>' . esc_html( gsup_ship_from_label( $link['ship_from'] ) ) . '</strong></dd>';
				}
				if ( '' !== $link['sku_id'] ) {
					echo '<dt>SKU ID</dt><dd>' . esc_html( $link['sku_id'] ) . '</dd>';
				}
				echo '<dt>Quantity</dt><dd><strong>' . (int) $qty . '</strong></dd>';
				echo '</dl>';
				if ( get_post_meta( $product->get_id(), '_gsup_option_gone', true ) || 'removed' === get_post_meta( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(), '_gsup_sync_status', true ) ) {
					echo '<div class="gsup-warn">AliExpress no longer sells this — check the listing before ordering, or contact the customer.</div>';
				}
				if ( 'parent' !== $link['level'] && '' === $link['sku_id'] && '' === $link['option'] ) {
					echo '<div class="gsup-warn gsup-warn--soft">No option stored for this item — check the AliExpress page carefully before ordering.</div>';
				}
				echo '</div>';
			}

			$ae_order = (string) $item->get_meta( GSUP_ITEM_AE_ORDER );
			$tracking = (string) $item->get_meta( GSUP_ITEM_TRACKING );
			echo '<div class="gsup-fields">';
			echo '<label>AliExpress order number<input type="text" name="gsup_items[' . (int) $item_id . '][ae_order]" value="' . esc_attr( $ae_order ) . '" autocomplete="off"></label>';
			echo '<label>Tracking number<input type="text" name="gsup_items[' . (int) $item_id . '][tracking]" value="' . esc_attr( $tracking ) . '" autocomplete="off"></label>';
			echo '</div>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">Fill these in after ordering, then click <strong>Update</strong>.</p>';
		echo '</div>';

		echo '<div class="gsup-order-address"><h4>Ship to</h4>';
		if ( 'givsen-pending' === $order->get_status() ) {
			echo '<p class="gsup-meta">The recipient’s address appears here once they claim the gift.</p></div></div>';
			return;
		}
		$rows      = self::address_rows( $order );
		$all_lines = array();
		echo '<table class="gsup-address"><tbody>';
		foreach ( $rows as $row ) {
			list( $label, $value, $note ) = $row;
			if ( '' !== $value ) {
				$all_lines[] = $label . ': ' . $value;
			}
			echo '<tr><th>' . esc_html( $label ) . '</th><td>';
			if ( '' === $value ) {
				echo '<span class="gsup-meta">—</span>';
			} else {
				echo '<span class="gsup-addr-val">' . esc_html( $value ) . '</span> ' . gsup_copy_button( $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( '' !== $note ) {
				echo '<div class="gsup-meta">' . esc_html( $note ) . '</div>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $all_lines ) {
			echo '<p>' . gsup_copy_button( implode( "\n", $all_lines ), 'Copy whole address' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div></div>';
	}

	public static function save( $order_id ) {
		if ( ! isset( $_POST['gsup_order_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['gsup_order_nonce'] ) ), 'gsup_order_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) || empty( $_POST['gsup_items'] ) || ! is_array( $_POST['gsup_items'] ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$posted = wp_unslash( $_POST['gsup_items'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		$notes  = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! isset( $posted[ $item_id ] ) || ! is_array( $posted[ $item_id ] ) ) {
				continue;
			}
			$changed = false;
			foreach (
				array(
					'ae_order' => array( GSUP_ITEM_AE_ORDER, 'AliExpress order' ),
					'tracking' => array( GSUP_ITEM_TRACKING, 'tracking number' ),
				) as $field => $spec
			) {
				$new = isset( $posted[ $item_id ][ $field ] ) ? mb_substr( trim( sanitize_text_field( $posted[ $item_id ][ $field ] ) ), 0, 100 ) : '';
				$old = (string) $item->get_meta( $spec[0] );
				if ( $new === $old ) {
					continue;
				}
				if ( '' === $new ) {
					$item->delete_meta_data( $spec[0] );
					$notes[] = sprintf( 'Removed %1$s for “%2$s”.', $spec[1], $item->get_name() );
				} else {
					$item->update_meta_data( $spec[0], $new );
					$notes[] = sprintf( '%1$s %2$s recorded for “%3$s”.', ucfirst( $spec[1] ), $new, $item->get_name() );
				}
				$changed = true;
			}
			if ( $changed ) {
				$item->save();
			}
		}
		if ( $notes ) {
			$order->add_order_note( 'Givsen Supplier: ' . implode( ' ', $notes ), 0, true );
		}
	}
}

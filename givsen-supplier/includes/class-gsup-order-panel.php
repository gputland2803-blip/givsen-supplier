<?php
/**
 * "Order on AliExpress" panel on the order edit screen (HPOS and legacy):
 * readiness, each item's AliExpress link and exact option, a copy-ready address,
 * per-item fields for the AliExpress order number and tracking number, automatic-ordering status
 * with "Place on AliExpress now" / "Check tracking now", and the order's profit.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Order_Panel {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ), 30 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save' ), 50 );
		add_action( 'admin_post_gsup_place_now', array( __CLASS__, 'handle_place_now' ) );
		add_action( 'admin_post_gsup_track_now', array( __CLASS__, 'handle_track_now' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/** Messages after the panel's buttons (shown on the order screen). */
	public static function notices() {
		$screen = get_current_screen();
		$order  = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		if ( $screen && in_array( $screen->id, array( 'shop_order', $order ), true ) ) {
			gsup_render_flash();
		}
	}

	private static function action_url( $action, WC_Order $order ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=gsup_' . $action . '&order_id=' . $order->get_id() ), 'gsup_' . $action . '_' . $order->get_id() );
	}

	private static function action_order( $action ) {
		$id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( 'You do not have permission to do that.', 403 );
		}
		check_admin_referer( 'gsup_' . $action . '_' . $id );
		$order = wc_get_order( $id );
		if ( ! $order ) {
			wp_die( 'Order not found.', 404 );
		}
		return $order;
	}

	public static function handle_place_now() {
		$order  = self::action_order( 'place_now' );
		$result = GSUP_Orders::place( $order->get_id(), true );
		if ( is_wp_error( $result ) ) {
			gsup_flash( esc_html( $result->get_error_message() ), 'error' );
		} elseif ( $result['problems'] ) {
			gsup_flash( 'Placed ' . (int) $result['placed'] . ' item(s) on AliExpress. ' . count( $result['problems'] ) . ' couldn’t be placed — see the reasons below.', $result['placed'] ? 'warning' : 'error' );
		} elseif ( $result['placed'] ) {
			gsup_flash( 'Placed ' . (int) $result['placed'] . ' item(s) on AliExpress.' );
		} else {
			gsup_flash( 'Nothing to place — every AliExpress item already has an order number.', 'info' );
		}
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	public static function handle_track_now() {
		$order  = self::action_order( 'track_now' );
		$result = GSUP_Orders::check_order( $order->get_id() );
		if ( is_wp_error( $result ) ) {
			gsup_flash( 'Couldn’t check with AliExpress: ' . esc_html( $result->get_error_message() ), 'error' );
		} elseif ( $result ) {
			gsup_flash( 'Tracking received for ' . (int) $result . ' item(s).' );
		} else {
			gsup_flash( 'No tracking on AliExpress yet — it usually appears a few days after the seller ships. It’s checked automatically every few hours.', 'info' );
		}
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
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
		self::render_auto( $order );

		echo '<div class="gsup-order">';
		echo '<div class="gsup-order-items"><h4>Items to buy</h4>';
		echo '<table class="widefat gsup-items"><tbody>';

		$profit = GSUP_Profit::order_summary( $order );
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
					echo '<div class="gsup-warn">AliExpress no longer sells this — <a href="' . esc_url( gsup_remap_url( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id() ) ) . '">change supplier</a>, then order it, or contact the customer.</div>';
				}
				if ( 'parent' !== $link['level'] && '' === $link['sku_id'] && '' === $link['option'] ) {
					echo '<div class="gsup-warn gsup-warn--soft">No option stored for this item — check the AliExpress page carefully before ordering.</div>';
				}
				echo '</div>';
			}

			$problem = (string) $item->get_meta( GSUP_ITEM_PROBLEM );
			if ( '' !== $problem && '' === (string) $item->get_meta( GSUP_ITEM_AE_ORDER ) ) {
				echo '<div class="gsup-warn"><strong>Not placed automatically:</strong> ' . esc_html( $problem ) . '</div>';
			} elseif ( '' !== $problem ) {
				echo '<div class="gsup-warn">' . esc_html( $problem ) . '</div>';
			}
			$method  = (string) $item->get_meta( GSUP_Orders::I_METHOD );
			$status  = (string) $item->get_meta( GSUP_Orders::I_AE_STATUS );
			$carrier = (string) $item->get_meta( GSUP_ITEM_CARRIER );
			if ( '' !== $method || '' !== $status || '' !== $carrier ) {
				$bits = array();
				if ( '' !== $method ) {
					$bits[] = 'Delivery: ' . $method;
				}
				if ( '' !== $status ) {
					$bits[] = 'AliExpress status: ' . self::ae_status_label( $status );
				}
				if ( '' !== $carrier ) {
					$bits[] = 'Carrier: ' . $carrier;
				}
				echo '<div class="gsup-meta">' . esc_html( implode( ' · ', $bits ) ) . '</div>';
			}
			if ( $profit && isset( $profit['lines'][ $item_id ] ) ) {
				self::render_line_profit( $profit['lines'][ $item_id ], $order );
			}

			$ae_order = (string) $item->get_meta( GSUP_ITEM_AE_ORDER );
			$tracking = (string) $item->get_meta( GSUP_ITEM_TRACKING );
			echo '<div class="gsup-fields">';
			echo '<label>AliExpress order number<input type="text" name="gsup_items[' . (int) $item_id . '][ae_order]" value="' . esc_attr( $ae_order ) . '" autocomplete="off"></label>';
			$track_link = '' !== $tracking ? ' <a href="' . esc_url( GSUP_Orders::tracking_url( preg_split( '/[\s,;]+/', $tracking )[0] ) ) . '" target="_blank" rel="noopener noreferrer">Track ↗</a>' : '';
			echo '<label>Tracking number' . $track_link . '<input type="text" name="gsup_items[' . (int) $item_id . '][tracking]" value="' . esc_attr( $tracking ) . '" autocomplete="off"></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			echo '</div>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">Filled in automatically when automatic ordering is on. Ordering by hand? Enter the AliExpress order number and click <strong>Update</strong> — tracking is then fetched for you.</p>';
		if ( $profit ) {
			self::render_order_profit( $profit, $order );
		}
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

	/** Automatic ordering status and buttons. */
	private static function render_auto( WC_Order $order ) {
		if ( ! GSUP_AliExpress::is_connected() ) {
			return;
		}
		$state    = (string) $order->get_meta( GSUP_Orders::M_AUTO_STATE );
		$unplaced = 0;
		$untrack  = 0;
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$ae      = (string) $item->get_meta( GSUP_ITEM_AE_ORDER );
			if ( '' === $ae && $product && '' !== gsup_get_supplier_link( $product )['product_id'] ) {
				++$unplaced;
			} elseif ( '' !== $ae && '' === (string) $item->get_meta( GSUP_ITEM_TRACKING ) && ! $item->get_meta( GSUP_Orders::I_DEAD ) ) {
				++$untrack;
			}
		}
		$labels = array(
			'queued'  => 'Queued to be placed on AliExpress automatically (within a minute or two).',
			'placed'  => 'Placed on AliExpress automatically.',
			'partial' => 'Partly placed on AliExpress — some items need ordering by hand (reasons below).',
			'failed'  => 'Couldn’t be placed on AliExpress automatically — reasons below.',
		);
		$line = isset( $labels[ $state ] ) ? $labels[ $state ] : '';
		if ( '' === $line && $unplaced && 'processing' === $order->get_status() ) {
			$line = GSUP_Orders::enabled() ? 'Not placed on AliExpress yet.' : 'Automatic ordering is off (WooCommerce → Givsen Supplier → Settings → Ordering & tracking).';
		}
		if ( '' === $line && ! $untrack ) {
			return;
		}
		echo '<p class="gsup-auto">' . esc_html( $line ) . ' ';
		if ( $unplaced && 'processing' === $order->get_status() ) {
			echo '<a class="button" href="' . esc_url( self::action_url( 'place_now', $order ) ) . '" data-gsup-confirm="Place ' . (int) $unplaced . ' item(s) on AliExpress now? Items that already have an AliExpress order number are skipped.">Place on AliExpress now</a> ';
		}
		if ( $untrack ) {
			echo '<a class="button" href="' . esc_url( self::action_url( 'track_now', $order ) ) . '">Check tracking now</a>';
		}
		echo '</p>';
	}

	private static function ae_status_label( $status ) {
		$map = array(
			'PLACE_ORDER_SUCCESS'       => 'waiting for payment',
			'WAIT_SELLER_SEND_GOODS'    => 'paid, waiting for the seller to ship',
			'SELLER_PART_SEND_GOODS'    => 'partly shipped',
			'WAIT_BUYER_ACCEPT_GOODS'   => 'shipped',
			'FUND_PROCESSING'           => 'payment processing',
			'WAIT_SELLER_EXAMINE_MONEY' => 'payment being checked',
			'RISK_CONTROL'              => 'payment being checked',
			'IN_ISSUE'                  => 'dispute open',
			'IN_FROZEN'                 => 'on hold',
			'IN_CANCEL'                 => 'cancelling',
			'FINISH'                    => 'finished',
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : strtolower( str_replace( '_', ' ', $status ) );
	}

	private static function render_line_profit( array $l, WC_Order $order ) {
		if ( null === $l['cost'] ) {
			echo '<div class="gsup-meta">Profit: AliExpress cost not known for this item.</div>';
			return;
		}
		$cur = array( 'currency' => $order->get_currency() );
		echo '<div class="gsup-meta gsup-line-profit">Customer paid ' . wp_kses_post( wc_price( $l['revenue'], $cur ) ) . ' · AliExpress ' . wp_kses_post( wc_price( $l['cost'], $cur ) );
		if ( 'aliexpress' !== $l['source'] ) {
			echo ' <span title="From the cost saved on the product, including delivery. Replaced by the AliExpress order’s cost once known.">(estimate)</span>';
		}
		echo ' · Profit <strong class="' . ( $l['profit'] < 0 ? 'gsup-sub--bad' : '' ) . '">' . wp_kses_post( wc_price( $l['profit'], $cur ) ) . '</strong></div>';
	}

	private static function render_order_profit( array $p, WC_Order $order ) {
		$cur = array( 'currency' => $order->get_currency() );
		$low = null !== $p['margin'] && $p['margin'] * 100 < GSUP_Profit::min_margin();
		echo '<h4 class="gsup-profit-title">Profit on this order</h4><table class="gsup-profit"><tbody>';
		echo '<tr><th>Customer paid (before tax, after refunds)</th><td>' . wp_kses_post( wc_price( $p['revenue'], $cur ) ) . '</td></tr>';
		echo '<tr><th>AliExpress cost with delivery</th><td>' . wp_kses_post( wc_price( $p['cost'], $cur ) ) . ( $p['unknown'] ? ' <span class="gsup-warn--soft">+ ' . (int) $p['unknown'] . ' item(s) not known</span>' : '' ) . '</td></tr>';
		if ( $p['fees'] > 0 ) {
			echo '<tr><th>Payment fees</th><td>' . wp_kses_post( wc_price( $p['fees'], $cur ) ) . '</td></tr>';
		}
		echo '<tr class="gsup-profit-total' . ( $p['profit'] < 0 || $low ? ' gsup-profit--low' : '' ) . '"><th>Profit</th><td>' . wp_kses_post( wc_price( $p['profit'], $cur ) ) . ' <span class="gsup-meta">(' . esc_html( GSUP_Profit::pct( $p['margin'] ) ) . ' margin)</span></td></tr>';
		echo '</tbody></table>';
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
			$changed       = false;
			$order_changed = false;
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
				$changed       = true;
				$order_changed = $order_changed || ( GSUP_ITEM_AE_ORDER === $spec[0] && '' !== $new );
			}
			if ( $changed ) {
				if ( $order_changed ) {
					// A new AliExpress order number replaces whatever happened to the old one.
					$item->delete_meta_data( GSUP_ITEM_PROBLEM );
					$item->delete_meta_data( GSUP_Orders::I_PLACING );
					$item->delete_meta_data( GSUP_Orders::I_DEAD );
					$item->delete_meta_data( GSUP_Orders::I_AE_STATUS );
				} elseif ( '' !== (string) $item->get_meta( GSUP_ITEM_TRACKING ) && ! $item->get_meta( GSUP_Orders::I_DEAD ) ) {
					$item->delete_meta_data( GSUP_ITEM_PROBLEM );
				}
				$item->save();
				$any = true;
			}
		}
		if ( $notes ) {
			$order->add_order_note( 'Givsen Supplier: ' . implode( ' ', $notes ), 0, true );
		}
		if ( ! empty( $any ) ) {
			GSUP_Orders::after_tracking_change( wc_get_order( $order_id ) );
		}
	}
}

<?php
/**
 * Parcels after they're ordered: late alerts and delivery.
 *
 * - No tracking N days after the AliExpress order (default 7) → alert.
 * - Not delivered by AliExpress's latest delivery estimate + grace days (default 5; 35 days when there's no estimate) → alert,
 *   with a link to the AliExpress order to open a dispute while buyer protection still applies.
 * - Delivered (from AliExpress's tracking events) → date on the item, order note, optional "delivered" email to the customer,
 *   and optionally the order is marked Completed then (instead of when tracking arrives).
 * Parcels in transit are checked twice a day, a batch at a time, least recently checked first.
 * One email per run lists any new alerts.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Parcels {

	const M_TRANSIT   = '_gsup_in_transit';     // 'yes' while any tracked line isn't delivered.
	const M_ALERT     = '_gsup_parcel_alert';   // 'yes' while any line has an open alert.
	const M_DELIVERED = '_gsup_delivered_at';   // Order: when the last parcel was delivered.
	const I_PLACED    = '_gsup_placed_at';      // Item: when the AliExpress order was placed/recorded.
	const I_ETA_DAYS  = '_gsup_eta_days';       // Item: AliExpress's latest delivery estimate, in days.
	const I_DELIVERED = '_gsup_delivered_at';
	const I_LAST      = '_gsup_parcel_last';    // Item: [time, text] of the latest tracking event.
	const I_NOTRACK   = '_gsup_alert_notrack';  // Item: when the "no tracking yet" alert was raised.
	const I_LATE      = '_gsup_alert_late';     // Item: when the "late" alert was raised.

	public static function init() {
		add_action( 'gsup_parcel_check', array( __CLASS__, 'check' ) );
	}

	public static function no_tracking_days() {
		return max( 1, (int) get_option( 'gsup_late_notrack_days', 7 ) );
	}

	public static function grace_days() {
		return max( 0, (int) get_option( 'gsup_late_grace_days', 5 ) );
	}

	public static function email_customer() {
		return 'no' !== get_option( 'gsup_delivered_email', 'yes' );
	}

	/** When orders are marked Completed: 'tracking' (default), 'delivered' or 'no'. */
	public static function complete_when() {
		$v = get_option( 'gsup_complete_when', '' );
		if ( '' === $v ) {
			$v = 'no' === get_option( 'gsup_complete_on_tracking', 'yes' ) ? 'no' : 'tracking';
		}
		return in_array( $v, array( 'tracking', 'delivered', 'no' ), true ) ? $v : 'tracking';
	}

	/** Record that a line was ordered (automatic ordering, or an order number typed in). */
	public static function mark_placed( WC_Order_Item_Product $item, $eta_days = 0 ) {
		$item->update_meta_data( self::I_PLACED, time() );
		if ( $eta_days ) {
			$item->update_meta_data( self::I_ETA_DAYS, (int) $eta_days );
		}
		$item->delete_meta_data( self::I_NOTRACK );
		$item->delete_meta_data( self::I_LATE );
		$item->delete_meta_data( self::I_DELIVERED );
	}

	private static function placed_at( WC_Order $order, $item ) {
		$t = (int) $item->get_meta( self::I_PLACED );
		if ( $t ) {
			return $t;
		}
		$d = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
		return $d ? $d->getTimestamp() : time();
	}

	/** Latest date the parcel should have arrived by. */
	public static function due_by( WC_Order $order, $item ) {
		$eta = (int) $item->get_meta( self::I_ETA_DAYS );
		return self::placed_at( $order, $item ) + ( $eta ? $eta + self::grace_days() : 35 ) * DAY_IN_SECONDS;
	}

	public static function dispute_url( $ae_order ) {
		return 'https://www.aliexpress.com/p/order/detail.html?orderId=' . rawurlencode( (string) $ae_order );
	}

	/** Lines with an AliExpress order that are still live (not cancelled, not fully refunded). */
	private static function lines( WC_Order $order ) {
		$out = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( '' === (string) $item->get_meta( GSUP_ITEM_AE_ORDER ) || $item->get_meta( GSUP_Orders::I_DEAD ) ) {
				continue;
			}
			if ( $item->get_quantity() + $order->get_qty_refunded_for_item( $item_id ) <= 0 ) {
				continue;
			}
			$out[ $item_id ] = $item;
		}
		return $out;
	}

	/**
	 * "No tracking after N days" — called by the tracking check for lines still without tracking.
	 *
	 * @return string|null Alert text when newly raised.
	 */
	public static function check_no_tracking( WC_Order $order, WC_Order_Item_Product $item ) {
		if ( $item->get_meta( self::I_NOTRACK ) || '' !== (string) $item->get_meta( GSUP_ITEM_TRACKING ) ) {
			return null;
		}
		$days = floor( ( time() - self::placed_at( $order, $item ) ) / DAY_IN_SECONDS );
		if ( $days < self::no_tracking_days() ) {
			return null;
		}
		$item->update_meta_data( self::I_NOTRACK, time() );
		$item->save();
		$order->update_meta_data( self::M_ALERT, 'yes' );
		$order->save_meta_data();
		$ae = (string) $item->get_meta( GSUP_ITEM_AE_ORDER );
		$order->add_order_note( sprintf( 'Givsen Supplier: no tracking for “%1$s” %2$d days after AliExpress order %3$s. Contact the seller or open a dispute: %4$s', $item->get_name(), $days, $ae, self::dispute_url( $ae ) ) );
		return sprintf( 'Order #%1$s — “%2$s”: no tracking %3$d days after AliExpress order %4$s. %5$s', $order->get_order_number(), $item->get_name(), $days, $ae, self::dispute_url( $ae ) );
	}

	/** Orders with parcels on the way, least recently checked first. */
	private static function transit_orders( $limit = 40 ) {
		return wc_get_orders(
			array(
				'limit'      => $limit,
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_key'   => self::M_TRANSIT, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'    => 'modified',
				'order'      => 'ASC',
			)
		);
	}

	/** Twice-daily check of parcels in transit. */
	public static function check() {
		if ( ! GSUP_AliExpress::is_connected() ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$orders = self::transit_orders();
		$ids    = array();
		foreach ( $orders as $order ) {
			foreach ( self::lines( $order ) as $item ) {
				if ( '' !== (string) $item->get_meta( GSUP_ITEM_TRACKING ) && ! $item->get_meta( self::I_DELIVERED ) ) {
					foreach ( preg_split( '/[\s,;]+/', (string) $item->get_meta( GSUP_ITEM_AE_ORDER ), -1, PREG_SPLIT_NO_EMPTY ) as $no ) {
						$ids[ $no ] = $no;
					}
				}
			}
		}
		$known  = $ids ? GSUP_AliExpress::parcels( array_values( $ids ) ) : array();
		$alerts = array();
		foreach ( $orders as $order ) {
			$alerts = array_merge( $alerts, self::update_order( $order, $known ) );
		}
		self::email_alerts( $alerts );
	}

	/**
	 * Apply parcel news to one order.
	 *
	 * @return string[] New alerts.
	 */
	public static function update_order( WC_Order $order, array $known ) {
		$alerts = array();
		$notes  = array();
		foreach ( self::lines( $order ) as $item ) {
			if ( '' === (string) $item->get_meta( GSUP_ITEM_TRACKING ) || $item->get_meta( self::I_DELIVERED ) ) {
				continue;
			}
			$delivered_at = null;
			$last         = null;
			$all          = true; // Every AliExpress order on this line delivered.
			foreach ( preg_split( '/[\s,;]+/', (string) $item->get_meta( GSUP_ITEM_AE_ORDER ), -1, PREG_SPLIT_NO_EMPTY ) as $no ) {
				$p = isset( $known[ $no ] ) ? $known[ $no ] : null;
				if ( ! is_array( $p ) ) {
					$all = false;
					continue;
				}
				if ( $p['last_time'] && ( ! $last || $p['last_time'] > $last[0] ) ) {
					$last = array( $p['last_time'], $p['last_text'] );
				}
				if ( $p['delivered'] ) {
					$delivered_at = max( (int) $delivered_at, (int) $p['delivered_at'] );
				} else {
					$all = false;
				}
			}
			if ( $last ) {
				$item->update_meta_data( self::I_LAST, $last );
			}
			if ( $all && $delivered_at ) {
				$item->update_meta_data( self::I_DELIVERED, $delivered_at );
				$item->delete_meta_data( self::I_LATE );
				$notes[] = sprintf( '“%1$s” was delivered on %2$s.', $item->get_name(), wp_date( 'j M Y', $delivered_at ) );
			} else {
				if ( ! $item->get_meta( self::I_LATE ) && time() > self::due_by( $order, $item ) ) {
					$item->update_meta_data( self::I_LATE, time() );
					$ae       = (string) $item->get_meta( GSUP_ITEM_AE_ORDER );
					$where    = $last ? ' Last update: ' . $last[1] . ' (' . wp_date( 'j M', $last[0] ) . ').' : '';
					$notes[]  = sprintf( '“%1$s” is late — it should have arrived by %2$s.%3$s Open a dispute on AliExpress if needed: %4$s', $item->get_name(), wp_date( 'j M Y', self::due_by( $order, $item ) ), $where, self::dispute_url( $ae ) );
					$alerts[] = sprintf( 'Order #%1$s — “%2$s” is late (due %3$s).%4$s %5$s', $order->get_order_number(), $item->get_name(), wp_date( 'j M', self::due_by( $order, $item ) ), $where, self::dispute_url( $ae ) );
				}
			}
			$item->save();
		}
		if ( $notes ) {
			$order->add_order_note( 'Givsen Supplier: ' . implode( ' ', $notes ) );
		}
		self::refresh_flags( $order );
		$order->set_date_modified( time() ); // Back of the queue.
		$order->save();
		return $alerts;
	}

	/**
	 * Keep the order's in-transit / alert / delivered state in step with its lines.
	 * Called after parcel checks and when tracking or order numbers change.
	 */
	public static function refresh_flags( WC_Order $order ) {
		$transit   = 0;
		$alert     = 0;
		$tracked   = 0;
		$delivered = 0;
		$last      = 0;
		foreach ( self::lines( $order ) as $item ) {
			if ( $item->get_meta( self::I_NOTRACK ) && '' === (string) $item->get_meta( GSUP_ITEM_TRACKING ) ) {
				++$alert;
			}
			if ( '' === (string) $item->get_meta( GSUP_ITEM_TRACKING ) ) {
				continue;
			}
			++$tracked;
			if ( $item->get_meta( self::I_DELIVERED ) ) {
				++$delivered;
				$last = max( $last, (int) $item->get_meta( self::I_DELIVERED ) );
			} else {
				++$transit;
				if ( $item->get_meta( self::I_LATE ) ) {
					++$alert;
				}
			}
		}
		$closed = in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true );
		$created = $order->get_date_created();
		$stale   = $created && $created->getTimestamp() < time() - 120 * DAY_IN_SECONDS; // Stop watching eventually.
		self::set_flag( $order, self::M_TRANSIT, $transit && ! $closed && ! $stale );
		self::set_flag( $order, self::M_ALERT, $alert && ! $closed );

		// Delivered means every AliExpress item on the order — not just the ones that have tracking so far.
		$all_delivered = $tracked && $delivered === $tracked && self::all_linked_delivered( $order );
		if ( $all_delivered && ! $order->get_meta( self::M_DELIVERED ) ) {
			$order->update_meta_data( self::M_DELIVERED, $last );
			$order->save_meta_data();
			if ( self::email_customer() ) {
				self::send_delivered_email( $order );
			}
			if ( 'delivered' === self::complete_when() && 'processing' === $order->get_status() && self::all_linked_delivered( $order ) ) {
				$order->update_status( 'completed', 'Givsen Supplier: every item has been delivered.' );
			}
		}
	}

	private static function set_flag( WC_Order $order, $key, $on ) {
		if ( $on ) {
			$order->update_meta_data( $key, 'yes' );
		} else {
			$order->delete_meta_data( $key );
		}
		$order->save_meta_data();
	}

	/** Every AliExpress-linked, not-refunded line is delivered (lines not yet ordered count as not delivered). */
	private static function all_linked_delivered( WC_Order $order ) {
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( $item->get_quantity() + $order->get_qty_refunded_for_item( $item_id ) <= 0 ) {
				continue;
			}
			$product = $item->get_product();
			$linked  = $product && '' !== gsup_get_supplier_link( $product )['product_id'];
			if ( ( $linked || '' !== (string) $item->get_meta( GSUP_ITEM_AE_ORDER ) ) && ! $item->get_meta( self::I_DELIVERED ) ) {
				return false;
			}
		}
		return true;
	}

	/** "Your order has been delivered", in WooCommerce's email style. */
	private static function send_delivered_email( WC_Order $order ) {
		$to = (array) apply_filters( 'gsup_delivered_email_recipients', array( $order->get_billing_email() ), $order );
		$to = array_filter( array_map( 'sanitize_email', $to ), 'is_email' );
		if ( ! $to || ! function_exists( 'WC' ) ) {
			return;
		}
		$mailer  = WC()->mailer();
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$heading = (string) apply_filters( 'gsup_delivered_email_heading', 'Your order has been delivered', $order );
		$name    = $order->get_shipping_first_name() ? $order->get_shipping_first_name() : $order->get_billing_first_name();
		$items   = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = '<li>' . esc_html( $item->get_name() ) . ' × ' . (int) $item->get_quantity() . '</li>';
		}
		$body  = '<p>' . esc_html( 'Hi ' . ( $name ? $name : 'there' ) . ',' ) . '</p>';
		$body .= '<p>' . esc_html( 'Good news — your order #' . $order->get_order_number() . ' from ' . $site . ' has been delivered.' ) . '</p>';
		$body .= '<ul>' . implode( '', $items ) . '</ul>';
		$body .= '<p>' . esc_html( 'If anything isn’t right, just reply to this email and we’ll sort it out.' ) . '</p>';
		$body  = (string) apply_filters( 'gsup_delivered_email_body', $body, $order );
		$mailer->send( implode( ',', $to ), '[' . $site . '] Your order #' . $order->get_order_number() . ' has been delivered', $mailer->wrap_message( $heading, $body ) );
		$order->add_order_note( 'Givsen Supplier: “delivered” email sent to ' . implode( ', ', $to ) . '.' );
	}

	/** One email per run with any new late-parcel alerts. */
	public static function email_alerts( array $alerts ) {
		if ( ! $alerts ) {
			return;
		}
		wp_mail(
			get_option( 'gsup_sync_email', get_option( 'admin_email' ) ),
			'[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . count( $alerts ) . ' parcel(s) need checking',
			"These parcels are late or still have no tracking:\n\n• " . implode( "\n• ", array_map( 'wp_strip_all_tags', $alerts ) ) . "\n\nAliExpress's buyer protection lets you open a dispute from the order page while it's still running — don't leave it too long.\n"
		);
	}
}

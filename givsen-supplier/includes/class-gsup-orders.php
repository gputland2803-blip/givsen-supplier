<?php
/**
 * Automatic ordering on AliExpress, and tracking numbers back into the order.
 *
 * Ordering (while Settings → Automatic ordering is on):
 *  - When an order reaches Processing (paid, or a Givsen gift once claimed), it's queued and placed in the background.
 *  - One AliExpress order per order line, so each line gets its own AliExpress order number.
 *  - Each line is checked first: linked to an exact option, still sold, in stock, a delivery method available,
 *    and (unless switched off) the AliExpress cost with delivery isn't more than the customer paid for that line.
 *  - Anything that can't be placed is left for you with the reason on the order panel, an order note and an email.
 *    Lines already carrying an AliExpress order number are never placed again. If AliExpress doesn't answer,
 *    the line is not retried automatically (the order may have gone through).
 *
 * Tracking (always on while AliExpress is connected):
 *  - Every few hours, orders with AliExpress order numbers but no tracking are checked with AliExpress.
 *  - Tracking number and carrier are saved on the line, an order note is added, and they're passed to
 *    Advanced Shipment Tracking when that plugin is active. Otherwise they're shown in customer emails and My Account.
 *  - When every line has tracking the order can be marked Completed (setting, on by default).
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Orders {

	const GROUP        = 'givsen-supplier';
	const M_AWAITING   = '_gsup_awaiting_tracking'; // 'yes' on the order while any line has an AliExpress order but no tracking.
	const M_AUTO_STATE = '_gsup_auto_state';        // '' | 'queued' | 'placed' | 'partial' | 'failed'
	const M_AUTO_AT    = '_gsup_auto_at';
	const I_PLACING    = '_gsup_placing';           // Set just before asking AliExpress, cleared after.
	const I_METHOD     = '_gsup_ae_ship_method';
	const I_AE_STATUS  = '_gsup_ae_status';
	const I_DEAD       = '_gsup_ae_dead';           // AliExpress order cancelled or not found: stop checking it for tracking.
	const M_CHECKED    = '_gsup_tracking_checked';  // Last tracking check (also rotates the queue).
	const GIVE_UP_DAYS = 60;

	/** Alerts raised during a tracking check, emailed together at the end. */
	private static $alerts = array();

	public static function init() {
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'queue' ), 20, 1 );
		add_action( 'gsup_place_order', array( __CLASS__, 'place' ), 10, 1 );
		add_action( 'gsup_tracking_check', array( __CLASS__, 'check_tracking' ) );

		// Show tracking to the customer, unless Advanced Shipment Tracking already does.
		add_action( 'woocommerce_email_order_meta', array( __CLASS__, 'email_tracking' ), 20, 3 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'account_tracking' ), 20 );
	}

	public static function enabled() {
		return 'yes' === get_option( 'gsup_auto_order', 'no' );
	}

	public static function loss_guard() {
		return 'no' !== get_option( 'gsup_auto_loss_guard', 'yes' );
	}

	public static function complete_on_tracking() {
		return 'tracking' === GSUP_Parcels::complete_when();
	}

	/** Tracking checks every 4 hours. */
	public static function schedule() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		if ( ! as_next_scheduled_action( 'gsup_tracking_check', array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 10 * MINUTE_IN_SECONDS, 4 * HOUR_IN_SECONDS, 'gsup_tracking_check', array(), self::GROUP );
		}
		if ( ! as_next_scheduled_action( 'gsup_parcel_check', array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 30 * MINUTE_IN_SECONDS, 12 * HOUR_IN_SECONDS, 'gsup_parcel_check', array(), self::GROUP );
		}
	}

	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'gsup_tracking_check', array(), self::GROUP );
			as_unschedule_all_actions( 'gsup_parcel_check', array(), self::GROUP );
			as_unschedule_all_actions( 'gsup_place_order' );
		}
	}

	/* ------------------------------------------------------------ ordering */

	/** Order reached Processing: place it in the background (a minute later, so payment details settle). */
	public static function queue( $order_id ) {
		if ( ! self::enabled() || ! GSUP_AliExpress::is_connected() || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::has_unplaced_lines( $order ) ) {
			return;
		}
		if ( ! as_next_scheduled_action( 'gsup_place_order', array( (int) $order_id ), self::GROUP ) ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, 'gsup_place_order', array( (int) $order_id ), self::GROUP );
		}
		$order->update_meta_data( self::M_AUTO_STATE, 'queued' );
		$order->save_meta_data();
	}

	/** Lines that are linked to AliExpress and have no AliExpress order number yet. */
	private static function has_unplaced_lines( WC_Order $order ) {
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! self::net_qty( $order, $item_id, $item ) ) {
				continue;
			}
			$product = $item->get_product();
			if ( $product && '' !== gsup_get_supplier_link( $product )['product_id'] && '' === (string) $item->get_meta( GSUP_ITEM_AE_ORDER ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Place every line that isn't on AliExpress yet.
	 *
	 * @param int  $order_id
	 * @param bool $manual   From the "Place on AliExpress now" button (also retries lines whose last try got no answer).
	 * @return array{placed:int,problems:array<int,string>}|WP_Error
	 */
	public static function place( $order_id, $manual = false ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'gsup_no_order', 'Order not found.' );
		}
		if ( 'processing' !== $order->get_status() ) {
			$order->delete_meta_data( self::M_AUTO_STATE );
			$order->save_meta_data();
			return new WP_Error( 'gsup_not_ready', 'Only orders in Processing are placed on AliExpress (this one is “' . wc_get_order_status_name( $order->get_status() ) . '”).' );
		}
		if ( ! GSUP_AliExpress::is_connected() ) {
			return new WP_Error( 'gsup_ae_not_connected', 'AliExpress isn’t connected.' );
		}
		$lock = 'gsup_placing_' . (int) $order_id;
		if ( get_transient( $lock ) ) {
			return new WP_Error( 'gsup_busy', 'This order is being placed on AliExpress right now.' );
		}
		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );

		$address  = self::address( $order );
		$country  = $address && ! is_wp_error( $address ) ? $address['country'] : '';
		$placed   = 0;
		$problems = array();
		$products = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			/** @var WC_Order_Item_Product $item */
			if ( '' !== (string) $item->get_meta( GSUP_ITEM_AE_ORDER ) ) {
				continue; // Already on AliExpress.
			}
			$product = $item->get_product();
			$link    = $product ? gsup_get_supplier_link( $product ) : null;
			if ( ! $link || '' === $link['product_id'] ) {
				continue; // Not an AliExpress item — nothing to do.
			}
			if ( ! self::net_qty( $order, $item_id, $item ) ) {
				continue; // Fully refunded — nothing to order.
			}
			if ( $item->get_meta( self::I_PLACING ) && ! $manual ) {
				$problems[ $item_id ] = self::problem( $item, 'The last attempt got no answer from AliExpress, so it may have been ordered. Check your AliExpress orders, then enter the order number or click “Place on AliExpress now”.' );
				continue;
			}
			if ( is_wp_error( $address ) ) {
				$problems[ $item_id ] = self::problem( $item, $address->get_error_message() );
				continue;
			}
			$result = self::place_line( $order, $item, $product, $link, $address, $country, $products );
			if ( is_wp_error( $result ) ) {
				$problems[ $item_id ] = self::problem( $item, $result->get_error_message() );
			} else {
				++$placed;
			}
		}

		$state = $problems ? ( $placed ? 'partial' : 'failed' ) : ( $placed ? 'placed' : '' );
		if ( $state ) {
			$order->update_meta_data( self::M_AUTO_STATE, $state );
		} else {
			$order->delete_meta_data( self::M_AUTO_STATE );
		}
		$order->update_meta_data( self::M_AUTO_AT, time() );
		$order->set_date_modified( time() ); // Item costs changed: refresh cached profit.
		if ( $placed ) {
			$order->update_meta_data( self::M_AWAITING, 'yes' );
		}
		$order->save();
		if ( $problems ) {
			$order->add_order_note( 'Givsen Supplier couldn’t place ' . count( $problems ) . ' item(s) on AliExpress — order them by hand:' . "\n• " . implode( "\n• ", $problems ) );
			self::email_problems( $order, $problems );
		}
		delete_transient( $lock );
		return array(
			'placed'   => $placed,
			'problems' => $problems,
		);
	}

	/** Quantity after refunds. */
	private static function net_qty( WC_Order $order, $item_id, $item ) {
		return max( 0, (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item_id ) );
	}

	/** Whether a line still waits for tracking from AliExpress. */
	private static function line_waits( WC_Order $order, $item_id, $item ) {
		return '' !== (string) $item->get_meta( GSUP_ITEM_AE_ORDER )
			&& '' === (string) $item->get_meta( GSUP_ITEM_TRACKING )
			&& ! $item->get_meta( self::I_DEAD )
			&& self::net_qty( $order, $item_id, $item ) > 0;
	}

	private static function problem( WC_Order_Item_Product $item, $message ) {
		$item->update_meta_data( GSUP_ITEM_PROBLEM, $message );
		$item->save();
		return $item->get_name() . ': ' . $message;
	}

	/**
	 * Place one line.
	 *
	 * @return string|WP_Error AliExpress order number.
	 */
	private static function place_line( WC_Order $order, WC_Order_Item_Product $item, WC_Product $product, array $link, array $address, $country, array &$products ) {
		$item_id = $item->get_id();
		$qty     = self::net_qty( $order, $item_id, $item );
		$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		if ( 'removed' === get_post_meta( $parent_id, GSUP_Sync::M_STATUS, true ) || get_post_meta( $product->get_id(), GSUP_Sync::M_GONE, true ) ) {
			return new WP_Error( 'gsup_gone', 'AliExpress no longer sells this.' );
		}
		if ( 'parent' === $link['level'] ) {
			return new WP_Error( 'gsup_parent', 'The variation isn’t linked to an AliExpress option.' );
		}

		$key = $link['product_id'] . '|' . $country;
		if ( ! isset( $products[ $key ] ) ) {
			$products[ $key ] = GSUP_AliExpress::get_product( $link['product_id'], $country );
		}
		$ae = $products[ $key ];
		if ( is_wp_error( $ae ) ) {
			return $ae;
		}
		if ( ! $ae['on_sale'] ) {
			return new WP_Error( 'gsup_off', 'The listing isn’t for sale on AliExpress right now.' );
		}
		if ( '' !== $link['sku_id'] ) {
			$sku = GSUP_AliExpress::find_sku( $ae, $link['sku_id'] );
		} else {
			$sku = 1 === count( $ae['skus'] ) ? $ae['skus'][0] : null;
		}
		if ( ! $sku ) {
			return new WP_Error( 'gsup_no_sku', '' === $link['sku_id'] ? 'No AliExpress option (SKU ID) is stored for this item.' : 'The option isn’t on the AliExpress listing any more (for delivery to ' . $country . ').' );
		}
		if ( '' === $sku['sku_attr'] ) {
			return new WP_Error( 'gsup_no_attr', 'AliExpress didn’t say how to order this option.' );
		}
		if ( null !== $sku['stock'] && $sku['stock'] < $qty ) {
			return new WP_Error( 'gsup_stock', 'Not enough stock on AliExpress (' . (int) $sku['stock'] . ' left, need ' . (int) $qty . ').' );
		}

		$options = GSUP_AliExpress::freight( $link['product_id'], $sku['sku_id'], $country, $qty );
		if ( is_wp_error( $options ) ) {
			return $options;
		}
		$freight = GSUP_AliExpress::choose_freight( $options );
		$cost    = round( (float) $sku['price'] * $qty + (float) $freight['fee'], 2 );
		$paid    = (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $item_id );
		if ( self::loss_guard() && $cost > $paid ) {
			return new WP_Error(
				'gsup_loss',
				sprintf( 'AliExpress would charge %1$s (with %2$s delivery) but the customer paid %3$s for this line.', gsup_money( $cost ), $freight['name'], gsup_money( $paid ) )
			);
		}

		$item->update_meta_data( self::I_PLACING, time() );
		$item->save();
		$ids = GSUP_AliExpress::place_order(
			$address,
			array(
				array(
					'product_id' => $link['product_id'],
					'qty'        => $qty,
					'sku_attr'   => $sku['sku_attr'],
					'service'    => $freight['code'],
					'memo'       => (string) apply_filters( 'gsup_order_memo', 'Please do not include invoices or prices in the parcel. Thank you!', $order, $item ),
				),
			),
			$order->get_order_number() . '-' . $item_id
		);
		if ( is_wp_error( $ids ) ) {
			if ( in_array( $ids->get_error_code(), array( 'gsup_ae_network', 'gsup_ae_bad_response', 'gsup_ae_order_unknown' ), true ) ) {
				return new WP_Error( 'gsup_unknown', $ids->get_error_message() . ' It may have gone through — check your AliExpress orders before ordering again.' );
			}
			$item->delete_meta_data( self::I_PLACING );
			$item->save();
			return $ids;
		}
		$ae_order = implode( ', ', $ids );
		$item->delete_meta_data( self::I_PLACING );
		$item->delete_meta_data( GSUP_ITEM_PROBLEM );
		$item->update_meta_data( GSUP_ITEM_AE_ORDER, $ae_order );
		$item->update_meta_data( self::I_METHOD, $freight['name'] );
		$item->update_meta_data( GSUP_ITEM_AE_COST, wc_format_decimal( $cost, 2 ) );
		GSUP_Parcels::mark_placed( $item, (int) $freight['max_days'] );
		$item->save();
		$order->add_order_note(
			sprintf(
				'Givsen Supplier placed “%1$s” × %2$d on AliExpress: order %3$s, %4$s delivery, cost %5$s%6$s.',
				$item->get_name(),
				$qty,
				$ae_order,
				$freight['name'],
				gsup_money( $cost ),
				GSUP_AliExpress::$last_pay_requested ? '' : ' — waiting for you to pay it on AliExpress'
			)
		);
		return $ae_order;
	}

	/**
	 * Delivery address in AliExpress's fields.
	 *
	 * @return array|WP_Error
	 */
	public static function address( WC_Order $order ) {
		$use = $order->has_shipping_address() ? 'shipping' : 'billing';
		$get = function ( $field ) use ( $order, $use ) {
			$method = 'get_' . $use . '_' . $field;
			return is_callable( array( $order, $method ) ) ? trim( (string) $order->$method() ) : '';
		};
		$country = $get( 'country' );
		$states  = $country ? WC()->countries->get_states( $country ) : array();
		$state   = $get( 'state' );
		$state   = ( is_array( $states ) && isset( $states[ $state ] ) ) ? html_entity_decode( $states[ $state ], ENT_QUOTES ) : $state;
		$name    = trim( $get( 'first_name' ) . ' ' . $get( 'last_name' ) );
		$phone   = 'shipping' === $use ? trim( (string) $order->get_shipping_phone() ) : '';
		$phone   = '' !== $phone ? $phone : trim( (string) $order->get_billing_phone() );
		$street  = $get( 'address_1' );
		$company = $get( 'company' );

		$missing = array();
		foreach (
			array(
				'name'           => $name,
				'street address' => $street,
				'city/suburb'    => $get( 'city' ),
				'postcode'       => $get( 'postcode' ),
				'country'        => $country,
				'phone number'   => $phone,
			) as $label => $value
		) {
			if ( '' === $value ) {
				$missing[] = $label;
			}
		}
		if ( $missing ) {
			return new WP_Error( 'gsup_address', 'The delivery address is missing: ' . implode( ', ', $missing ) . '.' );
		}

		list( $calling, $mobile ) = self::phone( $phone, $country );
		return array(
			'full_name'      => $name,
			'contact_person' => $name,
			'mobile_no'      => $mobile,
			'phone_country'  => $calling,
			'country'        => $country,
			'province'       => $state,
			'city'           => $get( 'city' ),
			'address'        => '' !== $company ? $company . ', ' . $street : $street,
			'address2'       => $get( 'address_2' ),
			'zip'            => $get( 'postcode' ),
		);
	}

	/** "+61 412 345 678" / "0412 345 678" → ['+61', '412345678']. */
	private static function phone( $phone, $country ) {
		$calling = '';
		if ( method_exists( WC()->countries, 'get_country_calling_code' ) ) {
			$calling = (string) WC()->countries->get_country_calling_code( $country );
		}
		$digits = preg_replace( '/\D/', '', $phone );
		$code   = ltrim( $calling, '+' );
		if ( '' !== $code && 0 === strpos( ltrim( $phone ), '+' ) && 0 === strpos( $digits, $code ) ) {
			$digits = substr( $digits, strlen( $code ) );
		} elseif ( '' !== $code && 0 === strpos( $digits, '00' . $code ) ) {
			$digits = substr( $digits, 2 + strlen( $code ) );
		}
		if ( '' !== $code && 'US' !== $country && 'CA' !== $country ) {
			$digits = ltrim( $digits, '0' ); // Trunk prefix, e.g. 0412… in Australia.
		}
		return array( '' !== $code ? '+' . $code : '', $digits );
	}

	private static function email_problems( WC_Order $order, array $problems ) {
		$to = get_option( 'gsup_sync_email', get_option( 'admin_email' ) );
		wp_mail(
			$to,
			'[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] Order #' . $order->get_order_number() . ' needs ordering on AliExpress by hand',
			"Givsen Supplier couldn’t place these items on AliExpress automatically:\n\n• " . implode( "\n• ", array_map( 'wp_strip_all_tags', $problems ) ) . "\n\nOrder: " . $order->get_edit_order_url() . "\n"
		);
	}

	/* ------------------------------------------------------------ tracking */

	/** Orders waiting for tracking, oldest first. */
	private static function awaiting_orders( $limit = 40 ) {
		return wc_get_orders(
			array(
				'limit'      => $limit,
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_key'   => self::M_AWAITING, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'    => 'modified', // Each check saves the order, so recently checked orders go to the back.
				'order'      => 'ASC',
				'return'     => 'ids',
			)
		);
	}

	/** Background check (every 4 hours). */
	public static function check_tracking() {
		if ( ! GSUP_AliExpress::is_connected() ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$ids = self::awaiting_orders();
		// Ask AliExpress about every waiting order in one go (a few at a time) rather than one by one.
		$numbers = array();
		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( self::line_waits( $order, $item_id, $item ) ) {
					foreach ( preg_split( '/[\s,;]+/', (string) $item->get_meta( GSUP_ITEM_AE_ORDER ), -1, PREG_SPLIT_NO_EMPTY ) as $no ) {
						$numbers[ $no ] = $no;
					}
				}
			}
		}
		$known = $numbers ? GSUP_AliExpress::get_orders( array_values( $numbers ) ) : array();
		self::$alerts = array();
		foreach ( $ids as $order_id ) {
			$r = self::check_order( $order_id, $known );
			if ( is_wp_error( $r ) && 'gsup_stop' === $r->get_error_code() ) {
				break; // Connection trouble: try again next time.
			}
		}
		GSUP_Parcels::email_alerts( self::$alerts );
		self::$alerts = array();
	}

	/**
	 * Fetch tracking for one order's AliExpress orders.
	 *
	 * @return int|WP_Error Number of lines that got tracking.
	 */
	public static function check_order( $order_id, array $known = array() ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return 0;
		}
		$found   = 0;
		$waiting = 0;
		$notes   = array();
		$cache   = $known;
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! self::line_waits( $order, $item_id, $item ) ) {
				continue;
			}
			$ae_order = trim( (string) $item->get_meta( GSUP_ITEM_AE_ORDER ) );
			$numbers  = array();
			$carriers = array();
			foreach ( preg_split( '/[\s,;]+/', $ae_order ) as $no ) {
				if ( '' === $no ) {
					continue;
				}
				if ( ! isset( $cache[ (string) $no ] ) ) {
					$cache[ $no ] = GSUP_AliExpress::get_order( $no );
				}
				$info = $cache[ $no ];
				if ( is_wp_error( $info ) ) {
					if ( in_array( $info->get_error_code(), array( 'gsup_ae_network', 'gsup_ae_bad_response', 'gsup_ae_not_connected', 'gsup_ae_expired', 'gsup_ae_no_app' ), true ) ) {
						return new WP_Error( 'gsup_stop', $info->get_error_message() );
					}
					if ( 'gsup_ae_order_missing' === $info->get_error_code() ) {
						$item->update_meta_data( self::I_DEAD, 'missing' );
						$item->update_meta_data( GSUP_ITEM_PROBLEM, $info->get_error_message() . ' Check the AliExpress order number.' );
						$notes[] = $info->get_error_message();
					}
					continue;
				}
				if ( $info['status'] !== (string) $item->get_meta( self::I_AE_STATUS ) ) {
					$item->update_meta_data( self::I_AE_STATUS, $info['status'] );
					if ( preg_match( '/cancel|close/i', $info['status'] ) ) {
						$item->update_meta_data( self::I_DEAD, 'cancelled' );
						$item->update_meta_data( GSUP_ITEM_PROBLEM, 'AliExpress order ' . $no . ' was cancelled (' . $info['status'] . '). Order it again by hand.' );
						$notes[] = 'AliExpress order ' . $no . ' for “' . $item->get_name() . '” was cancelled.';
					}
				}
				$same_currency = '' === $info['currency'] || strtoupper( $info['currency'] ) === strtoupper( $order->get_currency() );
				if ( $same_currency && null !== $info['amount'] && $info['amount'] > 0 && 1 === count( preg_split( '/[\s,;]+/', $ae_order, -1, PREG_SPLIT_NO_EMPTY ) ) ) {
					$item->update_meta_data( GSUP_ITEM_AE_COST, wc_format_decimal( $info['amount'], 2 ) );
				}
				foreach ( $info['tracking'] as $t ) {
					$numbers[ $t['number'] ]  = true;
					$carriers[ $t['carrier'] ] = true;
				}
			}
			if ( $numbers ) {
				$tracking = implode( ', ', array_keys( $numbers ) );
				$carrier  = implode( ', ', array_filter( array_keys( $carriers ) ) );
				$item->update_meta_data( GSUP_ITEM_TRACKING, $tracking );
				$item->update_meta_data( GSUP_ITEM_CARRIER, $carrier );
				$notes[] = sprintf( 'Tracking %1$s%2$s received from AliExpress for “%3$s”.', $tracking, '' !== $carrier ? ' (' . $carrier . ')' : '', $item->get_name() );
				self::to_ast( $order, array_keys( $numbers ), $carrier );
				++$found;
			} elseif ( ! $item->get_meta( self::I_DEAD ) ) {
				++$waiting;
				$item->save();
				$alert = GSUP_Parcels::check_no_tracking( $order, $item );
				if ( $alert ) {
					self::$alerts[] = $alert;
				}
			}
			$item->save();
		}
		$order->update_meta_data( self::M_CHECKED, time() );
		$order->set_date_modified( time() ); // Moves it to the back of the queue.
		if ( $notes ) {
			$order->add_order_note( 'Givsen Supplier: ' . implode( ' ', $notes ) );
		}
		self::after_tracking_change( $order, $waiting );
		return $found;
	}

	/**
	 * Keep the "awaiting tracking" flag right, and complete the order once every AliExpress line has tracking.
	 * Also called when you type numbers into the order panel.
	 */
	public static function after_tracking_change( WC_Order $order, $waiting = null ) {
		if ( null === $waiting ) {
			$waiting = 0;
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( self::line_waits( $order, $item_id, $item ) ) {
					++$waiting;
				}
			}
		}
		$created = $order->get_date_created();
		$too_old = $created && $created->getTimestamp() < time() - self::GIVE_UP_DAYS * DAY_IN_SECONDS;
		$closed  = in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true );
		if ( $waiting && ! $too_old && ! $closed ) {
			$order->update_meta_data( self::M_AWAITING, 'yes' );
			$order->save();
			GSUP_Parcels::refresh_flags( $order );
			return;
		}
		$was_waiting = 'yes' === $order->get_meta( self::M_AWAITING );
		$order->delete_meta_data( self::M_AWAITING );
		$order->save();
		GSUP_Parcels::refresh_flags( $order );
		if ( $was_waiting && ! $waiting && 'tracking' === GSUP_Parcels::complete_when() && 'processing' === $order->get_status() && self::all_lines_tracked( $order ) ) {
			$order->update_status( 'completed', 'Givsen Supplier: every item has tracking from AliExpress.' );
		}
	}

	/** Every AliExpress-linked line has tracking (lines not from AliExpress, and fully refunded lines, don't count). */
	private static function all_lines_tracked( WC_Order $order ) {
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! self::net_qty( $order, $item_id, $item ) ) {
				continue;
			}
			$product = $item->get_product();
			$linked  = $product && '' !== gsup_get_supplier_link( $product )['product_id'];
			if ( ( $linked || '' !== (string) $item->get_meta( GSUP_ITEM_AE_ORDER ) ) && '' === (string) $item->get_meta( GSUP_ITEM_TRACKING ) ) {
				return false;
			}
		}
		return true;
	}

	/** Advanced Shipment Tracking (zorem), when active. */
	private static function to_ast( WC_Order $order, array $numbers, $carrier ) {
		if ( ! function_exists( 'ast_insert_tracking_number' ) ) {
			return;
		}
		$provider = (string) apply_filters( 'gsup_ast_provider', 'Cainiao', $carrier, $order );
		foreach ( $numbers as $no ) {
			ast_insert_tracking_number( $order->get_id(), $no, $provider, time(), 0 );
		}
	}

	/* ----------------------------------------------------- customer display */

	public static function tracking_url( $number ) {
		return (string) apply_filters( 'gsup_tracking_url', 'https://global.cainiao.com/newDetail.htm?mailNoList=' . rawurlencode( $number ), $number );
	}

	/** @return array<int,array{name:string,numbers:string[],carrier:string}> */
	private static function customer_tracking( WC_Order $order ) {
		if ( function_exists( 'ast_insert_tracking_number' ) ) {
			return array(); // Advanced Shipment Tracking shows it.
		}
		$out = array();
		foreach ( $order->get_items() as $item ) {
			$tracking = trim( (string) $item->get_meta( GSUP_ITEM_TRACKING ) );
			if ( '' !== $tracking ) {
				$out[] = array(
					'name'    => $item->get_name(),
					'numbers' => preg_split( '/[\s,;]+/', $tracking, -1, PREG_SPLIT_NO_EMPTY ),
					'carrier' => (string) $item->get_meta( GSUP_ITEM_CARRIER ),
				);
			}
		}
		return $out;
	}

	public static function email_tracking( $order, $sent_to_admin, $plain_text ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$rows = self::customer_tracking( $order );
		if ( ! $rows ) {
			return;
		}
		if ( $plain_text ) {
			echo "\nTRACKING\n";
			foreach ( $rows as $r ) {
				foreach ( $r['numbers'] as $n ) {
					echo esc_html( $r['name'] . ': ' . $n . ' — ' . self::tracking_url( $n ) ) . "\n";
				}
			}
			return;
		}
		self::tracking_html( $rows, 'h2' );
	}

	public static function account_tracking( $order ) {
		if ( $order instanceof WC_Order ) {
			$rows = self::customer_tracking( $order );
			if ( $rows ) {
				self::tracking_html( $rows, 'h2' );
			}
		}
	}

	private static function tracking_html( array $rows, $tag ) {
		echo '<' . esc_attr( $tag ) . '>Tracking</' . esc_attr( $tag ) . '><ul class="gsup-tracking">';
		foreach ( $rows as $r ) {
			foreach ( $r['numbers'] as $n ) {
				echo '<li>' . esc_html( $r['name'] ) . ': <a href="' . esc_url( self::tracking_url( $n ) ) . '">' . esc_html( $n ) . '</a>' . ( '' !== $r['carrier'] ? ' (' . esc_html( $r['carrier'] ) . ')' : '' ) . '</li>';
			}
		}
		echo '</ul>';
	}
}

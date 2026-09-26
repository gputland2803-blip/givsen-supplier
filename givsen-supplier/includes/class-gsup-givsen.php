<?php
/**
 * Works alongside "Givsen for WooCommerce" (the gift plugin). Only does anything when that plugin is active.
 *
 * How Givsen orders look (from Givsen 1.4.2):
 * - Personal gifts (`_givsen_mode` share / phone / username / address): paid by the buyer, wait in
 *   "Awaiting Givsen Address" until the recipient claims, then Processing with the recipient's address.
 *   No recipient email is collected; `_givsen_recipient_first_name`, `_givsen_recipient_phone`.
 *   The buyer must never learn the recipient's address (`givsen_is_gift_order()`), unless they typed it
 *   themselves (`_givsen_address_by` = sender).
 * - Business gifting: the paid parent (`corporate`) never ships. Each claim creates a $0 child order
 *   (`corporate_child`, `_givsen_parent_order`) with the recipient's address, and their email as billing email
 *   when they gave one. The sender never sees a child order.
 * - Extra postage orders (`_givsen_postage_for`): a fee only, no products.
 *
 * What this module changes here:
 * - Automatic ordering never places a Business gifting parent; the loss check on a $0 child order uses the
 *   parent's price per recipient; a missing phone falls back to the business's phone, then your fallback number.
 * - Profit on child orders is the parent's price, shipping and fees per recipient (the parent itself ships nothing).
 * - Tracking numbers and links aren't shown to the buyer of a private gift (they'd reveal where it went),
 *   and aren't sent to Advanced Shipment Tracking for those orders.
 * - "Delivered" emails are worded as gifts: to the buyer ("your gift to Sam has been delivered") for personal gifts,
 *   and to the recipient, when they gave an email, for Business gifting.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Givsen {

	public static function active() {
		return function_exists( 'givsen_is_gift_order' );
	}

	public static function mode( WC_Order $order ) {
		return (string) $order->get_meta( '_givsen_mode' );
	}

	/** Business gifting parent: paid, but ships nothing itself. */
	public static function is_corporate_parent( WC_Order $order ) {
		return 'corporate' === self::mode( $order );
	}

	public static function is_corporate_child( WC_Order $order ) {
		return 'corporate_child' === self::mode( $order );
	}

	/** A gift whose buyer mustn't learn where it went. */
	public static function hide_from_buyer( WC_Order $order ) {
		if ( ! self::active() || ! givsen_is_gift_order( $order ) ) {
			return false;
		}
		return 'sender' !== (string) $order->get_meta( '_givsen_address_by' );
	}

	public static function parent_of( WC_Order $child ) {
		$id = (int) $child->get_meta( '_givsen_parent_order' );
		return $id ? wc_get_order( $id ) : null;
	}

	/**
	 * What one recipient's share of a Business gifting order was worth (before tax, after refunds).
	 *
	 * @return array|null {unit, shipping, revenue, fees}
	 */
	public static function child_share( WC_Order $child ) {
		if ( ! self::is_corporate_child( $child ) ) {
			return null;
		}
		$parent = self::parent_of( $child );
		if ( ! $parent ) {
			return null;
		}
		$qty  = 0;
		$line = 0.0;
		foreach ( $parent->get_items() as $item_id => $item ) {
			$qty  += (int) $item->get_quantity();
			$line += (float) $item->get_total() - (float) $parent->get_total_refunded_for_item( $item_id );
		}
		if ( $qty <= 0 ) {
			return null;
		}
		$unit     = $line / $qty;
		$shipping = (float) $parent->get_shipping_total() / $qty;
		$fees     = (float) $parent->get_total() > 0 ? ( GSUP_Profit::fee( $parent->get_total() ) + GSUP_Profit::fee_fixed() ) / $qty : 0.0;
		return array(
			'unit'     => $unit,
			'shipping' => $shipping,
			'revenue'  => $unit + $shipping,
			'fees'     => $fees,
		);
	}

	/** Phone for the courier when the order has none: the business's (Business gifting), then your fallback. */
	public static function fallback_phone( WC_Order $order ) {
		if ( self::is_corporate_child( $order ) ) {
			$parent = self::parent_of( $order );
			if ( $parent && '' !== trim( (string) $parent->get_billing_phone() ) ) {
				return trim( (string) $parent->get_billing_phone() );
			}
		}
		return trim( (string) get_option( 'gsup_fallback_phone', '' ) );
	}

	/**
	 * Who gets the "delivered" email, and what it says, for a Givsen order.
	 *
	 * @return array|null {to[], subject, heading, body} — null when it isn't a Givsen gift.
	 */
	public static function delivered_email( WC_Order $order ) {
		$mode = self::mode( $order );
		if ( '' === $mode || ! self::active() ) {
			return null;
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		if ( 'corporate_child' === $mode ) {
			$to = sanitize_email( $order->get_billing_email() );
			if ( ! is_email( $to ) ) {
				return array( 'to' => array() ); // Recipient gave no email: nobody to tell.
			}
			$parent = self::parent_of( $order );
			$from   = $parent ? (string) ( $parent->get_meta( '_givsen_org_name' ) ?: $parent->get_meta( '_givsen_sender_name' ) ?: $parent->get_billing_company() ) : '';
			$from   = function_exists( 'givsen_safe_sender_name' ) && '' !== $from ? givsen_safe_sender_name( $from ) : $from;
			$name   = $order->get_shipping_first_name() ? $order->get_shipping_first_name() : $order->get_billing_first_name();
			return array(
				'to'      => array( $to ),
				'subject' => '[' . $site . '] Your gift has been delivered',
				'heading' => 'Your gift has arrived',
				'body'    => '<p>' . esc_html( 'Hi ' . ( $name ? $name : 'there' ) . ',' ) . '</p><p>' . esc_html( 'Your gift' . ( '' !== $from && 'Someone' !== $from ? ' from ' . $from : '' ) . ' has been delivered. We hope you enjoy it!' ) . '</p><p>' . esc_html( 'If anything isn’t right, just reply to this email.' ) . '</p>',
			);
		}
		if ( 'corporate' === $mode ) {
			return array( 'to' => array() ); // The parent never ships.
		}
		// Personal gift: tell the buyer — who, not where.
		$to        = sanitize_email( $order->get_billing_email() );
		$recipient = (string) $order->get_meta( '_givsen_recipient_first_name' );
		if ( function_exists( 'givsen_greeting_first_name' ) ) {
			$recipient = (string) givsen_greeting_first_name( $recipient );
		}
		$buyer = $order->get_billing_first_name();
		return array(
			'to'      => is_email( $to ) ? array( $to ) : array(),
			'subject' => '[' . $site . '] Your gift' . ( '' !== $recipient ? ' to ' . $recipient : '' ) . ' has been delivered',
			'heading' => 'Your gift has been delivered',
			'body'    => '<p>' . esc_html( 'Hi ' . ( $buyer ? $buyer : 'there' ) . ',' ) . '</p><p>' . esc_html( 'Good news — your gift' . ( '' !== $recipient ? ' to ' . $recipient : '' ) . ' (order #' . $order->get_order_number() . ') has been delivered.' ) . '</p><p>' . esc_html( 'Thank you for sending it with ' . $site . '.' ) . '</p>',
		);
	}
}

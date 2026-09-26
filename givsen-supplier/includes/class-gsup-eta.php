<?php
/**
 * "Estimated delivery" on product pages, from AliExpress's delivery estimate for the shipping method you use
 * (kept up to date by the weekly delivery quote) plus your own processing days.
 *
 * Shown under the price as dates ("Estimated delivery: Tue 1 Oct – Fri 4 Oct") or days ("Delivered in 3–6 days").
 * Variable products show the range across their options, and the chosen option's own estimate once one is picked.
 * Weekends are skipped when counting if you choose business days. Nothing shows for products without an estimate.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Eta {

	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'show' ), 15 );
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'variation' ), 20, 3 );
	}

	public static function enabled() {
		return 'no' !== get_option( 'gsup_eta_show', 'no' );
	}

	/** Settings: processing days, date or day wording, business days. */
	public static function settings() {
		return array(
			'processing' => max( 0, (int) get_option( 'gsup_eta_processing', 1 ) ),
			'format'     => 'days' === get_option( 'gsup_eta_format', 'dates' ) ? 'days' : 'dates',
			'business'   => 'no' !== get_option( 'gsup_eta_business', 'yes' ),
		);
	}

	/** [min, max] days for one product/variation, or null. */
	private static function days_for( $id ) {
		$v = (string) get_post_meta( $id, GSUP_META_SHIP_DAYS, true );
		if ( ! preg_match( '/^(\d+)-(\d+)$/', $v, $m ) || (int) $m[2] <= 0 ) {
			return null;
		}
		return array( (int) $m[1], (int) $m[2] );
	}

	/** [min, max] days for a product (across a variable product's options). */
	public static function range( WC_Product $product ) {
		$ids = $product->is_type( 'variable' ) ? $product->get_children() : array( $product->get_id() );
		$min = null;
		$max = null;
		foreach ( $ids as $id ) {
			$d = self::days_for( $id );
			if ( $d ) {
				$min = null === $min ? $d[0] : min( $min, $d[0] );
				$max = null === $max ? $d[1] : max( $max, $d[1] );
			}
		}
		return null === $max ? null : array( max( 1, $min ), $max );
	}

	/** Add days to today, skipping weekends if counting business days. */
	private static function add_days( $days, $business ) {
		$d = new DateTimeImmutable( 'today', wp_timezone() );
		while ( $days > 0 ) {
			$d = $d->modify( '+1 day' );
			if ( ! $business || (int) $d->format( 'N' ) < 6 ) {
				--$days;
			}
		}
		return $d;
	}

	/** The line customers see. */
	public static function text( array $range ) {
		$s   = self::settings();
		$min = $range[0] + $s['processing'];
		$max = $range[1] + $s['processing'];
		if ( 'days' === $s['format'] ) {
			$text = sprintf( 'Delivered in %1$s %2$sdays', $min === $max ? $max : $min . '–' . $max, $s['business'] ? 'business ' : '' );
		} else {
			$from = self::add_days( $min, $s['business'] );
			$to   = self::add_days( $max, $s['business'] );
			$text = 'Estimated delivery: ' . ( $from == $to ? wp_date( 'D j M', $to->getTimestamp() ) : wp_date( 'D j M', $from->getTimestamp() ) . ' – ' . wp_date( 'D j M', $to->getTimestamp() ) ); // phpcs:ignore Universal.Operators.StrictComparisons -- comparing dates.
		}
		return (string) apply_filters( 'gsup_eta_text', $text, $range, $s );
	}

	public static function show() {
		global $product;
		if ( ! $product instanceof WC_Product || ! $product->is_in_stock() ) {
			return;
		}
		$range = self::range( $product );
		if ( $range ) {
			echo '<p class="gsup-eta" style="margin:.5em 0 1em">' . esc_html( self::text( $range ) ) . '</p>';
		}
	}

	/** Chosen option: its own estimate appears with its stock line. */
	public static function variation( $data, $product, $variation ) {
		$d = $variation ? self::days_for( $variation->get_id() ) : null;
		if ( $d && $variation->is_in_stock() ) {
			$data['availability_html'] = ( isset( $data['availability_html'] ) ? $data['availability_html'] : '' ) . '<p class="gsup-eta gsup-eta--option">' . esc_html( self::text( $d ) ) . '</p>';
		}
		return $data;
	}
}

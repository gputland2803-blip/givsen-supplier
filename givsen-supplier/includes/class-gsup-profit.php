<?php
/**
 * Profit at a glance.
 *
 * Cost of one item = AliExpress cost (_gsup_cost) + AliExpress delivery fee (_gsup_ship_cost, when known).
 * Profit = your price − cost − payment fee (Settings → Profit: % + fixed per order, e.g. Stripe's).
 * Margin = profit ÷ your price. Figures are before tax.
 *
 * - Products list: margin and profit per product (range for variable products); "Low margin" flag and filter.
 * - Orders: each item's cost is saved on the order when it's placed, so later cost changes don't rewrite history;
 *   the AliExpress order's real cost replaces the estimate once known. Profit shows on the order panel and the orders list.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Profit {

	const M_LOW     = '_gsup_low_margin'; // 'yes' on the product while any option is below the minimum margin.
	const M_SUMMARY = '_gsup_margin';     // Stored product_summary(), so lists don't load every variation.

	/** Set while the sync or "Add to store" saves many variations; they refresh the flag once at the end. */
	public static $paused = false;

	public static function init() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'snapshot_line' ), 20, 3 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_save' ), 20 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'on_variation_save' ), 20 );

		if ( is_admin() ) {
			// Orders list (HPOS and classic).
			add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'order_columns' ), 20 );
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'order_column' ), 10, 2 );
			add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'order_columns' ), 20 );
			add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'order_column' ), 10, 2 );
		}
	}

	public static function min_margin() {
		return max( 0, (float) get_option( 'gsup_min_margin', 30 ) );
	}

	public static function fee( $amount ) {
		return max( 0, (float) $amount ) * (float) get_option( 'gsup_fee_percent', 0 ) / 100;
	}

	public static function fee_fixed() {
		return (float) get_option( 'gsup_fee_fixed', 0 );
	}

	public static function pct( $ratio ) {
		return null === $ratio ? '—' : round( $ratio * 100 ) . '%';
	}

	/** Cost of one of this product/variation from AliExpress, delivery included. Null when not known. */
	public static function unit_cost( $wc_id ) {
		$cost = get_post_meta( $wc_id, GSUP_META_COST, true );
		if ( '' === $cost || (float) $cost <= 0 ) {
			return null;
		}
		return (float) $cost + (float) get_post_meta( $wc_id, GSUP_META_SHIP_COST, true );
	}

	/**
	 * Profit and margin for selling one item at $price.
	 * The fixed payment fee is per order, so it's left out here (it's counted on orders).
	 */
	public static function figures( $price, $cost ) {
		$price = (float) $price;
		if ( $price <= 0 || null === $cost ) {
			return null;
		}
		$profit = $price - $cost - self::fee( $price );
		return array(
			'price'  => $price,
			'cost'   => $cost,
			'profit' => $profit,
			'margin' => $profit / $price,
		);
	}

	/**
	 * Margin summary for a product (every option of a variable product).
	 *
	 * @return array|null {margin_min, margin_max, profit_min, profit_max, cost_min, cost_max, low, count}
	 */
	public static function product_summary( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		$ids  = $product->is_type( 'variable' ) ? $product->get_children() : array( $product->get_id() );
		$rows = array();
		foreach ( $ids as $id ) {
			$item = $product->is_type( 'variable' ) ? wc_get_product( $id ) : $product;
			if ( ! $item ) {
				continue;
			}
			$f = self::figures( $item->get_price(), self::unit_cost( $id ) );
			if ( $f ) {
				$rows[] = $f;
			}
		}
		if ( ! $rows ) {
			return null;
		}
		$margins = wp_list_pluck( $rows, 'margin' );
		$profits = wp_list_pluck( $rows, 'profit' );
		$costs   = wp_list_pluck( $rows, 'cost' );
		return array(
			'margin_min' => min( $margins ),
			'margin_max' => max( $margins ),
			'profit_min' => min( $profits ),
			'profit_max' => max( $profits ),
			'cost_min'   => min( $costs ),
			'cost_max'   => max( $costs ),
			'low'        => min( $margins ) * 100 < self::min_margin(),
			'count'      => count( $rows ),
		);
	}

	/**
	 * Recalculate a product's "Low margin" flag.
	 *
	 * @return bool Whether it's low now.
	 */
	public static function refresh_flag( $product_id ) {
		$s   = self::product_summary( wc_get_product( $product_id ) );
		$low = $s && $s['low'];
		if ( $s ) {
			update_post_meta( $product_id, self::M_SUMMARY, $s );
		} else {
			delete_post_meta( $product_id, self::M_SUMMARY );
		}
		if ( $low ) {
			update_post_meta( $product_id, self::M_LOW, 'yes' );
		} else {
			delete_post_meta( $product_id, self::M_LOW );
		}
		return $low;
	}

	/**
	 * Margin summary for lists: the stored one (kept up to date on every save and sync), worked out and stored
	 * the first time it's missing. "low" always follows the current minimum.
	 */
	public static function cached_summary( WC_Product $product ) {
		$s = get_post_meta( $product->get_id(), self::M_SUMMARY, true );
		if ( ! is_array( $s ) || ! isset( $s['margin_min'] ) ) {
			if ( '' === (string) get_post_meta( $product->get_id(), GSUP_META_PRODUCT, true ) ) {
				return self::product_summary( $product );
			}
			self::refresh_flag( $product->get_id() );
			$s = get_post_meta( $product->get_id(), self::M_SUMMARY, true );
			if ( ! is_array( $s ) ) {
				return null;
			}
		}
		$s['low'] = $s['margin_min'] * 100 < self::min_margin();
		return $s;
	}

	public static function on_product_save( $product_id ) {
		static $busy = false;
		if ( $busy || self::$paused ) {
			return;
		}
		$busy = true;
		self::refresh_flag( $product_id );
		$busy = false;
	}

	public static function on_variation_save( $variation_id ) {
		$parent = wp_get_post_parent_id( $variation_id );
		if ( $parent ) {
			self::on_product_save( $parent );
		}
	}

	/** Recalculate every linked product's flag (after the minimum margin or fees change). */
	public static function refresh_all() {
		$ids = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'meta_key'         => GSUP_META_PRODUCT, // phpcs:ignore WordPress.DB.SlowDBQuery
				'suppress_filters' => true,
			)
		);
		foreach ( $ids as $id ) {
			self::refresh_flag( $id );
		}
		return count( $ids );
	}

	/* -------------------------------------------------------------- orders */

	/** Save what one item costs at the moment the order is placed. */
	public static function snapshot_line( $item, $cart_item_key, $values ) {
		$product = $item->get_product();
		if ( ! $product ) {
			return;
		}
		$cost = self::unit_cost( $product->get_id() );
		if ( null !== $cost ) {
			$item->add_meta_data( GSUP_ITEM_UNIT_COST, wc_format_decimal( $cost, 4 ), true );
		}
	}

	/**
	 * Cost of an order line: the AliExpress order's cost when known, else the saved per-item cost × quantity,
	 * else today's cost.
	 *
	 * @return array{cost:float|null,source:string} source: 'aliexpress' | 'estimate' | ''
	 */
	public static function line_cost( WC_Order_Item_Product $item, $qty ) {
		$ae = $item->get_meta( GSUP_ITEM_AE_COST );
		if ( '' !== $ae && null !== $ae ) {
			return array(
				'cost'   => (float) $ae,
				'source' => 'aliexpress',
			);
		}
		$unit   = $item->get_meta( GSUP_ITEM_UNIT_COST );
		$source = 'estimate';
		if ( '' === $unit || null === $unit ) {
			$product = $item->get_product();
			$unit    = $product ? self::unit_cost( $product->get_id() ) : null;
			$source  = 'current'; // Today's product cost: changes with the sync.
		}
		if ( null === $unit ) {
			return array(
				'cost'   => null,
				'source' => 'current',
			);
		}
		return array(
			'cost'   => (float) $unit * $qty,
			'source' => $source,
		);
	}

	/**
	 * Profit for a whole order (before tax, after refunds and payment fees).
	 *
	 * @return array|null {revenue, cost, fees, profit, margin, lines: [item_id => {revenue, cost, source, profit}], unknown: int}
	 */
	public static function order_summary( WC_Order $order ) {
		$lines   = array();
		$cost    = 0.0;
		$unknown = 0;
		foreach ( $order->get_items() as $item_id => $item ) {
			$qty = $item->get_quantity() + $order->get_qty_refunded_for_item( $item_id );
			if ( $qty <= 0 ) {
				continue;
			}
			$lc  = self::line_cost( $item, $qty );
			$rev = (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $item_id );
			if ( null === $lc['cost'] ) {
				++$unknown;
			} else {
				$cost += $lc['cost'];
			}
			$lines[ $item_id ] = array(
				'revenue' => $rev,
				'cost'    => $lc['cost'],
				'source'  => $lc['source'],
				'profit'  => null === $lc['cost'] ? null : $rev - $lc['cost'],
			);
		}
		if ( ! $lines ) {
			return null;
		}
		// Refunds include their tax, so take refunded tax back out of the tax figure to avoid counting it twice.
		$refunded     = (float) $order->get_total_refunded();
		$tax_refunded = (float) $order->get_total_tax_refunded();
		$kept_total   = (float) $order->get_total() - $refunded;
		$revenue      = $kept_total - ( (float) $order->get_total_tax() - $tax_refunded );
		// Card fees are charged on what was paid and usually not returned on refunds.
		$fees         = (float) $order->get_total() > 0 ? self::fee( $order->get_total() ) + self::fee_fixed() : 0;
		$profit  = $revenue - $cost - $fees;
		return array(
			'revenue' => $revenue,
			'cost'    => $cost,
			'fees'    => $fees,
			'profit'  => $profit,
			'margin'  => $revenue > 0 ? $profit / $revenue : null,
			'lines'   => $lines,
			'unknown' => $unknown,
		);
	}

	public static function order_columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_total' === $key ) {
				$out['gsup_profit'] = 'Profit';
			}
		}
		if ( ! isset( $out['gsup_profit'] ) ) {
			$out['gsup_profit'] = 'Profit';
		}
		return $out;
	}

	/** Order profit for the orders list: stored on the order until the order next changes. */
	public static function cached_order_summary( WC_Order $order ) {
		$modified = $order->get_date_modified();
		$stamp    = ( $modified ? $modified->getTimestamp() : 0 ) . '|' . self::min_margin() . '|' . get_option( 'gsup_fee_percent', 0 ) . '|' . get_option( 'gsup_fee_fixed', 0 );
		$cached   = $order->get_meta( '_gsup_profit_cache' );
		if ( is_array( $cached ) && isset( $cached['stamp'] ) && $cached['stamp'] === $stamp ) {
			return $cached['summary'];
		}
		$s = self::order_summary( $order );
		foreach ( $s ? $s['lines'] : array() as $line ) {
			if ( 'current' === $line['source'] ) {
				return $s; // Depends on today's product costs: work it out fresh each time.
			}
		}
		$order->update_meta_data(
			'_gsup_profit_cache',
			array(
				'stamp'   => $stamp,
				'summary' => $s,
			)
		);
		$order->save_meta_data(); // Meta only: doesn't change the order's modified date.
		return $s;
	}

	public static function order_column( $column, $order ) {
		if ( 'gsup_profit' !== $column ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return;
		}
		$s = self::cached_order_summary( $order );
		if ( ! $s || count( $s['lines'] ) === $s['unknown'] ) {
			echo '<span class="gsup-meta">—</span>';
			return;
		}
		$low = null !== $s['margin'] && $s['margin'] * 100 < self::min_margin();
		echo '<span class="' . ( $s['profit'] < 0 || $low ? 'gsup-sub--bad' : '' ) . '">' . wp_kses_post( wc_price( $s['profit'], array( 'currency' => $order->get_currency() ) ) ) . '</span>';
		echo '<span class="gsup-sub gsup-sub--muted">' . esc_html( self::pct( $s['margin'] ) . ( $s['unknown'] ? ' · ' . $s['unknown'] . ' item cost unknown' : '' ) ) . '</span>';
	}
}

<?php
/**
 * Profit report: month by month for the last 12 months, and each product's profit for a chosen month.
 * Uses the same figures as the order panel (before tax, after refunds and payment fees).
 * Paid orders only (WooCommerce's paid statuses). Months are cached: past months for a week, this month for 30 minutes.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Report {

	const PAGE = 100;

	public static function init() {
		add_action( 'admin_post_gsup_report_refresh', array( __CLASS__, 'handle_refresh' ) );
	}

	/** Totals and per-product figures for one month ("2026-09"). */
	public static function month( $ym, $fresh = false ) {
		$key = 'gsup_report_' . $ym;
		if ( ! $fresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$tz    = wp_timezone();
		$start = new DateTimeImmutable( $ym . '-01 00:00:00', $tz );
		$end   = $start->modify( 'first day of next month' );
		$data  = array(
			'orders'   => 0,
			'revenue'  => 0.0,
			'cost'     => 0.0,
			'fees'     => 0.0,
			'profit'   => 0.0,
			'unknown'  => 0,
			'products' => array(),
			'built'    => time(),
		);
		$page = 1;
		do {
			$result = wc_get_orders(
				array(
					'limit'        => self::PAGE,
					'paged'        => $page,
					'paginate'     => true,
					'status'       => function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' ),
					'date_created' => $start->getTimestamp() . '...' . ( $end->getTimestamp() - 1 ),
					'type'         => 'shop_order',
				)
			);
			foreach ( $result->orders as $order ) {
				$s = GSUP_Profit::cached_order_summary( $order );
				if ( ! $s ) {
					// No products (e.g. a Givsen extra-postage payment): still money in.
					$extra            = (float) $order->get_total() - (float) $order->get_total_tax() - ( (float) $order->get_total_refunded() - (float) $order->get_total_tax_refunded() );
					$fee              = (float) $order->get_total() > 0 ? GSUP_Profit::fee( $order->get_total() ) + GSUP_Profit::fee_fixed() : 0.0;
					$data['revenue'] += $extra;
					$data['fees']    += $fee;
					$data['profit']  += $extra - $fee;
					continue;
				}
				++$data['orders'];
				$data['revenue'] += $s['revenue'];
				$data['cost']    += $s['cost'];
				$data['fees']    += $s['fees'];
				$data['profit']  += $s['profit'];
				$data['unknown'] += $s['unknown'];
				foreach ( $s['lines'] as $line ) {
					$pid = isset( $line['product'] ) ? (int) $line['product'] : 0;
					if ( ! isset( $data['products'][ $pid ] ) ) {
						$data['products'][ $pid ] = array(
							'name'    => isset( $line['name'] ) ? $line['name'] : '',
							'qty'     => 0,
							'revenue' => 0.0,
							'cost'    => 0.0,
							'unknown' => 0,
						);
					}
					$p             = &$data['products'][ $pid ];
					$p['qty']     += isset( $line['qty'] ) ? (int) $line['qty'] : 0;
					$p['revenue'] += $line['revenue'];
					if ( null === $line['cost'] ) {
						++$p['unknown'];
					} else {
						$p['cost'] += $line['cost'];
					}
					unset( $p );
				}
			}
			++$page;
		} while ( $page <= $result->max_num_pages );

		$current = gmdate( 'Y-m' ) === $ym || wp_date( 'Y-m' ) === $ym;
		set_transient( $key, $data, $current ? 30 * MINUTE_IN_SECONDS : WEEK_IN_SECONDS );
		return $data;
	}

	/** The last 12 months, newest first: "Y-m" => label. */
	private static function months() {
		$out = array();
		$d   = new DateTimeImmutable( 'first day of this month', wp_timezone() );
		for ( $i = 0; $i < 12; $i++ ) {
			$m                        = $d->modify( "-$i months" );
			$out[ $m->format( 'Y-m' ) ] = $m->format( 'F Y' );
		}
		return $out;
	}

	private static function pct( $profit, $revenue ) {
		return $revenue > 0 ? round( $profit / $revenue * 100 ) . '%' : '—';
	}

	public static function render() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- the first view of a year of orders can take a while; then it's cached.
		}
		$months = self::months();
		$pick   = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : (string) array_key_first( $months ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pick   = isset( $months[ $pick ] ) ? $pick : (string) array_key_first( $months );

		echo '<p class="gsup-intro">Profit from paid orders, before tax, after refunds, AliExpress costs (with delivery) and payment fees. Order totals include the shipping you charged.</p>';
		echo '<table class="widefat striped gsup-report"><thead><tr><th>Month</th><th>Orders</th><th>Revenue</th><th>AliExpress cost</th><th>Fees</th><th>Profit</th><th>Margin</th></tr></thead><tbody>';
		$totals = array( 0, 0.0, 0.0, 0.0, 0.0 );
		foreach ( $months as $ym => $label ) {
			$m = self::month( $ym );
			$totals[0] += $m['orders'];
			$totals[1] += $m['revenue'];
			$totals[2] += $m['cost'];
			$totals[3] += $m['fees'];
			$totals[4] += $m['profit'];
			$cls = $ym === $pick ? ' class="gsup-report-current"' : '';
			echo '<tr' . $cls . '><td><a href="' . esc_url( gsup_admin_url( array( 'tab' => 'reports', 'month' => $ym ) ) ) . '">' . esc_html( $label ) . '</a></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . (int) $m['orders'] . '</td><td>' . wp_kses_post( wc_price( $m['revenue'] ) ) . '</td><td>' . wp_kses_post( wc_price( $m['cost'] ) ) . ( $m['unknown'] ? ' <span class="gsup-warn--soft" title="Items without a known AliExpress cost">+' . (int) $m['unknown'] . ' ?</span>' : '' ) . '</td>';
			echo '<td>' . wp_kses_post( wc_price( $m['fees'] ) ) . '</td><td class="' . ( $m['profit'] < 0 ? 'gsup-sub--bad' : '' ) . '"><strong>' . wp_kses_post( wc_price( $m['profit'] ) ) . '</strong></td><td>' . esc_html( self::pct( $m['profit'], $m['revenue'] ) ) . '</td></tr>';
		}
		echo '</tbody><tfoot><tr><th>12 months</th><th>' . (int) $totals[0] . '</th><th>' . wp_kses_post( wc_price( $totals[1] ) ) . '</th><th>' . wp_kses_post( wc_price( $totals[2] ) ) . '</th><th>' . wp_kses_post( wc_price( $totals[3] ) ) . '</th><th>' . wp_kses_post( wc_price( $totals[4] ) ) . '</th><th>' . esc_html( self::pct( $totals[4], $totals[1] ) ) . '</th></tr></tfoot></table>';

		$m        = self::month( $pick );
		$products = $m['products'];
		foreach ( $products as &$p ) {
			$p['profit'] = $p['revenue'] - $p['cost'];
		}
		unset( $p );
		uasort(
			$products,
			function ( $a, $b ) {
				return $b['profit'] <=> $a['profit'];
			}
		);
		echo '<h2>Products in ' . esc_html( $months[ $pick ] ) . '</h2>';
		if ( ! $products ) {
			echo '<p class="gsup-meta">No paid orders this month.</p>';
		} else {
			echo '<table class="widefat striped gsup-report"><thead><tr><th>Product</th><th>Sold</th><th>Revenue</th><th>AliExpress cost</th><th>Profit</th><th>Margin</th></tr></thead><tbody>';
			foreach ( $products as $pid => $p ) {
				$name = $pid && get_post( $pid ) ? '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $pid . '&action=edit' ) ) . '">' . esc_html( get_the_title( $pid ) ) . '</a>' : esc_html( '' !== $p['name'] ? $p['name'] : 'Deleted product' );
				echo '<tr><td>' . $name . '</td><td>' . (int) $p['qty'] . '</td><td>' . wp_kses_post( wc_price( $p['revenue'] ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				echo '<td>' . wp_kses_post( wc_price( $p['cost'] ) ) . ( $p['unknown'] ? ' <span class="gsup-warn--soft">+' . (int) $p['unknown'] . ' unknown</span>' : '' ) . '</td>';
				echo '<td class="' . ( $p['profit'] < 0 ? 'gsup-sub--bad' : '' ) . '">' . wp_kses_post( wc_price( $p['profit'] ) ) . '</td><td>' . esc_html( self::pct( $p['profit'], $p['revenue'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<p class="gsup-meta">Product profit is the item price you charged minus its AliExpress cost with delivery; payment fees and the shipping you charged are counted in the monthly totals above.</p>';
		}
		echo '<p class="gsup-meta">Worked out ' . esc_html( human_time_diff( (int) $m['built'] ) ) . ' ago. <a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gsup_report_refresh&month=' . rawurlencode( $pick ) ), 'gsup_report_refresh' ) ) . '">Recalculate this month</a></p>';
	}

	public static function handle_refresh() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'You do not have permission to do that.', 403 );
		}
		check_admin_referer( 'gsup_report_refresh' );
		$ym = isset( $_GET['month'] ) && preg_match( '/^\d{4}-\d{2}$/', sanitize_text_field( wp_unslash( $_GET['month'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : wp_date( 'Y-m' );
		self::month( $ym, true );
		wp_safe_redirect( gsup_admin_url( array( 'tab' => 'reports', 'month' => $ym ) ) );
		exit;
	}
}

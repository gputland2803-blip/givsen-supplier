<?php
/**
 * "Supplier" column (link, options, ships-from, margin, country restriction) and a
 * Linked / Not linked / Low margin filter on Products → All Products.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Products_Column {

	public static function init() {
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_dropdown' ), 20 );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_filter' ) );
	}

	public static function add_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'name' === $key ) {
				$out['gsup_supplier'] = 'Supplier';
			}
		}
		if ( ! isset( $out['gsup_supplier'] ) ) {
			$out['gsup_supplier'] = 'Supplier';
		}
		return $out;
	}

	public static function render( $column, $post_id ) {
		if ( 'gsup_supplier' !== $column ) {
			return;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}
		$ae_pid = (string) get_post_meta( $post_id, GSUP_META_PRODUCT, true );
		if ( '' === $ae_pid ) {
			echo '<span class="gsup-badge gsup-badge--off">Not linked</span>';
			return;
		}

		$sync_status = (string) get_post_meta( $post_id, '_gsup_sync_status', true );
		echo '<a class="gsup-badge ' . ( 'removed' === $sync_status ? 'gsup-badge--bad' : 'gsup-badge--on' ) . '" href="' . esc_url( gsup_ae_url( $ae_pid ) ) . '" target="_blank" rel="noopener noreferrer" title="Open on AliExpress">AE ' . esc_html( $ae_pid ) . '</a>';
		if ( 'removed' === $sync_status ) {
			echo '<span class="gsup-sub gsup-sub--bad">Removed on AliExpress · <a href="' . esc_url( gsup_remap_url( $post_id ) ) . '">Change supplier</a></span>';
		} elseif ( 'missing' === $sync_status ) {
			echo '<span class="gsup-sub gsup-sub--warn">Not found at last sync</span>';
		}

		$ships = array();
		if ( $product->is_type( 'variable' ) ) {
			$children = $product->get_children();
			$total    = count( $children );
			$linked   = 0;
			if ( $children ) {
				update_meta_cache( 'post', $children );
			}
			foreach ( $children as $child_id ) {
				if ( '' !== (string) get_post_meta( $child_id, GSUP_META_SKU, true ) ) {
					++$linked;
				}
				$ship = (string) get_post_meta( $child_id, GSUP_META_SHIP, true );
				if ( '' !== $ship ) {
					$ships[ $ship ] = true;
				}
			}
			$class = ( $linked < $total ) ? 'gsup-sub gsup-sub--warn' : 'gsup-sub';
			echo '<span class="' . esc_attr( $class ) . '">' . (int) $linked . '/' . (int) $total . ' options linked</span>';
			$gone = 0;
			foreach ( $children as $child_id ) {
				if ( get_post_meta( $child_id, '_gsup_option_gone', true ) ) {
					++$gone;
				}
			}
			if ( $gone ) {
				echo '<span class="gsup-sub gsup-sub--bad">' . (int) $gone . ' no longer on AliExpress · <a href="' . esc_url( gsup_remap_url( $post_id ) ) . '">Change supplier</a></span>';
			}
		} else {
			if ( '' === (string) get_post_meta( $post_id, GSUP_META_SKU, true ) ) {
				echo '<span class="gsup-sub gsup-sub--muted">No option ID</span>';
			}
			$ship = (string) get_post_meta( $post_id, GSUP_META_SHIP, true );
			if ( '' !== $ship ) {
				$ships[ $ship ] = true;
			}
		}

		if ( $ships ) {
			echo '<span class="gsup-sub">Ships from ' . esc_html( implode( ', ', array_keys( $ships ) ) ) . '</span>';
		} else {
			echo '<span class="gsup-sub gsup-sub--warn">Ships-from not set</span>';
		}
		if ( ! $product->is_type( 'variable' ) && get_post_meta( $post_id, '_gsup_option_gone', true ) ) {
			echo '<span class="gsup-sub gsup-sub--bad">Option no longer on AliExpress</span>';
		}
		self::render_margin( $product );
		if ( GSUP_CBR::enabled() ) {
			$cbr = GSUP_CBR::label( $post_id );
			echo '<span class="gsup-sub' . ( '' === $cbr ? ' gsup-sub--muted' : '' ) . '">' . esc_html( '' !== $cbr ? $cbr : 'Shown to: all countries' ) . '</span>';
		}
		$synced = (int) get_post_meta( $post_id, '_gsup_synced_at', true );
		if ( $synced ) {
			echo '<span class="gsup-sub gsup-sub--muted">Synced ' . esc_html( human_time_diff( $synced ) ) . ' ago</span>';
		}
	}

	private static function render_margin( WC_Product $product ) {
		$m = GSUP_Profit::cached_summary( $product );
		if ( ! $m ) {
			echo '<span class="gsup-sub gsup-sub--muted">Margin: cost not known</span>';
			return;
		}
		$margin = GSUP_Profit::pct( $m['margin_min'] ) . ( round( $m['margin_min'] * 100 ) !== round( $m['margin_max'] * 100 ) ? '–' . GSUP_Profit::pct( $m['margin_max'] ) : '' );
		$profit = gsup_money( $m['profit_min'] ) . ( round( $m['profit_min'], 2 ) !== round( $m['profit_max'], 2 ) ? '–' . gsup_money( $m['profit_max'] ) : '' );
		$cost   = gsup_money( $m['cost_min'] ) . ( round( $m['cost_min'], 2 ) !== round( $m['cost_max'], 2 ) ? '–' . gsup_money( $m['cost_max'] ) : '' );
		$title  = 'AliExpress cost with delivery: ' . $cost . '. Margin is profit ÷ your price, after payment fees, before tax.';
		echo '<span class="gsup-sub gsup-margin' . ( $m['low'] ? ' gsup-sub--bad' : '' ) . '" title="' . esc_attr( $title ) . '">' . ( $m['low'] ? 'Low margin ' : 'Margin ' ) . esc_html( $margin ) . ' · ' . esc_html( $profit ) . '</span>';
	}

	public static function filter_dropdown( $post_type ) {
		if ( 'product' !== $post_type ) {
			return;
		}
		$current = isset( $_GET['gsup_link'] ) ? sanitize_key( wp_unslash( $_GET['gsup_link'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<select name="gsup_link"><option value="">Supplier: any</option>';
		foreach ( array(
			'linked'   => 'Linked to AliExpress',
			'unlinked' => 'Not linked',
			'low'      => 'Low margin',
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public static function apply_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'product' !== $query->get( 'post_type' ) ) {
			return;
		}
		$value = isset( $_GET['gsup_link'] ) ? sanitize_key( wp_unslash( $_GET['gsup_link'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $value, array( 'linked', 'unlinked', 'low' ), true ) ) {
			return;
		}
		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = 'low' === $value ? array(
			'key'   => GSUP_Profit::M_LOW,
			'value' => 'yes',
		) : array(
			'key'     => GSUP_META_PRODUCT,
			'compare' => 'linked' === $value ? 'EXISTS' : 'NOT EXISTS',
		);
		$query->set( 'meta_query', $meta_query );
	}
}

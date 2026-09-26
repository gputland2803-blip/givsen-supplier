<?php
/**
 * Daily sync with AliExpress, run in small batches through WooCommerce's job queue (Action Scheduler).
 *
 * Updates: stock, cost (_gsup_cost), delivery fee (_gsup_ship_cost), and — only if switched on — the regular
 * price from the pricing rule. Flags products whose margin a cost rise has pushed below the minimum.
 * Never touches: titles, descriptions, images, categories, sale prices.
 *
 * Removed on AliExpress:
 *  - whole product: drafted (only if it was published) when AliExpress clearly says it's gone or not for sale,
 *    or when it comes back missing on two syncs in a row. Connection/server problems never draft anything.
 *  - single option: that variation is set out of stock.
 * One summary email per run, only when something needs attention.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Sync {

	const GROUP      = 'givsen-supplier';
	const BATCH      = 25; // Fetched a few at a time in parallel (see GSUP_AliExpress::parallel()).
	const OPT_RUN    = 'gsup_sync_run';
	const OPT_LAST   = 'gsup_sync_last';
	const M_STATUS   = '_gsup_sync_status';   // '', 'missing', 'removed'
	const M_MISSING  = '_gsup_sync_missing';  // consecutive "not found" count
	const M_SYNCED   = '_gsup_synced_at';
	const M_DRAFTED  = '_gsup_drafted_by_sync';
	const M_GONE     = '_gsup_option_gone';
	const M_QUOTED   = '_gsup_ship_quoted';     // When the delivery fee was last asked for (quotes refresh weekly).

	public static function init() {
		add_action( 'gsup_sync_start', array( __CLASS__, 'start' ) );
		add_action( 'gsup_sync_batch', array( __CLASS__, 'batch' ) );
	}

	public static function enabled() {
		return 'no' !== get_option( 'gsup_sync_enabled', 'yes' );
	}

	public static function update_prices() {
		return 'yes' === get_option( 'gsup_sync_prices', 'no' );
	}

	/** Daily at about 3am store time, while sync is switched on. */
	public static function schedule() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		$next = as_next_scheduled_action( 'gsup_sync_start', array(), self::GROUP );
		if ( self::enabled() && ! $next ) {
			$tomorrow_3am = ( new DateTimeImmutable( 'tomorrow 03:00', wp_timezone() ) )->getTimestamp();
			as_schedule_recurring_action( $tomorrow_3am, DAY_IN_SECONDS, 'gsup_sync_start', array(), self::GROUP );
		} elseif ( ! self::enabled() && $next ) {
			as_unschedule_all_actions( 'gsup_sync_start', array(), self::GROUP );
		}
	}

	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'gsup_sync_start', array(), self::GROUP );
			as_unschedule_all_actions( 'gsup_sync_batch', array(), self::GROUP );
		}
	}

	public static function running() {
		$run = get_option( self::OPT_RUN );
		return is_array( $run ) && ! empty( $run['ids'] ) && ( time() - (int) $run['touched'] ) < HOUR_IN_SECONDS ? $run : null;
	}

	/** Every store product linked to AliExpress (not in the bin). */
	private static function linked_product_ids() {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => 'product',
					'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
					'numberposts'      => -1,
					'fields'           => 'ids',
					'meta_key'         => GSUP_META_PRODUCT, // phpcs:ignore WordPress.DB.SlowDBQuery
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'suppress_filters' => true,
				)
			)
		);
	}

	/** Begin a run (daily, or "Sync now"). */
	public static function start( $manual = false ) {
		if ( self::running() ) {
			return false;
		}
		if ( ! GSUP_AliExpress::is_connected() ) {
			self::finish(
				array(
					'started' => time(),
					'manual'  => (bool) $manual,
					'report'  => self::empty_report( array( 'AliExpress isn’t connected, so nothing was checked. Connect it in WooCommerce → Givsen Supplier → Settings.' ) ),
				)
			);
			return false;
		}
		$run = array(
			'ids'     => self::linked_product_ids(),
			'pos'     => 0,
			'started' => time(),
			'touched' => time(),
			'manual'  => (bool) $manual,
			'report'  => self::empty_report(),
		);
		if ( ! $run['ids'] ) {
			self::finish( $run );
			return true;
		}
		update_option( self::OPT_RUN, $run, false );
		as_enqueue_async_action( 'gsup_sync_batch', array(), self::GROUP );
		return true;
	}

	private static function empty_report( $errors = array() ) {
		return array(
			'checked'       => 0,
			'stock_changes' => 0,
			'cost_changes'  => 0,
			'price_changes' => 0,
			'ship_changes'  => 0,
			'low_margin'    => array(), // product IDs whose cost went up and margin is now below the minimum
			'removed'       => array(), // product IDs drafted/flagged
			'options_gone'  => array(), // variation/simple IDs newly out because the option is gone
			'back'          => array(), // product IDs back on AliExpress after being drafted by sync
			'missing'       => array(), // product IDs missing once (will be drafted if missing again)
			'errors'        => $errors,
		);
	}

	public static function batch() {
		$run = get_option( self::OPT_RUN );
		if ( ! is_array( $run ) || empty( $run['ids'] ) ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$end   = min( count( $run['ids'] ), $run['pos'] + self::BATCH );
		$cache = self::prefetch( array_slice( $run['ids'], $run['pos'], $end - $run['pos'] ) );
		for ( $i = $run['pos']; $i < $end; $i++ ) {
			$result = self::sync_product( $run['ids'][ $i ], $run['report'], $cache );
			if ( 'stop' === $result ) {
				// Connection or server problem: stop here rather than burn through the list. Nothing is drafted.
				$run['pos'] = count( $run['ids'] );
				self::finish( $run );
				return;
			}
			$run['pos'] = $i + 1;
		}
		$run['touched'] = time();
		if ( $run['pos'] >= count( $run['ids'] ) ) {
			self::finish( $run );
			return;
		}
		update_option( self::OPT_RUN, $run, false );
		as_enqueue_async_action( 'gsup_sync_batch', array(), self::GROUP );
	}

	/**
	 * Ask AliExpress about a whole batch at once (a few calls in flight at a time) instead of one by one:
	 * first the listings, then delivery quotes for the products whose quote is due.
	 *
	 * @return array Cache in the shape sync_product() reads.
	 */
	private static function prefetch( array $ids ) {
		if ( $ids ) {
			update_meta_cache( 'post', $ids );
		}
		$pairs = array();
		$ships = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			$ae_pid  = (string) get_post_meta( $id, GSUP_META_PRODUCT, true );
			if ( ! $product || '' === $ae_pid ) {
				continue;
			}
			if ( $product->is_type( 'variable' ) && $product->get_children() ) {
				update_meta_cache( 'post', $product->get_children() );
			}
			$ships[ $id ]               = self::ship_to_for( $product );
			$pairs[ $ae_pid . '|' . $ships[ $id ] ] = array( $ae_pid, $ships[ $id ] );
		}
		$cache = $pairs ? GSUP_AliExpress::get_products( array_values( $pairs ) ) : array();

		$specs = array();
		foreach ( $ships as $id => $ship_to ) {
			$key = (string) get_post_meta( $id, GSUP_META_PRODUCT, true ) . '|' . $ship_to;
			if ( empty( $cache[ $key ] ) || is_wp_error( $cache[ $key ] ) || ! self::quote_due( $id ) ) {
				continue;
			}
			$product = wc_get_product( $id );
			$sku_id  = self::quote_sku( $cache[ $key ], $product->is_type( 'variable' ) ? $product->get_children() : array( $id ) );
			if ( '' !== $sku_id ) {
				$specs[ 'freight|' . $key ] = array( $cache[ $key ]['product_id'], $sku_id, $ship_to );
			}
		}
		foreach ( $specs ? GSUP_AliExpress::freights( $specs ) : array() as $fkey => $options ) {
			$cache[ $fkey ] = is_wp_error( $options ) ? null : GSUP_AliExpress::choose_freight( $options );
		}
		return $cache;
	}

	/** Delivery fees change rarely: ask again after a week, or sooner if an option has none yet. */
	private static function quote_due( $product_id ) {
		$days   = max( 1, (int) apply_filters( 'gsup_ship_quote_days', 7 ) );
		$quoted = (int) get_post_meta( $product_id, self::M_QUOTED, true );
		if ( ! $quoted || $quoted < time() - $days * DAY_IN_SECONDS ) {
			return true;
		}
		$product = wc_get_product( $product_id );
		foreach ( ( $product && $product->is_type( 'variable' ) ) ? $product->get_children() : array( $product_id ) as $id ) {
			if ( '' === get_post_meta( $id, GSUP_META_SHIP_COST, true ) && '' !== get_post_meta( $id, GSUP_META_COST, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Which option to ask the delivery fee for: the first linked one still on the listing. */
	private static function quote_sku( array $ae, array $targets ) {
		foreach ( $targets as $id ) {
			$sku_id = (string) get_post_meta( $id, GSUP_META_SKU, true );
			if ( '' !== $sku_id && GSUP_AliExpress::find_sku( $ae, $sku_id ) ) {
				return $sku_id;
			}
		}
		return $ae['skus'] ? (string) $ae['skus'][0]['sku_id'] : '';
	}

	/** Which delivery country to ask AliExpress about, from where the product ships. */
	private static function ship_to_for( WC_Product $product ) {
		$ids = $product->is_type( 'variable' ) ? $product->get_children() : array( $product->get_id() );
		foreach ( $ids as $id ) {
			$ship = (string) get_post_meta( $id, GSUP_META_SHIP, true );
			if ( in_array( $ship, array( 'AU', 'US' ), true ) ) {
				return $ship;
			}
		}
		return gsup_quote_country( '' );
	}

	/**
	 * @return string 'ok' | 'removed' | 'missing' | 'stop'
	 */
	public static function sync_product( $product_id, array &$report, array &$cache = array() ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return 'ok';
		}
		$ae_pid = (string) get_post_meta( $product_id, GSUP_META_PRODUCT, true );
		if ( '' === $ae_pid ) {
			return 'ok';
		}
		$ship_to = self::ship_to_for( $product );
		$key     = $ae_pid . '|' . $ship_to;
		if ( ! isset( $cache[ $key ] ) ) {
			$cache[ $key ] = GSUP_AliExpress::get_product( $ae_pid, $ship_to );
		}
		$ae = $cache[ $key ];
		++$report['checked'];

		if ( is_wp_error( $ae ) ) {
			$verdict = self::classify_error( $ae );
			if ( 'stop' === $verdict ) {
				$report['errors'][] = $ae->get_error_message();
				return 'stop';
			}
			$missing = (int) get_post_meta( $product_id, self::M_MISSING, true ) + 1;
			update_post_meta( $product_id, self::M_MISSING, $missing );
			if ( 'gone' === $verdict || $missing >= 2 ) {
				self::mark_removed( $product, $report );
				return 'removed';
			}
			update_post_meta( $product_id, self::M_STATUS, 'missing' );
			$report['missing'][] = $product_id;
			return 'missing';
		}

		if ( ! $ae['on_sale'] ) {
			self::mark_removed( $product, $report );
			return 'removed';
		}

		// Found and on sale.
		if ( 'removed' === get_post_meta( $product_id, self::M_STATUS, true ) && get_post_meta( $product_id, self::M_DRAFTED, true ) ) {
			$report['back'][] = $product_id;
			delete_post_meta( $product_id, self::M_DRAFTED );
		}
		delete_post_meta( $product_id, self::M_MISSING );
		delete_post_meta( $product_id, self::M_STATUS );

		$targets = $product->is_type( 'variable' ) ? $product->get_children() : array( $product_id );
		$freight = self::freight_for( $ae, $targets, $ship_to, $cache, $product_id );
		$rose    = false;
		GSUP_Profit::$paused = true;
		foreach ( $targets as $target_id ) {
			$item   = wc_get_product( $target_id );
			$sku_id = (string) get_post_meta( $target_id, GSUP_META_SKU, true );
			if ( ! $item ) {
				continue;
			}
			if ( '' === $sku_id ) {
				// Not linked to a specific option: only a single-option listing can be matched safely.
				if ( 1 !== count( $ae['skus'] ) ) {
					continue;
				}
				$sku = $ae['skus'][0];
			} else {
				$sku = GSUP_AliExpress::find_sku( $ae, $sku_id );
			}
			if ( ! $sku ) {
				self::mark_option_gone( $item, $report );
				continue;
			}
			$rose = self::apply( $item, $sku, $report, $freight ) || $rose;
		}
		GSUP_Profit::$paused = false;
		if ( $product->is_type( 'variable' ) ) {
			WC_Product_Variable::sync( $product_id );
		}
		update_post_meta( $product_id, self::M_SYNCED, time() );
		wc_delete_product_transients( $product_id );
		if ( GSUP_Profit::refresh_flag( $product_id ) && $rose ) {
			$report['low_margin'][] = $product_id;
		}
		return 'ok';
	}

	/**
	 * 'gone'  — AliExpress clearly says the product no longer exists / is offline.
	 * 'maybe' — product-specific problem with no clear reason (counts towards "missing twice").
	 * 'stop'  — connection, sign-in or server trouble: never a reason to draft anything.
	 */
	private static function classify_error( WP_Error $e ) {
		$code = $e->get_error_code();
		if ( 'gsup_ae_not_found' === $code ) {
			return 'maybe';
		}
		if ( 'gsup_ae_product' === $code ) {
			$data = $e->get_error_data();
			$msg  = strtolower( is_array( $data ) && isset( $data['ae_msg'] ) ? $data['ae_msg'] : $e->get_error_message() );
			if ( preg_match( '/not\s*exist|offline|not\s*found|delet|removed|off\s*shelf|unavailable/', $msg ) ) {
				return 'gone';
			}
			if ( preg_match( '/limit|frequen|busy|timeout|system|server|try again/', $msg ) ) {
				return 'stop';
			}
			return 'maybe';
		}
		return 'stop';
	}

	private static function mark_removed( WC_Product $product, array &$report ) {
		$id = $product->get_id();
		if ( 'removed' === get_post_meta( $id, self::M_STATUS, true ) ) {
			return; // Already handled on an earlier run; don't repeat the email.
		}
		update_post_meta( $id, self::M_STATUS, 'removed' );
		if ( 'publish' === $product->get_status() ) {
			$product->set_status( 'draft' );
			$product->save();
			update_post_meta( $id, self::M_DRAFTED, time() );
		}
		$report['removed'][] = $id;
	}

	private static function mark_option_gone( WC_Product $item, array &$report ) {
		$id = $item->get_id();
		if ( get_post_meta( $id, self::M_GONE, true ) ) {
			return;
		}
		if ( $item->get_manage_stock() ) {
			$item->set_stock_quantity( 0 );
		}
		$item->set_stock_status( 'outofstock' );
		$item->save();
		update_post_meta( $id, self::M_GONE, time() );
		$report['options_gone'][] = $id;
	}

	/**
	 * Delivery quote for a product, asked once per AliExpress product and country per batch.
	 * A failed quote just leaves the stored delivery fee as it was.
	 */
	private static function freight_for( array $ae, array $targets, $ship_to, array &$cache, $product_id = 0 ) {
		$key = 'freight|' . $ae['product_id'] . '|' . $ship_to;
		if ( ! array_key_exists( $key, $cache ) ) {
			if ( $product_id && ! self::quote_due( $product_id ) ) {
				return null; // Not due: keep the stored fee.
			}
			$sku_id = self::quote_sku( $ae, $targets );
			if ( '' === $sku_id ) {
				return null;
			}
			$options       = GSUP_AliExpress::freight( $ae['product_id'], $sku_id, $ship_to );
			$cache[ $key ] = is_wp_error( $options ) ? null : GSUP_AliExpress::choose_freight( $options );
		}
		if ( $cache[ $key ] && $product_id ) {
			update_post_meta( $product_id, self::M_QUOTED, time() );
		}
		return $cache[ $key ];
	}

	/**
	 * @return bool Whether this option's cost (with delivery) went up.
	 */
	private static function apply( WC_Product $item, array $sku, array &$report, $freight = null ) {
		$id = $item->get_id();
		delete_post_meta( $id, self::M_GONE );
		$changed = false;

		if ( null === $sku['stock'] ) {
			if ( $item->get_manage_stock() || 'instock' !== $item->get_stock_status() ) {
				$item->set_manage_stock( false );
				$item->set_stock_status( 'instock' );
				$changed = true;
				++$report['stock_changes'];
			}
		} else {
			$qty = max( 0, (int) $sku['stock'] );
			if ( ! $item->get_manage_stock() || (int) $item->get_stock_quantity() !== $qty ) {
				$item->set_manage_stock( true );
				$item->set_stock_quantity( $qty );
				$changed = true;
				++$report['stock_changes'];
			}
		}

		$old_cost = (float) get_post_meta( $id, GSUP_META_COST, true );
		$old_ship = get_post_meta( $id, GSUP_META_SHIP_COST, true );
		$had_ship = '' !== $old_ship; // Products added before 0.6.0 have no delivery fee yet: that's not a cost rise.
		if ( $freight ) {
			$fee = wc_format_decimal( $freight['fee'], 2 );
			if ( (string) get_post_meta( $id, GSUP_META_SHIP_COST, true ) !== (string) $fee ) {
				$item->update_meta_data( GSUP_META_SHIP_COST, $fee );
				$changed = true;
				++$report['ship_changes'];
			}
			if ( (string) get_post_meta( $id, GSUP_META_SHIP_METHOD, true ) !== (string) $freight['code'] ) {
				$item->update_meta_data( GSUP_META_SHIP_METHOD, (string) $freight['code'] );
				$changed = true;
			}
		}
		$ship = $freight ? (float) $freight['fee'] : (float) get_post_meta( $id, GSUP_META_SHIP_COST, true );

		$cost = (string) $sku['price'];
		$rose = false;
		if ( '' !== $cost && (float) $cost > 0 ) {
			if ( (string) get_post_meta( $id, GSUP_META_COST, true ) !== $cost ) {
				$item->update_meta_data( GSUP_META_COST, $cost );
				$changed = true;
				++$report['cost_changes'];
			}
			$new_total = (float) $cost + ( $had_ship ? $ship : 0 );
			$old_total = $old_cost + ( $had_ship ? (float) $old_ship : 0 );
			$rose      = $old_cost > 0 && $new_total > $old_total + 0.004;
			if ( self::update_prices() ) {
				$price = GSUP_Creator::price_for( $cost, $ship );
				if ( '' !== $price && (string) $item->get_regular_price() !== (string) $price ) {
					$item->set_regular_price( $price );
					$changed = true;
					++$report['price_changes'];
				}
			}
		}
		if ( $changed ) {
			$item->save();
		}
		return $rose;
	}

	private static function finish( array $run ) {
		delete_option( self::OPT_RUN );
		$last = array(
			'started'  => (int) $run['started'],
			'finished' => time(),
			'manual'   => ! empty( $run['manual'] ),
			'total'    => isset( $run['ids'] ) ? count( $run['ids'] ) : 0,
			'report'   => $run['report'],
		);
		update_option( self::OPT_LAST, $last, false );
		self::email( $last );
	}

	private static function email( array $last ) {
		$r = $last['report'];
		if ( ! $r['removed'] && ! $r['options_gone'] && ! $r['back'] && ! $r['errors'] && empty( $r['low_margin'] ) ) {
			return;
		}
		$lines   = array();
		$lines[] = 'Givsen Supplier checked ' . (int) $r['checked'] . ' product(s) against AliExpress.';
		$lines[] = '';
		if ( $r['removed'] ) {
			$lines[] = 'No longer available on AliExpress — switched to draft so customers can’t order them:';
			foreach ( $r['removed'] as $id ) {
				$lines[] = '  • ' . gsup_product_label( $id ) . ' — ' . admin_url( 'post.php?post=' . (int) $id . '&action=edit' );
			}
			$lines[] = '';
		}
		if ( $r['options_gone'] ) {
			$lines[] = 'Options no longer on AliExpress — set to out of stock:';
			foreach ( $r['options_gone'] as $id ) {
				$lines[] = '  • ' . gsup_product_label( $id ) . ' — ' . gsup_product_edit_url( $id );
			}
			$lines[] = '';
		}
		if ( $r['back'] ) {
			$lines[] = 'Back on sale on AliExpress (still in draft — publish them if you want them back):';
			foreach ( $r['back'] as $id ) {
				$lines[] = '  • ' . gsup_product_label( $id ) . ' — ' . admin_url( 'post.php?post=' . (int) $id . '&action=edit' );
			}
			$lines[] = '';
		}
		if ( ! empty( $r['low_margin'] ) ) {
			$lines[] = 'AliExpress cost went up and your margin is now below ' . GSUP_Profit::min_margin() . '% — check the price:';
			foreach ( $r['low_margin'] as $id ) {
				$m       = GSUP_Profit::product_summary( wc_get_product( $id ) );
				$lines[] = '  • ' . gsup_product_label( $id ) . ( $m ? ' (margin ' . GSUP_Profit::pct( $m['margin_min'] ) . ')' : '' ) . ' — ' . admin_url( 'post.php?post=' . (int) $id . '&action=edit' );
			}
			$lines[] = '';
		}
		if ( $r['errors'] ) {
			$lines[] = 'The sync stopped early (nothing was switched to draft because of this):';
			foreach ( array_unique( $r['errors'] ) as $e ) {
				$lines[] = '  • ' . wp_strip_all_tags( $e );
			}
			$lines[] = '';
		}
		$lines[] = 'Details: ' . gsup_settings_url( 'sync' );

		$to = get_option( 'gsup_sync_email', get_option( 'admin_email' ) );
		wp_mail(
			$to,
			'[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] AliExpress sync: ' . ( $r['errors'] && ! $r['removed'] && ! $r['options_gone'] ? 'needs attention' : 'changes to check' ),
			implode( "\n", $lines )
		);
	}
}

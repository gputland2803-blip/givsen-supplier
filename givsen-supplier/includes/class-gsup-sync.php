<?php
/**
 * Daily sync with AliExpress, run in small batches through WooCommerce's job queue (Action Scheduler).
 *
 * Updates: stock, cost (_gsup_cost), delivery fee (_gsup_ship_cost), and — only if switched on — the regular
 * price from the pricing rule. Every source (warehouse) of an option gets its cost and stock updated too; which
 * stock the shop shows follows Settings → Selling worldwide ("primary warehouse" by default, or "any warehouse
 * that reaches a selling country", where a gone primary is replaced by the best other warehouse). Flags products whose margin a cost rise has pushed below the minimum.
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

	/** Switch to a product's backup supplier automatically when needed. */
	public static function backup_auto() {
		return 'no' !== get_option( 'gsup_backup_auto', 'yes' );
	}

	/** Cost rise (%) that makes a cheaper backup supplier take over. */
	public static function backup_rise() {
		return max( 1, (float) get_option( 'gsup_backup_rise', 15 ) );
	}

	/**
	 * How the sync treats your prices when AliExpress costs change:
	 * 'no' — never change them (default); 'low' — only raise a price whose margin has fallen below your minimum,
	 * up to the pricing rule (never lowers); 'yes' — regular prices always follow the pricing rule.
	 */
	public static function price_mode() {
		$v = get_option( 'gsup_sync_prices', 'no' );
		return in_array( $v, array( 'no', 'low', 'yes' ), true ) ? $v : 'no';
	}

	public static function update_prices() {
		return 'no' !== self::price_mode();
	}

	/**
	 * Stock to show for an AliExpress stock level: "sold out" below your threshold, and never more than your cap.
	 */
	public static function store_qty( $ae_qty ) {
		$qty = max( 0, (int) $ae_qty );
		$min = max( 0, (int) get_option( 'gsup_stock_min', 0 ) );
		$cap = max( 0, (int) get_option( 'gsup_stock_cap', 0 ) );
		if ( $min && $qty < $min ) {
			return 0;
		}
		return $cap ? min( $qty, $cap ) : $qty;
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
			'switched'      => array(), // product IDs moved to their backup supplier
			'backup_failed' => array(), // product IDs whose backup supplier couldn't be used
			'removed'       => array(), // product IDs drafted/flagged
			'options_gone'  => array(), // variation/simple IDs newly out because the option is gone
			'back'          => array(), // product IDs back on AliExpress after being drafted by sync
			'missing'       => array(), // product IDs missing once (will be drafted if missing again)
			'promoted'      => array(), // item IDs whose primary warehouse was replaced by another source
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
			$ships[ $id ] = self::ship_to_for( $product );
			foreach ( self::source_countries( $product, $ships[ $id ] ) as $c ) {
				$pairs[ $ae_pid . '|' . $c ] = array( $ae_pid, $c );
			}
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

	/**
	 * Countries to fetch a product's listing for so every source is seen: the primary's, plus each other warehouse's
	 * home country (Australia → AU, United States → US, the rest → your store's country).
	 */
	private static function source_countries( WC_Product $product, $primary_country ) {
		$out = array( $primary_country => true );
		foreach ( $product->is_type( 'variable' ) ? $product->get_children() : array( $product->get_id() ) as $id ) {
			foreach ( array_keys( GSUP_Sources::get( $id ) ) as $wh ) {
				$out[ gsup_quote_country( 'CN' === $wh ? '' : $wh ) ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Update an item's sources from the listings fetched for their home countries. Nothing is saved for items that
	 * have only their primary and no sources meta yet (they read the same either way).
	 *
	 * @param array|null $primary_sku The primary's SKU on the main listing (null = not there).
	 * @return array{0:array,1:string[]} [sources, warehouses seen on AliExpress this run]
	 */
	private static function update_sources( $item_id, $ae_pid, array &$cache, $primary_sku ) {
		$sources = GSUP_Sources::get( $item_id );
		$before  = $sources;
		$seen    = array();
		$primary = GSUP_Sources::wh( get_post_meta( $item_id, GSUP_META_SHIP, true ) );
		foreach ( $sources as $wh => $src ) {
			$sku = null;
			if ( $wh === $primary && $primary_sku ) {
				$sku = $primary_sku;
			} else {
				$country = gsup_quote_country( 'CN' === $wh ? '' : $wh );
				$key     = $ae_pid . '|' . $country;
				if ( ! isset( $cache[ $key ] ) ) {
					$cache[ $key ] = GSUP_AliExpress::get_product( $ae_pid, $country );
				}
				$sku = is_array( $cache[ $key ] ) ? GSUP_AliExpress::find_sku( $cache[ $key ], $src['sku'] ) : null;
			}
			if ( $sku ) {
				$seen[]                    = $wh;
				$sources[ $wh ]['cost']    = (string) $sku['price'];
				$sources[ $wh ]['stock']   = null === $sku['stock'] ? null : (int) $sku['stock'];
				$sources[ $wh ]['seen_at'] = time();
			}
		}
		$changed = false;
		foreach ( $sources as $wh => $src ) {
			if ( ! isset( $before[ $wh ] ) || $before[ $wh ]['cost'] !== $src['cost'] || $before[ $wh ]['stock'] !== $src['stock'] ) {
				$changed = true;
			}
		}
		if ( $changed && is_array( get_post_meta( $item_id, GSUP_Sources::META, true ) ) ) {
			GSUP_Sources::save( $item_id, $sources );
		}
		return array( $sources, $seen );
	}

	/**
	 * "Any warehouse" rule: the primary is gone, so make the best other source the primary — in stock and reaching a
	 * selling country if possible, else any source still on AliExpress (it then shows out of stock).
	 *
	 * @return array|null The new primary's SKU from the listing.
	 */
	private static function promote( WC_Product $item, $ae_pid, array $sources, array $seen, array &$cache, array &$report ) {
		$id      = $item->get_id();
		$primary = GSUP_Sources::wh( get_post_meta( $id, GSUP_META_SHIP, true ) );
		$live    = array_intersect_key( $sources, array_flip( $seen ) );
		unset( $live[ $primary ] );
		if ( ! $live ) {
			return null;
		}
		$wh = GSUP_Sources::best( $live, GSUP_Sources::reachable( $id ) );
		if ( null === $wh ) {
			$wh = GSUP_Sources::sort_warehouses( array_keys( $live ) )[0];
		}
		$key = $ae_pid . '|' . gsup_quote_country( 'CN' === $wh ? '' : $wh );
		$sku = isset( $cache[ $key ] ) && is_array( $cache[ $key ] ) ? GSUP_AliExpress::find_sku( $cache[ $key ], $live[ $wh ]['sku'] ) : null;
		if ( ! $sku ) {
			return null;
		}
		$item->update_meta_data( GSUP_META_SKU, (string) $sku['sku_id'] );
		$item->update_meta_data( GSUP_META_OPTION, mb_substr( $sku['option'], 0, 255 ) );
		if ( '' !== (string) $sku['ship_from'] ) {
			$item->update_meta_data( GSUP_META_SHIP, (string) $sku['ship_from'] );
		} else {
			$item->delete_meta_data( GSUP_META_SHIP );
		}
		// Delivery from that warehouse to your store's country, as last measured by the weekly reach refresh.
		foreach ( GSUP_Sources::reach( 0, $id ) as $r ) {
			if ( $r['warehouse'] === $wh && $r['country'] === GSUP_AliExpress::default_ship_to() && $r['deliverable'] ) {
				$item->update_meta_data( GSUP_META_SHIP_COST, wc_format_decimal( $r['ship_cost'], 2 ) );
				$item->update_meta_data( GSUP_META_SHIP_METHOD, (string) $r['method'] );
				if ( $r['days_max'] ) {
					$item->update_meta_data( GSUP_META_SHIP_DAYS, (int) $r['days_min'] . '-' . (int) $r['days_max'] );
				}
			}
		}
		$item->save();
		$report['promoted'][] = $id;
		return $sku;
	}

	/** "Any warehouse" rule: stock from the best reachable source (never added up across warehouses). */
	private static function apply_any_stock( WC_Product $item, array $sources ) {
		$st = GSUP_Sources::any_stock( $sources, GSUP_Sources::reachable( $item->get_id() ) );
		if ( ! $st['in_stock'] ) {
			$item->set_manage_stock( true );
			$item->set_stock_quantity( 0 );
			$item->set_stock_status( 'outofstock' );
		} elseif ( null === $st['qty'] ) {
			$item->set_manage_stock( false );
			$item->set_stock_status( 'instock' );
		} else {
			$item->set_manage_stock( true );
			$item->set_stock_quantity( self::store_qty( $st['qty'] ) );
		}
		$item->save();
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
				if ( self::try_backup( $product, 'listing removed', $report ) ) {
					return 'ok';
				}
				self::mark_removed( $product, $report );
				return 'removed';
			}
			update_post_meta( $product_id, self::M_STATUS, 'missing' );
			$report['missing'][] = $product_id;
			return 'missing';
		}

		if ( ! $ae['on_sale'] ) {
			if ( self::try_backup( $product, 'listing not for sale', $report ) ) {
				return 'ok';
			}
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
		$rise    = 0.0;     // Biggest cost rise among the options, as a fraction.
		$gone    = 0;
		$costs   = array(); // Item ID => cost with delivery now.
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
			list( $sources, $seen ) = self::update_sources( $target_id, $ae_pid, $cache, $sku );
			$any                    = 'any' === GSUP_Sources::stock_rule();
			if ( ! $sku && $any ) {
				$sku = self::promote( $item, $ae_pid, $sources, $seen, $cache, $report ); // Gone only when no source is left.
			}
			if ( ! $sku ) {
				self::mark_option_gone( $item, $report );
				++$gone;
				continue;
			}
			$rise                 = max( $rise, self::apply( $item, $sku, $report, $freight ) );
			if ( $any ) {
				self::apply_any_stock( $item, $sources ); // This run's stock for every source.
			}
			$costs[ $target_id ] = (float) $sku['price'] + ( $freight ? (float) $freight['fee'] : (float) get_post_meta( $target_id, GSUP_META_SHIP_COST, true ) );
		}
		$rose = $rise > 0;
		GSUP_Profit::$paused = false;
		if ( $product->is_type( 'variable' ) ) {
			WC_Product_Variable::sync( $product_id );
		}
		update_post_meta( $product_id, self::M_SYNCED, time() );
		wc_delete_product_transients( $product_id );
		if ( $gone ) {
			self::try_backup( $product, 'option no longer on AliExpress', $report );
		} elseif ( $rise * 100 > self::backup_rise() && self::try_backup( $product, 'cost rose ' . round( $rise * 100 ) . '%', $report, $costs ) ) {
			return 'ok';
		}
		if ( GSUP_Profit::refresh_flag( $product_id ) && $rose ) {
			$report['low_margin'][] = $product_id;
		}
		return 'ok';
	}

	/**
	 * Move a product to its backup supplier, if it has one that works: listing on sale, every matched option
	 * still there and in stock — and, for a price rise, cheaper than the current supplier.
	 *
	 * @param array|null $costs For a price rise: item ID => current cost with delivery.
	 * @return bool Switched.
	 */
	private static function try_backup( WC_Product $product, $why, array &$report, $costs = null ) {
		if ( ! self::backup_auto() || ! class_exists( 'GSUP_Remap' ) ) {
			return false;
		}
		$id = $product->get_id();
		$b  = GSUP_Remap::backup( $id );
		if ( ! $b ) {
			return false;
		}
		$new = GSUP_AliExpress::get_product( $b['product_id'], gsup_quote_country( $b['ship'] ) );
		$ok  = ! is_wp_error( $new ) && $new['on_sale'];
		foreach ( $ok ? $b['map'] : array() as $sku_id ) {
			$sku = GSUP_AliExpress::find_sku( $new, $sku_id );
			if ( ! $sku || $sku['ship_from'] !== (string) $b['ship'] || 0 === $sku['stock'] ) {
				$ok = false;
				break;
			}
		}
		if ( ! $ok ) {
			if ( null === $costs ) {
				$report['backup_failed'][] = $id; // Needed it and couldn't use it: tell you.
			}
			return false;
		}
		if ( null !== $costs ) {
			// Nothing is broken, so only switch if the backup covers every option in use…
			foreach ( array_keys( $costs ) as $item_id ) {
				if ( ! isset( $b['map'][ $item_id ] ) ) {
					return false;
				}
			}
			// …and is cheaper for them.
			$first   = reset( $b['map'] );
			$quote   = GSUP_Creator::quote( $new['product_id'], $first, $b['ship'] );
			$fee     = is_wp_error( $quote ) ? 0.0 : (float) $quote['fee'];
			$now     = 0.0;
			$instead = 0.0;
			foreach ( $b['map'] as $item_id => $sku_id ) {
				if ( isset( $costs[ $item_id ] ) ) {
					$now     += $costs[ $item_id ];
					$instead += (float) GSUP_AliExpress::find_sku( $new, $sku_id )['price'] + $fee;
				}
			}
			if ( ! $now || $instead >= $now ) {
				return false;
			}
		}
		$map = array();
		foreach ( $product->is_type( 'variable' ) ? $product->get_children() : array( $id ) as $item_id ) {
			$map[ $item_id ] = isset( $b['map'][ $item_id ] ) ? (string) $b['map'][ $item_id ] : '';
		}
		GSUP_Remap::switch_to( $id, $new, (string) $b['ship'], $map, false, true, 'automatic: ' . $why, null !== $costs );
		$report['switched'][] = $id;
		return true;
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
	 * @return float How much this option's cost (with delivery) went up, as a fraction (0 = no rise).
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
			$qty = self::store_qty( $sku['stock'] );
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
			$days = ! empty( $freight['max_days'] ) ? (int) $freight['min_days'] . '-' . (int) $freight['max_days'] : '';
			if ( '' !== $days && (string) get_post_meta( $id, GSUP_META_SHIP_DAYS, true ) !== $days ) {
				$item->update_meta_data( GSUP_META_SHIP_DAYS, $days );
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
			$mode  = self::price_mode();
			$price = 'no' === $mode ? '' : GSUP_Creator::price_for( $cost, $ship );
			if ( 'low' === $mode && '' !== $price ) {
				// Only step in when the margin has dropped below your minimum, and only upwards.
				$fig   = GSUP_Profit::figures( $item->get_regular_price(), (float) $cost + $ship );
				$price = $fig && $fig['margin'] * 100 < GSUP_Profit::min_margin() && (float) $price > (float) $item->get_regular_price() ? $price : '';
			}
			if ( '' !== $price ) {
				if ( (string) $item->get_regular_price() !== (string) $price ) {
					$item->set_regular_price( $price );
					$changed = true;
					++$report['price_changes'];
				}
			}
		}
		if ( $changed ) {
			$item->save();
		}
		return $rose ? $new_total / $old_total - 1 : 0.0;
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
		if ( ! $r['removed'] && ! $r['options_gone'] && ! $r['back'] && ! $r['errors'] && empty( $r['low_margin'] ) && empty( $r['switched'] ) && empty( $r['backup_failed'] ) && empty( $r['promoted'] ) ) {
			return;
		}
		$lines   = array();
		$lines[] = 'Givsen Supplier checked ' . (int) $r['checked'] . ' product(s) against AliExpress.';
		$lines[] = '';
		if ( $r['removed'] ) {
			$lines[] = 'No longer available on AliExpress — switched to draft so customers can’t order them. Use “Change supplier” to point each one at a new listing (options are matched for you):';
			foreach ( $r['removed'] as $id ) {
				$lines[] = '  • ' . gsup_product_label( $id ) . ' — ' . gsup_remap_url( $id );
			}
			$lines[] = '';
		}
		if ( $r['options_gone'] ) {
			$lines[] = 'Options no longer on AliExpress — set to out of stock:';
			foreach ( $r['options_gone'] as $id ) {
				$lines[] = '  • ' . gsup_product_label( $id ) . ' — change supplier: ' . gsup_remap_url( wp_get_post_parent_id( $id ) ? wp_get_post_parent_id( $id ) : $id );
			}
			$lines[] = '';
		}
		if ( ! empty( $r['promoted'] ) ) {
			$lines[] = 'Option no longer sold from its main warehouse — now supplied from another warehouse of the same listing:';
			foreach ( $r['promoted'] as $id ) {
				$lines[] = '  • ' . gsup_product_label( $id ) . ' → ' . gsup_ship_from_label( (string) get_post_meta( $id, GSUP_META_SHIP, true ) );
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
		if ( ! empty( $r['switched'] ) ) {
			$lines[] = 'Switched to their backup supplier automatically (your prices were left as they are):';
			foreach ( $r['switched'] as $id ) {
				$lines[] = '  • ' . gsup_product_label( $id ) . ' — ' . admin_url( 'post.php?post=' . (int) $id . '&action=edit' );
			}
			$lines[] = '';
		}
		if ( ! empty( $r['backup_failed'] ) ) {
			$lines[] = 'Needed their backup supplier but it couldn’t be used (listing gone or options missing) — choose a new one:';
			foreach ( $r['backup_failed'] as $id ) {
				$lines[] = '  • ' . gsup_product_label( $id ) . ' — ' . gsup_remap_url( $id );
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

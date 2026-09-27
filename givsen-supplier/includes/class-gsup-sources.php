<?php
/**
 * Sources and reach: one store product sold worldwide, with every AliExpress warehouse kept as a source.
 *
 * Sources — on each simple product / variation, meta `_gsup_sources`:
 *   { warehouse: { sku, option, cost, stock, seen_at } }   e.g. { AU: {...}, CN: {...}, TR: {...} }
 * Options with no "Ships From" on AliExpress are kept under CN. `_gsup_ae_sku_id` / `_gsup_ship_from` stay the
 * primary source, so everything that reads them keeps working; a product without `_gsup_sources` reads as
 * "primary only".
 *
 * Reach — table `{prefix}gsup_reach`, one row per option × selling country × warehouse: deliverable, in stock,
 * item cost and delivery (fee, method, days) for that country. Filled by refresh(): the listing is fetched once per
 * selling country (AliExpress only returns the options it can deliver there) and delivery is quoted once per
 * warehouse and country. Runs weekly for every linked product, from the Supplier tab ("Refresh sources"), and as a
 * bulk action. Countries you don't list are checked live when needed (live()), never stored.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Sources {

	const META      = '_gsup_sources';
	const M_REACHED = '_gsup_reach_at';   // Product: when its sources and reach were last refreshed.
	const GROUP     = 'givsen-supplier';
	const OPT_RUN   = 'gsup_reach_run';
	const OPT_LAST  = 'gsup_reach_last';
	const DEFAULT_COUNTRIES = array( 'AU', 'NZ', 'US', 'CA', 'GB', 'IE', 'DE', 'FR', 'NL', 'SE' );

	/** Connection trouble: keep what we had rather than record "can't deliver". */
	const SOFT_ERRORS = array( 'gsup_ae_network', 'gsup_ae_bad_response', 'gsup_ae_not_connected', 'gsup_ae_expired', 'gsup_ae_no_app', 'gsup_ae_missing' );

	public static function init() {
		add_action( 'gsup_reach_start', array( __CLASS__, 'start' ) );
		add_action( 'gsup_reach_batch', array( __CLASS__, 'batch' ) );
		add_action( 'gsup_sources_refresh', array( __CLASS__, 'refresh_job' ) );
		add_action( 'admin_post_gsup_sources_refresh', array( __CLASS__, 'handle_refresh' ) );
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
	}

	/* ---------------------------------------------------------------- admin */

	public static function refresh_url( $product_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=gsup_sources_refresh&product=' . (int) $product_id ), 'gsup_sources_refresh_' . (int) $product_id );
	}

	/** Supplier tab → "Refresh sources": straight away for one product. */
	public static function handle_refresh() {
		$product_id = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			wp_die( 'You do not have permission to do that.', 403 );
		}
		check_admin_referer( 'gsup_sources_refresh_' . $product_id );
		$back = admin_url( 'post.php?post=' . $product_id . '&action=edit' ) . '#gsup_supplier_data';
		if ( ! GSUP_AliExpress::is_connected() ) {
			gsup_flash( 'Connect AliExpress in Settings first.', 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$r = self::refresh( array( $product_id ) );
		$r = $r[ $product_id ] ?? new WP_Error( 'gsup_not_linked', 'This product isn’t linked to AliExpress.' );
		if ( is_wp_error( $r ) ) {
			gsup_flash( 'Couldn’t refresh sources: ' . esc_html( $r->get_error_message() ), 'error' );
		} else {
			gsup_flash(
				sprintf(
					'Sources refreshed: warehouses %1$s%2$s%3$s. Delivery checked for %4$d countries.',
					esc_html( implode( ', ', array_map( array( __CLASS__, 'label' ), $r['warehouses'] ) ) ),
					$r['added'] ? ' · ' . (int) $r['added'] . ' source(s) added' : '',
					$r['removed'] ? ' · ' . (int) $r['removed'] . ' no longer on AliExpress' : '',
					count( self::countries() ) - count( array_intersect( $r['skipped'], self::countries() ) )
				) . ( $r['skipped'] ? ' AliExpress didn’t answer for ' . esc_html( implode( ', ', $r['skipped'] ) ) . ' — try again later.' : '' ),
				$r['skipped'] ? 'warning' : 'success'
			);
		}
		wp_safe_redirect( $back );
		exit;
	}

	public static function bulk_action( $actions ) {
		$actions['gsup_sources'] = 'Refresh sources (Givsen Supplier)';
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'gsup_sources' !== $action ) {
			return $redirect;
		}
		$ok = array();
		foreach ( (array) $ids as $id ) {
			if ( current_user_can( 'edit_product', $id ) && '' !== (string) get_post_meta( $id, GSUP_META_PRODUCT, true ) ) {
				$ok[] = (int) $id;
			}
		}
		return add_query_arg( 'gsup_sources_queued', function_exists( 'as_enqueue_async_action' ) ? self::queue( $ok ) : 0, $redirect );
	}

	public static function bulk_notice() {
		if ( ! isset( $_GET['gsup_sources_queued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$n = absint( $_GET['gsup_sources_queued'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-' . ( $n ? 'success' : 'warning' ) . ' is-dismissible"><p>' . esc_html( $n ? 'Refreshing sources for ' . $n . ' product(s) in the background — each product’s Supplier tab shows its warehouses when done.' : 'None of the selected products is linked to AliExpress.' ) . '</p></div>';
	}

	/** "Australia", "China"… for a warehouse code. */
	public static function label( $wh ) {
		return gsup_ship_from_label( (string) $wh );
	}

	/**
	 * Supplier tab: each option's warehouses, and where each warehouse delivers.
	 */
	public static function panel_html( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return '';
		}
		$items   = self::items( $product );
		$rows    = self::reach( $product_id );
		$reached = (int) get_post_meta( $product_id, self::M_REACHED, true );
		$html    = '<div class="gsup-sources"><p class="form-field"><label>Warehouses</label><span>';
		$by_item = array();
		foreach ( $rows as $r ) {
			$by_item[ (int) $r['item_id'] ][ $r['warehouse'] ][ $r['country'] ] = $r;
		}
		$lines = array();
		foreach ( array_slice( $items, 0, 30 ) as $item ) {
			$sources = self::get( $item->get_id() );
			$primary = self::wh( get_post_meta( $item->get_id(), GSUP_META_SHIP, true ) );
			$chips   = array();
			foreach ( $sources as $wh => $src ) {
				$reach = array();
				foreach ( $by_item[ $item->get_id() ][ $wh ] ?? array() as $c => $r ) {
					if ( $r['deliverable'] ) {
						$reach[] = $c . ( $r['days_max'] ? ' ' . (int) $r['days_min'] . '–' . (int) $r['days_max'] . 'd' : '' );
					}
				}
				$chips[] = '<span class="gsup-chip' . ( $wh === $primary ? ' gsup-chip--main' : '' ) . '" title="' . esc_attr( $reach ? 'Delivers to: ' . implode( ', ', $reach ) : ( $reached ? 'Delivers to none of your selling countries' : 'Not checked yet' ) ) . '">' . esc_html( self::label( $wh ) . ( '' !== (string) $src['cost'] ? ' · ' . gsup_money( $src['cost'] ) : '' ) . ( null === $src['stock'] ? '' : ' · ' . ( $src['stock'] > 0 ? (int) $src['stock'] . ' left' : 'out of stock' ) ) . ( $reached ? ' · ' . count( $reach ) . '/' . count( self::countries() ) . ' countries' : '' ) ) . '</span>';
			}
			$name    = $item->is_type( 'variation' ) && function_exists( 'wc_get_formatted_variation' ) ? wp_strip_all_tags( wc_get_formatted_variation( $item, true, false, false ) ) : '';
			$lines[] = ( '' !== $name ? '<em>' . esc_html( $name ) . ':</em> ' : '' ) . ( $chips ? implode( ' ', $chips ) : '<span class="gsup-meta">no AliExpress option linked</span>' );
		}
		$html .= implode( '<br>', $lines );
		if ( count( $items ) > 30 ) {
			$html .= '<br><span class="gsup-meta">…and ' . ( count( $items ) - 30 ) . ' more options.</span>';
		}
		$html .= '<br><span class="gsup-meta">Main warehouse in bold (the one orders use). ' . ( $reached ? 'Checked ' . esc_html( wp_date( 'j M Y', $reached ) ) . ' for ' . count( self::countries() ) . ' selling countries — hover a warehouse for delivery times.' : 'Other warehouses and delivery by country haven’t been checked yet.' ) . '</span>';
		$html .= '<br><a class="button" href="' . esc_url( self::refresh_url( $product_id ) ) . '">Refresh sources</a> <span class="gsup-meta">Finds the listing’s other warehouses and checks delivery to each selling country (about ' . (int) ( count( self::countries() ) * 3 ) . ' AliExpress calls).</span>';
		$html .= '</span></p></div>';
		return $html;
	}

	/* ------------------------------------------------------------ settings */

	/** Countries you sell to (Settings → Selling worldwide). */
	public static function countries() {
		$saved = get_option( 'gsup_sell_countries', null );
		$list  = is_array( $saved ) ? $saved : self::DEFAULT_COUNTRIES;
		$out   = array();
		foreach ( $list as $c ) {
			$c = strtoupper( (string) $c );
			if ( preg_match( '/^[A-Z]{2}$/', $c ) ) {
				$out[ $c ] = $c;
			}
		}
		return $out ? array_values( $out ) : array( GSUP_AliExpress::default_ship_to() ); // None chosen: your store's country.
	}

	/** Also sell to countries not in the list (checked live when needed). */
	public static function sell_others() {
		return 'no' !== get_option( 'gsup_sell_others', 'yes' );
	}

	/**
	 * Stock shown in the shop: 'primary' — from the primary warehouse, as before (default until ordering can route
	 * to other warehouses); 'any' — in stock if any warehouse that reaches a selling country has it.
	 */
	public static function stock_rule() {
		return 'any' === get_option( 'gsup_stock_rule', 'primary' ) ? 'any' : 'primary';
	}

	/* ------------------------------------------------------------- sources */

	/** Warehouse code for a ships-from value ('' — not stated on AliExpress — is China). */
	public static function wh( $ship ) {
		$ship = strtoupper( (string) $ship );
		return '' === $ship ? 'CN' : $ship;
	}

	/**
	 * Which warehouse to prefer as the primary: Australia, then your store's country, then the United States,
	 * then the rest alphabetically, China last.
	 */
	public static function rank( $wh ) {
		$base = class_exists( 'GSUP_AliExpress' ) ? GSUP_AliExpress::default_ship_to() : 'AU';
		if ( 'AU' === $wh ) {
			return 0;
		}
		if ( $base === $wh ) {
			return 1;
		}
		if ( 'US' === $wh ) {
			return 2;
		}
		return 'CN' === $wh ? 4 : 3;
	}

	/** Sort warehouse codes by preference. */
	public static function sort_warehouses( array $codes ) {
		usort(
			$codes,
			function ( $a, $b ) {
				$r = self::rank( $a ) <=> self::rank( $b );
				return $r ? $r : strcmp( $a, $b );
			}
		);
		return $codes;
	}

	/** An option's identity without where it ships from: "colour:white|size:m". */
	public static function key_of( array $sku ) {
		$parts = array();
		foreach ( $sku['props'] as $p ) {
			if ( empty( $p['is_ship'] ) ) {
				$parts[] = self::norm( $p['name'] ) . ':' . self::norm( $p['value'] );
			}
		}
		return implode( '|', $parts );
	}

	private static function norm( $text ) {
		$text = function_exists( 'remove_accents' ) ? remove_accents( (string) $text ) : (string) $text;
		return trim( preg_replace( '/\s+/', ' ', strtolower( html_entity_decode( $text, ENT_QUOTES ) ) ) );
	}

	/**
	 * An item's sources. Items without the meta read as their primary only.
	 *
	 * @return array<string,array{sku:string,option:string,cost:string,stock:int|null,seen_at:int}>
	 */
	public static function get( $item_id ) {
		$saved = get_post_meta( $item_id, self::META, true );
		if ( is_array( $saved ) && $saved ) {
			return $saved;
		}
		$sku = (string) get_post_meta( $item_id, GSUP_META_SKU, true );
		if ( '' === $sku ) {
			return array();
		}
		return array(
			self::wh( get_post_meta( $item_id, GSUP_META_SHIP, true ) ) => array(
				'sku'     => $sku,
				'option'  => (string) get_post_meta( $item_id, GSUP_META_OPTION, true ),
				'cost'    => (string) get_post_meta( $item_id, GSUP_META_COST, true ),
				'stock'   => null,
				'seen_at' => 0,
			),
		);
	}

	public static function save( $item_id, array $sources ) {
		$order = self::sort_warehouses( array_keys( $sources ) );
		$out   = array();
		foreach ( $order as $wh ) {
			$out[ $wh ] = $sources[ $wh ];
		}
		update_post_meta( $item_id, self::META, $out );
	}

	/** A source entry from a listing's SKU. */
	public static function entry( array $sku ) {
		return array(
			'sku'     => (string) $sku['sku_id'],
			'option'  => mb_substr( (string) $sku['option'], 0, 255 ),
			'cost'    => (string) $sku['price'],
			'stock'   => null === $sku['stock'] ? null : (int) $sku['stock'],
			'seen_at' => time(),
		);
	}

	/**
	 * One listing from several fetches (one per delivery country). Each SKU keeps the version fetched for its own
	 * warehouse's home country when there is one — that's where its price and stock are quoted.
	 *
	 * @param array<string,array|WP_Error> $listings country => listing
	 * @return array|null Listing with the union of SKUs.
	 */
	public static function union( array $listings ) {
		$base = null;
		$skus = array();
		$from = array();
		foreach ( $listings as $country => $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			if ( ! $base ) {
				$base = $l;
			}
			foreach ( $l['skus'] as $sku ) {
				$id   = (string) $sku['sku_id'];
				$home = gsup_quote_country( $sku['ship_from'] );
				if ( ! isset( $skus[ $id ] ) || ( $home === $country && $from[ $id ] !== $home ) ) {
					$skus[ $id ] = $sku;
					$from[ $id ] = $country;
				}
			}
		}
		if ( ! $base ) {
			return null;
		}
		$base['skus'] = array_values( $skus );
		return $base;
	}

	/**
	 * Match store items to a listing's options in every warehouse.
	 * First exactly, by option values without Ships From (e.g. "Colour: White · Size: M" from Australia is the same as
	 * from China); items still unmatched in a warehouse fall back to Change supplier's word matching.
	 *
	 * @param WC_Product[] $items
	 * @param array        $listing Union listing.
	 * @return array<int,array<string,array>> item ID => [warehouse => SKU]
	 */
	public static function match( array $items, array $listing ) {
		$by_wh = array();
		$by_id = array();
		foreach ( $listing['skus'] as $sku ) {
			$by_wh[ self::wh( $sku['ship_from'] ) ][] = $sku;
			$by_id[ (string) $sku['sku_id'] ]        = $sku;
		}
		$out  = array();
		$keys = array();
		foreach ( $items as $item ) {
			$id         = $item->get_id();
			$out[ $id ] = array();
			$primary    = (string) get_post_meta( $id, GSUP_META_SKU, true );
			if ( '' !== $primary && isset( $by_id[ $primary ] ) ) {
				$keys[ $id ] = self::key_of( $by_id[ $primary ] );
				$out[ $id ][ self::wh( $by_id[ $primary ]['ship_from'] ) ] = $by_id[ $primary ];
			} else {
				// Primary missing from the listing: its known sources still identify the option.
				foreach ( self::get( $id ) as $src ) {
					if ( isset( $by_id[ $src['sku'] ] ) ) {
						$keys[ $id ] = self::key_of( $by_id[ $src['sku'] ] );
						break;
					}
				}
			}
		}
		foreach ( $by_wh as $wh => $skus ) {
			$used = array();
			foreach ( $out as $id => $m ) {
				if ( isset( $m[ $wh ] ) ) {
					$used[ (string) $m[ $wh ]['sku_id'] ] = true;
				}
			}
			$left = array();
			foreach ( $items as $item ) {
				$id = $item->get_id();
				if ( isset( $out[ $id ][ $wh ] ) ) {
					continue;
				}
				$hit = null;
				if ( isset( $keys[ $id ] ) ) {
					foreach ( $skus as $sku ) {
						if ( ! isset( $used[ (string) $sku['sku_id'] ] ) && self::key_of( $sku ) === $keys[ $id ] ) {
							$hit = $sku;
							break;
						}
					}
				}
				if ( $hit ) {
					$out[ $id ][ $wh ]              = $hit;
					$used[ (string) $hit['sku_id'] ] = true;
				} else {
					$left[] = $item;
				}
			}
			$free = array_values(
				array_filter(
					$skus,
					function ( $s ) use ( $used ) {
						return ! isset( $used[ (string) $s['sku_id'] ] );
					}
				)
			);
			// Word matching only when the listing has several options (a lone option would always "match").
			if ( $left && $free && ( count( $items ) > 1 || count( $skus ) > 1 ) ) {
				foreach ( GSUP_Remap::auto_match( $left, $free ) as $id => $m ) {
					if ( $m['score'] >= 0.8 ) {
						foreach ( $free as $sku ) {
							if ( (string) $sku['sku_id'] === (string) $m['sku'] ) {
								$out[ $id ][ $wh ] = $sku;
							}
						}
					}
				}
			} elseif ( $left && 1 === count( $items ) && 1 === count( $skus ) && ! isset( $keys[ $items[0]->get_id() ] ) ) {
				$out[ $items[0]->get_id() ][ $wh ] = $skus[0];
			}
		}
		return $out;
	}

	/** Store items of a product: its variations, or the product itself. */
	public static function items( WC_Product $product ) {
		if ( ! $product->is_type( 'variable' ) ) {
			return array( $product );
		}
		$out = array();
		foreach ( $product->get_children() as $id ) {
			$v = wc_get_product( $id );
			if ( $v ) {
				$out[] = $v;
			}
		}
		return $out;
	}

	/* -------------------------------------------------------------- refresh */

	/** Delivery countries to fetch a product's listing for: selling countries plus each warehouse's home country. */
	public static function fetch_countries( array $warehouses = array() ) {
		$list = self::countries();
		foreach ( $warehouses as $wh ) {
			$list[] = gsup_quote_country( 'CN' === $wh ? '' : $wh );
		}
		$list[] = GSUP_AliExpress::default_ship_to();
		return array_values( array_unique( array_map( 'strtoupper', $list ) ) );
	}

	/**
	 * Refresh sources and reach for products: listings for every selling country and delivery quotes per
	 * warehouse and country, all in parallel.
	 *
	 * @param int[] $product_ids
	 * @return array<int,array|WP_Error> product ID => {warehouses[], added, removed, rows, skipped[]} or error
	 */
	public static function refresh( array $product_ids ) {
		$jobs  = array();
		$pairs = array();
		foreach ( $product_ids as $pid ) {
			$product = wc_get_product( $pid );
			$ae      = (string) get_post_meta( $pid, GSUP_META_PRODUCT, true );
			if ( ! $product || '' === $ae || $product->is_type( 'variation' ) ) {
				continue;
			}
			$items = self::items( $product );
			$whs   = array();
			foreach ( $items as $item ) {
				$whs = array_merge( $whs, array_keys( self::get( $item->get_id() ) ) );
			}
			$countries    = self::fetch_countries( array_unique( $whs ) );
			$jobs[ $pid ] = array( $product, $ae, $items, $countries );
			foreach ( $countries as $c ) {
				$pairs[ $ae . '|' . $c ] = array( $ae, $c );
			}
		}
		$fetched = $pairs ? GSUP_AliExpress::get_products( array_values( $pairs ) ) : array();

		// Work out sources, then ask for one delivery quote per product, warehouse and country.
		$plans = array();
		$specs = array();
		foreach ( $jobs as $pid => $job ) {
			list( $product, $ae, $items, $countries ) = $job;
			$listings = array();
			$skipped  = array();
			$error    = null;
			foreach ( $countries as $c ) {
				$l = $fetched[ $ae . '|' . $c ] ?? new WP_Error( 'gsup_ae_missing', 'No reply from AliExpress.' );
				if ( is_wp_error( $l ) ) {
					$skipped[] = $c;
					$error     = $error ? $error : $l;
					continue;
				}
				$listings[ $c ] = $l;
			}
			$listing = self::union( $listings );
			if ( ! $listing ) {
				$plans[ $pid ] = $error ? $error : new WP_Error( 'gsup_ae_missing', 'No reply from AliExpress.' );
				continue;
			}
			$matches       = self::match( $items, $listing );
			$plans[ $pid ] = array( $product, $ae, $items, $listings, $matches, $skipped, $listing );
			$whs           = array();
			foreach ( $matches as $m ) {
				foreach ( $m as $wh => $sku ) {
					$whs[ $wh ][ (string) $sku['sku_id'] ] = true;
				}
			}
			foreach ( $whs as $wh => $sku_ids ) {
				foreach ( self::countries() as $c ) {
					if ( ! isset( $listings[ $c ] ) ) {
						continue;
					}
					// Quote an option of this warehouse that AliExpress can deliver there, if any.
					foreach ( $listings[ $c ]['skus'] as $sku ) {
						if ( isset( $sku_ids[ (string) $sku['sku_id'] ] ) ) {
							$specs[ $ae . '|' . $wh . '|' . $c ] = array( $ae, (string) $sku['sku_id'], $c );
							break;
						}
					}
				}
			}
		}
		$quotes = array();
		foreach ( $specs ? GSUP_AliExpress::freights( $specs ) : array() as $key => $options ) {
			if ( is_wp_error( $options ) ) {
				$quotes[ $key ] = in_array( $options->get_error_code(), self::SOFT_ERRORS, true ) ? 'skip' : null;
			} else {
				$quotes[ $key ] = $options ? GSUP_AliExpress::choose_freight( $options ) : null;
			}
		}

		$out = array();
		foreach ( $plans as $pid => $plan ) {
			$out[ $pid ] = is_wp_error( $plan ) ? $plan : self::apply_refresh( $pid, $plan, $quotes );
		}
		return $out;
	}

	/** Save one product's refreshed sources and reach rows. */
	private static function apply_refresh( $pid, array $plan, array $quotes ) {
		global $wpdb;
		list( $product, $ae, $items, $listings, $matches, $skipped, $listing ) = $plan;
		$all_fetched = ! $skipped;
		$added       = 0;
		$removed     = 0;
		$rows        = array();
		$now         = current_time( 'mysql', true );
		$seen_ids    = array();
		foreach ( $listing['skus'] as $sku ) {
			$seen_ids[ (string) $sku['sku_id'] ] = true;
		}
		$warehouses = array();
		foreach ( $items as $item ) {
			$id      = $item->get_id();
			$sources = self::get( $id );
			$primary = self::wh( get_post_meta( $id, GSUP_META_SHIP, true ) );
			foreach ( $matches[ $id ] ?? array() as $wh => $sku ) {
				if ( ! isset( $sources[ $wh ] ) || $sources[ $wh ]['sku'] !== (string) $sku['sku_id'] ) {
					++$added;
				}
				$sources[ $wh ] = self::entry( $sku );
			}
			// A source is dropped only when AliExpress answered for every country and it wasn't in any reply
			// (never the primary — the daily sync decides about that).
			foreach ( $sources as $wh => $src ) {
				if ( $all_fetched && ! isset( $seen_ids[ $src['sku'] ] ) && $wh !== $primary ) {
					unset( $sources[ $wh ] );
					++$removed;
				}
			}
			if ( $sources ) {
				self::save( $id, $sources );
			}
			foreach ( $sources as $wh => $src ) {
				$warehouses[ $wh ] = true;
				foreach ( self::countries() as $c ) {
					if ( ! isset( $listings[ $c ] ) ) {
						continue; // No answer for this country: keep what we had.
					}
					$in_reply = null;
					foreach ( $listings[ $c ]['skus'] as $s ) {
						if ( (string) $s['sku_id'] === $src['sku'] ) {
							$in_reply = $s;
							break;
						}
					}
					$q = $quotes[ $ae . '|' . $wh . '|' . $c ] ?? null;
					if ( 'skip' === $q ) {
						continue;
					}
					$deliverable = $in_reply && $q;
					$rows[]      = array(
						'product_id'  => (int) $pid,
						'item_id'     => (int) $id,
						'country'     => $c,
						'warehouse'   => $wh,
						'deliverable' => $deliverable ? 1 : 0,
						'in_stock'    => $deliverable && ( null === $in_reply['stock'] || $in_reply['stock'] > 0 ) ? 1 : 0,
						'stock'       => $in_reply && null !== $in_reply['stock'] ? (int) $in_reply['stock'] : null,
						'cost'        => $in_reply && '' !== (string) $in_reply['price'] ? round( (float) $in_reply['price'], 2 ) : null,
						'ship_cost'   => $q ? round( (float) $q['fee'], 2 ) : null,
						'method'      => $q ? mb_substr( (string) $q['code'], 0, 64 ) : '',
						'days_min'    => $q ? (int) $q['min_days'] : 0,
						'days_max'    => $q ? (int) $q['max_days'] : 0,
						'checked_at'  => $now,
					);
				}
			}
		}
		self::write_rows( $pid, $rows );
		update_post_meta( $pid, self::M_REACHED, time() );
		return array(
			'warehouses' => self::sort_warehouses( array_keys( $warehouses ) ),
			'added'      => $added,
			'removed'    => $removed,
			'rows'       => count( $rows ),
			'skipped'    => $skipped,
		);
	}

	/** Shop lists cached per country are rebuilt after any change. */
	public static function bump() {
		self::$reach_memo = array();
		update_option( 'gsup_reach_ver', (int) get_option( 'gsup_reach_ver', 0 ) + 1, true );
	}

	/** Replace a product's reach rows for the countries just checked (others are kept). */
	private static function write_rows( $pid, array $rows ) {
		global $wpdb;
		self::bump();
		$table = GSUP_Install::reach_table();
		$pairs = array();
		foreach ( $rows as $r ) {
			$pairs[ $r['country'] ] = true;
		}
		if ( $pairs ) {
			$in = implode( ',', array_fill( 0, count( $pairs ), '%s' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE product_id = %d AND country IN ($in)", array_merge( array( (int) $pid ), array_keys( $pairs ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $r ) {
				$values[] = '(%d,%d,%s,%s,%d,%d,' . ( null === $r['stock'] ? 'NULL' : '%d' ) . ',' . ( null === $r['cost'] ? 'NULL' : '%f' ) . ',' . ( null === $r['ship_cost'] ? 'NULL' : '%f' ) . ',%s,%d,%d,%s)';
				array_push( $args, $r['product_id'], $r['item_id'], $r['country'], $r['warehouse'], $r['deliverable'], $r['in_stock'] );
				foreach ( array( 'stock', 'cost', 'ship_cost' ) as $k ) {
					if ( null !== $r[ $k ] ) {
						$args[] = $r[ $k ];
					}
				}
				array_push( $args, $r['method'], $r['days_min'], $r['days_max'], $r['checked_at'] );
			}
			$wpdb->query( $wpdb->prepare( "REPLACE INTO {$table} (product_id,item_id,country,warehouse,deliverable,in_stock,stock,cost,ship_cost,method,days_min,days_max,checked_at) VALUES " . implode( ',', $values ), $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/** Drop a product's reach rows (its listing changed). */
	public static function forget( $product_id ) {
		global $wpdb;
		$wpdb->delete( GSUP_Install::reach_table(), array( 'product_id' => (int) $product_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::bump();
		delete_post_meta( $product_id, self::M_REACHED );
	}

	/**
	 * Reach rows for a product (or one item).
	 *
	 * @return array[]
	 */
	public static function reach( $product_id, $item_id = 0 ) {
		global $wpdb;
		// Once per product per request (the shop and gift checks ask for several countries in turn).
		if ( ! $item_id && isset( self::$reach_memo[ (int) $product_id ] ) ) {
			return self::$reach_memo[ (int) $product_id ];
		}
		$table = GSUP_Install::reach_table();
		$sql   = $item_id
			? $wpdb->prepare( "SELECT * FROM {$table} WHERE item_id = %d ORDER BY country, warehouse", (int) $item_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->prepare( "SELECT * FROM {$table} WHERE product_id = %d ORDER BY item_id, country, warehouse", (int) $product_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $item_id ) {
			self::$reach_memo[ (int) $product_id ] = $rows;
		}
		return $rows;
	}

	/** @var array<int,array[]> Reach rows read this request, by product. */
	private static $reach_memo = array();

	/**
	 * Reach for a country you don't list: one listing call and a quote per warehouse, live (kept for 6 hours in a
	 * transient, never in the reach table).
	 *
	 * @return array<int,array<string,array>>|WP_Error item ID => [warehouse => {deliverable, in_stock, stock, cost, ship_cost, method, days_min, days_max}]
	 */
	public static function live( $product_id, $country ) {
		$country = strtoupper( (string) $country );
		$cache   = 'gsup_live_' . (int) $product_id . '_' . $country;
		$hit     = get_transient( $cache );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$product = wc_get_product( $product_id );
		$ae      = (string) get_post_meta( $product_id, GSUP_META_PRODUCT, true );
		if ( ! $product || '' === $ae ) {
			return new WP_Error( 'gsup_not_linked', 'Not linked to AliExpress.' );
		}
		$l = GSUP_AliExpress::get_product( $ae, $country );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		$by_id = array();
		foreach ( $l['skus'] as $s ) {
			$by_id[ (string) $s['sku_id'] ] = $s;
		}
		$out   = array();
		$specs = array();
		foreach ( self::items( $product ) as $item ) {
			foreach ( self::get( $item->get_id() ) as $wh => $src ) {
				if ( isset( $by_id[ $src['sku'] ] ) && ! isset( $specs[ $wh ] ) ) {
					$specs[ $wh ] = array( $ae, $src['sku'], $country );
				}
			}
		}
		$quotes = $specs ? GSUP_AliExpress::freights( $specs ) : array();
		foreach ( self::items( $product ) as $item ) {
			foreach ( self::get( $item->get_id() ) as $wh => $src ) {
				$s = $by_id[ $src['sku'] ] ?? null;
				$q = isset( $quotes[ $wh ] ) && ! is_wp_error( $quotes[ $wh ] ) && $quotes[ $wh ] ? GSUP_AliExpress::choose_freight( $quotes[ $wh ] ) : null;
				$out[ $item->get_id() ][ $wh ] = array(
					'deliverable' => (bool) ( $s && $q ),
					'in_stock'    => (bool) ( $s && $q && ( null === $s['stock'] || $s['stock'] > 0 ) ),
					'stock'       => $s ? $s['stock'] : null,
					'cost'        => $s ? (float) $s['price'] : null,
					'ship_cost'   => $q ? (float) $q['fee'] : null,
					'method'      => $q ? (string) $q['code'] : '',
					'days_min'    => $q ? (int) $q['min_days'] : 0,
					'days_max'    => $q ? (int) $q['max_days'] : 0,
				);
			}
		}
		set_transient( $cache, $out, 6 * HOUR_IN_SECONDS );
		return $out;
	}

	/* ----------------------------------------------------------- stock rule */

	/**
	 * Warehouses that can deliver an item to at least one selling country, from the reach table.
	 * No reach rows yet (never refreshed) → every source counts.
	 *
	 * @return string[]
	 */
	public static function reachable( $item_id ) {
		global $wpdb;
		$countries = self::countries();
		$table     = GSUP_Install::reach_table();
		$in        = implode( ',', array_fill( 0, count( $countries ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$any = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE item_id = %d", (int) $item_id ) );
		if ( ! $any ) {
			return array_keys( self::get( $item_id ) );
		}
		$found = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT warehouse FROM {$table} WHERE item_id = %d AND deliverable = 1 AND country IN ($in)", array_merge( array( (int) $item_id ), $countries ) ) );
		// phpcs:enable
		return array_map( 'strval', $found );
	}

	/**
	 * Stock to show for an item under the "any warehouse" rule, from its sources' latest stock.
	 *
	 * @param array    $sources   From get().
	 * @param string[] $reachable Warehouses that reach a selling country.
	 * @return array{in_stock:bool,qty:int|null} qty null = in stock, amount unknown.
	 */
	public static function any_stock( array $sources, array $reachable ) {
		$in    = false;
		$qty   = 0;
		$known = true;
		foreach ( $sources as $wh => $src ) {
			if ( ! in_array( (string) $wh, $reachable, true ) ) {
				continue;
			}
			if ( null === $src['stock'] ) {
				$in    = true;
				$known = false;
			} elseif ( (int) $src['stock'] > 0 ) {
				$in  = true;
				$qty = max( $qty, (int) $src['stock'] ); // One order ships from one warehouse: never add them up.
			}
		}
		return array(
			'in_stock' => $in,
			'qty'      => $in && ! $known ? null : $qty,
		);
	}

	/**
	 * Best source to become the primary when the primary has gone: reachable, in stock, by warehouse preference.
	 *
	 * @return string|null Warehouse.
	 */
	public static function best( array $sources, array $reachable, $except = '' ) {
		foreach ( self::sort_warehouses( array_keys( $sources ) ) as $wh ) {
			$src = $sources[ $wh ];
			if ( $wh !== $except && in_array( $wh, $reachable, true ) && ( null === $src['stock'] || $src['stock'] > 0 ) ) {
				return $wh;
			}
		}
		return null;
	}

	/* ---------------------------------------------------------- weekly job */

	/** Weekly, early Monday morning store time, while the daily sync is on. */
	public static function schedule() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		$next = as_next_scheduled_action( 'gsup_reach_start', array(), self::GROUP );
		$on   = GSUP_Sync::enabled();
		if ( $on && ! $next ) {
			$when = ( new DateTimeImmutable( 'next monday 04:00', wp_timezone() ) )->getTimestamp();
			as_schedule_recurring_action( $when, WEEK_IN_SECONDS, 'gsup_reach_start', array(), self::GROUP );
		} elseif ( ! $on && $next ) {
			as_unschedule_all_actions( 'gsup_reach_start', array(), self::GROUP );
		}
	}

	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 'gsup_reach_start', 'gsup_reach_batch', 'gsup_sources_refresh' ) as $hook ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}
		}
	}

	public static function running() {
		$run = get_option( self::OPT_RUN );
		return is_array( $run ) && ! empty( $run['ids'] ) && ( time() - (int) $run['touched'] ) < HOUR_IN_SECONDS ? $run : null;
	}

	/** Products per background step (each is ~countries × (1 + warehouses) calls). */
	public static function batch_size() {
		return max( 1, (int) apply_filters( 'gsup_reach_batch_size', 3 ) );
	}

	public static function start( $manual = false ) {
		if ( self::running() || ! GSUP_AliExpress::is_connected() ) {
			return false;
		}
		$ids = self::linked_ids();
		update_option(
			self::OPT_RUN,
			array(
				'ids'     => $ids,
				'pos'     => 0,
				'started' => time(),
				'touched' => time(),
				'manual'  => (bool) $manual,
				'done'    => 0,
				'failed'  => 0,
				'rows'    => 0,
			),
			false
		);
		if ( $ids ) {
			as_enqueue_async_action( 'gsup_reach_batch', array(), self::GROUP );
		} else {
			self::finish( get_option( self::OPT_RUN ) );
		}
		return true;
	}

	public static function batch() {
		$run = get_option( self::OPT_RUN );
		if ( ! is_array( $run ) || empty( $run['ids'] ) ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$slice = array_slice( $run['ids'], $run['pos'], self::batch_size() );
		foreach ( self::refresh( $slice ) as $result ) {
			if ( is_wp_error( $result ) ) {
				++$run['failed'];
			} else {
				++$run['done'];
				$run['rows'] += $result['rows'];
			}
		}
		$run['pos']    += count( $slice );
		$run['touched'] = time();
		if ( $run['pos'] >= count( $run['ids'] ) ) {
			self::finish( $run );
			return;
		}
		update_option( self::OPT_RUN, $run, false );
		as_enqueue_async_action( 'gsup_reach_batch', array(), self::GROUP );
	}

	private static function finish( $run ) {
		delete_option( self::OPT_RUN );
		update_option(
			self::OPT_LAST,
			array(
				'started'  => (int) $run['started'],
				'finished' => time(),
				'total'    => count( $run['ids'] ),
				'done'     => (int) $run['done'],
				'failed'   => (int) $run['failed'],
				'rows'     => (int) $run['rows'],
			),
			false
		);
	}

	/** Background refresh for the bulk action (a few products per job). */
	public static function refresh_job( $ids ) {
		self::refresh( array_map( 'absint', (array) $ids ) );
	}

	/** Queue products for a background refresh. @return int Products queued. */
	public static function queue( array $ids ) {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		foreach ( array_chunk( $ids, self::batch_size() ) as $chunk ) {
			as_enqueue_async_action( 'gsup_sources_refresh', array( $chunk ), self::GROUP );
		}
		return count( $ids );
	}

	private static function linked_ids() {
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

	/**
	 * AliExpress calls the weekly refresh makes: per product, one listing call per selling country plus one
	 * delivery quote per warehouse and country.
	 *
	 * @return array{products:int,countries:int,warehouses:float,weekly:int,daily:int}
	 */
	public static function estimate() {
		global $wpdb;
		$products  = count( self::linked_ids() );
		$countries = count( self::countries() );
		$table     = GSUP_Install::reach_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$known = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT product_id) FROM {$table}" );
		$pairs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT DISTINCT product_id, warehouse FROM {$table}) t" );
		// phpcs:enable
		$wh = $known ? $pairs / $known : 1.0;
		return array(
			'products'   => $products,
			'countries'  => $countries,
			'warehouses' => round( $wh, 1 ),
			'weekly'     => (int) round( $products * $countries * ( 1 + $wh ) ),
			'daily'      => (int) round( $products * min( 2, max( 1, $wh ) ) ),
		);
	}
}

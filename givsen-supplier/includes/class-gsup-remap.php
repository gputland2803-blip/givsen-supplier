<?php
/**
 * "Change supplier": point an existing store product at a different AliExpress listing
 * (the seller removed it, an option ran out for good, or a cheaper/faster seller turned up).
 *
 * Your product stays exactly as it is — title, description, photos, prices, reviews, URL.
 * Each variation is matched to the new listing's options automatically (by its option values,
 * then by what it was on AliExpress), and you can change any match before saving.
 * Saving updates the supplier links, cost, delivery fee and stock, clears the "removed" flags,
 * and can republish a product the sync had switched to draft.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Remap {

	const M_HISTORY = '_gsup_supplier_history'; // [{from, to, at, why}] — earlier AliExpress products.
	const M_BACKUP  = '_gsup_backup';           // {product_id, ship, map: {item ID: SKU ID}, saved_at} — used when the main listing fails.

	public static function init() {
		add_action( 'admin_post_gsup_remap', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_gsup_backup_remove', array( __CLASS__, 'handle_backup_remove' ) );
	}

	/** The product's backup supplier, if one is saved. */
	public static function backup( $product_id ) {
		$b = get_post_meta( $product_id, self::M_BACKUP, true );
		return is_array( $b ) && ! empty( $b['product_id'] ) && ! empty( $b['map'] ) ? $b : null;
	}

	public static function handle_backup_remove() {
		$product_id = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			wp_die( 'You do not have permission to do that.', 403 );
		}
		check_admin_referer( 'gsup_backup_remove_' . $product_id );
		delete_post_meta( $product_id, self::M_BACKUP );
		gsup_flash( 'Backup supplier removed.', 'info' );
		wp_safe_redirect( admin_url( 'post.php?post=' . $product_id . '&action=edit' ) );
		exit;
	}

	public static function backup_remove_url( $product_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=gsup_backup_remove&product=' . (int) $product_id ), 'gsup_backup_remove_' . (int) $product_id );
	}

	/**
	 * Save a listing as a product's backup supplier, matching its options automatically (the extension's
	 * "Save as backup supplier"). Never switches the supplier and never changes prices.
	 *
	 * @param array  $new     Listing from GSUP_AliExpress::get_product().
	 * @param string $ship    Warehouse chosen on the page ('' = the listing's only warehouse).
	 * @param bool   $replace Replace an existing backup (the extension asks you to confirm first).
	 * @return array|WP_Error {matched, total, unmatched: [names], cost: {current, backup, items: [...]}, replaced, supplier_url}
	 */
	public static function save_backup_from_listing( $product_id, array $new, $ship, $replace ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->is_type( 'variation' ) ) {
			return new WP_Error( 'gsup_no_product', 'That product isn’t in your store any more.' );
		}
		$main = (string) get_post_meta( $product_id, GSUP_META_PRODUCT, true );
		if ( '' === $main ) {
			return new WP_Error( 'gsup_not_linked', 'That product isn’t linked to AliExpress yet — link it first.' );
		}
		if ( (string) $new['product_id'] === $main ) {
			return new WP_Error( 'gsup_same_as_main', 'This listing is already that product’s main supplier.' );
		}
		$old = self::backup( $product_id );
		if ( $old && ! $replace ) {
			return new WP_Error( 'gsup_backup_exists', 'That product already has a backup supplier (' . $old['product_id'] . '). Confirm to replace it.', array( 'current_backup' => (string) $old['product_id'] ) );
		}
		$groups = GSUP_Creator::by_warehouse( $new );
		if ( '' === $ship && 1 === count( $groups ) ) {
			$ship = (string) array_key_first( $groups );
		}
		if ( ! isset( $groups[ $ship ] ) ) {
			$have = array();
			foreach ( array_keys( $groups ) as $code ) {
				$have[] = GSUP_Creator::warehouse_label( (string) $code );
			}
			return new WP_Error( 'gsup_no_warehouse', 'This listing has no options shipping from ' . GSUP_Creator::warehouse_label( $ship ) . ( $have ? ' (it ships from: ' . implode( ', ', $have ) . '). Pick the warehouse on the page first.' : '.' ) );
		}
		$skus    = $groups[ $ship ];
		$items   = self::targets( $product );
		$matches = self::auto_match( $items, $skus );
		if ( ! $matches ) {
			return new WP_Error( 'gsup_no_match', 'None of the product’s options could be matched to this listing — use Change supplier… on the product to match them by hand.' );
		}
		$map       = array();
		$unmatched = array();
		foreach ( $items as $item ) {
			if ( isset( $matches[ $item->get_id() ] ) ) {
				$map[ $item->get_id() ] = (string) $matches[ $item->get_id() ]['sku'];
			} else {
				$unmatched[] = self::item_label( $item );
			}
		}
		// Cost now vs. the backup, for the matched options (the backup's delivery quoted once).
		$quote = GSUP_Creator::quote( $new['product_id'], reset( $map ), $ship );
		$fee   = is_wp_error( $quote ) || ! $quote ? 0.0 : (float) $quote['fee'];
		$rows  = array();
		$now   = 0.0;
		$then  = 0.0;
		foreach ( $items as $item ) {
			if ( ! isset( $map[ $item->get_id() ] ) ) {
				continue;
			}
			$sku     = GSUP_AliExpress::find_sku( $new, $map[ $item->get_id() ] );
			$current = GSUP_Profit::unit_cost( $item->get_id() );
			$backup  = $sku ? (float) $sku['price'] + $fee : null;
			$rows[]  = array(
				'name'    => self::item_label( $item ),
				'current' => null === $current ? null : round( $current, 2 ),
				'backup'  => null === $backup ? null : round( $backup, 2 ),
			);
			if ( null !== $current && null !== $backup ) {
				$now  += $current;
				$then += $backup;
			}
		}
		update_post_meta(
			$product_id,
			self::M_BACKUP,
			array(
				'product_id' => (string) $new['product_id'],
				'ship'       => $ship,
				'map'        => $map,
				'saved_at'   => time(),
			)
		);
		return array(
			'matched'      => count( $map ),
			'total'        => count( $items ),
			'unmatched'    => $unmatched,
			'cost'         => array(
				'current'  => round( $now, 2 ),
				'backup'   => round( $then, 2 ),
				'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				'items'    => $rows,
			),
			'replaced'     => $old ? (string) $old['product_id'] : '',
			'supplier_url' => admin_url( 'post.php?post=' . (int) $product_id . '&action=edit#gsup_supplier_data' ),
		);
	}

	/** Options of the product the backup has no match for (worked out, not stored, so the backup keeps one shape). */
	public static function backup_unmatched( $product_id ) {
		$b       = self::backup( $product_id );
		$product = $b ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return array();
		}
		$out = array();
		foreach ( self::targets( $product ) as $item ) {
			if ( ! isset( $b['map'][ $item->get_id() ] ) ) {
				$out[] = self::item_label( $item );
			}
		}
		return $out;
	}

	/** "Colour: Red, Size: M" for a variation; the product name for a simple product. */
	private static function item_label( WC_Product $item ) {
		if ( $item->is_type( 'variation' ) && function_exists( 'wc_get_formatted_variation' ) ) {
			return wp_strip_all_tags( wc_get_formatted_variation( $item, true, false, false ) );
		}
		return $item->get_name();
	}

	/** Linked store products for the extension's "Use as backup" search. */
	public static function linked_products( $search, $limit = 20 ) {
		$ids = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'      => $limit,
				'fields'           => 'ids',
				's'                => (string) $search,
				'meta_key'         => GSUP_META_PRODUCT, // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'          => '' === (string) $search ? 'modified' : 'relevance',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$b     = self::backup( $id );
			$out[] = array(
				'id'            => (int) $id,
				'name'          => html_entity_decode( $product->get_name(), ENT_QUOTES ),
				'ae_product_id' => (string) get_post_meta( $id, GSUP_META_PRODUCT, true ),
				'ships_from'    => implode( ', ', array_map( 'gsup_ship_from_label', GSUP_CBR::warehouses( $product ) ) ),
				'has_backup'    => (bool) $b,
				'backup_id'     => $b ? (string) $b['product_id'] : '',
				'edit_url'      => admin_url( 'post.php?post=' . (int) $id . '&action=edit' ),
			);
		}
		return $out;
	}

	public static function url( $product_id, $args = array() ) {
		return gsup_admin_url( array_merge( array( 'tab' => 'remap', 'product' => (int) $product_id ), $args ) );
	}

	/** Store items to map: the variations of a variable product, or the product itself. */
	private static function targets( WC_Product $product ) {
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

	/** Lower-case words that identify an option, without filler. */
	private static function words( $text ) {
		$text  = strtolower( remove_accents( html_entity_decode( (string) $text, ENT_QUOTES ) ) );
		$text  = preg_replace( '/ships?\s*from\s*:?\s*[a-z ]+/', ' ', $text );
		$stop  = array( 'color', 'colour', 'size', 'style', 'type', 'pcs', 'pc', 'piece', 'pieces', 'set', 'the', 'a', 'and', 'with', 'for', 'of' );
		$words = array();
		foreach ( preg_split( '/[^a-z0-9.]+/', $text, -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			$w = trim( $w, '.' );
			// Keep sizes and numbers (42, 30cm); drop filler and piece counts (2pcs).
			if ( '' !== $w && ! in_array( $w, $stop, true ) && ! preg_match( '/^\d+pcs?$/', $w ) ) {
				$words[ $w ] = true;
			}
		}
		return $words;
	}

	/** What a store item is: its attribute values (as customers see them) and its old AliExpress option text. */
	private static function item_words( WC_Product $item ) {
		$text = '';
		if ( $item->is_type( 'variation' ) ) {
			$text .= ' ' . implode( ' ', array_values( $item->get_variation_attributes( false ) ) );
		}
		$text .= ' ' . get_post_meta( $item->get_id(), GSUP_META_OPTION, true );
		return self::words( $text );
	}

	/**
	 * Best one-to-one matches between store items and the new listing's options.
	 *
	 * @param WC_Product[] $items
	 * @param array        $skus  New listing's options (one warehouse).
	 * @return array<int,array{sku:string,score:float}> item ID => match
	 */
	public static function auto_match( array $items, array $skus ) {
		if ( 1 === count( $items ) && 1 === count( $skus ) ) {
			return array( $items[0]->get_id() => array( 'sku' => (string) $skus[0]['sku_id'], 'score' => 1.0 ) );
		}
		$pairs = array();
		foreach ( $items as $item ) {
			$a = self::item_words( $item );
			if ( ! $a ) {
				continue;
			}
			foreach ( $skus as $sku ) {
				$b = self::words( GSUP_Creator::option_text( $sku ) );
				if ( ! $b ) {
					continue;
				}
				$common = count( array_intersect_key( $a, $b ) );
				if ( ! $common ) {
					continue;
				}
				// Share of the smaller side that matches, nudged by overall overlap.
				$score   = $common / min( count( $a ), count( $b ) ) * 0.8 + $common / count( $a + $b ) * 0.2;
				$pairs[] = array( $score, $item->get_id(), (string) $sku['sku_id'] );
			}
		}
		usort(
			$pairs,
			function ( $x, $y ) {
				return $y[0] <=> $x[0];
			}
		);
		$out  = array();
		$used = array();
		foreach ( $pairs as $p ) {
			if ( $p[0] < 0.5 || isset( $out[ $p[1] ] ) || isset( $used[ $p[2] ] ) ) {
				continue;
			}
			$out[ $p[1] ]  = array( 'sku' => $p[2], 'score' => round( $p[0], 2 ) );
			$used[ $p[2] ] = true;
		}
		return $out;
	}

	/* -------------------------------------------------------------- screen */

	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$product_id = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0;
		$input      = isset( $_GET['ae'] ) ? sanitize_text_field( wp_unslash( $_GET['ae'] ) ) : '';
		$want       = isset( $_GET['ship'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['ship'] ) ) ) : '';
		// phpcs:enable
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->is_type( 'variation' ) ) {
			echo '<p>Choose “Change supplier” from a product’s Supplier tab.</p>';
			return;
		}
		$items   = self::targets( $product );
		$current = (string) get_post_meta( $product_id, GSUP_META_PRODUCT, true );
		$status  = (string) get_post_meta( $product_id, GSUP_Sync::M_STATUS, true );
		$gone    = 0;
		$ships   = array();
		foreach ( $items as $item ) {
			$gone += get_post_meta( $item->get_id(), GSUP_Sync::M_GONE, true ) ? 1 : 0;
			$sf    = (string) get_post_meta( $item->get_id(), GSUP_META_SHIP, true );
			if ( '' !== $sf ) {
				$ships[ $sf ] = true;
			}
		}

		echo '<h2>Change supplier for “' . esc_html( $product->get_name() ) . '”</h2>';
		echo '<p class="gsup-meta">Your product stays as it is — title, description, photos, prices, reviews and web address. Only the AliExpress link behind each option changes.</p>';
		if ( 'removed' === $status || $gone ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( 'removed' === $status ? 'AliExpress no longer sells the current listing.' : $gone . ' option(s) are no longer on the current listing.' ) . '</p></div>';
		}
		if ( '' !== $current ) {
			echo '<p>Current listing: <a href="' . esc_url( gsup_ae_url( $current ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $current ) . ' ↗</a></p>';
		}
		$search = 'https://www.aliexpress.com/w/wholesale-' . rawurlencode( str_replace( ' ', '-', GSUP_Tidy::title( $product->get_name() ) ) ) . '.html';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="gsup-link-form" style="max-width:760px">';
		echo '<input type="hidden" name="page" value="gsup"><input type="hidden" name="tab" value="remap"><input type="hidden" name="product" value="' . (int) $product_id . '">';
		echo '<input type="text" name="ae" class="large-text" value="' . esc_attr( $input ) . '" placeholder="New AliExpress link or product ID" required>';
		echo '<button type="submit" class="button button-primary">Match options</button></form>';
		echo '<p class="gsup-meta"><a href="' . esc_url( $search ) . '" target="_blank" rel="noopener noreferrer">Search AliExpress for a replacement ↗</a> — then copy the product’s link here.</p>';

		if ( '' === $input ) {
			return;
		}
		if ( ! GSUP_AliExpress::is_connected() ) {
			echo '<p>Connect AliExpress in Settings first.</p>';
			return;
		}
		$want    = '' !== $want ? ( 'NONE' === $want ? '' : $want ) : ( 1 === count( $ships ) ? (string) key( $ships ) : 'AU' );
		$new     = GSUP_AliExpress::get_product( $input, gsup_quote_country( $want ) );
		if ( is_wp_error( $new ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $new->get_error_message() ) . '</p></div>';
			return;
		}
		$groups = GSUP_Creator::by_warehouse( $new );
		if ( ! $groups ) {
			echo '<div class="notice notice-warning inline"><p>That listing has no options for sale right now.</p></div>';
			return;
		}
		if ( ! isset( $groups[ $want ] ) ) {
			$want = (string) array_key_first( $groups );
		}
		$skus    = $groups[ $want ];
		$matches = self::auto_match( $items, $skus );
		$freight = GSUP_Creator::quote( $new['product_id'], $skus[0]['sku_id'], $want );
		$fee     = is_wp_error( $freight ) ? null : (float) $freight['fee'];

		echo '<div class="gsup-test-result">';
		if ( '' !== $new['image'] ) {
			echo '<img src="' . esc_url( $new['image'] ) . '" alt="" referrerpolicy="no-referrer">';
		}
		echo '<div><a href="' . esc_url( gsup_ae_url( $new['product_id'] ) ) . '" target="_blank" rel="noopener noreferrer"><strong>' . esc_html( $new['title'] ) . '</strong> ↗</a><div class="gsup-meta">Product ' . esc_html( $new['product_id'] ) . ( null === $fee ? ' · no delivery quote' : ' · delivery ' . esc_html( 0.0 === $fee ? 'free' : gsup_money( $fee ) ) . ' with ' . esc_html( $freight['name'] ) ) . '</div></div></div>';

		echo '<p class="gsup-warehouses">Warehouse: ';
		foreach ( $groups as $code => $list ) {
			$label = GSUP_Creator::warehouse_label( $code ) . ' (' . count( $list ) . ')';
			if ( (string) $code === $want ) {
				echo '<span class="button button-primary" aria-current="true">' . esc_html( $label ) . '</span> ';
			} else {
				echo '<a class="button" href="' . esc_url( self::url( $product_id, array( 'ae' => $new['product_id'], 'ship' => '' === $code ? 'none' : $code ) ) ) . '">' . esc_html( $label ) . '</a> ';
			}
		}
		echo '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="gsup_remap"><input type="hidden" name="product" value="' . (int) $product_id . '">';
		echo '<input type="hidden" name="ae" value="' . esc_attr( $new['product_id'] ) . '"><input type="hidden" name="ship" value="' . esc_attr( '' === $want ? 'none' : $want ) . '">';
		wp_nonce_field( 'gsup_remap_' . $product_id );
		echo '<table class="widefat striped gsup-sku-table"><thead><tr><th>Your option</th><th>New AliExpress option</th><th>New cost</th><th>Margin at your price</th></tr></thead><tbody>';
		$matched = 0;
		foreach ( $items as $item ) {
			$id    = $item->get_id();
			$label = $item->is_type( 'variation' ) ? wc_get_formatted_variation( $item, true, false, false ) : $item->get_name();
			$pick  = isset( $matches[ $id ] ) ? $matches[ $id ]['sku'] : '';
			$matched += '' !== $pick ? 1 : 0;
			echo '<tr><td><strong>' . esc_html( wp_strip_all_tags( $label ) ) . '</strong>';
			$old = (string) get_post_meta( $id, GSUP_META_OPTION, true );
			if ( '' !== $old ) {
				echo '<div class="gsup-meta">Was: ' . esc_html( $old ) . '</div>';
			}
			echo '</td><td><select name="map[' . (int) $id . ']" class="gsup-remap-pick" style="max-width:100%">';
			echo '<option value="">— No match: set out of stock —</option>';
			foreach ( $skus as $sku ) {
				$text = GSUP_Creator::option_text( $sku );
				echo '<option value="' . esc_attr( $sku['sku_id'] ) . '"' . selected( $pick, (string) $sku['sku_id'], false ) . '>' . esc_html( ( '' !== $text ? $text : '(single option)' ) . ' — ' . $sku['price'] . ( 0 === $sku['stock'] ? ' — out of stock' : '' ) ) . '</option>';
			}
			echo '</select>';
			if ( '' !== $pick && $matches[ $id ]['score'] < 0.8 ) {
				echo '<div class="gsup-meta gsup-warn--soft">Best guess — please check.</div>';
			}
			echo '</td>';
			$sku = '' !== $pick ? GSUP_AliExpress::find_sku( $new, $pick ) : null;
			$fig = $sku ? GSUP_Profit::figures( $item->get_price(), (float) $sku['price'] + (float) $fee ) : null;
			echo '<td>' . esc_html( $sku ? gsup_money( (float) $sku['price'] + (float) $fee ) : '—' ) . '</td>';
			echo '<td' . ( $fig && $fig['margin'] * 100 < GSUP_Profit::min_margin() ? ' class="gsup-sub--bad"' : '' ) . '>' . esc_html( $fig ? GSUP_Profit::pct( $fig['margin'] ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="gsup-meta">' . (int) $matched . ' of ' . count( $items ) . ' matched automatically. Margins use your current prices and the new cost with delivery.</p>';
		echo '<p><label><input type="checkbox" name="reprice" value="1"> Also set prices from my pricing rule with the new costs</label><br>';
		$drafted = get_post_meta( $product_id, GSUP_Sync::M_DRAFTED, true ) && 'draft' === $product->get_status();
		echo '<label><input type="checkbox" name="republish" value="1"' . ( $drafted ? ' checked' : ' disabled' ) . '> Publish it again' . ( $drafted ? ' (the sync switched it to draft when the old listing disappeared)' : ' (only for products the sync switched to draft)' ) . '</label></p>';
		echo '<p><button type="submit" name="mode" value="switch" class="button button-primary">Change supplier now</button> ';
		echo '<button type="submit" name="mode" value="backup" class="button">Save as backup supplier</button> ';
		echo '<a class="button-link" href="' . esc_url( admin_url( 'post.php?post=' . $product_id . '&action=edit' ) ) . '">Cancel</a></p>';
		echo '<p class="gsup-meta"><strong>Backup supplier:</strong> keeps your current supplier and remembers these matches. The daily sync switches over automatically if the current listing is removed, an option disappears, or its cost rises by more than ' . (int) GSUP_Sync::backup_rise() . '% and the backup is cheaper' . ( GSUP_Sync::backup_auto() ? '' : ' — <em>automatic switching is off in Settings → Daily sync</em>' ) . '.</p>';
		echo '</form>';
	}

	/* ---------------------------------------------------------------- save */

	public static function handle_save() {
		$product_id = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			wp_die( 'You do not have permission to do that.', 403 );
		}
		check_admin_referer( 'gsup_remap_' . $product_id );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$ae        = isset( $_POST['ae'] ) ? gsup_parse_product_id( sanitize_text_field( wp_unslash( $_POST['ae'] ) ) ) : '';
		$ship      = isset( $_POST['ship'] ) ? strtoupper( sanitize_key( wp_unslash( $_POST['ship'] ) ) ) : '';
		$ship      = 'NONE' === $ship ? '' : $ship;
		$map       = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? array_map( 'gsup_parse_sku_id', array_map( 'sanitize_text_field', wp_unslash( $_POST['map'] ) ) ) : array();
		$reprice   = ! empty( $_POST['reprice'] );
		$republish = ! empty( $_POST['republish'] );
		$mode      = isset( $_POST['mode'] ) && 'backup' === $_POST['mode'] ? 'backup' : 'switch';
		// phpcs:enable
		$product = wc_get_product( $product_id );
		$back    = self::url( $product_id, array( 'ae' => $ae, 'ship' => '' === $ship ? 'none' : $ship ) );
		if ( ! $product || '' === $ae ) {
			gsup_flash( 'Something was missing — try again.', 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$new = GSUP_AliExpress::get_product( $ae, gsup_quote_country( $ship ) );
		if ( is_wp_error( $new ) ) {
			gsup_flash( esc_html( $new->get_error_message() ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		if ( 'backup' === $mode ) {
			$clean = array();
			foreach ( $map as $item_id => $sku_id ) {
				if ( '' !== $sku_id && GSUP_AliExpress::find_sku( $new, $sku_id ) ) {
					$clean[ (int) $item_id ] = (string) $sku_id;
				}
			}
			if ( ! $clean ) {
				gsup_flash( 'Match at least one option to save a backup supplier.', 'error' );
				wp_safe_redirect( $back );
				exit;
			}
			update_post_meta(
				$product_id,
				self::M_BACKUP,
				array(
					'product_id' => (string) $new['product_id'],
					'ship'       => $ship,
					'map'        => $clean,
					'saved_at'   => time(),
				)
			);
			gsup_flash( 'Backup supplier saved (AliExpress product ' . esc_html( $new['product_id'] ) . ', ' . count( $clean ) . ' option(s) matched). Your current supplier stays in use.' );
			wp_safe_redirect( admin_url( 'post.php?post=' . $product_id . '&action=edit' ) );
			exit;
		}
		list( $linked, $unmatched ) = self::switch_to( $product_id, $new, $ship, $map, $reprice, $republish, 'changed by hand' );

		gsup_flash(
			sprintf(
				'Supplier changed to AliExpress product %1$s: %2$d option(s) linked%3$s.',
				esc_html( $new['product_id'] ),
				$linked,
				$unmatched ? ', ' . $unmatched . ' set out of stock (no match — link them in the Variations tab or delete them)' : ''
			),
			$unmatched ? 'warning' : 'success'
		);
		wp_safe_redirect( admin_url( 'post.php?post=' . $product_id . '&action=edit' ) );
		exit;
	}

	/**
	 * Point a product at another AliExpress listing. Used by the screen and by the sync's automatic backup switch.
	 *
	 * @param array  $new       Listing from GSUP_AliExpress::get_product().
	 * @param array  $map       Store item ID => new SKU ID ('' = no match).
	 * @param string $why       For the supplier history.
	 * @param bool   $old_as_backup Keep the current listing as the backup (when it still works, e.g. a price rise).
	 * @return array{0:int,1:int} options linked, options without a match
	 */
	public static function switch_to( $product_id, array $new, $ship, array $map, $reprice, $republish, $why = '', $old_as_backup = false ) {
		$product = wc_get_product( $product_id );
		$old_map = array();
		foreach ( self::targets( $product ) as $item ) {
			$sku_id = (string) get_post_meta( $item->get_id(), GSUP_META_SKU, true );
			if ( '' !== $sku_id ) {
				$old_map[ $item->get_id() ] = $sku_id;
			}
		}
		$old_ship_list = GSUP_CBR::warehouses( $product );
		$freight = null;
		foreach ( $map as $sku_id ) {
			if ( '' !== $sku_id ) {
				$freight = GSUP_Creator::quote( $new['product_id'], $sku_id, $ship );
				$freight = is_wp_error( $freight ) ? null : $freight;
				break;
			}
		}

		$old_pid   = (string) get_post_meta( $product_id, GSUP_META_PRODUCT, true );
		$old_ships = $old_ship_list;
		$linked    = 0;
		$unmatched = 0;
		GSUP_Profit::$paused = true;
		foreach ( self::targets( $product ) as $item ) {
			$id     = $item->get_id();
			$sku_id = isset( $map[ $id ] ) ? (string) $map[ $id ] : '';
			$sku    = '' !== $sku_id ? GSUP_AliExpress::find_sku( $new, $sku_id ) : null;
			if ( ! $sku || $sku['ship_from'] !== $ship ) {
				// No match on the new listing: keep it unsellable until you decide.
				foreach ( array( GSUP_META_SKU, GSUP_META_OPTION ) as $key ) {
					$item->delete_meta_data( $key );
				}
				if ( $item->get_manage_stock() ) {
					$item->set_stock_quantity( 0 );
				}
				$item->set_stock_status( 'outofstock' );
				$item->update_meta_data( GSUP_Sync::M_GONE, time() );
				$item->save();
				++$unmatched;
				continue;
			}
			$item->update_meta_data( GSUP_META_SKU, (string) $sku['sku_id'] );
			$item->update_meta_data( GSUP_META_OPTION, mb_substr( $sku['option'], 0, 255 ) );
			$item->update_meta_data( GSUP_META_COST, (string) $sku['price'] );
			if ( '' !== $ship ) {
				$item->update_meta_data( GSUP_META_SHIP, $ship );
			} else {
				$item->delete_meta_data( GSUP_META_SHIP );
			}
			GSUP_Creator::set_freight_meta( $item, $freight );
			$regular = $item->get_regular_price();
			GSUP_Creator::apply_price_and_stock( $item, $sku, $freight ); // Stock (and the rule's price)…
			if ( ! $reprice ) {
				$item->set_regular_price( $regular );                      // …keeping your price unless asked.
			}
			$item->delete_meta_data( GSUP_Sync::M_GONE );
			$item->save();
			++$linked;
		}
		GSUP_Profit::$paused = false;

		$history   = (array) get_post_meta( $product_id, self::M_HISTORY, true );
		$history[] = array(
			'from' => $old_pid,
			'to'   => (string) $new['product_id'],
			'at'   => time(),
			'why'  => $why,
		);
		update_post_meta( $product_id, self::M_HISTORY, array_slice( array_filter( $history ), -10 ) );
		update_post_meta( $product_id, GSUP_META_PRODUCT, (string) $new['product_id'] );
		update_post_meta( $product_id, GSUP_Sync::M_QUOTED, time() );
		delete_post_meta( $product_id, GSUP_Sync::M_STATUS );
		delete_post_meta( $product_id, GSUP_Sync::M_MISSING );
		$product = wc_get_product( $product_id );
		if ( $republish && $linked && get_post_meta( $product_id, GSUP_Sync::M_DRAFTED, true ) && 'draft' === $product->get_status() ) {
			$product->set_status( 'publish' );
			$product->save();
		}
		delete_post_meta( $product_id, GSUP_Sync::M_DRAFTED );
		if ( $product->is_type( 'variable' ) ) {
			WC_Product_Variable::sync( $product_id );
		}
		wc_delete_product_transients( $product_id );
		GSUP_Profit::refresh_flag( $product_id );
		GSUP_CBR::apply( $product_id, array( $ship ) !== $old_ships ); // Warehouse changed → its countries.

		if ( $old_as_backup && '' !== $old_pid && $old_map && count( $old_ship_list ) <= 1 ) {
			update_post_meta(
				$product_id,
				self::M_BACKUP,
				array(
					'product_id' => $old_pid,
					'ship'       => $old_ship_list ? (string) $old_ship_list[0] : '',
					'map'        => $old_map,
					'saved_at'   => time(),
				)
			);
		} else {
			$b = self::backup( $product_id );
			if ( $b && (string) $b['product_id'] === (string) $new['product_id'] ) {
				delete_post_meta( $product_id, self::M_BACKUP ); // The backup is now the main supplier.
			}
		}
		return array( $linked, $unmatched );
	}
}

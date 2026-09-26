<?php
/**
 * Clean-up after moving to "one product sold worldwide" (Givsen Supplier → Clean-up):
 *
 * - Remove Givsen Supplier's country restrictions (CBR): only from products this plugin restricted — recorded since
 *   0.16.0, or, for older ones, restrictions that are exactly what it sets for the product's warehouse. Anything
 *   else was set by hand and is left alone. Preview first; nothing changes without confirming.
 * - Merge warehouse versions: products that share one AliExpress listing (e.g. separate Australian and US
 *   versions) become one. The one with more sales is kept; the other's warehouses move across as sources (matched by
 *   option), it's switched to draft and unlinked, and its address redirects (301) to the kept product.
 *   Preview first; nothing merges without confirming.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Cleanup {

	const M_MERGED_INTO = '_gsup_merged_into';
	const OPT_REDIRECTS = 'gsup_redirects'; // Old product path => kept product ID.

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'redirect' ), 1 );
		add_action( 'admin_post_gsup_cbr_remove', array( __CLASS__, 'handle_cbr_remove' ) );
		add_action( 'admin_post_gsup_merge', array( __CLASS__, 'handle_merge' ) );
	}

	public static function url( $args = array() ) {
		return gsup_admin_url( array_merge( array( 'tab' => 'cleanup' ), $args ) );
	}

	/* ------------------------------------------------------------ redirects */

	/** A merged product's old address → the product it was merged into. */
	public static function redirect() {
		if ( ! is_404() ) {
			return;
		}
		$map = get_option( self::OPT_REDIRECTS, array() );
		if ( ! is_array( $map ) || ! $map ) {
			return;
		}
		$path = self::path( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared to stored paths only.
		if ( isset( $map[ $path ] ) && 'publish' === get_post_status( (int) $map[ $path ] ) ) {
			wp_safe_redirect( get_permalink( (int) $map[ $path ] ), 301 );
			exit;
		}
	}

	/** "/product/pearl-necklace-au/" from a URL or request path. */
	public static function path( $url ) {
		$p = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		return '' === $p ? '' : '/' . trim( strtolower( rawurldecode( $p ) ), '/' ) . '/';
	}

	/* ---------------------------------------------------------------- merge */

	/**
	 * Products sharing an AliExpress listing: [{ae, keep, merge[]}], kept one = more sales (older on a tie).
	 *
	 * @return array[]
	 */
	public static function groups() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS ae, p.ID AS id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND pm.meta_value <> '' AND p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private')",
				GSUP_META_PRODUCT
			),
			ARRAY_A
		);
		// phpcs:enable
		$by = array();
		foreach ( $rows as $r ) {
			$by[ (string) $r['ae'] ][] = (int) $r['id'];
		}
		$out = array();
		foreach ( $by as $ae => $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}
			usort(
				$ids,
				function ( $a, $b ) {
					$s = (int) get_post_meta( $b, 'total_sales', true ) <=> (int) get_post_meta( $a, 'total_sales', true );
					return $s ? $s : $a <=> $b;
				}
			);
			$out[] = array(
				'ae'    => (string) $ae,
				'keep'  => array_shift( $ids ),
				'merge' => $ids,
			);
		}
		return $out;
	}

	/** An option's identity from AliExpress's option text, without Ships From: "color:white|size:m". */
	public static function option_key( $text ) {
		$parts = array();
		foreach ( preg_split( '/\s*·\s*/u', (string) $text ) as $pair ) {
			$bits = array_map( 'trim', explode( ':', $pair, 2 ) );
			if ( 2 === count( $bits ) && ! preg_match( '/ships?\s*from/i', $bits[0] ) ) {
				$parts[] = strtolower( $bits[0] ) . ':' . strtolower( preg_replace( '/\s+/', ' ', $bits[1] ) );
			}
		}
		return implode( '|', $parts );
	}

	/**
	 * Merge products into the one kept. Each option of a merged product hands its warehouses to the kept product's
	 * matching option (same values without Ships From; a simple product matches a simple product).
	 *
	 * @return array{moved:int,unmatched:string[],drafted:int[]}
	 */
	public static function merge( $keep_id, array $merge_ids ) {
		$keep = wc_get_product( $keep_id );
		$out  = array(
			'moved'     => 0,
			'unmatched' => array(),
			'drafted'   => array(),
		);
		if ( ! $keep ) {
			return $out;
		}
		$keep_items = array();
		foreach ( GSUP_Sources::items( $keep ) as $item ) {
			$keep_items[ self::option_key( get_post_meta( $item->get_id(), GSUP_META_OPTION, true ) ) ] = $item->get_id();
		}
		$redirects = get_option( self::OPT_REDIRECTS, array() );
		$redirects = is_array( $redirects ) ? $redirects : array();
		$history   = (array) get_post_meta( $keep_id, '_gsup_merged_from', true );
		foreach ( $merge_ids as $mid ) {
			$other = wc_get_product( $mid );
			if ( ! $other || (int) $mid === (int) $keep_id ) {
				continue;
			}
			foreach ( GSUP_Sources::items( $other ) as $item ) {
				$key    = self::option_key( get_post_meta( $item->get_id(), GSUP_META_OPTION, true ) );
				$target = $keep_items[ $key ] ?? ( 1 === count( $keep_items ) && ! $other->is_type( 'variable' ) ? reset( $keep_items ) : null );
				if ( ! $target ) {
					$out['unmatched'][] = $other->get_name() . ( '' !== $key ? ' — ' . str_replace( '|', ', ', $key ) : '' );
					continue;
				}
				$sources = GSUP_Sources::get( $target );
				foreach ( GSUP_Sources::get( $item->get_id() ) as $wh => $src ) {
					if ( ! isset( $sources[ $wh ] ) ) {
						$sources[ $wh ] = $src;
						++$out['moved'];
					}
				}
				if ( $sources ) {
					GSUP_Sources::save( $target, $sources );
				}
			}
			$path = self::path( get_permalink( $mid ) );
			if ( '' !== $path && '/' !== $path ) {
				$redirects[ $path ] = (int) $keep_id;
			}
			// Keep the old link for reference, but take it out of the sync, reach checks and ordering.
			update_post_meta( $mid, '_gsup_merged_ae', (string) get_post_meta( $mid, GSUP_META_PRODUCT, true ) );
			delete_post_meta( $mid, GSUP_META_PRODUCT );
			update_post_meta( $mid, self::M_MERGED_INTO, (int) $keep_id );
			GSUP_Sources::forget( $mid );
			if ( 'draft' !== $other->get_status() ) {
				$other->set_status( 'draft' );
				$other->save();
			}
			$history[]         = array(
				'id'  => (int) $mid,
				'at'  => time(),
				'url' => $path,
			);
			$out['drafted'][] = (int) $mid;
		}
		update_option( self::OPT_REDIRECTS, $redirects, false );
		update_post_meta( $keep_id, '_gsup_merged_from', array_values( array_filter( $history ) ) );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			GSUP_Sources::queue( array( $keep_id ) ); // Delivery by country for the merged warehouses.
		}
		wc_delete_product_transients( $keep_id );
		return $out;
	}

	/* --------------------------------------------------------------- screen */

	private static function product_link( $id ) {
		return '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $id . '&action=edit' ) ) . '">' . esc_html( gsup_product_label( $id ) ) . '</a>';
	}

	public static function render() {
		echo '<h2>Clean-up for selling worldwide</h2>';
		echo '<p class="gsup-meta">Nothing on this page changes anything until you tick products and confirm.</p>';

		// Country restrictions.
		$r = GSUP_CBR::restricted();
		echo '<h3>Remove Givsen Supplier’s country restrictions</h3>';
		echo '<p>' . ( GSUP_CBR::worldwide() ? 'Delivery by country is on, so products are now shown wherever a warehouse can reach and new restrictions are no longer set.' : 'New restrictions stop being set once delivery by country is switched on (Settings → Selling worldwide).' ) . ' Only restrictions Givsen Supplier set are listed; ' . count( $r['hand'] ) . ' product(s) with restrictions you set by hand are left alone.</p>';
		if ( ! $r['plugin'] && ! $r['likely'] ) {
			echo '<p class="gsup-meta">No products restricted by Givsen Supplier.</p>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="gsup_cbr_remove">';
			wp_nonce_field( 'gsup_cbr_remove' );
			echo '<table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" class="gsup-check-all" checked aria-label="Select all"></td><th>Product</th><th>Restriction</th><th>Set by</th></tr></thead><tbody>';
			foreach ( array( 'plugin' => 'Givsen Supplier (recorded)', 'likely' => 'Givsen Supplier (matches what it sets for this warehouse; set before it kept a record)' ) as $who => $label ) {
				foreach ( $r[ $who ] as $id ) {
					echo '<tr><th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="' . (int) $id . '" checked></th><td>' . self::product_link( $id ) . '</td><td>' . esc_html( GSUP_CBR::label( $id ) ) . '</td><td>' . esc_html( $label ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				}
			}
			echo '</tbody></table>';
			echo '<p><button type="submit" class="button button-primary" data-gsup-confirm="Remove the country restriction from the ticked products? They will be shown in every country (the shop hides them where nothing can deliver).">Remove restrictions from ticked products</button></p></form>';
		}

		// Merge.
		$groups = self::groups();
		echo '<h3>Merge warehouse versions</h3>';
		echo '<p>Products that use the same AliExpress listing — usually one per warehouse, e.g. an Australian and a US version. Merging keeps the one with more sales, moves the other’s warehouses across as sources, switches the other to draft and redirects its address (301) to the kept product.</p>';
		if ( ! $groups ) {
			echo '<p class="gsup-meta">No products share an AliExpress listing.</p>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="gsup_merge">';
		wp_nonce_field( 'gsup_merge' );
		echo '<table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" class="gsup-check-all" aria-label="Select all"></td><th>Keep (more sales)</th><th>Merge into it</th><th>AliExpress listing</th></tr></thead><tbody>';
		foreach ( $groups as $g ) {
			$merge = array();
			foreach ( $g['merge'] as $id ) {
				$merge[] = self::product_link( $id ) . ' <span class="gsup-meta">(' . (int) get_post_meta( $id, 'total_sales', true ) . ' sold · ' . esc_html( implode( ', ', array_map( 'gsup_ship_from_label', GSUP_CBR::warehouses( wc_get_product( $id ) ) ) ) ) . ')</span>';
			}
			$keep = (int) $g['keep'];
			echo '<tr><th scope="row" class="check-column"><input type="checkbox" name="groups[]" value="' . esc_attr( $keep . ':' . implode( ',', $g['merge'] ) ) . '"></th>';
			echo '<td>' . self::product_link( $keep ) . ' <span class="gsup-meta">(' . (int) get_post_meta( $keep, 'total_sales', true ) . ' sold · ' . esc_html( implode( ', ', array_map( 'gsup_ship_from_label', GSUP_CBR::warehouses( wc_get_product( $keep ) ) ) ) ) . ')</span></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			echo '<td>' . implode( '<br>', $merge ) . '</td><td><a href="' . esc_url( gsup_ae_url( $g['ae'] ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $g['ae'] ) . ' ↗</a></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		echo '</tbody></table>';
		echo '<p class="gsup-meta">Reviews, sales history and past orders stay with each product. The drafted product keeps its old link in <em>_gsup_merged_ae</em> but is no longer synced or ordered from.</p>';
		echo '<p><button type="submit" class="button button-primary" data-gsup-confirm="Merge the ticked products? The merged ones are switched to draft and redirect to the kept product.">Merge ticked products</button></p></form>';
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_products' ) ) {
			wp_die( 'You do not have permission to do that.', 403 );
		}
		check_admin_referer( $action );
	}

	public static function handle_cbr_remove() {
		self::guard( 'gsup_cbr_remove' );
		$ids     = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ids'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$removed = 0;
		foreach ( $ids as $id ) {
			if ( current_user_can( 'edit_product', $id ) && GSUP_CBR::remove( $id ) ) {
				++$removed;
			}
		}
		gsup_flash( $removed . ' country restriction(s) removed. Restrictions you set by hand were left as they are.' );
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function handle_merge() {
		self::guard( 'gsup_merge' );
		$groups = isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['groups'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$done   = 0;
		$moved  = 0;
		$left   = array();
		foreach ( $groups as $g ) {
			if ( ! preg_match( '/^(\d+):([\d,]+)$/', $g, $m ) ) {
				continue;
			}
			$keep  = (int) $m[1];
			$merge = array_filter( array_map( 'intval', explode( ',', $m[2] ) ) );
			// Only products that still share the kept product's listing.
			$ae    = (string) get_post_meta( $keep, GSUP_META_PRODUCT, true );
			$merge = array_values(
				array_filter(
					$merge,
					function ( $id ) use ( $ae ) {
						return '' !== $ae && (string) get_post_meta( $id, GSUP_META_PRODUCT, true ) === $ae && current_user_can( 'edit_product', $id );
					}
				)
			);
			if ( ! $merge || ! current_user_can( 'edit_product', $keep ) ) {
				continue;
			}
			$r      = self::merge( $keep, $merge );
			$done  += count( $r['drafted'] );
			$moved += $r['moved'];
			$left   = array_merge( $left, $r['unmatched'] );
		}
		gsup_flash( sprintf( '%1$d product(s) merged, %2$d warehouse source(s) moved across. The merged products are drafts and redirect to the kept ones.', $done, $moved ) . ( $left ? ' Options with no match on the kept product (add them there if you want them): ' . esc_html( implode( '; ', array_slice( $left, 0, 8 ) ) ) . '.' : '' ), $left ? 'warning' : 'success' );
		wp_safe_redirect( self::url() );
		exit;
	}
}

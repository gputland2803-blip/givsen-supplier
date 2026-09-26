<?php
/**
 * Import list: AliExpress products/options captured by the extension (or added by hand),
 * waiting to be linked to a WooCommerce product or variation.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Import {

	const STATUSES = array( 'new', 'linked', 'dismissed' );

	/**
	 * Clean incoming data from the extension or the manual form.
	 */
	public static function normalize( array $data ) {
		$image = isset( $data['image'] ) ? esc_url_raw( (string) $data['image'], array( 'https' ) ) : '';
		$sku   = gsup_parse_sku_id( isset( $data['sku_id'] ) ? $data['sku_id'] : '' );
		if ( '' === $sku && empty( $data['sku_id'] ) && isset( $data['url'] ) ) {
			$sku = gsup_parse_sku_id( $data['url'] ); // Links sometimes carry the chosen option.
		}
		return array(
			'ae_product_id' => gsup_parse_product_id( isset( $data['product_id'] ) && '' !== (string) $data['product_id'] ? $data['product_id'] : ( isset( $data['url'] ) ? $data['url'] : '' ) ),
			'ae_sku_id'     => $sku,
			'ship_from'     => gsup_sanitize_ship_from( isset( $data['ship_from'] ) ? $data['ship_from'] : '' ),
			'option_label'  => mb_substr( sanitize_text_field( isset( $data['option'] ) ? (string) $data['option'] : '' ), 0, 255 ),
			'title'         => mb_substr( sanitize_text_field( isset( $data['title'] ) ? (string) $data['title'] : '' ), 0, 500 ),
			'image_url'     => mb_substr( $image, 0, 2000 ),
			'price'         => mb_substr( sanitize_text_field( isset( $data['price'] ) ? (string) $data['price'] : '' ), 0, 32 ),
			'currency'      => substr( preg_replace( '/[^A-Z]/', '', strtoupper( isset( $data['currency'] ) ? (string) $data['currency'] : '' ) ), 0, 8 ),
			'category_ids'  => implode( ',', gsup_clean_category_ids( isset( $data['category_ids'] ) ? $data['category_ids'] : array() ) ),
			// The product's words from the AliExpress page (overview, description, specifications) — sent by the extension.
			'page_text'     => mb_substr( sanitize_textarea_field( isset( $data['page_text'] ) ? (string) $data['page_text'] : '' ), 0, 8000 ),
		);
	}

	const BATCH_MAX = 30;

	/**
	 * Add several products at once from search results or a store page (the extension's bulk import).
	 * Each becomes a row with no option chosen yet; products already in the store or waiting in the list are skipped.
	 * Checking with AliExpress happens in the background.
	 *
	 * @param array $items        [{product_id, title, image, price, currency}]
	 * @param array $category_ids Store categories chosen for them.
	 * @return array{added:int[],already:string[],failed:array,row_ids:int[]}
	 */
	public static function add_batch( array $items, array $category_ids = array() ) {
		$out  = array(
			'added'   => array(),
			'already' => array(),
			'failed'  => array(),
			'row_ids' => array(),
		);
		$items = array_slice( $items, 0, self::BATCH_MAX );
		$ids   = array();
		foreach ( $items as $item ) {
			$ids[] = is_array( $item ) && isset( $item['product_id'] ) ? (string) $item['product_id'] : '';
		}
		$where = self::statuses( $ids ); // Already in the store or the import list: one lookup for the batch.
		$seen  = array();
		foreach ( $items as $item ) {
			$pid = gsup_parse_product_id( is_array( $item ) && isset( $item['product_id'] ) ? (string) $item['product_id'] : '' );
			if ( '' === $pid ) {
				$out['failed'][] = array(
					'product_id' => is_array( $item ) && isset( $item['product_id'] ) ? mb_substr( (string) $item['product_id'], 0, 40 ) : '',
					'reason'     => 'Not an AliExpress product ID.',
				);
				continue;
			}
			if ( isset( $seen[ $pid ] ) || ! empty( $where[ $pid ] ) ) {
				$out['already'][] = $pid;
				$seen[ $pid ]     = true;
				continue;
			}
			$seen[ $pid ] = true;
			$result       = self::add(
				array(
					'product_id'   => $pid,
					'title'        => isset( $item['title'] ) ? (string) $item['title'] : '',
					'image'        => isset( $item['image'] ) ? (string) $item['image'] : '',
					'price'        => isset( $item['price'] ) ? preg_replace( '/[^0-9.]/', '', (string) $item['price'] ) : '',
					'currency'     => isset( $item['currency'] ) ? (string) $item['currency'] : '',
					'category_ids' => $category_ids,
				),
				'bulk'
			);
			if ( is_wp_error( $result ) ) {
				$out['failed'][] = array(
					'product_id' => $pid,
					'reason'     => $result->get_error_message(),
				);
				continue;
			}
			$out['added'][]   = $pid;
			$out['row_ids'][] = (int) $result['id'];
		}
		if ( $out['row_ids'] && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'gsup_enrich_rows', array( $out['row_ids'] ), 'givsen-supplier' );
		}
		return $out;
	}

	/**
	 * Where each AliExpress product already is: 'store' (linked to a store product), 'import' (in the import list),
	 * or '' (neither). Two queries for the whole list.
	 *
	 * @param string[] $ids
	 * @return array<string,string>
	 */
	public static function statuses( array $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'gsup_parse_product_id', array_slice( $ids, 0, 200 ) ) ) ) );
		$out = array_fill_keys( $ids, '' );
		if ( ! $ids ) {
			return $out;
		}
		$in    = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$table = GSUP_Install::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ae_product_id FROM {$table} WHERE status <> 'dismissed' AND ae_product_id IN ($in)", $ids ) ) as $pid ) {
			$out[ (string) $pid ] = 'import';
		}
		$store = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft') AND pm.meta_value IN ($in)",
				array_merge( array( GSUP_META_PRODUCT ), $ids )
			)
		);
		// phpcs:enable
		foreach ( (array) $store as $pid ) {
			$out[ (string) $pid ] = 'store';
		}
		return $out;
	}

	/** The latest words captured from this product's AliExpress page (any option, any row). */
	public static function page_text_for( $ae_product_id ) {
		global $wpdb;
		$table = GSUP_Install::table();
		return (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT page_text FROM {$table} WHERE ae_product_id = %s AND page_text IS NOT NULL AND page_text <> '' ORDER BY updated_at DESC LIMIT 1", (string) $ae_product_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Add a captured product/option. The same product + SKU is updated rather than duplicated.
	 *
	 * @return array{id:int,duplicate:bool,status:string}|WP_Error
	 */
	public static function add( array $data, $source ) {
		global $wpdb;
		$row = self::normalize( $data );
		if ( '' === $row['ae_product_id'] ) {
			return new WP_Error( 'gsup_no_product', 'No AliExpress product ID found.' );
		}
		$table = GSUP_Install::table();
		$now   = current_time( 'mysql', true );

		$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$table} WHERE ae_product_id = %s AND ae_sku_id = %s ORDER BY id DESC LIMIT 1", $row['ae_product_id'], $row['ae_sku_id'] ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( $existing ) {
			$update = array( 'updated_at' => $now );
			foreach ( array( 'ship_from', 'option_label', 'title', 'image_url', 'price', 'currency', 'category_ids', 'page_text' ) as $field ) {
				if ( '' !== $row[ $field ] ) {
					$update[ $field ] = $row[ $field ];
				}
			}
			$status = $existing['status'];
			if ( 'dismissed' === $status ) {
				$update['status'] = 'new';
				$status           = 'new';
			}
			$wpdb->update( $table, $update, array( 'id' => (int) $existing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return array(
				'id'        => (int) $existing['id'],
				'duplicate' => true,
				'status'    => $status,
			);
		}

		$row['source']        = sanitize_key( $source );
		$row['status']        = 'new';
		$row['wc_product_id'] = 0;
		$row['created_at']    = $now;
		$row['updated_at']    = $now;
		$ok                   = $wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $ok ) {
			return new WP_Error( 'gsup_db', 'Could not save to the import list.' );
		}
		return array(
			'id'        => (int) $wpdb->insert_id,
			'duplicate' => false,
			'status'    => 'new',
		);
	}

	/**
	 * Fill an import row from the AliExpress API: title, image, the option's text, ships-from and price.
	 * If the product has only one option, that option is picked. Never blocks an import when the API fails.
	 *
	 * @return true|WP_Error
	 */
	public static function enrich( $id ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row || ! class_exists( 'GSUP_AliExpress' ) || ! GSUP_AliExpress::is_connected() ) {
			return new WP_Error( 'gsup_ae_off', 'AliExpress API not connected.' );
		}
		$ship_to = in_array( $row['ship_from'], array( 'AU', 'US' ), true ) ? $row['ship_from'] : '';
		return self::apply_listing( $row, GSUP_AliExpress::get_product( $row['ae_product_id'], $ship_to ) );
	}

	/**
	 * Check many rows with AliExpress at once (a few calls in flight at a time) — used for bulk imports,
	 * in the background, so a batch never slows the request that added it.
	 *
	 * @param int[] $ids Import row IDs.
	 * @return int Rows checked.
	 */
	public static function enrich_many( array $ids ) {
		if ( ! class_exists( 'GSUP_AliExpress' ) || ! GSUP_AliExpress::is_connected() ) {
			return 0;
		}
		$rows  = array();
		$pairs = array();
		foreach ( array_slice( array_map( 'absint', $ids ), 0, 60 ) as $id ) {
			$row = self::get( $id );
			if ( ! $row ) {
				continue;
			}
			$ship                     = in_array( $row['ship_from'], array( 'AU', 'US' ), true ) ? $row['ship_from'] : GSUP_AliExpress::default_ship_to();
			$rows[ $id ]              = array( $row, $row['ae_product_id'] . '|' . $ship );
			$pairs[ $rows[ $id ][1] ] = array( $row['ae_product_id'], $ship );
		}
		$listings = $pairs ? GSUP_AliExpress::get_products( array_values( $pairs ) ) : array();
		foreach ( $rows as $r ) {
			self::apply_listing( $r[0], isset( $listings[ $r[1] ] ) ? $listings[ $r[1] ] : new WP_Error( 'gsup_ae_missing', 'No reply from AliExpress.' ) );
		}
		return count( $rows );
	}

	/** Background job: check freshly added bulk rows. */
	public static function enrich_job( $ids ) {
		self::enrich_many( (array) $ids );
	}

	/**
	 * Fill a row from its AliExpress listing (or record why it couldn't be checked).
	 *
	 * @param array          $row
	 * @param array|WP_Error $product Listing from GSUP_AliExpress::get_product().
	 * @return true|WP_Error
	 */
	private static function apply_listing( array $row, $product ) {
		global $wpdb;
		$id     = (int) $row['id'];
		$update = array( 'api_checked_at' => current_time( 'mysql', true ) );

		if ( is_wp_error( $product ) ) {
			$update['api_note'] = mb_substr( $product->get_error_message(), 0, 255 );
			$wpdb->update( GSUP_Install::table(), $update, array( 'id' => (int) $id ) ); // phpcs:ignore
			return $product;
		}

		if ( '' !== $product['title'] ) {
			$update['title'] = mb_substr( $product['title'], 0, 500 );
		}
		if ( '' !== $product['image'] ) {
			$update['image_url'] = esc_url_raw( $product['image'], array( 'https' ) );
		}

		$sku = null;
		if ( '' !== (string) $row['ae_sku_id'] ) {
			$sku = GSUP_AliExpress::find_sku( $product, $row['ae_sku_id'] );
		} elseif ( 1 === count( $product['skus'] ) ) {
			$sku                 = $product['skus'][0];
			$update['ae_sku_id'] = $sku['sku_id'];
		}

		if ( $sku ) {
			if ( '' !== $sku['option'] ) {
				$update['option_label'] = mb_substr( $sku['option'], 0, 255 );
			}
			if ( '' !== $sku['ship_from'] ) {
				$update['ship_from'] = $sku['ship_from'];
			}
			$update['price']    = mb_substr( (string) $sku['price'], 0, 32 );
			$update['currency'] = substr( preg_replace( '/[^A-Z]/', '', strtoupper( $sku['currency'] ) ), 0, 8 );
			$update['api_note'] = 0 === $sku['stock'] ? 'Checked with AliExpress — this option is out of stock.' : 'Checked with AliExpress.';
		} elseif ( '' !== (string) $row['ae_sku_id'] ) {
			$update['api_note'] = 'This option ID isn’t on the AliExpress listing any more.';
		} elseif ( 'bulk' === (string) $row['source'] ) {
			$update['api_note'] = sprintf( 'Checked with AliExpress: %d options — choose the warehouse and options on Add to store.', count( $product['skus'] ) );
			$first              = $product['skus'] ? $product['skus'][0] : null;
			if ( $first && '' === (string) $row['price'] ) {
				$update['price']    = mb_substr( (string) $first['price'], 0, 32 );
				$update['currency'] = substr( preg_replace( '/[^A-Z]/', '', strtoupper( $first['currency'] ) ), 0, 8 );
			}
		} else {
			$update['api_note'] = sprintf( 'This product has %d options on AliExpress — add the one you want with the extension.', count( $product['skus'] ) );
		}
		if ( ! $product['on_sale'] ) {
			$update['api_note'] = 'Not for sale on AliExpress right now (' . $product['status'] . ').';
		}

		// If the same product + option is already in the list, don't create a clash on the unique pair.
		if ( isset( $update['ae_sku_id'] ) ) {
			$clash = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . GSUP_Install::table() . ' WHERE ae_product_id = %s AND ae_sku_id = %s AND id <> %d LIMIT 1', $row['ae_product_id'], $update['ae_sku_id'], (int) $id ) ); // phpcs:ignore
			if ( $clash ) {
				unset( $update['ae_sku_id'] );
				$update['api_note'] = 'This product’s only option is already in your import list — you can dismiss this one.';
			}
		}

		$wpdb->update( GSUP_Install::table(), $update, array( 'id' => (int) $id ) ); // phpcs:ignore
		return true;
	}

	public static function get( $id ) {
		global $wpdb;
		$table = GSUP_Install::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore
	}

	public static function query( $status, $per_page, $page ) {
		global $wpdb;
		$table  = GSUP_Install::table();
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );
		if ( 'all' === $status ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", (int) $per_page, $offset ), ARRAY_A ); // phpcs:ignore
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", $status, (int) $per_page, $offset ), ARRAY_A ); // phpcs:ignore
	}

	public static function counts() {
		global $wpdb;
		$table  = GSUP_Install::table();
		$counts = array_fill_keys( self::STATUSES, 0 );
		foreach ( $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ) as $r ) { // phpcs:ignore
			$counts[ $r['status'] ] = (int) $r['n'];
		}
		$counts['all'] = array_sum( $counts );
		return $counts;
	}

	public static function set_status( $id, $status, $wc_product_id = null ) {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		$data = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( null !== $wc_product_id ) {
			$data['wc_product_id'] = (int) $wc_product_id;
		}
		return false !== $wpdb->update( GSUP_Install::table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( GSUP_Install::table(), array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	/**
	 * Write an import row's supplier IDs onto a WooCommerce product or variation.
	 *
	 * @return string|WP_Error Success message, or an error explaining why nothing changed.
	 */
	public static function link_to_product( array $row, $wc_id ) {
		$product = wc_get_product( $wc_id );
		if ( ! $product ) {
			return new WP_Error( 'gsup_no_wc', 'Choose a product to link to.' );
		}
		if ( ! $product->is_type( array( 'simple', 'variable', 'variation' ) ) ) {
			return new WP_Error( 'gsup_type', 'Only simple products, variable products and variations can be linked.' );
		}

		$ae_pid    = (string) $row['ae_product_id'];
		$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$current   = (string) get_post_meta( $parent_id, GSUP_META_PRODUCT, true );

		if ( '' !== $current && $current !== $ae_pid ) {
			return new WP_Error(
				'gsup_conflict',
				sprintf(
					'“%1$s” is already linked to a different AliExpress product (%2$s). Change it on the product’s Supplier tab first if you really want to switch.',
					esc_html( gsup_product_label( $parent_id ) ),
					esc_html( $current )
				)
			);
		}

		update_post_meta( $parent_id, GSUP_META_PRODUCT, $ae_pid );

		if ( $product->is_type( 'variable' ) ) {
			$msg = sprintf( 'Linked “%s” to AliExpress product %s.', esc_html( gsup_product_label( $wc_id ) ), esc_html( $ae_pid ) );
			if ( '' !== (string) $row['ae_sku_id'] ) {
				$msg .= ' The option (SKU) wasn’t stored because you picked the whole product — link it to a specific variation to store the option.';
			}
			wc_delete_product_transients( $parent_id );
			return $msg;
		}

		foreach (
			array(
				GSUP_META_SKU    => (string) $row['ae_sku_id'],
				GSUP_META_SHIP   => (string) $row['ship_from'],
				GSUP_META_OPTION => (string) $row['option_label'],
			) as $key => $value
		) {
			if ( '' !== $value ) {
				update_post_meta( $product->get_id(), $key, $value );
			}
		}
		wc_delete_product_transients( $parent_id );

		$msg = sprintf( 'Linked “%s” to AliExpress product %s', esc_html( gsup_product_label( $wc_id ) ), esc_html( $ae_pid ) );
		if ( '' !== (string) $row['ae_sku_id'] ) {
			$msg .= sprintf( ', option %s.', esc_html( $row['ae_sku_id'] ) );
		} elseif ( '' === (string) get_post_meta( $product->get_id(), GSUP_META_SKU, true ) ) {
			$msg .= '. No option (SKU) was captured — add it on the product if AliExpress has options.';
		} else {
			$msg .= '. Its existing option (SKU) was kept.';
		}
		return $msg;
	}
}

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
			foreach ( array( 'ship_from', 'option_label', 'title', 'image_url', 'price', 'currency', 'category_ids' ) as $field ) {
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
		$product = GSUP_AliExpress::get_product( $row['ae_product_id'], $ship_to );
		$update  = array( 'api_checked_at' => current_time( 'mysql', true ) );

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

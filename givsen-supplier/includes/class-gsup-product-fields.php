<?php
/**
 * "Supplier" tab on the product editor (product ID; SKU + ships-from for simple products)
 * and supplier fields inside each variation.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Product_Fields {

	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
	}

	public static function tab( $tabs ) {
		$tabs['gsup_supplier'] = array(
			'label'    => 'Supplier',
			'target'   => 'gsup_supplier_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 65,
		);
		return $tabs;
	}

	public static function panel() {
		global $post;
		$id     = $post ? (int) $post->ID : 0;
		$ae_pid = (string) get_post_meta( $id, GSUP_META_PRODUCT, true );
		$ship   = (string) get_post_meta( $id, GSUP_META_SHIP, true );

		echo '<div id="gsup_supplier_data" class="panel woocommerce_options_panel hidden">';
		echo '<div class="options_group">';
		woocommerce_wp_text_input(
			array(
				'id'          => 'gsup_ae_product_id',
				'label'       => 'AliExpress product ID',
				'value'       => $ae_pid,
				'placeholder' => 'Paste the AliExpress link or ID',
				'desc_tip'    => true,
				'description' => 'The number in the AliExpress link (…/item/1005001234567890.html). You can paste the whole link.',
			)
		);
		if ( '' !== $ae_pid ) {
			echo '<p class="form-field"><label>&nbsp;</label><a href="' . esc_url( gsup_ae_url( $ae_pid ) ) . '" target="_blank" rel="noopener noreferrer">Open on AliExpress ↗</a></p>';
		}
		echo '</div>';

		echo '<div class="options_group show_if_simple">';
		woocommerce_wp_text_input(
			array(
				'id'          => 'gsup_ae_sku_id',
				'label'       => 'AliExpress SKU ID',
				'value'       => (string) get_post_meta( $id, GSUP_META_SKU, true ),
				'placeholder' => 'Filled in by the Chrome extension',
				'desc_tip'    => true,
				'description' => 'Identifies the exact option on AliExpress, including where it ships from. Leave blank if the AliExpress listing has no options.',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => 'gsup_ae_option',
				'label'       => 'Option on AliExpress',
				'value'       => (string) get_post_meta( $id, GSUP_META_OPTION, true ),
				'placeholder' => 'e.g. Color: Black · Ships From: Australia',
				'desc_tip'    => true,
				'description' => 'What to pick on AliExpress when ordering. Shown on the order panel.',
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => 'gsup_ship_from',
				'label'   => 'Ships from',
				'value'   => $ship,
				'options' => gsup_ship_from_options_with( $ship ),
			)
		);
		echo '</div>';

		echo '<div class="options_group show_if_variable"><p class="gsup-note">Each variation stores its own AliExpress SKU ID and ships-from country — open the <strong>Variations</strong> tab.</p></div>';
		echo '</div>';
	}

	/**
	 * Runs inside WooCommerce's verified product save.
	 */
	public static function save( $product ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce before this hook.
		if ( ! isset( $_POST['gsup_ae_product_id'] ) ) {
			return;
		}
		$raw = trim( sanitize_text_field( wp_unslash( $_POST['gsup_ae_product_id'] ) ) );
		$pid = gsup_parse_product_id( $raw );

		if ( '' === $raw ) {
			$product->delete_meta_data( GSUP_META_PRODUCT );
		} elseif ( '' === $pid ) {
			WC_Admin_Meta_Boxes::add_error( 'Givsen Supplier: couldn’t find an AliExpress product ID in “' . esc_html( $raw ) . '”. The previous value was kept.' );
		} else {
			$product->update_meta_data( GSUP_META_PRODUCT, $pid );
		}

		if ( $product->is_type( 'simple' ) ) {
			$sku_raw = isset( $_POST['gsup_ae_sku_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['gsup_ae_sku_id'] ) ) ) : '';
			$sku     = gsup_parse_sku_id( $sku_raw );
			if ( '' !== $sku_raw && '' === $sku ) {
				WC_Admin_Meta_Boxes::add_error( 'Givsen Supplier: the AliExpress SKU ID should be a number. The previous value was kept.' );
			} else {
				self::set_meta( $product, GSUP_META_SKU, $sku );
			}
			self::set_meta( $product, GSUP_META_OPTION, isset( $_POST['gsup_ae_option'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['gsup_ae_option'] ) ), 0, 255 ) : '' );
			self::set_meta( $product, GSUP_META_SHIP, isset( $_POST['gsup_ship_from'] ) ? gsup_sanitize_ship_from( wp_unslash( $_POST['gsup_ship_from'] ) ) : '' );
		} else {
			// Variable products keep option-level data on their variations only.
			$product->delete_meta_data( GSUP_META_SKU );
			$product->delete_meta_data( GSUP_META_OPTION );
			$product->delete_meta_data( GSUP_META_SHIP );
		}
		// phpcs:enable
	}

	private static function set_meta( $product, $key, $value ) {
		if ( '' === (string) $value ) {
			$product->delete_meta_data( $key );
		} else {
			$product->update_meta_data( $key, $value );
		}
	}

	public static function variation_fields( $loop, $variation_data, $variation ) {
		$id   = (int) $variation->ID;
		$ship = (string) get_post_meta( $id, GSUP_META_SHIP, true );
		echo '<div class="gsup-variation-fields"><p class="gsup-variation-title">Supplier (AliExpress)</p>';
		woocommerce_wp_text_input(
			array(
				'id'            => 'gsup_sku_' . $loop,
				'name'          => 'gsup_sku[' . $loop . ']',
				'label'         => 'AliExpress SKU ID',
				'value'         => (string) get_post_meta( $id, GSUP_META_SKU, true ),
				'placeholder'   => 'Filled in by the Chrome extension',
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_select(
			array(
				'id'            => 'gsup_ship_' . $loop,
				'name'          => 'gsup_ship[' . $loop . ']',
				'label'         => 'Ships from',
				'value'         => $ship,
				'options'       => gsup_ship_from_options_with( $ship ),
				'wrapper_class' => 'form-row form-row-last',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => 'gsup_option_' . $loop,
				'name'          => 'gsup_option[' . $loop . ']',
				'label'         => 'Option on AliExpress',
				'value'         => (string) get_post_meta( $id, GSUP_META_OPTION, true ),
				'placeholder'   => 'e.g. Color: Black · Ships From: Australia',
				'wrapper_class' => 'form-row form-row-full',
			)
		);
		echo '</div>';
	}

	/**
	 * Runs inside WooCommerce's save-variations request (nonce already checked).
	 */
	public static function save_variation( $variation_id, $i ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['gsup_sku'][ $i ] ) ) {
			return;
		}
		$sku_raw = trim( sanitize_text_field( wp_unslash( $_POST['gsup_sku'][ $i ] ) ) );
		$sku     = gsup_parse_sku_id( $sku_raw );
		if ( '' === $sku_raw || '' !== $sku ) {
			self::set_post_meta( $variation_id, GSUP_META_SKU, $sku );
		}
		self::set_post_meta( $variation_id, GSUP_META_SHIP, isset( $_POST['gsup_ship'][ $i ] ) ? gsup_sanitize_ship_from( wp_unslash( $_POST['gsup_ship'][ $i ] ) ) : '' );
		self::set_post_meta( $variation_id, GSUP_META_OPTION, isset( $_POST['gsup_option'][ $i ] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['gsup_option'][ $i ] ) ), 0, 255 ) : '' );
		// phpcs:enable
	}

	private static function set_post_meta( $id, $key, $value ) {
		if ( '' === (string) $value ) {
			delete_post_meta( $id, $key );
		} else {
			update_post_meta( $id, $key, $value );
		}
	}
}

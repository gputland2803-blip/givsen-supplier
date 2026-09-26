<?php
/**
 * Plugin Name:       Givsen Supplier
 * Description:       Links WooCommerce products to AliExpress by ID (product ID on the product, SKU ID on each variation), places paid orders on AliExpress automatically and brings tracking back, and shows your margin on every product and order. Replaces DSers.
 * Version:           0.7.0
 * Author:            Givsen
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * WC requires at least: 8.0
 * Text Domain:       givsen-supplier
 */

defined( 'ABSPATH' ) || exit;

define( 'GSUP_VERSION', '0.7.0' );
define( 'GSUP_DB_VERSION', '4' ); // 4: version flag autoloaded.
define( 'GSUP_FILE', __FILE__ );
define( 'GSUP_DIR', plugin_dir_path( __FILE__ ) );
define( 'GSUP_URL', plugin_dir_url( __FILE__ ) );

require_once GSUP_DIR . 'includes/functions.php';
require_once GSUP_DIR . 'includes/class-gsup-install.php';

register_activation_hook( __FILE__, array( 'GSUP_Install', 'activate' ) );
register_deactivation_hook( __FILE__, 'gsup_deactivate' );

function gsup_deactivate() {
	delete_transient( 'gsup_schedules_ok' );
	wp_clear_scheduled_hook( 'gsup_keep_alive' );
	if ( class_exists( 'GSUP_Sync' ) ) {
		GSUP_Sync::unschedule();
	}
	if ( class_exists( 'GSUP_Orders' ) ) {
		GSUP_Orders::unschedule();
	}
}

add_action( 'before_woocommerce_init', 'gsup_declare_wc_compat' );
function gsup_declare_wc_compat() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', GSUP_FILE, true );
	}
}

add_action( 'plugins_loaded', 'gsup_boot', 20 );
function gsup_boot() {
	// Upgrades and schedule checks only run in the admin and background jobs — never on shop pages.
	$backstage = is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	if ( $backstage ) {
		GSUP_Install::maybe_upgrade();
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'gsup_notice_needs_woocommerce' );
		return;
	}

	require_once GSUP_DIR . 'includes/class-gsup-import.php';
	require_once GSUP_DIR . 'includes/class-gsup-rest.php';
	require_once GSUP_DIR . 'includes/class-gsup-aliexpress.php';
	require_once GSUP_DIR . 'includes/class-gsup-tidy.php';
	require_once GSUP_DIR . 'includes/class-gsup-creator.php';
	require_once GSUP_DIR . 'includes/class-gsup-sync.php';
	require_once GSUP_DIR . 'includes/class-gsup-profit.php';
	require_once GSUP_DIR . 'includes/class-gsup-cbr.php';
	require_once GSUP_DIR . 'includes/class-gsup-orders.php';
	GSUP_Sync::init();
	GSUP_Profit::init();
	GSUP_Orders::init();

	// Keep the AliExpress connection alive on quiet days.
	add_action( 'gsup_keep_alive', array( 'GSUP_AliExpress', 'keep_alive' ) );
	if ( $backstage ) {
		add_action( 'init', 'gsup_ensure_schedules', 20 );
	}
	GSUP_REST::init();

	if ( is_admin() ) {
		require_once GSUP_DIR . 'includes/class-gsup-product-fields.php';
		require_once GSUP_DIR . 'includes/class-gsup-products-column.php';
		require_once GSUP_DIR . 'includes/class-gsup-admin-page.php';
		require_once GSUP_DIR . 'includes/class-gsup-order-panel.php';
		require_once GSUP_DIR . 'includes/class-gsup-remap.php';
		GSUP_Remap::init();
		GSUP_Product_Fields::init();
		GSUP_Products_Column::init();
		GSUP_Admin_Page::init();
		GSUP_Order_Panel::init();
	}
}

/**
 * Make sure the background jobs are scheduled. Checked at most every 12 hours
 * (settings changes re-check straight away), so it costs nothing on most requests.
 */
function gsup_ensure_schedules() {
	if ( get_transient( 'gsup_schedules_ok' ) ) {
		return;
	}
	if ( ! wp_next_scheduled( 'gsup_keep_alive' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'gsup_keep_alive' );
	}
	GSUP_Sync::schedule();
	GSUP_Orders::schedule();
	set_transient( 'gsup_schedules_ok', 1, 12 * HOUR_IN_SECONDS );
}

function gsup_notice_needs_woocommerce() {
	echo '<div class="notice notice-error"><p><strong>Givsen Supplier</strong> needs WooCommerce to be active.</p></div>';
}

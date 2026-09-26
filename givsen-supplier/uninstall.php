<?php
/**
 * Removes the import list, the plugin's settings and the AliExpress connection.
 * Supplier links, costs and sync notes on products, and AliExpress order/tracking numbers on orders are kept,
 * so reinstalling picks up exactly where you left off.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gsup_import" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_option( 'gsup_secret' );
delete_option( 'gsup_db_version' );
delete_option( 'gsup_ae_app_key' );
delete_option( 'gsup_ae_app_secret' );
delete_option( 'gsup_ae_token' );
delete_option( 'gsup_price_multiplier' );
delete_option( 'gsup_price_add' );
delete_option( 'gsup_price_round' );
wp_clear_scheduled_hook( 'gsup_keep_alive' );
foreach ( array( 'gsup_sync_enabled', 'gsup_sync_prices', 'gsup_sync_email', 'gsup_sync_run', 'gsup_sync_last' ) as $gsup_opt ) {
	delete_option( $gsup_opt );
}
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'gsup_sync_start', array(), 'givsen-supplier' );
	as_unschedule_all_actions( 'gsup_sync_batch', array(), 'givsen-supplier' );
}

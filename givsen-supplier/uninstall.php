<?php
/**
 * Removes the import list, the plugin's settings and the AliExpress connection.
 * Supplier links, costs, delivery fees and sync notes on products, CBR restrictions, and AliExpress order/tracking
 * numbers and costs on orders are kept,
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
delete_transient( 'gsup_schedules_ok' );
foreach (
	array(
		'gsup_sync_enabled', 'gsup_sync_prices', 'gsup_sync_email', 'gsup_sync_run', 'gsup_sync_last', 'gsup_price_shipping',
		'gsup_auto_order', 'gsup_auto_pay', 'gsup_auto_loss_guard', 'gsup_complete_on_tracking', 'gsup_ship_pref',
		'gsup_late_notrack_days', 'gsup_late_grace_days', 'gsup_delivered_email', 'gsup_complete_when',
		'gsup_stock_min', 'gsup_stock_cap',
		'gsup_reviews_publish', 'gsup_fallback_phone',
		'gsup_min_margin', 'gsup_backup_auto', 'gsup_backup_rise', 'gsup_fee_percent', 'gsup_fee_fixed',
		'gsup_cbr_enabled', 'gsup_cbr_map', 'gsup_cbr_type_key', 'gsup_cbr_countries_key', 'gsup_cbr_type_value', 'gsup_cbr_format',
	) as $gsup_opt
) {
	delete_option( $gsup_opt );
}
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'gsup_sync_start', array(), 'givsen-supplier' );
	as_unschedule_all_actions( 'gsup_sync_batch', array(), 'givsen-supplier' );
	as_unschedule_all_actions( 'gsup_tracking_check', array(), 'givsen-supplier' );
	as_unschedule_all_actions( 'gsup_parcel_check', array(), 'givsen-supplier' );
	as_unschedule_all_actions( 'gsup_place_order' );
}

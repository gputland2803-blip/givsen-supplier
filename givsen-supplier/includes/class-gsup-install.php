<?php
defined( 'ABSPATH' ) || exit;

class GSUP_Install {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'gsup_import';
	}

	/** Where every warehouse can deliver each option (see GSUP_Sources). */
	public static function reach_table() {
		global $wpdb;
		return $wpdb->prefix . 'gsup_reach';
	}

	public static function activate() {
		self::create_table();
		if ( ! get_option( 'gsup_secret' ) ) {
			update_option( 'gsup_secret', self::new_secret(), false );
		}
		update_option( 'gsup_db_version', GSUP_DB_VERSION, true );
	}

	/** Runs on every load so replacing the plugin files (no re-activation) still upgrades the table. */
	public static function maybe_upgrade() {
		if ( get_option( 'gsup_db_version' ) !== GSUP_DB_VERSION ) {
			self::activate();
		}
	}

	public static function new_secret() {
		return wp_generate_password( 40, false, false );
	}

	private static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ae_product_id varchar(32) NOT NULL DEFAULT '',
  ae_sku_id varchar(64) NOT NULL DEFAULT '',
  ship_from varchar(8) NOT NULL DEFAULT '',
  option_label varchar(255) NOT NULL DEFAULT '',
  title text NOT NULL,
  image_url text NOT NULL,
  price varchar(32) NOT NULL DEFAULT '',
  currency varchar(8) NOT NULL DEFAULT '',
  source varchar(20) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'new',
  wc_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  category_ids varchar(255) NOT NULL DEFAULT '',
  page_text text NULL,
  api_note varchar(255) NOT NULL DEFAULT '',
  api_checked_at datetime NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY ae_product_id (ae_product_id),
  KEY status (status)
) {$collate};"
		);
		$reach = self::reach_table();
		dbDelta(
			"CREATE TABLE {$reach} (
  product_id bigint(20) unsigned NOT NULL,
  item_id bigint(20) unsigned NOT NULL,
  country char(2) NOT NULL,
  warehouse varchar(8) NOT NULL,
  deliverable tinyint(1) NOT NULL DEFAULT 0,
  in_stock tinyint(1) NOT NULL DEFAULT 0,
  stock int(11) NULL DEFAULT NULL,
  cost decimal(12,2) NULL DEFAULT NULL,
  ship_cost decimal(12,2) NULL DEFAULT NULL,
  method varchar(64) NOT NULL DEFAULT '',
  days_min smallint(5) unsigned NOT NULL DEFAULT 0,
  days_max smallint(5) unsigned NOT NULL DEFAULT 0,
  checked_at datetime NOT NULL,
  PRIMARY KEY  (item_id,country,warehouse),
  KEY shop (country,warehouse,deliverable,in_stock,product_id),
  KEY product_id (product_id)
) {$collate};"
		);
	}
}

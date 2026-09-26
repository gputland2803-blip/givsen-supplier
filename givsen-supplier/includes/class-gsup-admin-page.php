<?php
/**
 * WooCommerce → Givsen Supplier: the import list and the extension connection settings.
 * Also loads the plugin's admin CSS/JS on the screens it touches.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Admin_Page {

	const PER_PAGE = 50;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_filter( 'woocommerce_screen_ids', array( __CLASS__, 'screen_ids' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'product_screen_notices' ) );
		foreach ( array( 'add_manual', 'link', 'dismiss', 'restore', 'delete', 'refresh', 'regen_key', 'ae_save_app', 'ae_connect', 'ae_disconnect', 'ae_test', 'create', 'save_pricing', 'save_sync', 'sync_now', 'save_auto', 'save_profit', 'save_cbr', 'cbr_inspect', 'cbr_apply', 'save_eta', 'ae_log_clear', 'ae_log_save' ) as $action ) {
			add_action( 'admin_post_gsup_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	public static function menu() {
		$counts = class_exists( 'GSUP_Import' ) ? GSUP_Import::counts() : array( 'new' => 0 );
		$bubble = $counts['new'] ? ' <span class="awaiting-mod count-' . (int) $counts['new'] . '"><span class="pending-count">' . (int) $counts['new'] . '</span></span>' : '';
		add_submenu_page( 'woocommerce', 'Givsen Supplier', 'Givsen Supplier' . $bubble, 'manage_woocommerce', 'gsup', array( __CLASS__, 'render' ) );
	}

	/** Shows "product created" messages after landing on the new product. */
	public static function product_screen_notices() {
		$screen = get_current_screen();
		if ( $screen && 'product' === $screen->id ) {
			gsup_render_flash();
		}
	}

	/** Lets WooCommerce load its admin styles and product search on this page. */
	public static function screen_ids( $ids ) {
		$ids[] = 'woocommerce_page_gsup';
		return $ids;
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		$id     = $screen ? $screen->id : '';
		$order  = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		if ( ! in_array( $id, array( 'woocommerce_page_gsup', 'product', 'edit-product', 'shop_order', $order ), true ) ) {
			return;
		}
		wp_enqueue_style( 'gsup-admin', GSUP_URL . 'assets/admin.css', array(), GSUP_VERSION );
		wp_enqueue_script( 'gsup-admin', GSUP_URL . 'assets/admin.js', array(), GSUP_VERSION, true );
		if ( 'woocommerce_page_gsup' === $id ) {
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}
	}

	/* ---------------------------------------------------------------- render */

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'import'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = in_array( $tab, array( 'import', 'settings', 'create', 'remap', 'reports', 'tidy' ), true ) ? $tab : 'import';
		echo '<div class="wrap gsup-wrap">';
		echo '<h1 class="wp-heading-inline">Givsen Supplier</h1>';
		echo '<nav class="nav-tab-wrapper">';
		echo '<a class="nav-tab' . ( 'import' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( gsup_admin_url() ) . '">Import list</a>';
		echo '<a class="nav-tab' . ( 'reports' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( gsup_admin_url( array( 'tab' => 'reports' ) ) ) . '">Profit report</a>';
		if ( 'create' === $tab ) {
			echo '<span class="nav-tab nav-tab-active">Add to store</span>';
		} elseif ( 'remap' === $tab ) {
			echo '<span class="nav-tab nav-tab-active">Change supplier</span>';
		} elseif ( 'tidy' === $tab ) {
			echo '<span class="nav-tab nav-tab-active">Tidy text</span>';
		}
		echo '<a class="nav-tab' . ( 'settings' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( gsup_admin_url( array( 'tab' => 'settings' ) ) ) . '">Settings</a>';
		echo '</nav>';
		gsup_render_flash();
		if ( 'settings' === $tab ) {
			self::render_settings();
		} elseif ( 'create' === $tab ) {
			self::render_create();
		} elseif ( 'tidy' === $tab && class_exists( 'GSUP_Bulk_Tidy' ) ) {
			GSUP_Bulk_Tidy::render();
		} elseif ( 'reports' === $tab && class_exists( 'GSUP_Report' ) ) {
			GSUP_Report::render();
		} elseif ( 'remap' === $tab && class_exists( 'GSUP_Remap' ) ) {
			GSUP_Remap::render();
		} else {
			self::render_import();
		}
		echo '</div>';
	}

	private static function render_import() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'new';
		$status = in_array( $status, array_merge( GSUP_Import::STATUSES, array( 'all' ) ), true ) ? $status : 'new';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable
		$counts = GSUP_Import::counts();
		$rows   = GSUP_Import::query( $status, self::PER_PAGE, $paged );

		echo '<p class="gsup-intro">Products you add with the <strong>Add to Givsen</strong> button on AliExpress land here. Link each one to a product or variation in your store — the link is stored by ID, so renaming anything in WooCommerce never breaks it.</p>';

		self::render_manual_form();

		echo '<ul class="subsubsub">';
		$labels = array(
			'new'       => 'To link',
			'linked'    => 'Linked',
			'dismissed' => 'Dismissed',
			'all'       => 'All',
		);
		$parts  = array();
		foreach ( $labels as $key => $label ) {
			$parts[] = '<li><a href="' . esc_url( gsup_admin_url( array( 'status' => $key ) ) ) . '"' . ( $status === $key ? ' class="current"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . (int) $counts[ $key ] . ')</span></a>';
		}
		echo implode( ' | </li>', $parts ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.

		echo '<table class="widefat striped gsup-import-table"><thead><tr>';
		echo '<th class="gsup-col-img"></th><th>AliExpress product</th><th>Option</th><th>Price seen</th><th>Added</th><th class="gsup-col-action">Link to your product</th>';
		echo '</tr></thead><tbody>';

		if ( ! $rows ) {
			$empty = 'new' === $status ? 'Nothing waiting. Use <strong>Add to Givsen</strong> on an AliExpress product page, or add one by hand above.' : 'Nothing here.';
			echo '<tr><td colspan="6" class="gsup-empty">' . wp_kses_post( $empty ) . '</td></tr>';
		}

		$pairs = array();
		foreach ( $rows as $row ) {
			$pairs[] = array( $row['ae_product_id'], $row['ae_sku_id'] );
		}
		gsup_prime_links( $pairs );
		foreach ( $rows as $row ) {
			self::render_row( $row, $status );
		}
		echo '</tbody></table>';

		$total = 'all' === $status ? $counts['all'] : $counts[ $status ];
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<p class="gsup-pager">';
			if ( $paged > 1 ) {
				echo '<a class="button" href="' . esc_url( gsup_admin_url( array( 'status' => $status, 'paged' => $paged - 1 ) ) ) . '">‹ Newer</a> ';
			}
			echo 'Page ' . (int) $paged . ' of ' . (int) $pages;
			if ( $paged < $pages ) {
				echo ' <a class="button" href="' . esc_url( gsup_admin_url( array( 'status' => $status, 'paged' => $paged + 1 ) ) ) . '">Older ›</a>';
			}
			echo '</p>';
		}
	}

	private static function render_row( array $row, $status_filter ) {
		$id    = (int) $row['id'];
		$title = '' !== $row['title'] ? $row['title'] : 'AliExpress product ' . $row['ae_product_id'];
		echo '<tr class="gsup-row gsup-row--' . esc_attr( $row['status'] ) . '">';

		echo '<td class="gsup-col-img">';
		if ( '' !== $row['image_url'] ) {
			echo '<img src="' . esc_url( $row['image_url'] ) . '" alt="" loading="lazy" referrerpolicy="no-referrer">';
		}
		echo '</td>';

		echo '<td><a href="' . esc_url( gsup_ae_url( $row['ae_product_id'] ) ) . '" target="_blank" rel="noopener noreferrer"><strong>' . esc_html( $title ) . '</strong> ↗</a>';
		echo '<div class="gsup-meta">Product ID ' . esc_html( $row['ae_product_id'] ) . '</div></td>';

		echo '<td>';
		if ( '' !== $row['option_label'] ) {
			echo esc_html( $row['option_label'] ) . '<br>';
		}
		echo '<span class="gsup-meta">';
		echo '' !== $row['ae_sku_id'] ? 'SKU ' . esc_html( $row['ae_sku_id'] ) : '<em>No option captured</em>';
		if ( '' !== $row['ship_from'] ) {
			echo ' · Ships from <strong>' . esc_html( gsup_ship_from_label( $row['ship_from'] ) ) . '</strong>';
		}
		echo '</span>';
		if ( ! empty( $row['category_ids'] ) ) {
			$names = gsup_category_names( $row['category_ids'] );
			if ( $names ) {
				echo '<div class="gsup-meta">Category: ' . esc_html( implode( ', ', $names ) ) . '</div>';
			}
		}
		if ( ! empty( $row['api_note'] ) ) {
			$ok = 0 === strpos( $row['api_note'], 'Checked with AliExpress.' );
			echo '<div class="gsup-api-note' . ( $ok ? ' gsup-api-note--ok' : '' ) . '">' . esc_html( $row['api_note'] ) . '</div>';
		}
		echo '</td>';

		echo '<td>' . esc_html( trim( $row['price'] . ' ' . $row['currency'] ) ) . '</td>';
		echo '<td>' . esc_html( get_date_from_gmt( $row['updated_at'], 'j M Y' ) ) . '</td>';

		echo '<td class="gsup-col-action">';
		if ( 'linked' === $row['status'] ) {
			$wc_id = (int) $row['wc_product_id'];
			echo '<span class="gsup-badge gsup-badge--on">Linked</span> <a href="' . esc_url( gsup_product_edit_url( $wc_id ) ) . '">' . esc_html( gsup_product_label( $wc_id ) ) . '</a>';
		} else {
			$existing = gsup_find_linked( $row['ae_product_id'], $row['ae_sku_id'] );
			if ( $existing ) {
				echo '<div class="gsup-already">Already linked in your store: ';
				$links = array();
				foreach ( $existing as $wc_id ) {
					$links[] = '<a href="' . esc_url( gsup_product_edit_url( $wc_id ) ) . '">' . esc_html( gsup_product_label( $wc_id ) ) . '</a>';
				}
				echo implode( ', ', $links ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			}
			if ( 'new' === $row['status'] && GSUP_AliExpress::is_connected() ) {
				echo '<p class="gsup-create-cta"><a class="button button-primary" href="' . esc_url( self::create_url( $row['ae_product_id'], $row['ship_from'], $id ) ) . '">Add to store as new product</a> <span class="gsup-meta">or link to an existing one:</span></p>';
			}
			if ( 'new' === $row['status'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gsup-link-form">';
				echo '<input type="hidden" name="action" value="gsup_link"><input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
				echo '<input type="hidden" name="return_status" value="' . esc_attr( $status_filter ) . '">';
				wp_nonce_field( 'gsup_link_' . $id );
				$placeholder = '' !== $row['ae_sku_id'] ? 'Search for the variation…' : 'Search your products…';
				echo '<select class="wc-product-search" name="wc_product_id" style="width:100%" data-placeholder="' . esc_attr( $placeholder ) . '" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true"></select>';
				echo '<button type="submit" class="button">Link</button>';
				echo '</form>';
			}
		}
		echo '<div class="gsup-row-actions">';
		if ( GSUP_AliExpress::is_connected() && 'dismissed' !== $row['status'] ) {
			echo '<a href="' . esc_url( self::row_action_url( 'refresh', $id, $status_filter ) ) . '">Check with AliExpress</a> · ';
		}
		if ( 'new' === $row['status'] ) {
			echo '<a href="' . esc_url( self::row_action_url( 'dismiss', $id, $status_filter ) ) . '">Dismiss</a>';
		} elseif ( 'dismissed' === $row['status'] ) {
			echo '<a href="' . esc_url( self::row_action_url( 'restore', $id, $status_filter ) ) . '">Restore</a> · ';
			echo '<a class="gsup-danger" href="' . esc_url( self::row_action_url( 'delete', $id, $status_filter ) ) . '" data-gsup-confirm="Delete this from the import list? Your products are not affected.">Delete</a>';
		} else {
			echo '<a class="gsup-danger" href="' . esc_url( self::row_action_url( 'delete', $id, $status_filter ) ) . '" data-gsup-confirm="Remove this from the import list? The link on your product stays.">Remove from list</a>';
		}
		echo '</div></td></tr>';
	}

	private static function row_action_url( $action, $id, $status_filter ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'        => 'gsup_' . $action,
					'id'            => $id,
					'return_status' => $status_filter,
				),
				admin_url( 'admin-post.php' )
			),
			'gsup_row_' . $id
		);
	}

	private static function render_manual_form() {
		echo '<details class="gsup-manual"><summary>Add an AliExpress product by hand</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="gsup_add_manual">';
		wp_nonce_field( 'gsup_add_manual' );
		echo '<p><label>AliExpress link or product ID<br><input type="text" name="gsup_url" class="large-text" required placeholder="https://www.aliexpress.com/item/1005001234567890.html"></label></p>';
		echo '<div class="gsup-grid">';
		echo '<p><label>SKU ID (optional)<br><input type="text" name="gsup_sku" class="regular-text" placeholder="Picked up from the link if it has one"></label></p>';
		echo '<p><label>Ships from<br><select name="gsup_ship">';
		foreach ( gsup_ship_from_options() as $code => $label ) {
			echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label>Option on AliExpress (optional)<br><input type="text" name="gsup_option" class="regular-text" placeholder="e.g. Color: Black · Ships From: Australia"></label></p>';
		echo '<p><label>Title (optional)<br><input type="text" name="gsup_title" class="regular-text"></label></p>';
		echo '</div>';
		echo '<p><button type="submit" class="button button-primary">Add to import list</button></p>';
		echo '</form></details>';
	}

	/** Settings sections, in menu order: key => [menu label, page title, one-line purpose]. */
	private static function sections() {
		return array(
			'overview'   => array( 'Overview', 'Overview', 'What’s set up, and anything that needs you.' ),
			'aliexpress' => array( 'AliExpress connection', 'AliExpress connection', 'Your AliExpress app, the connection, and a product tester.' ),
			'ordering'   => array( 'Ordering & tracking', 'Ordering & tracking', 'Place paid orders on AliExpress automatically and bring tracking back.' ),
			'pricing'    => array( 'Pricing & profit', 'Pricing, profit & delivery estimate', 'How new products are priced, how margin is worked out, and the delivery estimate customers see.' ),
			'sync'       => array( 'Daily sync', 'Daily sync', 'Keep stock, costs and delivery fees in step with AliExpress.' ),
			'countries'  => array( 'Country restrictions', 'Country restrictions', 'Show each product only in the countries its warehouse serves (CBR).' ),
			'extension'  => array( 'Chrome extension', 'Chrome extension', 'Connect the Add to Givsen button to this store.' ),
		);
	}

	private static function current_section() {
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array_key_exists( $section, self::sections() ) ? $section : 'overview';
	}

	/**
	 * Status shown next to a section in the menu and on the overview.
	 *
	 * @return array{0:string,1:string} state ('on' | 'warn' | 'off' | 'info'), short label
	 */
	private static function section_status( $key ) {
		$connected = GSUP_AliExpress::is_connected();
		switch ( $key ) {
			case 'aliexpress':
				$token = GSUP_AliExpress::token();
				if ( ! GSUP_AliExpress::has_app() ) {
					return array( 'off', 'Not set up' );
				}
				if ( ! $token ) {
					return array( 'warn', 'Not connected' );
				}
				return $token['expires_at'] <= time() ? array( 'warn', 'Expired' ) : array( 'on', 'Connected' );
			case 'ordering':
				if ( ! GSUP_Orders::enabled() ) {
					return array( 'off', 'Off' );
				}
				return $connected ? array( 'on', 'On' ) : array( 'warn', 'Needs AliExpress' );
			case 'pricing':
				$r = GSUP_Creator::rule();
				return array( 'info', 'Price × ' . rtrim( rtrim( number_format( $r['multiplier'], 2 ), '0' ), '.' ) . ( $r['add'] ? ' + ' . wc_format_decimal( $r['add'], 2 ) : '' ) );
			case 'sync':
				if ( ! GSUP_Sync::enabled() ) {
					return array( 'off', 'Off' );
				}
				if ( GSUP_Sync::running() ) {
					return array( 'info', 'Running' );
				}
				$last = get_option( GSUP_Sync::OPT_LAST );
				if ( is_array( $last ) && ! empty( $last['report']['errors'] ) ) {
					return array( 'warn', 'Last run stopped' );
				}
				return $connected ? array( 'on', 'On' ) : array( 'warn', 'Needs AliExpress' );
			case 'countries':
				return GSUP_CBR::enabled() ? array( 'on', 'On' ) : array( 'off', 'Off' );
			case 'extension':
				return '' !== (string) get_option( 'gsup_secret' ) ? array( 'on', 'Key ready' ) : array( 'warn', 'No key' );
		}
		return array( 'info', '' );
	}

	private static function render_settings() {
		$current  = self::current_section();
		$sections = self::sections();
		echo '<div class="gsup-settings-layout">';
		echo '<nav class="gsup-subnav" aria-label="Settings sections"><ul>';
		foreach ( $sections as $key => $s ) {
			list( $state, $label ) = self::section_status( $key );
			$active = $key === $current;
			echo '<li><a href="' . esc_url( gsup_settings_url( $key ) ) . '"' . ( $active ? ' class="is-active" aria-current="page"' : '' ) . '>';
			echo '<span class="gsup-subnav-label">' . esc_html( $s[0] ) . '</span>';
			if ( '' !== $label ) {
				echo '<span class="gsup-pill gsup-pill--' . esc_attr( $state ) . '">' . esc_html( $label ) . '</span>';
			}
			echo '</a></li>';
		}
		echo '</ul></nav>';

		echo '<div class="gsup-panel">';
		echo '<header class="gsup-panel-head"><h2>' . esc_html( $sections[ $current ][1] ) . '</h2><p>' . esc_html( $sections[ $current ][2] ) . '</p></header>';
		switch ( $current ) {
			case 'aliexpress':
				self::render_ae_settings();
				break;
			case 'ordering':
				self::render_auto();
				break;
			case 'pricing':
				self::render_pricing();
				self::render_profit();
				self::render_eta();
				break;
			case 'sync':
				self::render_sync();
				break;
			case 'countries':
				self::render_cbr();
				break;
			case 'extension':
				self::render_extension();
				break;
			default:
				self::render_overview();
		}
		echo '</div></div>';
	}

	/** Status of every area at a glance, plus anything waiting on you. */
	private static function render_overview() {
		// Needs your attention.
		$todo = array();
		if ( ! GSUP_AliExpress::is_connected() ) {
			$todo[] = array( 'Connect AliExpress — ordering, sync, shipping quotes and Add to store all need it.', gsup_settings_url( 'aliexpress' ), 'Connect' );
		}
		$counts = GSUP_Import::counts();
		if ( $counts['new'] ) {
			$todo[] = array( $counts['new'] . ' item(s) in the import list waiting to be linked or added to the store.', gsup_admin_url(), 'Open import list' );
		}
		if ( function_exists( 'wc_get_orders' ) ) {
			$stuck = wc_get_orders(
				array(
					'limit'      => 20,
					'status'     => array( 'processing' ),
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'     => GSUP_Orders::M_AUTO_STATE,
							'value'   => array( 'failed', 'partial' ),
							'compare' => 'IN',
						),
					),
				)
			);
			foreach ( $stuck as $order ) {
				$todo[] = array( 'Order #' . $order->get_order_number() . ' has items that couldn’t be placed on AliExpress automatically.', $order->get_edit_order_url(), 'Open order' );
			}
		}
		$late = function_exists( 'wc_get_orders' ) ? wc_get_orders(
			array(
				'limit'      => 10,
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_key'   => GSUP_Parcels::M_ALERT, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		) : array();
		foreach ( $late as $order ) {
			$todo[] = array( 'Order #' . $order->get_order_number() . ' has a parcel that’s late or still without tracking.', $order->get_edit_order_url(), 'Open order' );
		}
		$low = ( new WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'meta_key'               => GSUP_Profit::M_LOW, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'             => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		) )->found_posts;
		if ( $low ) {
			$todo[] = array( (int) $low . ' product(s) with a margin below ' . GSUP_Profit::min_margin() . '%.', admin_url( 'edit.php?post_type=product&gsup_link=low' ), 'Show them' );
		}
		$last = get_option( GSUP_Sync::OPT_LAST );
		if ( is_array( $last ) && ! empty( $last['report']['errors'] ) ) {
			$todo[] = array( 'The last daily sync stopped early: ' . wp_strip_all_tags( reset( $last['report']['errors'] ) ), gsup_settings_url( 'sync' ), 'See report' );
		}

		echo '<h3 class="gsup-first">Needs your attention</h3>';
		if ( ! $todo ) {
			echo '<p class="gsup-allgood">✓ Nothing right now.</p>';
		} else {
			echo '<ul class="gsup-todo">';
			foreach ( $todo as $t ) {
				echo '<li><span>' . esc_html( $t[0] ) . '</span> <a class="button button-small" href="' . esc_url( $t[1] ) . '">' . esc_html( $t[2] ) . '</a></li>';
			}
			echo '</ul>';
		}

		// One card per area.
		$awaiting = function_exists( 'wc_get_orders' ) ? (int) wc_get_orders(
			array(
				'limit'      => 1,
				'paginate'   => true,
				'return'     => 'ids',
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_key'   => GSUP_Orders::M_AWAITING, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		)->total : 0;
		$token = GSUP_AliExpress::token();
		$r     = GSUP_Creator::rule();
		$cards = array(
			'aliexpress' => $token ? 'Connected' . ( '' !== $token['account'] ? ' as ' . $token['account'] : '' ) . ', valid until ' . wp_date( 'j M Y', $token['expires_at'] ) . '.' : ( GSUP_AliExpress::has_app() ? 'App saved — click Connect to finish.' : 'Add your App Key and App Secret to start.' ),
			'ordering'   => ( GSUP_Orders::enabled() ? 'Paid orders are placed on AliExpress automatically.' : 'Orders are placed by hand from the order screen.' ) . ' ' . $awaiting . ' order(s) waiting for tracking.',
			'pricing'    => 'Price = cost' . ( $r['shipping'] ? ' + delivery' : '' ) . ' × ' . rtrim( rtrim( number_format( $r['multiplier'], 2 ), '0' ), '.' ) . ( $r['add'] ? ' + ' . wc_format_decimal( $r['add'], 2 ) : '' ) . ( $r['round'] ? ', rounded to .95' : '' ) . '. Low margin below ' . GSUP_Profit::min_margin() . '%.',
			'sync'       => GSUP_Sync::enabled() ? ( is_array( $last ) ? 'Last run ' . human_time_diff( $last['finished'] ) . ' ago — ' . (int) $last['report']['checked'] . ' products checked.' : 'Runs daily at about 3am. Hasn’t run yet.' ) : 'Off — stock and costs aren’t being updated.',
			'countries'  => GSUP_CBR::enabled() ? 'New products are restricted to their warehouse’s countries.' : 'Off — products are shown in every country unless you set CBR by hand.',
			'extension'  => 'Site address and connection key for the Add to Givsen button.',
		);
		echo '<h3>Your setup</h3><div class="gsup-cards">';
		foreach ( $cards as $key => $text ) {
			list( $state, $label ) = self::section_status( $key );
			$sections = self::sections();
			echo '<a class="gsup-card" href="' . esc_url( gsup_settings_url( $key ) ) . '">';
			echo '<span class="gsup-card-head"><strong>' . esc_html( $sections[ $key ][0] ) . '</strong>';
			if ( '' !== $label ) {
				echo '<span class="gsup-pill gsup-pill--' . esc_attr( $state ) . '">' . esc_html( $label ) . '</span>';
			}
			echo '</span><span class="gsup-card-text">' . esc_html( $text ) . '</span><span class="gsup-card-link">' . ( 'off' === $state || 'warn' === $state ? 'Set up →' : 'Settings →' ) . '</span></a>';
		}
		echo '</div>';
	}

	private static function render_extension() {
		$key  = (string) get_option( 'gsup_secret' );
		$site = home_url( '/' );
		echo '<p>Paste these two values into the Givsen Supplier Chrome extension’s settings (click the <strong>G</strong> icon in Chrome’s toolbar), then click <strong>Save &amp; test</strong>.</p>';
		echo '<table class="form-table gsup-settings"><tbody>';
		echo '<tr><th scope="row">Site address</th><td><code>' . esc_html( $site ) . '</code> ' . gsup_copy_button( $site ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th scope="row">Connection key</th><td><code class="gsup-key">' . esc_html( $key ) . '</code> ' . gsup_copy_button( $key ) . '<p class="description">Anyone with this key can add items to your import list (nothing else). Keep it private.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</tbody></table>';
		echo '<h3>Replace the key</h3><p class="gsup-meta">Only if you think someone else has it. The extension stops working until you paste the new key into it.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="gsup_regen_key">';
		wp_nonce_field( 'gsup_regen_key' );
		echo '<p><button type="submit" class="button" data-gsup-confirm="Make a new connection key? The Chrome extension will stop working until you paste the new key into it.">Make a new connection key</button></p>';
		echo '</form>';
	}

	private static function create_url( $ae_product_id, $ship = null, $row_id = 0 ) {
		$args = array(
			'tab' => 'create',
			'ae'  => $ae_product_id,
		);
		if ( null !== $ship ) {
			$args['ship'] = '' === $ship ? 'none' : $ship;
		}
		if ( $row_id ) {
			$args['row'] = (int) $row_id;
		}
		return gsup_admin_url( $args );
	}

	private static function render_create() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$ae   = isset( $_GET['ae'] ) ? gsup_parse_product_id( sanitize_text_field( wp_unslash( $_GET['ae'] ) ) ) : '';
		$want = isset( $_GET['ship'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['ship'] ) ) ) : '';
		// phpcs:enable
		if ( '' === $ae ) {
			echo '<p>No AliExpress product chosen. Use “Add to store” from the import list.</p>';
			return;
		}
		if ( ! GSUP_AliExpress::is_connected() ) {
			echo '<p>Connect AliExpress in Settings first.</p>';
			return;
		}
		$want    = 'NONE' === $want ? '' : $want;
		$ship_to = 'US' === $want ? 'US' : 'AU';
		$product = GSUP_AliExpress::get_product( $ae, $ship_to );
		if ( is_wp_error( $product ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $product->get_error_message() ) . '</p></div>';
			return;
		}
		$groups = GSUP_Creator::by_warehouse( $product );
		if ( ! $groups ) {
			echo '<div class="notice notice-warning inline"><p>This AliExpress listing has no options for sale right now.</p></div>';
			return;
		}
		if ( ! isset( $groups[ $want ] ) ) {
			$want = (string) array_key_first( $groups );
		}
		$pairs = array( array( $product['product_id'], '' ) );
		foreach ( $product['skus'] as $sku ) {
			$pairs[] = array( $product['product_id'], $sku['sku_id'] );
		}
		gsup_prime_links( $pairs );
		$existing = gsup_find_linked( $product['product_id'] );

		echo '<div class="gsup-test-result">';
		if ( '' !== $product['image'] ) {
			echo '<img src="' . esc_url( $product['image'] ) . '" alt="" referrerpolicy="no-referrer">';
		}
		echo '<div><a href="' . esc_url( gsup_ae_url( $product['product_id'] ) ) . '" target="_blank" rel="noopener noreferrer"><strong>' . esc_html( $product['title'] ) . '</strong> ↗</a>';
		echo '<div class="gsup-meta">Product ' . esc_html( $product['product_id'] ) . ( $product['on_sale'] ? '' : ' · <strong>not for sale on AliExpress right now</strong>' ) . '</div></div></div>';

		if ( $existing ) {
			$links = array();
			foreach ( $existing as $wc_id ) {
				$links[] = '<a href="' . esc_url( gsup_product_edit_url( $wc_id ) ) . '">' . esc_html( gsup_product_label( $wc_id ) ) . '</a>';
			}
			echo '<div class="notice notice-info inline"><p>Already in your store: ' . implode( ', ', $links ) . '. You can still add another product — for example the other warehouse.</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<h2>1. Which warehouse?</h2><p class="gsup-meta">One product per warehouse, so Australians see Australian stock and US visitors see US stock.</p><p class="gsup-warehouses">';
		foreach ( $groups as $code => $skus ) {
			$label = GSUP_Creator::warehouse_label( $code ) . ' (' . count( $skus ) . ')';
			if ( (string) $code === $want ) {
				echo '<span class="button button-primary" aria-current="true">' . esc_html( $label ) . '</span> ';
			} else {
				echo '<a class="button" href="' . esc_url( self::create_url( $product['product_id'], (string) $code, isset( $_GET['row'] ) ? absint( $_GET['row'] ) : 0 ) ) . '">' . esc_html( $label ) . '</a> '; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		echo '</p>';
		if ( ! in_array( $want, array( 'AU', 'US' ), true ) ) {
			echo '<p class="gsup-meta">Stock shown is for delivery to Australia.</p>';
		} elseif ( 'US' === $want ) {
			echo '<p class="gsup-meta">Stock and cost shown are for delivery to the United States.</p>';
		}

		$rule    = GSUP_Creator::rule();
		$freight = GSUP_Creator::quote( $product['product_id'], $groups[ $want ][0]['sku_id'], $want );
		$fee     = is_wp_error( $freight ) ? 0 : (float) $freight['fee'];
		if ( is_wp_error( $freight ) ) {
			echo '<div class="notice notice-warning inline"><p>No delivery quote from AliExpress, so prices below don’t include delivery: ' . esc_html( $freight->get_error_message() ) . '</p></div>';
		} else {
			echo '<p class="gsup-meta">Delivery to ' . esc_html( gsup_ship_from_label( gsup_quote_country( $want ) ) ) . ': <strong>' . esc_html( 0.0 === $fee ? 'free' : gsup_money( $fee ) ) . '</strong> with ' . esc_html( $freight['name'] ) . ( $freight['max_days'] ? ' (' . (int) $freight['min_days'] . '–' . (int) $freight['max_days'] . ' days)' : '' ) . '. Saved on the product and ' . ( $rule['shipping'] ? 'included in your price.' : 'not included in your price (Pricing setting).' ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gsup-create-form">';
		echo '<input type="hidden" name="action" value="gsup_create">';
		echo '<input type="hidden" name="ae" value="' . esc_attr( $product['product_id'] ) . '">';
		echo '<input type="hidden" name="ship" value="' . esc_attr( '' === $want ? 'none' : $want ) . '">';
		wp_nonce_field( 'gsup_create_' . $product['product_id'] );

		echo '<h2>2. Which options?</h2>';
		echo '<table class="widefat striped gsup-sku-table"><thead><tr><td class="check-column"><input type="checkbox" class="gsup-check-all" checked aria-label="Select all"></td><th>Option</th><th>Your cost</th><th>Delivery</th><th>Your price</th><th>Margin</th><th>Stock</th></tr></thead><tbody>';
		foreach ( $groups[ $want ] as $sku ) {
			$in_store = gsup_find_option_links( $product['product_id'], $sku['sku_id'] );
			$text     = GSUP_Creator::option_text( $sku );
			echo '<tr><th scope="row" class="check-column"><input type="checkbox" name="sku_ids[]" value="' . esc_attr( $sku['sku_id'] ) . '"' . checked( ! $in_store && 0 !== $sku['stock'], true, false ) . '></th>';
			echo '<td>' . esc_html( '' !== $text ? $text : '(single option)' );
			if ( $in_store ) {
				echo '<div class="gsup-meta">Already in your store</div>';
			}
			$price = (float) GSUP_Creator::price_for( $sku['price'], $fee );
			$fig   = GSUP_Profit::figures( $price, (float) $sku['price'] + $fee );
			echo '</td><td>' . esc_html( trim( $sku['price'] . ' ' . $sku['currency'] ) ) . '</td>';
			echo '<td>' . esc_html( is_wp_error( $freight ) ? '—' : ( 0.0 === $fee ? 'Free' : wc_format_decimal( $fee, 2 ) ) ) . '</td>';
			echo '<td>' . wp_kses_post( wc_price( $price ) ) . '</td>';
			echo '<td>' . esc_html( $fig ? GSUP_Profit::pct( $fig['margin'] ) : '—' ) . '</td>';
			echo '<td>' . esc_html( null === $sku['stock'] ? 'In stock' : ( 0 === $sku['stock'] ? 'Out of stock' : (string) $sku['stock'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="gsup-meta">Prices use your pricing rule: cost × ' . esc_html( rtrim( rtrim( number_format( $rule['multiplier'], 2 ), '0' ), '.' ) ) . ( $rule['add'] ? ' + ' . esc_html( wc_format_decimal( $rule['add'], 2 ) ) : '' ) . ( $rule['round'] ? ', rounded to .95' : '' ) . ( $rule['shipping'] ? ' (cost includes delivery)' : '' ) . '. <a href="' . esc_url( gsup_settings_url( 'pricing' ) ) . '">Change it</a>.</p>';

		// Categories: from the import row (chosen in the extension), else the last ones used.
		$row_id   = isset( $_GET['row'] ) ? absint( $_GET['row'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$row      = $row_id ? GSUP_Import::get( $row_id ) : null;
		$selected = $row && '' !== $row['category_ids'] ? gsup_clean_category_ids( $row['category_ids'] ) : gsup_clean_category_ids( (array) get_user_meta( get_current_user_id(), 'gsup_last_categories', true ) );
		echo '<h2>3. Category</h2>';
		$tree = gsup_category_tree();
		if ( ! $tree ) {
			echo '<p class="gsup-meta">No product categories yet.</p>';
		} else {
			echo '<div class="gsup-cat-box">';
			foreach ( $tree as $c ) {
				echo '<label style="padding-left:' . (int) ( $c['depth'] * 18 ) . 'px"><input type="checkbox" name="category_ids[]" value="' . (int) $c['id'] . '"' . checked( in_array( $c['id'], $selected, true ), true, false ) . '> ' . esc_html( $c['name'] ) . '</label>';
			}
			echo '</div>';
		}
		echo '<p class="gsup-meta">' . ( $row && '' !== $row['category_ids'] ? 'Ticked from what you chose in the extension. ' : ( $selected ? 'Ticked with the categories you used last time. ' : '' ) ) . 'Leave all unticked to use WooCommerce’s default category. <a href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=product_cat&post_type=product' ) ) . '" target="_blank" rel="noopener">Add a new category ↗</a> (then reload this page).</p>';

		$tidy = GSUP_Tidy::title( $product['title'] );
		echo '<h2>4. Title</h2><p><input type="text" name="title" class="large-text" value="' . esc_attr( $tidy ) . '"></p>';
		if ( $tidy !== trim( $product['title'] ) ) {
			echo '<p class="gsup-meta">Tidied from AliExpress’s title: “' . esc_html( $product['title'] ) . '”. <a href="#" class="gsup-use-original" data-title="' . esc_attr( $product['title'] ) . '">Use the original</a></p>';
		}

		// Option names and values, tidied and editable. Links are by SKU ID, so these can be anything.
		$opt_names = array();
		foreach ( $groups[ $want ] as $sku ) {
			foreach ( $sku['props'] as $prop ) {
				if ( ! $prop['is_ship'] ) {
					$opt_names[ $prop['name'] ][ $prop['value'] ] = true;
				}
			}
		}
		if ( $opt_names ) {
			echo '<h2>5. Option names</h2><p class="gsup-meta">What customers see. Tidied from AliExpress’s wording — change anything you like. The link to AliExpress is by ID, so renaming never breaks ordering.</p>';
			echo '<table class="widefat striped gsup-rename"><tbody>';
			$ni = 0;
			foreach ( $opt_names as $oname => $ovalues ) {
				echo '<tr><th scope="row"><input type="hidden" name="rn_name_orig[' . $ni . ']" value="' . esc_attr( base64_encode( $oname ) ) . '"><input type="text" name="rn_name[' . $ni . ']" value="' . esc_attr( GSUP_Tidy::option_name( $oname ) ) . '" aria-label="Option name"></th><td>';
				$vi = 0;
				foreach ( array_keys( $ovalues ) as $ovalue ) {
					$tv = GSUP_Tidy::option_value( $ovalue );
					echo '<label class="gsup-rename-value"><input type="hidden" name="rn_value_orig[' . $ni . '][' . $vi . ']" value="' . esc_attr( base64_encode( $ovalue ) ) . '"><input type="text" name="rn_value[' . $ni . '][' . $vi . ']" value="' . esc_attr( $tv ) . '" title="' . esc_attr( 'AliExpress: ' . $ovalue ) . '"></label> ';
					++$vi;
				}
				echo '</td></tr>';
				++$ni;
			}
			echo '</tbody></table>';
		}

		$prefs = self::import_prefs();
		echo '<h2>' . ( $opt_names ? '6' : '5' ) . '. What to bring into your store</h2>';
		echo '<table class="form-table gsup-settings gsup-bring"><tbody>';
		echo '<tr><th scope="row">Description</th><td><fieldset>';
		foreach (
			array(
				'text'  => array( 'The wording only, to rewrite', 'Paragraphs and lists, without layout or seller notes like “leave 5-star feedback”. Its photos can go in the gallery (below).' ),
				'clean' => array( 'Cleaned, with its images', 'Seller notes and AliExpress styling removed; images copied to your site.' ),
				'empty' => array( 'Nothing — I’ll write my own', '' ),
			) as $value => $text
		) {
			echo '<label style="display:block;margin-bottom:4px"><input type="radio" name="description" value="' . esc_attr( $value ) . '"' . checked( $prefs['description'], $value, false ) . '> ' . esc_html( $text[0] ) . ( '' !== $text[1] ? ' <span class="gsup-meta">— ' . esc_html( $text[1] ) . '</span>' : '' ) . '</label>';
		}
		echo '</fieldset></td></tr>';
		echo '<tr><th scope="row">Photos</th><td><label>Copy the first <input type="number" name="photos" min="0" max="10" class="small-text" value="' . esc_attr( $prefs['photos'] ) . '"> product photos</label> <span class="gsup-meta">(0 = none; ' . count( $product['images'] ) . ' on AliExpress)</span><br>';
		$desc_count = count( GSUP_Creator::description_images( $product['description'] ) );
		echo '<label><input type="checkbox" name="option_photos" value="1"' . checked( $prefs['option_photos'], true, false ) . '> Each option’s photo on its variation</label><br>';
		echo '<label><input type="checkbox" name="desc_photos" value="1"' . checked( $prefs['desc_photos'], true, false ) . '> Also add the description’s photos to the gallery</label> <span class="gsup-meta">(' . (int) $desc_count . ' found — detail shots, size charts; up to ' . (int) GSUP_Creator::MAX_DESC_PHOTOS . '; not needed with “Cleaned, with its images”)</span></td></tr>';
		echo '<tr><th scope="row">Details</th><td><label><input type="checkbox" name="specs" value="1"' . checked( $prefs['specs'], true, false ) . '> Item specifics (material, size…) in the <strong>Additional information</strong> tab</label>';
		$specs = GSUP_Tidy::specs( isset( $product['specs'] ) ? $product['specs'] : array() );
		if ( $specs ) {
			$preview = array();
			foreach ( array_slice( $specs, 0, 4, true ) as $sn => $sv ) {
				$preview[] = $sn . ': ' . $sv;
			}
			echo ' <span class="gsup-meta">(' . esc_html( implode( ' · ', $preview ) . ( count( $specs ) > 4 ? ' …' : '' ) ) . ')</span>';
		}
		echo '<br><label><input type="checkbox" name="short" value="1"' . checked( $prefs['short'], true, false ) . '> Short description from the first few specifics</label></td></tr>';
		echo '</tbody></table><p class="gsup-meta">Your choices are remembered for next time. Photos are copied into your Media Library, so nothing on your product pages loads from AliExpress.</p>';
		echo '<p class="gsup-meta">The product is created as a <strong>draft</strong>, so you can rewrite the wording and check prices before publishing. Copying photos can take up to a minute.</p>';
		echo '<p><button type="submit" class="button button-primary button-hero gsup-create-btn">Create draft product</button></p>';
		echo '</form>';
	}

	private static function render_pricing() {
		$r = GSUP_Creator::rule();
		echo '<h3 class="gsup-first" id="gsup-pricing">Pricing for new products</h3>';
		echo '<p>Used when you add a product from AliExpress (and by the daily sync if price updates are on). Your price = AliExpress cost × multiplier + extra amount.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gsup-save-form">';
		echo '<input type="hidden" name="action" value="gsup_save_pricing">';
		wp_nonce_field( 'gsup_save_pricing' );
		echo '<table class="form-table gsup-settings"><tbody>';
		echo '<tr><th scope="row"><label for="gsup_mult">Multiplier</label></th><td><input type="number" step="0.01" min="0.01" id="gsup_mult" name="multiplier" value="' . esc_attr( $r['multiplier'] ) . '" class="small-text"> <span class="gsup-meta">e.g. 2 = double the cost</span></td></tr>';
		echo '<tr><th scope="row"><label for="gsup_add">Extra amount</label></th><td><input type="number" step="0.01" id="gsup_add" name="add" value="' . esc_attr( $r['add'] ) . '" class="small-text"> ' . esc_html( get_woocommerce_currency() ) . '</td></tr>';
		echo '<tr><th scope="row">Rounding</th><td><label><input type="checkbox" name="round" value="yes"' . checked( $r['round'], true, false ) . '> Round up to .95 (e.g. 23.40 → 23.95)</label></td></tr>';
		echo '<tr><th scope="row">Delivery</th><td><label><input type="checkbox" name="shipping" value="yes"' . checked( $r['shipping'], true, false ) . '> Add AliExpress’s delivery fee to the cost before applying the rule</label><p class="description">The fee is quoted by AliExpress for your shipping method preference and kept up to date by the daily sync.</p></td></tr>';
		echo '<tr><th scope="row">Example</th><td>AliExpress cost 10.00' . ( $r['shipping'] ? ' + delivery 3.00' : '' ) . ' → your price <strong>' . wp_kses_post( wc_price( (float) GSUP_Creator::price_for( 10, 3 ) ) ) . '</strong></td></tr>';
		echo '</tbody></table><p><button type="submit" class="button button-primary">Save pricing</button></p></form>';
	}

	private static function render_sync() {
		$last    = get_option( GSUP_Sync::OPT_LAST );
		$run     = GSUP_Sync::running();
		$email   = get_option( 'gsup_sync_email', get_option( 'admin_email' ) );

		echo '<p>Every day at about 3am it updates stock, your cost and the delivery fee for every linked product, flags products whose margin a cost rise has pushed below your minimum, sets options AliExpress no longer sells to out of stock, and switches products AliExpress has removed to draft. It never changes titles, descriptions, photos or categories.</p>';

		if ( $run ) {
			echo '<div class="notice notice-info inline"><p><strong>Sync running:</strong> ' . (int) $run['pos'] . ' of ' . count( $run['ids'] ) . ' products checked. <a href="' . esc_url( gsup_settings_url( 'sync' ) ) . '">Refresh</a> to see progress.</p></div>';
		} elseif ( is_array( $last ) ) {
			$r = $last['report'];
			echo '<div class="gsup-sync-last"><p><strong>Last sync:</strong> ' . esc_html( wp_date( 'j M Y, g:ia', $last['finished'] ) ) . ( $last['manual'] ? ' (run by hand)' : '' ) . ' — checked ' . (int) $r['checked'] . ' of ' . (int) $last['total'] . ' products.</p><ul>';
			echo '<li>Stock updated: ' . (int) $r['stock_changes'] . ' · Cost changes: ' . (int) $r['cost_changes'] . ' · Delivery fee changes: ' . (int) ( $r['ship_changes'] ?? 0 ) . ( GSUP_Sync::update_prices() ? ' · Prices updated: ' . (int) $r['price_changes'] : '' ) . '</li>';
			foreach (
				array(
					'removed'      => 'Removed on AliExpress (switched to draft)',
					'options_gone' => 'Options no longer on AliExpress (out of stock)',
					'back'         => 'Back on sale on AliExpress (still in draft)',
					'missing'      => 'Not found today (will be drafted if still missing tomorrow)',
					'low_margin'   => 'Cost went up — margin now below ' . GSUP_Profit::min_margin() . '%',
					'switched'     => 'Switched to their backup supplier',
					'backup_failed' => 'Backup supplier couldn’t be used',
				) as $key => $label
			) {
				if ( empty( $r[ $key ] ) ) {
					continue;
				}
				$links = array();
				foreach ( $r[ $key ] as $id ) {
					$links[] = '<a href="' . esc_url( gsup_product_edit_url( $id ) ) . '">' . esc_html( gsup_product_label( $id ) ) . '</a>';
				}
				echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . implode( ', ', $links ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			foreach ( array_unique( $r['errors'] ) as $err ) {
				echo '<li class="gsup-warn"><strong>Stopped early:</strong> ' . esc_html( $err ) . ' Nothing was switched to draft because of this.</li>';
			}
			echo '</ul></div>';
		} else {
			echo '<p class="gsup-meta">Hasn’t run yet.</p>';
		}

		if ( ! $run && GSUP_AliExpress::is_connected() ) {
			echo '<p><a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gsup_sync_now' ), 'gsup_sync_now' ) ) . '">Sync now</a></p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gsup-save-form">';
		echo '<input type="hidden" name="action" value="gsup_save_sync">';
		wp_nonce_field( 'gsup_save_sync' );
		echo '<table class="form-table gsup-settings"><tbody>';
		echo '<tr><th scope="row">Daily sync</th><td><label><input type="checkbox" name="enabled" value="yes"' . checked( GSUP_Sync::enabled(), true, false ) . '> Run every day</label></td></tr>';
		$mode = GSUP_Sync::price_mode();
		echo '<tr><th scope="row">Prices</th><td><fieldset>';
		foreach (
			array(
				'no'   => array( 'Never change my prices', 'Only your cost is recorded.' ),
				'low'  => array( 'Raise a price only when its margin falls below ' . GSUP_Profit::min_margin() . '%', 'Up to your pricing rule; never lowers a price. Recommended.' ),
				'yes'  => array( 'Always follow my pricing rule', 'Regular prices go up and down with AliExpress costs.' ),
			) as $value => $text
		) {
			echo '<label style="display:block;margin-bottom:4px"><input type="radio" name="prices" value="' . esc_attr( $value ) . '"' . checked( $mode, $value, false ) . '> ' . esc_html( $text[0] ) . ' <span class="gsup-meta">— ' . esc_html( $text[1] ) . '</span></label>';
		}
		echo '<p class="description">Sale prices are never touched.</p></fieldset></td></tr>';
		echo '<tr><th scope="row">Stock buffer</th><td>Show as sold out when AliExpress has fewer than <input type="number" name="stock_min" min="0" max="1000" class="small-text" value="' . esc_attr( (int) get_option( 'gsup_stock_min', 0 ) ) . '"> left, and show at most <input type="number" name="stock_cap" min="0" max="100000" class="small-text" value="' . esc_attr( (int) get_option( 'gsup_stock_cap', 0 ) ) . '"> in stock.<p class="description">0 = off. Stops you selling the last few units other shops are also selling, and hides how much stock the supplier has. Applies from the next sync and to new products.</p></td></tr>';
		echo '<tr><th scope="row">Backup suppliers</th><td><label><input type="checkbox" name="backup_auto" value="yes"' . checked( GSUP_Sync::backup_auto(), true, false ) . '> Switch to a product’s backup supplier automatically</label> when its listing is removed, an option disappears, or its cost rises more than <input type="number" name="backup_rise" min="1" max="200" step="1" class="small-text" value="' . esc_attr( GSUP_Sync::backup_rise() ) . '">% and the backup is cheaper.<p class="description">Save a backup from a product’s <strong>Change supplier…</strong> button. Your prices are never changed by a switch; you’re emailed each time.</p></td></tr>';
		echo '<tr><th scope="row"><label for="gsup_sync_email">Email summaries to</label></th><td><input type="email" id="gsup_sync_email" name="email" class="regular-text" value="' . esc_attr( $email ) . '"><p class="description">Only sent when something needs your attention.</p></td></tr>';
		echo '</tbody></table><p><button type="submit" class="button button-primary">Save sync settings</button></p></form>';
	}

	private static function render_auto() {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-info inline"><p>Only an administrator can change these settings.</p></div>';
		}

		echo '<p>When an order reaches <strong>Processing</strong> — paid, or a Givsen gift once the recipient claims it — each item is placed on AliExpress in the background with the customer’s address, and the AliExpress order number is saved on the order. Tracking numbers are fetched every few hours for every order with an AliExpress order number (including ones you place by hand).</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gsup-save-form">';
		echo '<input type="hidden" name="action" value="gsup_save_auto">';
		wp_nonce_field( 'gsup_save_auto' );
		$pref = get_option( 'gsup_ship_pref', 'cheapest_tracked' );
		echo '<table class="form-table gsup-settings"><tbody>';
		echo '<tr><th scope="row">Place orders</th><td><label><input type="checkbox" name="auto" value="yes"' . checked( GSUP_Orders::enabled(), true, false ) . '> Place Processing orders on AliExpress automatically</label>';
		if ( ! GSUP_AliExpress::is_connected() ) {
			echo '<p class="description gsup-warn--soft"><a href="' . esc_url( gsup_settings_url( 'aliexpress' ) ) . '">Connect AliExpress</a> first.</p>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row">Payment</th><td><label><input type="checkbox" name="pay" value="yes"' . checked( 'no' !== get_option( 'gsup_auto_pay', 'yes' ), true, false ) . '> Pay straight away with the payment method saved on your AliExpress account</label><p class="description">Off: orders wait on AliExpress for you to pay them (e.g. several at once).</p></td></tr>';
		echo '<tr><th scope="row"><label for="gsup_ship_pref">Shipping method</label></th><td><select id="gsup_ship_pref" name="ship_pref">';
		foreach (
			array(
				'cheapest_tracked' => 'Cheapest with tracking',
				'cheapest'         => 'Cheapest',
				'fastest'          => 'Fastest',
			) as $value => $label
		) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $pref, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">Also used for the delivery fee in your costs and prices.</p></td></tr>';
		echo '<tr><th scope="row"><label for="gsup_fallback_phone">Phone for the courier</label></th><td><input type="tel" id="gsup_fallback_phone" name="fallback_phone" class="regular-text" value="' . esc_attr( get_option( 'gsup_fallback_phone', '' ) ) . '" placeholder="e.g. +61 2 1234 5678"><p class="description">AliExpress needs a phone number. Used only when an order has none — e.g. a gift recipient who left theirs blank' . ( GSUP_Givsen::active() ? ' (for Givsen Business gifting, the business’s phone is tried first)' : '' ) . '.</p></td></tr>';
		echo '<tr><th scope="row">Safety</th><td><label><input type="checkbox" name="guard" value="yes"' . checked( GSUP_Orders::loss_guard(), true, false ) . '> Don’t place an item if AliExpress would charge more than the customer paid for it</label></td></tr>';
		$when = GSUP_Parcels::complete_when();
		echo '<tr><th scope="row"><label for="gsup_complete_when">Mark orders Completed</label></th><td><select id="gsup_complete_when" name="complete_when">';
		foreach (
			array(
				'tracking'  => 'When every item has tracking',
				'delivered' => 'When every item has been delivered',
				'no'        => 'Never — I’ll do it myself',
			) as $value => $label
		) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $when, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">WooCommerce sends the customer its “order complete” email, which includes the tracking links.' . ( function_exists( 'ast_insert_tracking_number' ) ? ' Tracking is also added to Advanced Shipment Tracking.' : '' ) . '</p></td></tr>';
		echo '<tr><th scope="row">Delivered</th><td><label><input type="checkbox" name="delivered_email" value="yes"' . checked( GSUP_Parcels::email_customer(), true, false ) . '> Email the customer when AliExpress’s tracking shows the parcel delivered</label></td></tr>';
		echo '<tr><th scope="row">Late parcel alerts</th><td>Alert me when an item has no tracking <input type="number" name="notrack_days" min="1" max="60" class="small-text" value="' . esc_attr( GSUP_Parcels::no_tracking_days() ) . '"> days after ordering, or isn’t delivered <input type="number" name="grace_days" min="0" max="60" class="small-text" value="' . esc_attr( GSUP_Parcels::grace_days() ) . '"> days after AliExpress’s latest delivery estimate.<p class="description">You get an order note, one email per check, and a link to open a dispute on AliExpress while buyer protection still applies.</p></td></tr>';
		echo '</tbody></table>';
		echo '<p class="gsup-meta">Anything that can’t be placed — not linked to an exact option, gone from AliExpress, out of stock, no delivery to that country, or would lose money — is left for you with the reason on the order and an email to ' . esc_html( get_option( 'gsup_sync_email', get_option( 'admin_email' ) ) ) . '.</p>';
		echo '<p><button type="submit" class="button button-primary">Save automatic ordering</button></p></form>';
	}

	private static function render_eta() {
		$s = GSUP_Eta::settings();
		echo '<h3 id="gsup-eta">Delivery estimate on product pages</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gsup-save-form"><input type="hidden" name="action" value="gsup_save_eta">';
		wp_nonce_field( 'gsup_save_eta' );
		echo '<table class="form-table gsup-settings"><tbody>';
		echo '<tr><th scope="row">Show it</th><td><label><input type="checkbox" name="show" value="yes"' . checked( GSUP_Eta::enabled(), true, false ) . '> Show an estimated delivery under the price</label><p class="description">From AliExpress’s delivery estimate for your shipping method (updated weekly), plus your processing time. Products without an estimate show nothing.</p></td></tr>';
		echo '<tr><th scope="row"><label for="gsup_eta_processing">Processing time</label></th><td><input type="number" id="gsup_eta_processing" name="processing" min="0" max="30" class="small-text" value="' . esc_attr( $s['processing'] ) . '"> days added before it ships</td></tr>';
		echo '<tr><th scope="row">Wording</th><td><label><input type="radio" name="format" value="dates"' . checked( $s['format'], 'dates', false ) . '> Dates — “Estimated delivery: Tue 1 Oct – Fri 4 Oct”</label><br><label><input type="radio" name="format" value="days"' . checked( $s['format'], 'days', false ) . '> Days — “Delivered in 3–6 business days”</label><br>';
		echo '<label><input type="checkbox" name="business" value="yes"' . checked( $s['business'], true, false ) . '> Count business days only (skip weekends)</label></td></tr>';
		echo '<tr><th scope="row">Example</th><td>' . esc_html( GSUP_Eta::text( array( 2, 5 ) ) ) . ' <span class="gsup-meta">(for a 2–5 day estimate)</span></td></tr>';
		echo '</tbody></table><p><button type="submit" class="button button-primary">Save delivery estimate</button></p></form>';
	}

	private static function render_profit() {
		echo '<h3 id="gsup-profit">Profit and margin</h3>';
		echo '<p>Margin is shown on the products list and on every order: your price minus the AliExpress cost with delivery and payment fees, as a share of your price (before tax).</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gsup-save-form">';
		echo '<input type="hidden" name="action" value="gsup_save_profit">';
		wp_nonce_field( 'gsup_save_profit' );
		echo '<table class="form-table gsup-settings"><tbody>';
		echo '<tr><th scope="row"><label for="gsup_min_margin">Flag margins below</label></th><td><input type="number" step="1" min="0" max="100" id="gsup_min_margin" name="min_margin" value="' . esc_attr( GSUP_Profit::min_margin() ) . '" class="small-text"> % <p class="description">Products under this show “Low margin” (and can be filtered). The daily sync emails you when a cost rise pushes a product under it.</p></td></tr>';
		echo '<tr><th scope="row">Payment fees</th><td><input type="number" step="0.01" min="0" name="fee_percent" value="' . esc_attr( get_option( 'gsup_fee_percent', 0 ) ) . '" class="small-text"> % + <input type="number" step="0.01" min="0" name="fee_fixed" value="' . esc_attr( get_option( 'gsup_fee_fixed', 0 ) ) . '" class="small-text"> ' . esc_html( get_woocommerce_currency() ) . ' per order <p class="description">e.g. Stripe’s card fee. Leave at 0 to ignore fees.</p></td></tr>';
		echo '</tbody></table><p><button type="submit" class="button button-primary">Save profit settings</button></p></form>';
	}

	private static function render_cbr() {
		$k   = GSUP_CBR::keys();
		$map = GSUP_CBR::map();

		echo '<p>Sets Country Based Restrictions on each product from where it ships, so new products are shown to the right country automatically. Only products whose options all ship from one warehouse are changed; restrictions you’ve set by hand are kept unless you choose to overwrite them.</p>';

		// Inspector.
		echo '<h3 class="gsup-first">1. Check how CBR saves its setting</h3>';
		echo '<p class="gsup-meta">Set CBR by hand on one product (e.g. “Show only in Australia”), save it, then look at it here. The keys and values it shows go into step 2.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gsup-link-form" style="max-width:600px">';
		echo '<input type="hidden" name="action" value="gsup_cbr_inspect">';
		wp_nonce_field( 'gsup_cbr_inspect' );
		echo '<select class="wc-product-search" name="product_id" style="width:100%" data-placeholder="Search your products…" data-action="woocommerce_json_search_products" data-allow_clear="true"></select>';
		echo '<button type="submit" class="button">Look at this product</button></form>';
		$key     = 'gsup_cbr_inspect_' . get_current_user_id();
		$inspect = get_transient( $key );
		if ( is_array( $inspect ) ) {
			delete_transient( $key );
			echo '<div class="gsup-sync-last"><p><strong>' . esc_html( gsup_product_label( $inspect['id'] ) ) . '</strong> — country-related settings found:</p>';
			if ( ! $inspect['meta'] ) {
				echo '<p class="gsup-warn--soft">None. Set a restriction on this product in CBR, click Update there, then look again.</p>';
			} else {
				echo '<table class="widefat striped"><thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>';
				foreach ( $inspect['meta'] as $mk => $mv ) {
					echo '<tr><td><code>' . esc_html( $mk ) . '</code></td><td><code>' . esc_html( is_scalar( $mv ) ? (string) $mv : wp_json_encode( $mv ) ) . '</code>' . ( is_array( $mv ) ? ' <span class="gsup-meta">(a list)</span>' : '' ) . '</td></tr>';
				}
				echo '</tbody></table>';
				if ( isset( $inspect['meta'][ $k['type_key'] ] ) && isset( $inspect['meta'][ $k['countries_key'] ] ) ) {
					echo '<p class="gsup-api-note gsup-api-note--ok">These match the keys in step 2.</p>';
				}
			}
			echo '</div>';
		}

		echo '<h3>2. Settings</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gsup-save-form">';
		echo '<input type="hidden" name="action" value="gsup_save_cbr">';
		wp_nonce_field( 'gsup_save_cbr' );
		echo '<table class="form-table gsup-settings"><tbody>';
		echo '<tr><th scope="row">Set restrictions</th><td><label><input type="checkbox" name="enabled" value="yes"' . checked( GSUP_CBR::enabled(), true, false ) . '> Set CBR on new products from where they ship</label></td></tr>';
		echo '<tr><th scope="row">Show products from</th><td><table class="gsup-cbr-map"><tbody>';
		$codes = array_unique( array_merge( array( 'AU', 'US', 'CN', 'GB', 'NZ', 'CA' ), array_keys( $map ) ) );
		foreach ( $codes as $code ) {
			$val = isset( $map[ $code ] ) ? implode( ', ', (array) $map[ $code ] ) : '';
			echo '<tr><td>' . esc_html( GSUP_Creator::warehouse_label( $code ) ) . '</td><td>→ only in <input type="text" name="map[' . esc_attr( $code ) . ']" value="' . esc_attr( $val ) . '" class="regular-text" placeholder="e.g. AU, NZ"></td></tr>';
		}
		echo '</tbody></table><p class="description">Country codes, separated by commas. Leave a warehouse blank to leave its products alone.</p></td></tr>';
		echo '</tbody></table>';
		echo '<details class="gsup-advanced"' . ( GSUP_CBR::DEFAULT_TYPE_KEY !== $k['type_key'] || GSUP_CBR::DEFAULT_COUNTRIES_KEY !== $k['countries_key'] ? ' open' : '' ) . '><summary>Advanced: where CBR stores its setting</summary>';
		echo '<p class="gsup-meta">Already set to CBR’s usual keys. Only change these if step 1 shows different ones.</p>';
		echo '<table class="form-table gsup-settings"><tbody>';
		echo '<tr><th scope="row"><label for="gsup_cbr_type_key">Restriction type key</label></th><td><input type="text" id="gsup_cbr_type_key" name="type_key" value="' . esc_attr( $k['type_key'] ) . '" class="regular-text code"> = <input type="text" name="type_value" value="' . esc_attr( $k['type_value'] ) . '" class="small-text code" style="width:9em"><p class="description">The key and value CBR saves for “show only in these countries”.</p></td></tr>';
		echo '<tr><th scope="row"><label for="gsup_cbr_countries_key">Countries key</label></th><td><input type="text" id="gsup_cbr_countries_key" name="countries_key" value="' . esc_attr( $k['countries_key'] ) . '" class="regular-text code"> saved as <select name="format"><option value="array"' . selected( $k['format'], 'array', false ) . '>a list</option><option value="csv"' . selected( $k['format'], 'csv', false ) . '>text, comma-separated</option></select></td></tr>';
		echo '</tbody></table></details><p><button type="submit" class="button button-primary">Save country restrictions</button></p></form>';

		echo '<h3>3. Existing products</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="gsup_cbr_apply">';
		wp_nonce_field( 'gsup_cbr_apply' );
		echo '<p><label><input type="checkbox" name="overwrite" value="yes"> Also replace restrictions already set on a product</label></p>';
		echo '<p><button type="submit" class="button"' . ( GSUP_CBR::enabled() ? '' : ' disabled' ) . ' data-gsup-confirm="Set country restrictions on your linked products from where they ship?">Apply to linked products</button>' . ( GSUP_CBR::enabled() ? '' : ' <span class="gsup-meta">Turn on “Set restrictions” first.</span>' ) . '</p>';
		echo '</form>';
	}

	private static function render_ae_settings() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$result = isset( $_GET['gsup_ae'] ) ? sanitize_key( wp_unslash( $_GET['gsup_ae'] ) ) : '';
		// phpcs:enable
		$messages = array(
			'connected' => array( 'success', 'AliExpress is connected.' ),
			'denied'    => array( 'warning', 'The connection wasn’t approved on AliExpress. Click Connect AliExpress to try again.' ),
			'bad_state' => array( 'error', 'That connection link had expired or was already used. Click Connect AliExpress again.' ),
		);
		if ( isset( $messages[ $result ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $messages[ $result ][0] ) . '"><p>' . esc_html( $messages[ $result ][1] ) . '</p></div>';
		} elseif ( 'error' === $result ) {
			$err = get_transient( 'gsup_ae_connect_error' );
			delete_transient( 'gsup_ae_connect_error' );
			echo '<div class="notice notice-error"><p>AliExpress didn’t complete the connection: ' . esc_html( $err ? $err : 'unknown error' ) . '</p></div>';
		}

		$key       = GSUP_AliExpress::app_key();
		$has_sec   = '' !== (string) get_option( GSUP_AliExpress::OPT_SECRET, '' );
		$token     = GSUP_AliExpress::token();
		$callback  = GSUP_AliExpress::callback_url();

		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-info inline"><p>Only an administrator can change these settings.</p></div>';
		}
		echo '<h3 class="gsup-first">1. Your AliExpress app</h3>';
		echo '<p>From your app in the AliExpress Open Platform console (App Management). Your App Secret is stored on your site only.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gsup-save-form">';
		echo '<input type="hidden" name="action" value="gsup_ae_save_app">';
		wp_nonce_field( 'gsup_ae_save_app' );
		echo '<table class="form-table gsup-settings"><tbody>';
		echo '<tr><th scope="row"><label for="gsup_ae_key">App Key</label></th><td><input type="text" id="gsup_ae_key" name="app_key" class="regular-text" value="' . esc_attr( $key ) . '" autocomplete="off"></td></tr>';
		echo '<tr><th scope="row"><label for="gsup_ae_secret">App Secret</label></th><td><input type="password" id="gsup_ae_secret" name="app_secret" class="regular-text" value="" autocomplete="new-password" placeholder="' . esc_attr( $has_sec ? 'Saved — leave blank to keep' : 'Paste your App Secret' ) . '"></td></tr>';
		echo '<tr><th scope="row">Callback URL</th><td><code>' . esc_html( $callback ) . '</code> ' . gsup_copy_button( $callback ) . '<p class="description">Paste this into your app’s <strong>Callback URL</strong> in the AliExpress console before connecting.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">Save App Key &amp; Secret</button></p>';
		echo '</form>';

		echo '<h3>2. Connection</h3>';
		if ( ! GSUP_AliExpress::has_app() ) {
			echo '<p class="gsup-meta">Save your App Key and App Secret first.</p>';
			return;
		}
		if ( $token ) {
			$expired = $token['expires_at'] <= time();
			echo '<p><span class="gsup-badge ' . ( $expired ? 'gsup-badge--off' : 'gsup-badge--on' ) . '">' . ( $expired ? 'Expired' : 'Connected' ) . '</span> ';
			if ( '' !== $token['account'] ) {
				echo 'as <strong>' . esc_html( $token['account'] ) . '</strong> · ';
			}
			echo 'valid until ' . esc_html( wp_date( 'j M Y', $token['expires_at'] ) );
			if ( ! empty( $token['refresh_token'] ) ) {
				echo ' <span class="gsup-meta">(renews automatically)</span>';
			}
			echo '</p>';
		} else {
			echo '<p><span class="gsup-badge gsup-badge--off">Not connected</span></p>';
		}
		echo '<p><a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gsup_ae_connect' ), 'gsup_ae_connect' ) ) . '">' . ( $token ? 'Reconnect AliExpress' : 'Connect AliExpress' ) . '</a>';
		if ( $token ) {
			echo ' <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gsup_ae_disconnect' ), 'gsup_ae_disconnect' ) ) . '" data-gsup-confirm="Disconnect AliExpress? Auto-fill stops until you connect again.">Disconnect</a>';
		}
		echo '</p>';

		if ( ! $token ) {
			return;
		}
		echo '<h3>3. Test with a product</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gsup-test-form">';
		echo '<input type="hidden" name="action" value="gsup_ae_test">';
		wp_nonce_field( 'gsup_ae_test' );
		echo '<input type="text" name="ae_product" class="regular-text" placeholder="AliExpress link or product ID" required> ';
		echo '<select name="ship_to"><option value="AU">Deliver to Australia</option><option value="US">Deliver to United States</option></select> ';
		echo '<button type="submit" class="button">Fetch from AliExpress</button>';
		echo '</form>';
		self::render_ae_test_result();
		self::render_ae_log();
	}

	/** Last AliExpress calls, to copy when something needs looking at. */
	private static function render_ae_log() {
		$log = get_option( 'gsup_ae_log_entries' );
		$log = is_array( $log ) ? array_reverse( $log ) : array();
		echo '<h3 id="gsup-ae-log">4. Diagnostics</h3>';
		echo '<p class="gsup-meta">The last ' . count( $log ) . ' AliExpress calls (up to 30), newest first. Your access token, app key and customers’ addresses are never kept. If something isn’t working, click <strong>Copy all</strong> and paste it to whoever is helping you.</p>';
		if ( $log ) {
			$text = '';
			foreach ( $log as $e ) {
				$text .= '=== ' . gmdate( 'Y-m-d H:i:s', $e['at'] ) . ' UTC · ' . $e['api'] . ' · HTTP ' . $e['http'] . ' · ' . $e['ms'] . ' ms' . ( '' !== $e['error'] ? ' · ' . $e['error'] : '' ) . "\n";
				$text .= 'params: ' . wp_json_encode( $e['params'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
				$text .= 'reply: ' . $e['reply'] . "\n\n";
			}
			echo '<p>' . gsup_copy_button( 'Givsen Supplier ' . GSUP_VERSION . "\n\n" . $text, 'Copy all' ) . ' <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gsup_ae_log_clear' ), 'gsup_ae_log_clear' ) ) . '">Clear</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<table class="widefat striped gsup-sku-table"><thead><tr><th>When</th><th>Call</th><th>Result</th><th>Time</th></tr></thead><tbody>';
			foreach ( array_slice( $log, 0, 15 ) as $e ) {
				echo '<tr><td>' . esc_html( human_time_diff( $e['at'] ) ) . ' ago</td><td><code>' . esc_html( $e['api'] ) . '</code></td><td>' . ( '' !== $e['error'] ? '<span class="gsup-warn">' . esc_html( $e['error'] ) . '</span>' : 'OK' ) . '</td><td>' . (int) $e['ms'] . ' ms</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gsup-save-form"><input type="hidden" name="action" value="gsup_ae_log_save">';
		wp_nonce_field( 'gsup_ae_log_save' );
		echo '<p><label><input type="checkbox" name="on" value="yes"' . checked( GSUP_AliExpress::logging(), true, false ) . '> Keep this log</label> <button type="submit" class="button">Save</button></p></form>';
	}

	private static function render_ae_test_result() {
		$key  = 'gsup_ae_test_' . get_current_user_id();
		$test = get_transient( $key );
		if ( ! is_array( $test ) ) {
			return;
		}
		delete_transient( $key );
		if ( ! empty( $test['error'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $test['error'] ) . '</p></div>';
			return;
		}
		$p = $test['product'];
		echo '<div class="gsup-test-result">';
		if ( '' !== $p['image'] ) {
			echo '<img src="' . esc_url( $p['image'] ) . '" alt="" referrerpolicy="no-referrer">';
		}
		echo '<div><strong>' . esc_html( $p['title'] ) . '</strong><div class="gsup-meta">Product ' . esc_html( $p['product_id'] ) . ' · ' . ( $p['on_sale'] ? 'For sale' : 'Not for sale (' . esc_html( $p['status'] ) . ')' ) . ' · prices for delivery to ' . esc_html( $p['ship_to'] ) . ' · ' . count( $p['skus'] ) . ( 1 === count( $p['skus'] ) ? ' option' : ' options' ) . '</div></div></div>';
		echo '<p><a class="button button-primary" href="' . esc_url( self::create_url( $p['product_id'], 'US' === $p['ship_to'] ? 'US' : 'AU' ) ) . '">Add to store as new product</a></p>';
		echo '<table class="widefat striped gsup-sku-table"><thead><tr><th>Option</th><th>Ships from</th><th>SKU ID</th><th>Price</th><th>Stock</th></tr></thead><tbody>';
		foreach ( $p['skus'] as $sku ) {
			echo '<tr><td>' . esc_html( '' !== $sku['option'] ? $sku['option'] : '(no options)' ) . '</td>';
			echo '<td>' . esc_html( $sku['ship_from'] ? gsup_ship_from_label( $sku['ship_from'] ) : '—' ) . '</td>';
			echo '<td><code>' . esc_html( $sku['sku_id'] ) . '</code></td>';
			echo '<td>' . esc_html( trim( $sku['price'] . ' ' . $sku['currency'] ) ) . '</td>';
			echo '<td>' . esc_html( null === $sku['stock'] ? 'In stock' : (string) $sku['stock'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ------------------------------------------------------------- handlers */

	private static function guard( $nonce_action ) {
		// Settings that spend money or hand out access: administrators only.
		$admin_only = array( 'gsup_ae_save_app', 'gsup_ae_connect', 'gsup_ae_disconnect', 'gsup_save_auto', 'gsup_regen_key' );
		$cap        = in_array( $nonce_action, $admin_only, true ) ? 'manage_options' : 'manage_woocommerce';
		if ( ! current_user_can( $cap ) ) {
			wp_die( 'manage_options' === $cap ? 'Only an administrator can change this setting.' : 'You do not have permission to do that.', 403 );
		}
		check_admin_referer( $nonce_action );
	}

	private static function back( $args = array() ) {
		$status = isset( $_REQUEST['return_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['return_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $status && ! isset( $args['status'] ) && ! isset( $args['tab'] ) ) {
			$args['status'] = $status;
		}
		wp_safe_redirect( gsup_admin_url( $args ) );
		exit;
	}

	private static function request_id() {
		return isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public static function handle_add_manual() {
		self::guard( 'gsup_add_manual' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$url    = isset( $_POST['gsup_url'] ) ? sanitize_text_field( wp_unslash( $_POST['gsup_url'] ) ) : '';
		$sku_in = isset( $_POST['gsup_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['gsup_sku'] ) ) : '';
		$data   = array(
			'url'       => $url,
			'sku_id'    => $sku_in,
			'ship_from' => isset( $_POST['gsup_ship'] ) ? sanitize_text_field( wp_unslash( $_POST['gsup_ship'] ) ) : '',
			'option'    => isset( $_POST['gsup_option'] ) ? sanitize_text_field( wp_unslash( $_POST['gsup_option'] ) ) : '',
			'title'     => isset( $_POST['gsup_title'] ) ? sanitize_text_field( wp_unslash( $_POST['gsup_title'] ) ) : '',
		);
		// phpcs:enable
		$result = GSUP_Import::add( $data, 'manual' );
		if ( is_wp_error( $result ) ) {
			gsup_flash( 'Couldn’t find an AliExpress product ID in “' . esc_html( $url ) . '”. Paste the product page link or the long number from it.', 'error' );
		} else {
			if ( GSUP_AliExpress::is_connected() ) {
				GSUP_Import::enrich( $result['id'] );
			}
			if ( $result['duplicate'] ) {
				gsup_flash( 'That product and option were already in the import list — updated it.', 'info' );
			} else {
				gsup_flash( 'Added to the import list.' );
			}
		}
		self::back( array( 'status' => 'new' ) );
	}

	public static function handle_link() {
		$id = self::request_id();
		self::guard( 'gsup_link_' . $id );
		$row   = GSUP_Import::get( $id );
		$wc_id = isset( $_POST['wc_product_id'] ) ? absint( $_POST['wc_product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $row ) {
			gsup_flash( 'That import list item no longer exists.', 'error' );
			self::back();
		}
		if ( ! $wc_id ) {
			gsup_flash( 'Pick a product or variation from the search box first.', 'error' );
			self::back();
		}
		$result = GSUP_Import::link_to_product( $row, $wc_id );
		if ( is_wp_error( $result ) ) {
			gsup_flash( $result->get_error_message(), 'error' );
		} else {
			GSUP_Import::set_status( $id, 'linked', $wc_id );
			gsup_flash( $result . ' <a href="' . esc_url( gsup_product_edit_url( $wc_id ) ) . '">Edit product</a>' );
		}
		self::back();
	}

	public static function handle_dismiss() {
		$id = self::request_id();
		self::guard( 'gsup_row_' . $id );
		GSUP_Import::set_status( $id, 'dismissed' );
		gsup_flash( 'Dismissed. You’ll find it under “Dismissed” if you need it again.', 'info' );
		self::back();
	}

	public static function handle_restore() {
		$id = self::request_id();
		self::guard( 'gsup_row_' . $id );
		GSUP_Import::set_status( $id, 'new' );
		gsup_flash( 'Moved back to “To link”.' );
		self::back();
	}

	public static function handle_delete() {
		$id = self::request_id();
		self::guard( 'gsup_row_' . $id );
		GSUP_Import::delete( $id );
		gsup_flash( 'Removed from the import list.', 'info' );
		self::back();
	}

	public static function handle_refresh() {
		$id = self::request_id();
		self::guard( 'gsup_row_' . $id );
		$r = GSUP_Import::enrich( $id );
		if ( is_wp_error( $r ) ) {
			gsup_flash( 'Couldn’t check with AliExpress: ' . esc_html( $r->get_error_message() ), 'error' );
		} else {
			gsup_flash( 'Updated from AliExpress.' );
		}
		self::back();
	}

	public static function handle_ae_save_app() {
		self::guard( 'gsup_ae_save_app' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$key    = isset( $_POST['app_key'] ) ? preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['app_key'] ) ) ) : '';
		$secret = isset( $_POST['app_secret'] ) ? preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['app_secret'] ) ) ) : '';
		// phpcs:enable
		$changed = $key !== GSUP_AliExpress::app_key();
		update_option( GSUP_AliExpress::OPT_KEY, $key, false );
		if ( '' !== $secret ) {
			update_option( GSUP_AliExpress::OPT_SECRET, $secret, false );
			$changed = true;
		}
		if ( $changed && GSUP_AliExpress::token() ) {
			GSUP_AliExpress::disconnect();
			gsup_flash( 'Saved. Your app details changed, so connect AliExpress again.', 'warning' );
		} else {
			gsup_flash( 'Saved.' );
		}
		self::back( array( 'tab' => 'settings', 'section' => 'aliexpress' ) );
	}

	public static function handle_ae_connect() {
		self::guard( 'gsup_ae_connect' );
		if ( ! GSUP_AliExpress::has_app() ) {
			gsup_flash( 'Save your App Key and App Secret first.', 'error' );
			self::back( array( 'tab' => 'settings', 'section' => 'aliexpress' ) );
		}
		wp_redirect( GSUP_AliExpress::authorize_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- AliExpress sign-in page.
		exit;
	}

	public static function handle_ae_disconnect() {
		self::guard( 'gsup_ae_disconnect' );
		GSUP_AliExpress::disconnect();
		gsup_flash( 'AliExpress disconnected.', 'info' );
		self::back( array( 'tab' => 'settings', 'section' => 'aliexpress' ) );
	}

	public static function handle_ae_test() {
		self::guard( 'gsup_ae_test' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$input   = isset( $_POST['ae_product'] ) ? sanitize_text_field( wp_unslash( $_POST['ae_product'] ) ) : '';
		$ship_to = isset( $_POST['ship_to'] ) && 'US' === $_POST['ship_to'] ? 'US' : 'AU';
		// phpcs:enable
		$product = GSUP_AliExpress::get_product( $input, $ship_to );
		set_transient(
			'gsup_ae_test_' . get_current_user_id(),
			is_wp_error( $product ) ? array( 'error' => $product->get_error_message() ) : array( 'product' => $product ),
			10 * MINUTE_IN_SECONDS
		);
		self::back( array( 'tab' => 'settings', 'section' => 'aliexpress' ) );
	}

	public static function handle_create() {
		$ae = isset( $_POST['ae'] ) ? gsup_parse_product_id( sanitize_text_field( wp_unslash( $_POST['ae'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::guard( 'gsup_create_' . $ae );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$ship  = isset( $_POST['ship'] ) ? strtoupper( sanitize_key( wp_unslash( $_POST['ship'] ) ) ) : '';
		$ship  = 'NONE' === $ship ? '' : $ship;
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$skus  = isset( $_POST['sku_ids'] ) && is_array( $_POST['sku_ids'] ) ? array_map( 'gsup_parse_sku_id', array_map( 'sanitize_text_field', wp_unslash( $_POST['sku_ids'] ) ) ) : array();
		$cats  = isset( $_POST['category_ids'] ) && is_array( $_POST['category_ids'] ) ? gsup_clean_category_ids( wp_unslash( $_POST['category_ids'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned to real category IDs.
		// phpcs:enable
		$back = gsup_admin_url( array( 'tab' => 'create', 'ae' => $ae, 'ship' => '' === $ship ? 'none' : $ship ) );

		$product = GSUP_AliExpress::get_product( $ae, 'US' === $ship ? 'US' : 'AU' );
		if ( is_wp_error( $product ) ) {
			gsup_flash( esc_html( $product->get_error_message() ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$id = GSUP_Creator::create( $product, $ship, array_filter( $skus ), $title, $cats, self::posted_create_options() );
		if ( is_wp_error( $id ) ) {
			gsup_flash( esc_html( $id->get_error_message() ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		update_user_meta( get_current_user_id(), 'gsup_last_categories', $cats );
		gsup_flash( 'Draft product created from AliExpress with its supplier links set. Check the title, description' . ( $cats ? '' : ', category' ) . ' and prices, then click <strong>Publish</strong>.' );
		$created = wc_get_product( $id );
		if ( $created && ! $created->get_image_id() && ! empty( $product['images'] ) && ( ! isset( $_POST['photos'] ) || (int) $_POST['photos'] > 0 ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
			gsup_flash( 'The photos couldn’t be copied from AliExpress (your server may be blocking the download). Add them under <strong>Product image</strong> and <strong>Product gallery</strong>.', 'warning' );
		}
		wp_safe_redirect( admin_url( 'post.php?post=' . (int) $id . '&action=edit' ) );
		exit;
	}

	/** Your last Add to store choices (description, photos, specifics). */
	private static function import_prefs() {
		$saved = get_user_meta( get_current_user_id(), 'gsup_import_prefs', true );
		return array_merge(
			array(
				'description'   => 'text',
				'photos'        => 10,
				'option_photos' => true,
				'desc_photos'   => true,
				'specs'         => true,
				'short'         => false,
			),
			is_array( $saved ) ? $saved : array()
		);
	}

	/** Renames and text options from the Add to store form. */
	private static function posted_create_options() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in handle_create(); each value sanitized below.
		$opts = array(
			'names'            => array(),
			'values'           => array(),
			'description'      => isset( $_POST['description'] ) && in_array( $_POST['description'], array( 'text', 'clean', 'empty' ), true ) ? sanitize_key( $_POST['description'] ) : 'text',
			'photos'           => isset( $_POST['photos'] ) ? max( 0, min( 10, (int) $_POST['photos'] ) ) : 10,
			'option_photos'    => ! empty( $_POST['option_photos'] ),
			'desc_photos'      => ! empty( $_POST['desc_photos'] ),
			'specs'            => ! empty( $_POST['specs'] ),
			'short'            => ! empty( $_POST['short'] ),
		);
		// Remember these for next time.
		update_user_meta(
			get_current_user_id(),
			'gsup_import_prefs',
			array_intersect_key( $opts, array_flip( array( 'description', 'photos', 'option_photos', 'desc_photos', 'specs', 'short' ) ) )
		);
		$name_orig  = isset( $_POST['rn_name_orig'] ) && is_array( $_POST['rn_name_orig'] ) ? wp_unslash( $_POST['rn_name_orig'] ) : array();
		$name_new   = isset( $_POST['rn_name'] ) && is_array( $_POST['rn_name'] ) ? wp_unslash( $_POST['rn_name'] ) : array();
		$value_orig = isset( $_POST['rn_value_orig'] ) && is_array( $_POST['rn_value_orig'] ) ? wp_unslash( $_POST['rn_value_orig'] ) : array();
		$value_new  = isset( $_POST['rn_value'] ) && is_array( $_POST['rn_value'] ) ? wp_unslash( $_POST['rn_value'] ) : array();
		// phpcs:enable
		// Originals are only used to look up AliExpress's own text, never stored or shown.
		$decode = function ( $v ) {
			$d = is_string( $v ) ? base64_decode( $v, true ) : false;
			return false === $d ? '' : $d;
		};
		foreach ( $name_orig as $i => $orig ) {
			$orig = $decode( $orig );
			if ( '' === $orig ) {
				continue;
			}
			if ( isset( $name_new[ $i ] ) ) {
				$opts['names'][ $orig ] = mb_substr( sanitize_text_field( $name_new[ $i ] ), 0, 100 );
			}
			if ( isset( $value_orig[ $i ] ) && is_array( $value_orig[ $i ] ) ) {
				foreach ( $value_orig[ $i ] as $j => $vorig ) {
					if ( isset( $value_new[ $i ][ $j ] ) ) {
						$opts['values'][ $orig ][ $decode( $vorig ) ] = mb_substr( sanitize_text_field( $value_new[ $i ][ $j ] ), 0, 150 );
					}
				}
			}
		}
		return $opts;
	}

	public static function handle_save_pricing() {
		self::guard( 'gsup_save_pricing' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$mult = isset( $_POST['multiplier'] ) ? (float) $_POST['multiplier'] : 2;
		$add  = isset( $_POST['add'] ) ? (float) $_POST['add'] : 0;
		$rnd  = isset( $_POST['round'] ) ? 'yes' : 'no';
		// phpcs:enable
		update_option( 'gsup_price_multiplier', $mult > 0 ? $mult : 2, false );
		update_option( 'gsup_price_add', $add, false );
		update_option( 'gsup_price_round', $rnd, false );
		update_option( 'gsup_price_shipping', isset( $_POST['shipping'] ) ? 'yes' : 'no', false ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		gsup_flash( 'Pricing saved.' );
		wp_safe_redirect( gsup_settings_url( 'pricing' ) );
		exit;
	}

	public static function handle_save_sync() {
		self::guard( 'gsup_save_sync' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		update_option( 'gsup_sync_enabled', isset( $_POST['enabled'] ) ? 'yes' : 'no', false );
		$mode = isset( $_POST['prices'] ) ? sanitize_key( wp_unslash( $_POST['prices'] ) ) : 'no';
		update_option( 'gsup_sync_prices', in_array( $mode, array( 'no', 'low', 'yes' ), true ) ? $mode : 'no', false );
		update_option( 'gsup_stock_min', max( 0, isset( $_POST['stock_min'] ) ? (int) $_POST['stock_min'] : 0 ), false );
		update_option( 'gsup_stock_cap', max( 0, isset( $_POST['stock_cap'] ) ? (int) $_POST['stock_cap'] : 0 ), false );
		update_option( 'gsup_backup_auto', isset( $_POST['backup_auto'] ) ? 'yes' : 'no', false );
		update_option( 'gsup_backup_rise', max( 1, min( 200, isset( $_POST['backup_rise'] ) ? (float) $_POST['backup_rise'] : 15 ) ), false );
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		// phpcs:enable
		update_option( 'gsup_sync_email', is_email( $email ) ? $email : get_option( 'admin_email' ), false );
		GSUP_Sync::schedule();
		delete_transient( 'gsup_schedules_ok' );
		gsup_flash( 'Sync settings saved.' );
		wp_safe_redirect( gsup_settings_url( 'sync' ) );
		exit;
	}

	public static function handle_sync_now() {
		self::guard( 'gsup_sync_now' );
		if ( GSUP_Sync::start( true ) ) {
			gsup_flash( 'Sync started. It runs in the background — refresh this page to see progress.' );
		} else {
			gsup_flash( GSUP_Sync::running() ? 'A sync is already running.' : 'The sync couldn’t start — see the details below.', 'warning' );
		}
		wp_safe_redirect( gsup_settings_url( 'sync' ) );
		exit;
	}

	public static function handle_save_auto() {
		self::guard( 'gsup_save_auto' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$pref = isset( $_POST['ship_pref'] ) ? sanitize_key( wp_unslash( $_POST['ship_pref'] ) ) : 'cheapest_tracked';
		update_option( 'gsup_auto_order', isset( $_POST['auto'] ) ? 'yes' : 'no', false );
		update_option( 'gsup_auto_pay', isset( $_POST['pay'] ) ? 'yes' : 'no', false );
		update_option( 'gsup_auto_loss_guard', isset( $_POST['guard'] ) ? 'yes' : 'no', false );
		$when = isset( $_POST['complete_when'] ) ? sanitize_key( wp_unslash( $_POST['complete_when'] ) ) : 'tracking';
		update_option( 'gsup_complete_when', in_array( $when, array( 'tracking', 'delivered', 'no' ), true ) ? $when : 'tracking', false );
		update_option( 'gsup_delivered_email', isset( $_POST['delivered_email'] ) ? 'yes' : 'no', false );
		update_option( 'gsup_fallback_phone', isset( $_POST['fallback_phone'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['fallback_phone'] ) ), 0, 30 ) : '', false );
		update_option( 'gsup_late_notrack_days', max( 1, min( 60, isset( $_POST['notrack_days'] ) ? (int) $_POST['notrack_days'] : 7 ) ), false );
		update_option( 'gsup_late_grace_days', max( 0, min( 60, isset( $_POST['grace_days'] ) ? (int) $_POST['grace_days'] : 5 ) ), false );
		// phpcs:enable
		update_option( 'gsup_ship_pref', in_array( $pref, array( 'cheapest_tracked', 'cheapest', 'fastest' ), true ) ? $pref : 'cheapest_tracked', false );
		gsup_flash( GSUP_Orders::enabled() ? 'Saved. New Processing orders will be placed on AliExpress automatically.' : 'Saved. Automatic ordering is off.' );
		wp_safe_redirect( gsup_settings_url( 'ordering' ) );
		exit;
	}

	public static function handle_save_profit() {
		self::guard( 'gsup_save_profit' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		update_option( 'gsup_min_margin', min( 100, max( 0, isset( $_POST['min_margin'] ) ? (float) $_POST['min_margin'] : 30 ) ), false );
		update_option( 'gsup_fee_percent', max( 0, isset( $_POST['fee_percent'] ) ? (float) $_POST['fee_percent'] : 0 ), false );
		update_option( 'gsup_fee_fixed', max( 0, isset( $_POST['fee_fixed'] ) ? (float) $_POST['fee_fixed'] : 0 ), false );
		// phpcs:enable
		$n = GSUP_Profit::refresh_all();
		gsup_flash( 'Profit settings saved. Margins rechecked on ' . (int) $n . ' linked product(s).' );
		wp_safe_redirect( gsup_settings_url( 'pricing' ) );
		exit;
	}

	public static function handle_save_cbr() {
		self::guard( 'gsup_save_cbr' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$map = array();
		if ( isset( $_POST['map'] ) && is_array( $_POST['map'] ) ) {
			foreach ( wp_unslash( $_POST['map'] ) as $code => $countries ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned below.
				$code = gsup_sanitize_ship_from( sanitize_text_field( $code ) );
				$list = GSUP_CBR::parse_countries( sanitize_text_field( $countries ) );
				if ( '' !== $code && $list ) {
					$map[ $code ] = $list;
				}
			}
		}
		update_option( 'gsup_cbr_map', $map, false );
		update_option( 'gsup_cbr_enabled', isset( $_POST['enabled'] ) ? 'yes' : 'no', false );
		foreach ( array( 'type_key', 'countries_key', 'type_value' ) as $field ) {
			$value = isset( $_POST[ $field ] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', wp_unslash( $_POST[ $field ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reduced to a meta key.
			if ( 'type_value' !== $field && GSUP_CBR::protected_key( $value ) ) {
				gsup_flash( esc_html( '“' . $value . '” is WooCommerce’s own product data, not a country setting — not saved.' ), 'error' );
				continue;
			}
			update_option( 'gsup_cbr_' . $field, $value, false );
		}
		update_option( 'gsup_cbr_format', isset( $_POST['format'] ) && 'csv' === $_POST['format'] ? 'csv' : 'array', false );
		// phpcs:enable
		gsup_flash( 'Country restriction settings saved.' );
		wp_safe_redirect( gsup_settings_url( 'countries' ) );
		exit;
	}

	public static function handle_cbr_inspect() {
		self::guard( 'gsup_cbr_inspect' );
		$id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $id ) {
			set_transient(
				'gsup_cbr_inspect_' . get_current_user_id(),
				array(
					'id'   => $id,
					'meta' => GSUP_CBR::inspect( $id ),
				),
				10 * MINUTE_IN_SECONDS
			);
		} else {
			gsup_flash( 'Pick a product first.', 'error' );
		}
		wp_safe_redirect( gsup_settings_url( 'countries' ) );
		exit;
	}

	public static function handle_cbr_apply() {
		self::guard( 'gsup_cbr_apply' );
		$results = GSUP_CBR::apply_all( isset( $_POST['overwrite'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$labels  = array(
			'set'     => 'restriction set',
			'same'    => 'already right',
			'kept'    => 'kept your own restriction',
			'mixed'   => 'ship from more than one warehouse (left alone)',
			'no_ship' => 'ships-from not set (left alone)',
			'no_rule' => 'no countries chosen for that warehouse',
		);
		$parts = array();
		foreach ( $labels as $key => $label ) {
			if ( ! empty( $results[ $key ] ) ) {
				$parts[] = count( $results[ $key ] ) . ' ' . $label;
			}
		}
		gsup_flash( $parts ? 'Country restrictions: ' . esc_html( implode( ' · ', $parts ) ) . '.' : 'No linked products found.', empty( $results['set'] ) ? 'info' : 'success' );
		wp_safe_redirect( gsup_settings_url( 'countries' ) );
		exit;
	}

	public static function handle_save_eta() {
		self::guard( 'gsup_save_eta' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		update_option( 'gsup_eta_show', isset( $_POST['show'] ) ? 'yes' : 'no', false );
		update_option( 'gsup_eta_processing', max( 0, min( 30, isset( $_POST['processing'] ) ? (int) $_POST['processing'] : 1 ) ), false );
		update_option( 'gsup_eta_format', isset( $_POST['format'] ) && 'days' === $_POST['format'] ? 'days' : 'dates', false );
		update_option( 'gsup_eta_business', isset( $_POST['business'] ) ? 'yes' : 'no', false );
		// phpcs:enable
		gsup_flash( 'Delivery estimate saved.' );
		wp_safe_redirect( gsup_settings_url( 'pricing' ) . '#gsup-eta' );
		exit;
	}

	public static function handle_ae_log_clear() {
		self::guard( 'gsup_ae_log_clear' );
		delete_option( 'gsup_ae_log_entries' );
		gsup_flash( 'Diagnostics log cleared.', 'info' );
		wp_safe_redirect( gsup_settings_url( 'aliexpress' ) . '#gsup-ae-log' );
		exit;
	}

	public static function handle_ae_log_save() {
		self::guard( 'gsup_ae_log_save' );
		update_option( 'gsup_ae_log', isset( $_POST['on'] ) ? 'yes' : 'no', false ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		if ( ! isset( $_POST['on'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			delete_option( 'gsup_ae_log_entries' );
		}
		gsup_flash( 'Saved.' );
		wp_safe_redirect( gsup_settings_url( 'aliexpress' ) . '#gsup-ae-log' );
		exit;
	}

	public static function handle_regen_key() {
		self::guard( 'gsup_regen_key' );
		update_option( 'gsup_secret', GSUP_Install::new_secret(), false );
		gsup_flash( 'New connection key made. Paste it into the Chrome extension’s settings.', 'warning' );
		self::back( array( 'tab' => 'settings', 'section' => 'extension' ) );
	}
}

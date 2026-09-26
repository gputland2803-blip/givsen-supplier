<?php
/**
 * Rewrite with AI: product titles, descriptions and short descriptions rewritten by Claude in your store's voice.
 *
 * Products → select → Bulk actions → "Rewrite with AI" (or the row link). The screen asks Claude for each product
 * one at a time (so nothing times out), shows old and new side by side — every new text editable — and applies only
 * the ones you tick. What was there before is saved; "Undo text changes" on the product's Supplier tab puts it back.
 *
 * Uses the Claude API directly (WordPress's HTTP functions — no extra libraries in the plugin). Your API key is stored
 * on your site only and only administrators can change it. Default model: Claude Haiku 4.5, the cheapest — about a
 * fifth of a cent per product. Only facts already on the product are used; the prompt forbids invented claims and
 * any mention of where the product is sourced.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_AI {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	const MAX      = 50;

	/** Models offered, with price per million tokens (input, output) for the running cost shown on screen. */
	public static function models() {
		return array(
			'claude-haiku-4-5' => array( 'Claude Haiku 4.5 — cheapest (about 0.2¢ a product)', 1.00, 5.00 ),
			'claude-sonnet-5'  => array( 'Claude Sonnet 5 — better writing (about 0.4¢ a product)', 2.00, 10.00 ),
		);
	}

	public static function init() {
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 20, 2 );
		add_action( 'wp_ajax_gsup_ai_rewrite', array( __CLASS__, 'ajax_rewrite' ) );
		add_action( 'admin_post_gsup_ai_apply', array( __CLASS__, 'handle_apply' ) );
	}

	public static function key() {
		return trim( (string) get_option( 'gsup_ai_key', '' ) );
	}

	public static function ready() {
		return '' !== self::key();
	}

	public static function model() {
		$m = (string) get_option( 'gsup_ai_model', 'claude-haiku-4-5' );
		return isset( self::models()[ $m ] ) ? $m : 'claude-haiku-4-5';
	}

	public static function settings() {
		return array(
			'voice'     => (string) get_option( 'gsup_ai_voice', 'Warm, friendly and clear, for people buying a gift for someone they care about. Short sentences. No hype.' ),
			'extra'     => (string) get_option( 'gsup_ai_extra', '' ),
			'title_max' => max( 30, min( 150, (int) get_option( 'gsup_ai_title_max', 70 ) ) ),
			'english'   => (string) get_option( 'gsup_ai_english', 'Australian' ),
		);
	}

	/* ------------------------------------------------------------ entry points */

	public static function bulk_action( $actions ) {
		$actions['gsup_ai'] = 'Rewrite with AI (Givsen Supplier)';
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'gsup_ai' !== $action ) {
			return $redirect;
		}
		return gsup_admin_url(
			array(
				'tab' => 'ai',
				'ids' => implode( ',', array_slice( array_map( 'absint', (array) $ids ), 0, self::MAX ) ),
			)
		);
	}

	public static function row_action( $actions, $post ) {
		if ( $post && 'product' === $post->post_type && current_user_can( 'edit_product', $post->ID ) ) {
			$actions['gsup_ai'] = '<a href="' . esc_url( gsup_admin_url( array( 'tab' => 'ai', 'ids' => (int) $post->ID ) ) ) . '">Rewrite with AI</a>';
		}
		return $actions;
	}

	/* ------------------------------------------------------------------ prompt */

	/** Everything Claude may use about a product — only what's already on it. */
	private static function facts( WC_Product $product ) {
		$attrs = array();
		foreach ( $product->get_attributes() as $attr ) {
			$name    = wc_attribute_label( $attr->get_name() );
			$values  = $attr->is_taxonomy() ? wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) ) : $attr->get_options();
			$attrs[] = $name . ': ' . implode( ', ', array_map( 'wp_strip_all_tags', (array) $values ) );
		}
		$cats = wc_get_product_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		return array(
			'current_title'             => $product->get_name(),
			'current_description'       => trim( wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br/>', '<br />', '</li>' ), "\n", $product->get_description() ) ) ),
			'current_short_description' => trim( wp_strip_all_tags( $product->get_short_description() ) ),
			'details'                   => $attrs,
			'categories'                => is_array( $cats ) ? array_values( $cats ) : array(),
		);
	}

	private static function system_prompt() {
		$s    = self::settings();
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$p    = "You write product copy for {$site}, an online store. Rewrite the product's title, description and short description.\n\n";
		$p   .= "Voice: {$s['voice']}\n";
		$p   .= "Spelling: {$s['english']} English.\n\n";
		$p   .= "Rules:\n";
		$p   .= "- Use only facts given about the product (title, description, details, categories). Never invent materials, sizes, quantities, certifications, benefits or claims. If something is unclear, leave it out.\n";
		$p   .= "- Keep every size, dimension, weight, capacity, quantity, material and compatibility detail that is given, exactly.\n";
		$p   .= "- Never mention AliExpress, Alibaba, suppliers, sellers, factories, wholesale, dropshipping, shipping times, feedback, ratings or reviews.\n";
		$p   .= "- Title: at most {$s['title_max']} characters, natural and descriptive, the product type near the start. No keyword stuffing, no ALL CAPS, no emoji, no quotation marks.\n";
		$p   .= "- Description: HTML using only <p>, <ul>, <li> and <strong>. Two or three short paragraphs, then a bulleted list of the key details. No headings, links, images or tables.\n";
		$p   .= "- Short description: one or two sentences of plain text for the top of the product page.\n";
		if ( '' !== trim( $s['extra'] ) ) {
			$p .= "\nAlso: " . trim( $s['extra'] ) . "\n";
		}
		return $p;
	}

	private static function schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'             => array( 'type' => 'string' ),
				'description_html'  => array( 'type' => 'string' ),
				'short_description' => array( 'type' => 'string' ),
			),
			'required'             => array( 'title', 'description_html', 'short_description' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Ask Claude for new copy for one product.
	 *
	 * @return array|WP_Error {title, description, short, cost}
	 */
	public static function rewrite( WC_Product $product ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'gsup_ai_no_key', 'Add your Claude API key in Givsen Supplier → Settings → AI writing.' );
		}
		$model    = self::model();
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 60,
				'headers' => array(
					'content-type'      => 'application/json',
					'x-api-key'         => self::key(),
					'anthropic-version' => '2023-06-01',
				),
				'body'    => wp_json_encode(
					array(
						'model'         => $model,
						'max_tokens'    => 2000,
						'system'        => self::system_prompt(),
						'messages'      => array(
							array(
								'role'    => 'user',
								'content' => "Rewrite this product. Everything known about it:\n\n" . wp_json_encode( self::facts( $product ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
							),
						),
						'output_config' => array(
							'format' => array(
								'type'   => 'json_schema',
								'schema' => self::schema(),
							),
						),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'gsup_ai_network', 'Couldn’t reach Claude: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			$msg = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'HTTP ' . $code;
			if ( 401 === $code ) {
				$msg = 'Claude didn’t accept the API key — check it in Settings → AI writing.';
			} elseif ( 429 === $code || 529 === $code ) {
				$msg = 'Claude is busy or you’ve hit your rate limit — wait a minute and click Retry.';
			} elseif ( 400 === $code && false !== stripos( $msg, 'credit' ) ) {
				$msg = 'Your Claude account is out of credit — top it up in the Claude Console.';
			}
			return new WP_Error( 'gsup_ai_api', $msg );
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'gsup_ai_bad', 'Claude sent an unreadable reply.' );
		}
		$stop = isset( $data['stop_reason'] ) ? (string) $data['stop_reason'] : '';
		if ( 'refusal' === $stop ) {
			return new WP_Error( 'gsup_ai_refused', 'Claude declined to rewrite this product.' );
		}
		if ( 'max_tokens' === $stop ) {
			return new WP_Error( 'gsup_ai_long', 'The rewrite ran too long and was cut off — try again.' );
		}
		$text = '';
		foreach ( isset( $data['content'] ) ? (array) $data['content'] : array() as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$text .= $block['text'];
			}
		}
		$out = json_decode( $text, true );
		if ( ! is_array( $out ) || ! isset( $out['title'], $out['description_html'], $out['short_description'] ) ) {
			return new WP_Error( 'gsup_ai_bad', 'Claude’s reply wasn’t in the expected shape — try again.' );
		}
		$u     = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();
		$price = self::models()[ $model ];
		$cost  = ( (int) ( $u['input_tokens'] ?? 0 ) * $price[1] + (int) ( $u['output_tokens'] ?? 0 ) * $price[2] ) / 1000000;
		return array(
			'title'       => sanitize_text_field( $out['title'] ),
			'description' => self::clean_html( $out['description_html'] ),
			'short'       => sanitize_textarea_field( $out['short_description'] ),
			'cost'        => round( $cost, 5 ),
		);
	}

	/** Only the tags the prompt allows, whatever comes back. */
	private static function clean_html( $html ) {
		return trim(
			wp_kses(
				(string) $html,
				array(
					'p'      => array(),
					'ul'     => array(),
					'li'     => array(),
					'strong' => array(),
				)
			)
		);
	}

	/* ------------------------------------------------------------------ screen */

	private static function ids_from_request() {
		$raw = isset( $_REQUEST['ids'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ids'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array_slice( array_filter( array_map( 'absint', explode( ',', $raw ) ) ), 0, self::MAX );
	}

	public static function render() {
		$ids = self::ids_from_request();
		if ( ! self::ready() ) {
			echo '<div class="notice notice-warning inline"><p>Add your Claude API key first: <a href="' . esc_url( gsup_settings_url( 'ai' ) ) . '">Settings → AI writing</a>.</p></div>';
			return;
		}
		if ( ! $ids ) {
			echo '<p>Choose products in <a href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">Products → All Products</a>, then pick <strong>Rewrite with AI</strong> from <em>Bulk actions</em> (or use the link under a product’s name).</p>';
			return;
		}
		$models = self::models();
		echo '<h2>Rewrite ' . count( $ids ) . ' product(s) with AI</h2>';
		echo '<p class="gsup-meta">Using ' . esc_html( $models[ self::model() ][0] ) . ' in your store’s voice (<a href="' . esc_url( gsup_settings_url( 'ai' ) ) . '">change</a>). Nothing changes until you click <strong>Apply</strong>; every new text can be edited first, and the old text is saved so it can be undone.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gsup-ai-form">';
		echo '<input type="hidden" name="action" value="gsup_ai_apply">';
		wp_nonce_field( 'gsup_ai_apply' );
		echo '<p><label><input type="checkbox" name="do_title" value="1" checked> Titles</label> &nbsp; <label><input type="checkbox" name="do_desc" value="1" checked> Descriptions</label> &nbsp; <label><input type="checkbox" name="do_short" value="1" checked> Short descriptions</label></p>';
		echo '<p><button type="button" class="button button-primary gsup-ai-start">Write new copy</button> <span class="gsup-ai-status gsup-meta"></span></p>';
		echo '<div class="gsup-ai-list">';
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || $product->is_type( 'variation' ) || ! current_user_can( 'edit_product', $id ) ) {
				continue;
			}
			echo '<div class="gsup-ai-item" data-id="' . (int) $id . '">';
			echo '<div class="gsup-ai-head"><label><input type="checkbox" name="pick[]" value="' . (int) $id . '" disabled> <strong>' . esc_html( $product->get_name() ) . '</strong></label> <a href="' . esc_url( admin_url( 'post.php?post=' . (int) $id . '&action=edit' ) ) . '" target="_blank" rel="noopener">edit ↗</a> <span class="gsup-ai-state gsup-meta">Waiting</span></div>';
			echo '<div class="gsup-ai-cols"><div class="gsup-ai-old"><div class="gsup-meta">Now</div><div class="gsup-ai-old-desc">' . wp_kses_post( wp_trim_words( wp_strip_all_tags( $product->get_description() ), 60 ) ) . '</div></div>';
			echo '<div class="gsup-ai-new"><div class="gsup-meta">New</div>';
			echo '<input type="text" class="large-text" name="title[' . (int) $id . ']" placeholder="New title" disabled>';
			echo '<textarea class="large-text" rows="7" name="desc[' . (int) $id . ']" placeholder="New description" disabled></textarea>';
			echo '<textarea class="large-text" rows="2" name="short[' . (int) $id . ']" placeholder="New short description" disabled></textarea>';
			echo '</div></div></div>';
		}
		echo '</div>';
		echo '<p><button type="submit" class="button button-primary button-hero gsup-ai-apply" disabled>Apply to ticked products</button></p></form>';
		$nonce = wp_create_nonce( 'gsup_ai_rewrite' );
		?>
		<script>
		(function () {
			var form = document.querySelector('.gsup-ai-form');
			var start = form.querySelector('.gsup-ai-start');
			var status = form.querySelector('.gsup-ai-status');
			var total = 0, cost = 0;
			function one(item) {
				var state = item.querySelector('.gsup-ai-state');
				state.textContent = 'Writing…';
				var body = new URLSearchParams({ action: 'gsup_ai_rewrite', _ajax_nonce: <?php echo wp_json_encode( $nonce ); ?>, product: item.dataset.id });
				return fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (!res || !res.success) {
							state.innerHTML = '';
							var err = document.createElement('span'); err.className = 'gsup-warn';
							err.textContent = (res && res.data && res.data.message) ? res.data.message : 'Something went wrong.';
							var again = document.createElement('a'); again.href = '#'; again.textContent = ' Retry';
							again.addEventListener('click', function (e) { e.preventDefault(); one(item); });
							state.appendChild(err); state.appendChild(again);
							return;
						}
						var d = res.data;
						item.querySelector('[name="title[' + item.dataset.id + ']"]').value = d.title;
						item.querySelector('[name="desc[' + item.dataset.id + ']"]').value = d.description;
						item.querySelector('[name="short[' + item.dataset.id + ']"]').value = d.short;
						item.querySelectorAll('input, textarea').forEach(function (f) { f.disabled = false; });
						item.querySelector('[name="pick[]"]').checked = true;
						form.querySelector('.gsup-ai-apply').disabled = false;
						state.textContent = 'Ready — check it, edit if you like';
						total++; cost += d.cost || 0;
						status.textContent = total + ' written · about US$' + cost.toFixed(3) + ' so far';
					})
					.catch(function () { state.textContent = 'Couldn’t reach your site — Retry'; });
			}
			start.addEventListener('click', function () {
				start.disabled = true;
				var items = Array.prototype.slice.call(form.querySelectorAll('.gsup-ai-item'));
				// Two at a time: quick, without tripping Claude's rate limits.
				var queue = items.slice();
				function next() { var it = queue.shift(); return it ? one(it).then(next) : Promise.resolve(); }
				Promise.all([next(), next()]).then(function () { status.textContent += ' · done'; });
			});
		})();
		</script>
		<?php
	}

	public static function ajax_rewrite() {
		check_ajax_referer( 'gsup_ai_rewrite' );
		$id      = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product || ! current_user_can( 'edit_product', $id ) ) {
			wp_send_json_error( array( 'message' => 'You can’t edit this product.' ), 403 );
		}
		$r = self::rewrite( $product );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( $r );
	}

	public static function handle_apply() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( 'You do not have permission to do that.', 403 );
		}
		check_admin_referer( 'gsup_ai_apply' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above; each value sanitised below.
		$picked = isset( $_POST['pick'] ) && is_array( $_POST['pick'] ) ? array_slice( array_map( 'absint', $_POST['pick'] ), 0, self::MAX ) : array();
		$titles = isset( $_POST['title'] ) && is_array( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : array();
		$descs  = isset( $_POST['desc'] ) && is_array( $_POST['desc'] ) ? wp_unslash( $_POST['desc'] ) : array();
		$shorts = isset( $_POST['short'] ) && is_array( $_POST['short'] ) ? wp_unslash( $_POST['short'] ) : array();
		$do     = array(
			'title' => ! empty( $_POST['do_title'] ),
			'desc'  => ! empty( $_POST['do_desc'] ),
			'short' => ! empty( $_POST['do_short'] ),
		);
		// phpcs:enable
		$done = 0;
		foreach ( $picked as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || ! current_user_can( 'edit_product', $id ) ) {
				continue;
			}
			GSUP_Bulk_Tidy::save_undo( $product );
			if ( $do['title'] && isset( $titles[ $id ] ) && '' !== trim( sanitize_text_field( $titles[ $id ] ) ) ) {
				$product->set_name( sanitize_text_field( $titles[ $id ] ) );
			}
			if ( $do['desc'] && isset( $descs[ $id ] ) && '' !== trim( $descs[ $id ] ) ) {
				$product->set_description( wp_kses_post( $descs[ $id ] ) );
			}
			if ( $do['short'] && isset( $shorts[ $id ] ) && '' !== trim( $shorts[ $id ] ) ) {
				$product->set_short_description( wp_kses_post( wpautop( sanitize_textarea_field( $shorts[ $id ] ) ) ) );
			}
			$product->save();
			++$done;
		}
		gsup_flash( 'Rewrote ' . (int) $done . ' product(s). Each can be put back with “Undo text changes” on its Supplier tab.' );
		wp_safe_redirect( admin_url( 'edit.php?post_type=product' ) );
		exit;
	}
}

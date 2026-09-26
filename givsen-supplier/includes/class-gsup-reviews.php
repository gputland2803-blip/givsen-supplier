<?php
/**
 * AliExpress reviews → WooCommerce product reviews.
 *
 * The Chrome extension reads a listing's reviews while you're on its AliExpress page (AliExpress's API doesn't offer
 * them) and sends them here. They're added to every store product linked to that AliExpress product, as normal
 * WooCommerce reviews with star ratings — pending your approval unless you choose to publish them straight away.
 * The same review is never added twice. Review photos are shown under the review text.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Reviews {

	const MAX_PER_REQUEST = 50;
	const C_ID            = '_gsup_ae_review_id';
	const C_IMAGES        = '_gsup_review_images';

	public static function init() {
		add_action( 'woocommerce_review_after_comment_text', array( __CLASS__, 'show_images' ) );
	}

	public static function publish_now() {
		return 'yes' === get_option( 'gsup_reviews_publish', 'no' );
	}

	/**
	 * Add reviews from the extension.
	 *
	 * @param string $ae_product_id
	 * @param array  $reviews [{id, name, country, rating 1-5, text, date, images[]}]
	 * @return array|WP_Error {added, skipped, products: [{id, name, edit_url}]}
	 */
	public static function import( $ae_product_id, array $reviews ) {
		$ae_product_id = gsup_parse_product_id( $ae_product_id );
		if ( '' === $ae_product_id ) {
			return new WP_Error( 'gsup_no_product', 'No AliExpress product ID.' );
		}
		$products = array();
		foreach ( gsup_find_linked( $ae_product_id ) as $id ) {
			$post = get_post( $id );
			if ( $post && 'product' === $post->post_type ) {
				$products[] = (int) $id;
			}
		}
		if ( ! $products ) {
			return new WP_Error( 'gsup_not_in_store', 'This AliExpress product isn’t linked to a product in your store yet — add or link it first, then import its reviews.' );
		}
		$added   = 0;
		$skipped = 0;
		foreach ( array_slice( $reviews, 0, self::MAX_PER_REQUEST ) as $r ) {
			$r = self::clean( $r );
			if ( ! $r ) {
				++$skipped;
				continue;
			}
			foreach ( $products as $pid ) {
				if ( self::exists( $pid, $r['id'] ) ) {
					++$skipped;
					continue;
				}
				$comment_id = wp_insert_comment(
					array(
						'comment_post_ID'      => $pid,
						'comment_author'       => $r['name'] . ( '' !== $r['country'] ? ' (' . $r['country'] . ')' : '' ),
						'comment_author_email' => '',
						'comment_author_url'   => '',
						'comment_content'      => $r['text'],
						'comment_type'         => 'review',
						'comment_parent'       => 0,
						'user_id'              => 0,
						'comment_approved'     => self::publish_now() ? 1 : 0,
						'comment_date'         => wp_date( 'Y-m-d H:i:s', $r['date'] ),
						'comment_date_gmt'     => gmdate( 'Y-m-d H:i:s', $r['date'] ),
						'comment_agent'        => 'Givsen Supplier (AliExpress review)',
					)
				);
				if ( ! $comment_id ) {
					++$skipped;
					continue;
				}
				add_comment_meta( $comment_id, 'rating', $r['rating'], true );
				add_comment_meta( $comment_id, 'verified', 0, true );
				add_comment_meta( $comment_id, self::C_ID, $r['id'], true );
				if ( $r['images'] ) {
					add_comment_meta( $comment_id, self::C_IMAGES, $r['images'], true );
				}
				++$added;
			}
		}
		foreach ( $products as $pid ) {
			if ( class_exists( 'WC_Comments' ) ) {
				WC_Comments::clear_transients( $pid ); // Recounts the product's rating.
			}
		}
		return array(
			'added'    => $added,
			'skipped'  => $skipped,
			'pending'  => ! self::publish_now(),
			'products' => array_map(
				function ( $id ) {
					return array(
						'id'       => $id,
						'name'     => get_the_title( $id ),
						'edit_url' => admin_url( 'edit.php?post_type=product&page=product-reviews' ),
					);
				},
				$products
			),
		);
	}

	/** Validate one review from the extension. */
	private static function clean( $r ) {
		if ( ! is_array( $r ) ) {
			return null;
		}
		$text   = trim( sanitize_textarea_field( isset( $r['text'] ) ? (string) $r['text'] : '' ) );
		$rating = isset( $r['rating'] ) ? (int) round( (float) $r['rating'] ) : 0;
		$id     = preg_replace( '/[^A-Za-z0-9_-]/', '', isset( $r['id'] ) ? (string) $r['id'] : '' );
		if ( $rating < 1 || $rating > 5 ) {
			return null;
		}
		if ( '' === $id ) {
			$id = 'h' . substr( md5( $text . '|' . ( $r['name'] ?? '' ) . '|' . ( $r['date'] ?? '' ) ), 0, 20 );
		}
		$date = isset( $r['date'] ) ? ( is_numeric( $r['date'] ) ? (int) $r['date'] : (int) strtotime( (string) $r['date'] ) ) : 0;
		if ( $date > 9999999999 ) {
			$date = (int) ( $date / 1000 );
		}
		$date   = $date > 0 && $date <= time() ? $date : time();
		$images = array();
		foreach ( array_slice( isset( $r['images'] ) && is_array( $r['images'] ) ? $r['images'] : array(), 0, 6 ) as $u ) {
			$u = (string) $u;
			if ( 0 === strpos( $u, '//' ) ) {
				$u = 'https:' . $u;
			}
			$u = esc_url_raw( $u, array( 'https' ) );
			if ( '' !== $u && preg_match( '#^https://[^/]*(alicdn|aliexpress)[^/]*/#i', $u ) ) {
				$images[] = $u;
			}
		}
		if ( '' === $text && ! $images ) {
			$text = str_repeat( '★', $rating );
		}
		$name = trim( sanitize_text_field( isset( $r['name'] ) ? (string) $r['name'] : '' ) );
		return array(
			'id'      => mb_substr( $id, 0, 64 ),
			'name'    => '' !== $name ? mb_substr( $name, 0, 60 ) : 'AliExpress buyer',
			'country' => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', isset( $r['country'] ) ? (string) $r['country'] : '' ), 0, 2 ) ),
			'rating'  => $rating,
			'text'    => mb_substr( $text, 0, 3000 ),
			'date'    => $date,
			'images'  => $images,
		);
	}

	private static function exists( $product_id, $review_id ) {
		return (bool) get_comments(
			array(
				'post_id'    => $product_id,
				'status'     => 'all',
				'type'       => 'review',
				'count'      => true,
				'meta_key'   => self::C_ID, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $review_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
	}

	/** Review photos under the review text. */
	public static function show_images( $comment ) {
		$images = get_comment_meta( $comment->comment_ID, self::C_IMAGES, true );
		if ( ! is_array( $images ) || ! $images ) {
			return;
		}
		echo '<div class="gsup-review-photos" style="display:flex;flex-wrap:wrap;gap:6px;margin:6px 0">';
		foreach ( $images as $u ) {
			echo '<a href="' . esc_url( $u ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( preg_replace( '/(\.(jpe?g|png|webp))$/i', '$1_220x220.$2', $u ) ) . '" alt="" loading="lazy" referrerpolicy="no-referrer" style="width:72px;height:72px;object-fit:cover;border-radius:4px"></a>';
		}
		echo '</div>';
	}
}

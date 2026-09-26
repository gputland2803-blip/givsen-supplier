<?php
/**
 * Tidies AliExpress wording into something that reads well in a WooCommerce store.
 *
 * - Titles: drops sales filler ("2025 New Hot Sale Free Shipping"), piece counts, repeated words and shouting.
 * - Option names/values: "1PC-RED" → "Red", "colour" → "Colour".
 * - Descriptions: removes AliExpress styling, fonts, fixed sizes, links back to AliExpress and empty blocks,
 *   and lazy-loads images, leaving plain HTML that follows your theme.
 * - Specifications: AliExpress's item specifics (Material, Size…), without the noise (Origin, CN, Brand Name: None…),
 *   ready for WooCommerce's "Additional information" tab.
 *
 * Supplier links are stored by ID, so any of this can be changed later without breaking anything.
 */

defined( 'ABSPATH' ) || exit;

class GSUP_Tidy {

	/**
	 * Sales phrases removed wherever they appear (filter gsup_tidy_title_phrases).
	 * Two words or more, so they can't be part of a real product name.
	 */
	private static function phrases() {
		return (array) apply_filters(
			'gsup_tidy_title_phrases',
			array(
				'new arrival', 'new arrivals', 'hot sale', 'hot sales', 'hot selling', 'best selling', 'best seller',
				'free shipping', 'fast shipping', 'dropshipping', 'drop shipping', 'factory price', 'factory direct',
				'high quality', 'top quality', 'good quality', 'limited time', 'in stock', 'brand new', 'dropship',
			)
		);
	}

	/**
	 * Single words removed only from the start of a title, where AliExpress sellers stack them
	 * ("2025 New Hot Sale Women's Dress") — never from the middle ("Hot Water Bottle").
	 */
	private static function leading_words() {
		return (array) apply_filters(
			'gsup_tidy_title_words',
			array( 'new', 'hot', 'sale', 'newest', 'latest', 'cheap', 'original', 'genuine', 'official', 'wholesale', 'ins', 'fashion', 'trendy', 'popular', 'luxury' )
		);
	}

	public static function title( $title ) {
		$t = html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES );
		$t = preg_replace( '/[【】\[\]{}]+/u', ' ', $t );
		$phrases = self::phrases();
		usort(
			$phrases,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		foreach ( $phrases as $w ) {
			$t = preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( $w, '/' ) . '(?![\p{L}\p{N}])/iu', ' ¤ ', $t );
		}
		// A leading run of two or more fillers — years ("2025"), piece counts ("1PC"), filler words, phrases —
		// is seller padding. A single one may be part of the name ("Hot Water Bottle", "2000 Lumen").
		$lead  = array_map( 'strtolower', self::leading_words() );
		$words = preg_split( '/\s+/', trim( $t ), -1, PREG_SPLIT_NO_EMPTY );
		$run   = 0;
		foreach ( $words as $word ) {
			$w = strtolower( trim( $word, ',.;:-/|+&!' ) );
			if ( '' === $w || '¤' === $w || in_array( $w, $lead, true ) || preg_match( '/^(19|20)\d{2}$/', $w ) || preg_match( '/^\d+(pcs?|pieces?)$/', $w ) ) {
				++$run;
				continue;
			}
			break;
		}
		$fillers = count( array_filter( array_slice( $words, 0, $run ), function ( $w ) {
			return '' !== trim( $w, ',.;:-/|+&!' );
		} ) );
		if ( $fillers >= 2 && count( $words ) - $run >= 2 ) {
			$words = array_slice( $words, $run );
		}
		$t = str_replace( '¤', ' ', implode( ' ', $words ) );
		// Piece counts tacked on at the end ("… Gift Set 3pcs").
		$t = preg_replace( '/[\s,\/-]+\d+\s*(pcs?|pieces?)\s*$/i', '', $t );
		// Drop repeated words, keeping the first.
		$seen = array();
		$out  = array();
		foreach ( preg_split( '/\s+/', $t, -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
			$key = strtolower( trim( $word, ',.;:-/|+&' ) );
			if ( '' === $key ) {
				// A lone separator ("/", "-"): keep one between words.
				if ( $out && '' !== trim( end( $out ), ',.;:-/|+&' ) ) {
					$out[] = $word;
				}
				continue;
			}
			if ( isset( $seen[ $key ] ) && strlen( $key ) > 2 ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $word;
		}
		$t = implode( ' ', $out );
		$t = preg_replace( '/\s*([,;|\/+&-])\s*(?=[,;|\/+&-]|$)/', '', $t ); // Dangling separators.
		$t = trim( preg_replace( '/\s+/', ' ', $t ), " \t,.;:-/|+&" );
		$t = self::case_fix( $t );
		$max = (int) apply_filters( 'gsup_tidy_title_length', 90 );
		if ( mb_strlen( $t ) > $max ) {
			$cut = mb_substr( $t, 0, $max );
			$sp  = mb_strrpos( $cut, ' ' );
			$t   = rtrim( $sp > 40 ? mb_substr( $cut, 0, $sp ) : $cut, " ,.;:-/|+&" );
		}
		return '' !== $t ? $t : trim( (string) $title );
	}

	/** Words kept in capitals when fixing ALL-CAPS text (filter gsup_tidy_acronyms). */
	private static function keep_caps( $word ) {
		static $list = null;
		if ( null === $list ) {
			$list = array_flip( (array) apply_filters( 'gsup_tidy_acronyms', array( 'LED', 'USB', 'RGB', 'UV', 'TV', 'PC', 'DIY', 'GPS', 'HD', 'UHD', 'LCD', 'AI', 'AC', 'DC', 'UK', 'US', 'USA', 'EU', 'AU', 'NZ', 'PVC', 'ABS', 'PU', 'EVA', 'TPU', 'BPA', 'MAX', 'PRO', 'OTG', 'NFC', 'IP', 'HDMI', 'SD', 'TF', 'CPU', 'RAM', 'DJ' ) ) );
		}
		return isset( $list[ $word ] ) || preg_match( '/\d/', $word ) || preg_match( '/^(X{0,4}[SML]|\d?X{1,4}L)$/', $word );
	}

	/** "RED" / "red" → "Red"; mixed case left alone. Acronyms and sizes (LED, USB, XL) stay in capitals. */
	private static function case_fix( $t ) {
		$letters = preg_replace( '/[^\p{L}]/u', '', $t );
		if ( '' === $letters ) {
			return $t;
		}
		$upper = mb_strtoupper( $letters ) === $letters;
		$lower = mb_strtolower( $letters ) === $letters;
		if ( ! $upper && ! $lower ) {
			return $t;
		}
		$small = array( 'a', 'an', 'and', 'for', 'with', 'the', 'of', 'to', 'in', 'on', 'by', 'or', 'at' );
		$first = true;
		return preg_replace_callback(
			'/[\p{L}\p{N}\']+/u',
			function ( $m ) use ( $upper, $small, &$first ) {
				$w       = $m[0];
				$is_first = $first;
				$first   = false;
				if ( $upper && self::keep_caps( $w ) ) {
					return $w;
				}
				$lw = mb_strtolower( $w );
				if ( ! $is_first && in_array( $lw, $small, true ) ) {
					return $lw;
				}
				return mb_strtoupper( mb_substr( $lw, 0, 1 ) ) . mb_substr( $lw, 1 );
			},
			$t
		);
	}

	public static function option_name( $name ) {
		$n = trim( preg_replace( '/\s+/', ' ', (string) $name ) );
		return self::case_fix( $n );
	}

	public static function option_value( $value ) {
		$v = trim( (string) $value );
		$v = preg_replace( '/^\s*\d+\s*(pcs?|pieces?)\s*[-_:,]?\s*/i', '', $v ); // "1PC-Red" → "Red".
		$v = preg_replace( '/\s*[-_]\s*\d+\s*(pcs?|pieces?)\s*$/i', '', $v );     // "Red-1pcs" → "Red".
		$v = trim( preg_replace( '/\s+/', ' ', $v ), " \t-_,.;:" );
		$v = self::case_fix( $v );
		return '' !== $v ? $v : trim( (string) $value );
	}

	/**
	 * Seller boilerplate that gives away where it came from, or makes no sense in your store
	 * ("leave us 5-star feedback", "visit our store", "open a dispute"…). Filter gsup_tidy_boilerplate.
	 */
	public static function is_boilerplate( $text ) {
		$pattern = (string) apply_filters(
			'gsup_tidy_boilerplate',
			'/aliexpress|ali\s*express|alibaba|taobao|dropship|\b(5|five)[\s-]*stars?\b|feedback|open(ing)? a dispute|buyer protection|our (online )?store|store home|add (our |to )?(store|wish\s*list)|follow (us|our)|contact us before|leave (us )?(a )?(positive )?(review|rating)|shipping (policy|time)s?:|payment:|return policy/i'
		);
		return (bool) preg_match( $pattern, (string) $text );
	}

	/**
	 * Remove paragraphs, list items and cells whose text is seller boilerplate. Only short, self-contained
	 * blocks (no lists, tables, images or other blocks inside) are ever removed, so one stray line can't take
	 * real product wording with it.
	 */
	private static function drop_boilerplate( $h ) {
		$h = self::drop_ae_links( $h );
		for ( $i = 0; $i < 2; $i++ ) {
			$h = preg_replace_callback(
				// Innermost blocks only: the content may not contain another block, so a wrapper is never matched.
				'#<(p|li|h[1-6]|td|th|div)\b[^>]*>((?:(?!<(?:p|div|ul|ol|li|table|tr|td|th|img|h[1-6])\b).)*?)</\1>#is',
				function ( $m ) {
					$text = wp_strip_all_tags( $m[2] );
					return mb_strlen( $text ) <= 300 && self::is_boilerplate( $text ) ? '' : $m[0];
				},
				$h
			);
		}
		return $h;
	}

	/** Links to AliExpress (store pages, other listings) go, words and all — they're never product details. */
	private static function drop_ae_links( $h ) {
		return preg_replace( '#<a\b[^>]*href=["\']?[^"\'>]*(aliexpress|alicdn|alibaba)[^"\'>]*["\']?[^>]*>.*?</a>#is', '', (string) $h );
	}

	/**
	 * AliExpress description as plain text to rewrite: paragraphs, lists and bold only —
	 * no images, tables, layout or seller boilerplate.
	 */
	public static function description_text( $html ) {
		$h = (string) $html;
		$h = preg_replace( '#<(script|style|iframe|noscript|object|embed|form)\b[^>]*>.*?</\1\s*>#is', '', $h );
		$h = preg_replace( '#<!--.*?-->#s', '', $h );
		$h = preg_replace( '#<img\b[^>]*>#i', '', $h );
		$h = self::drop_ae_links( $h );
		$h = preg_replace( '#(</(p|ul|ol)>)#i', "$1\n", $h );
		$h = preg_replace( '#</t[dh]>\s*</tr>#i', "\n", $h );
		$h = preg_replace( '#</?(tr|div|section|article|table|tbody|thead|center|h[1-6])\b[^>]*>#i', "\n", $h );
		$h = preg_replace( '#</t[dh]>[ \t]*<t[dh]\b[^>]*>#i', ': ', $h ); // Cells in a row: "Material: Soy wax".
		$h = self::drop_boilerplate( $h );
		$h = wp_kses(
			$h,
			array(
				'p'      => array(),
				'br'     => array(),
				'ul'     => array(),
				'ol'     => array(),
				'li'     => array(),
				'strong' => array(),
				'b'      => array(),
				'em'     => array(),
			)
		);
		// Loose lines become paragraphs; boilerplate lines go.
		$out = array();
		foreach ( preg_split( "#\n+|<br\s*/?>\s*<br\s*/?>#i", $h ) as $chunk ) {
			$chunk = trim( preg_replace( '/(&nbsp;|\s)+/u', ' ', $chunk ) );
			$text  = trim( wp_strip_all_tags( $chunk ) );
			if ( '' === $text || ( mb_strlen( $text ) <= 300 && self::is_boilerplate( $text ) ) ) {
				continue;
			}
			$out[] = preg_match( '#^<(p|ul|ol|li)\b#i', $chunk ) ? $chunk : '<p>' . $chunk . '</p>';
		}
		$h = implode( "\n", $out );
		$h = preg_replace( '#<p>\s*</p>#', '', $h );
		return trim( force_balance_tags( $h ) );
	}

	/**
	 * Text from AliExpress's mobile description — a JSON list of modules (text and images), or plain HTML.
	 * Listings whose main description is only images often still have text here.
	 */
	public static function mobile_text( $mobile ) {
		$mobile = (string) $mobile;
		if ( '' === trim( $mobile ) ) {
			return '';
		}
		$data = json_decode( $mobile, true );
		if ( ! is_array( $data ) ) {
			return self::description_text( $mobile ); // Plain HTML.
		}
		$texts = array();
		$walk  = function ( $node ) use ( &$walk, &$texts ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			foreach ( $node as $k => $v ) {
				if ( is_string( $v ) && in_array( (string) $k, array( 'content', 'text', 'txt', 'value' ), true ) && '' !== trim( wp_strip_all_tags( $v ) ) && ! preg_match( '#^https?://#i', trim( $v ) ) ) {
					$texts[] = $v;
				} elseif ( is_array( $v ) ) {
					$walk( $v );
				}
			}
		};
		$walk( $data );
		return $texts ? self::description_text( '<p>' . implode( '</p><p>', $texts ) . '</p>' ) : '';
	}

	/**
	 * Words captured from the AliExpress page by the extension ("Overview:", "Description:", "Specifications:"
	 * sections of plain lines) → simple HTML: paragraphs, with the overview and specifications as bullet lists.
	 */
	public static function page_text_html( $text ) {
		$text = trim( str_replace( "\r", '', (string) $text ) );
		if ( '' === $text ) {
			return '';
		}
		$out = array();
		foreach ( preg_split( "/\n{2,}/", $text ) as $section ) {
			$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $section ) ) ) );
			if ( ! $lines ) {
				continue;
			}
			$label = '';
			if ( preg_match( '/^(Overview|Description|Specifications):$/', $lines[0], $m ) ) {
				$label = $m[1];
				array_shift( $lines );
			}
			$lines = array_values(
				array_filter(
					$lines,
					function ( $l ) {
						return mb_strlen( $l ) > 300 || ! self::is_boilerplate( $l );
					}
				)
			);
			if ( ! $lines ) {
				continue;
			}
			if ( 'Description' === $label || ( '' === $label && count( $lines ) < 3 ) ) {
				foreach ( $lines as $l ) {
					$out[] = '<p>' . esc_html( $l ) . '</p>';
				}
			} else {
				$li = '';
				foreach ( $lines as $l ) {
					// "Heading: text" (overview bullets, specifications) → bold heading.
					$li .= preg_match( '/^([^:]{2,60}):\s+(.+)$/u', $l, $m )
						? '<li><strong>' . esc_html( $m[1] ) . ':</strong> ' . esc_html( $m[2] ) . '</li>'
						: '<li>' . esc_html( $l ) . '</li>';
				}
				$out[] = '<ul>' . $li . '</ul>';
			}
		}
		return implode( "\n", $out );
	}

	/**
	 * A plain, factual starting description when AliExpress gives no usable wording (image-only listings):
	 * the product name, its specifics and the options on offer — ready to rewrite, never invented.
	 *
	 * @param array $specs   Name => value (from specs()).
	 * @param array $options Option name => values.
	 */
	public static function starter_description( $title, array $specs, array $options ) {
		$li = '';
		foreach ( $options as $name => $values ) {
			if ( $values ) {
				$li .= '<li><strong>' . esc_html( $name ) . ':</strong> ' . esc_html( implode( ', ', $values ) ) . '</li>';
			}
		}
		foreach ( $specs as $name => $value ) {
			$li .= '<li><strong>' . esc_html( $name ) . ':</strong> ' . esc_html( $value ) . '</li>';
		}
		$out = '<p>' . esc_html( rtrim( (string) $title, '. ' ) ) . '.</p>';
		return $li ? $out . "\n<ul>" . $li . '</ul>' : $out;
	}

	/** AliExpress description → clean HTML that follows the store's theme. */
	public static function description( $html, $title = '' ) {
		$h = (string) $html;
		$h = preg_replace( '#<(script|style|iframe|noscript|object|embed|form)\b[^>]*>.*?</\1\s*>#is', '', $h );
		$h = preg_replace( '#<!--.*?-->#s', '', $h );
		// Presentation attributes: let the theme decide.
		$h = preg_replace( '#\s(?:style|class|id|width|height|align|valign|bgcolor|border|cellpadding|cellspacing|face|size|color|data-[\w-]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $h );
		// Unwrap presentational tags.
		$h = preg_replace( '#</?(font|span|center|u|o:p)\b[^>]*>#i', '', $h );
		// Links back to AliExpress (store pages, other items) go, words and all.
		$h = self::drop_ae_links( $h );
		// Images: protocol-relative → https, lazy-loaded, with alt text.
		$alt = esc_attr( $title );
		$h   = preg_replace_callback(
			'#<img\b([^>]*)>#i',
			function ( $m ) use ( $alt ) {
				$attrs = preg_replace( '#\s(?:alt|loading)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $m[1] );
				$attrs = preg_replace( '#src=(["\']?)//#i', 'src=$1https://', $attrs );
				return '<img' . rtrim( $attrs, ' /' ) . ' alt="' . $alt . '" loading="lazy">';
			},
			$h
		);
		$h = self::drop_boilerplate( $h );
		// Empty blocks left behind.
		for ( $i = 0; $i < 3; $i++ ) {
			$h = preg_replace( '#<(p|div|strong|b|em|i|h\d|li|ul|td|tr)\b[^>]*>(\s|&nbsp;|<br\s*/?>)*</\1>#i', '', $h );
		}
		$h = preg_replace( '#(<br\s*/?>\s*){3,}#i', '<br><br>', $h );
		$h = preg_replace( "#\n{3,}#", "\n\n", $h );
		return trim( wp_kses_post( $h ) );
	}

	/**
	 * Item specifics worth showing to customers.
	 *
	 * @param array $specs [[name, value], …] from GSUP_AliExpress::get_product()
	 * @return array<string,string> name => value
	 */
	public static function specs( array $specs, $limit = 12 ) {
		$skip = (array) apply_filters( 'gsup_tidy_skip_specs', array( 'origin', 'cn', 'brand name', 'certification', 'is_customized', 'is customized', 'choice', 'semi_choice', 'high-concerned chemical', 'model number', 'item number', 'place of origin', 'sku', 'hign-concerned chemical', 'is smart device', 'feature 1', 'wholesale' ) );
		$none = array( 'none', 'no', 'null', 'n/a', '-', 'nonebrand', 'no brand', 'other', 'others', 'oem' );
		$out  = array();
		foreach ( $specs as $s ) {
			$name  = trim( (string) ( $s[0] ?? '' ) );
			$value = trim( (string) ( $s[1] ?? '' ) );
			if ( '' === $name || '' === $value || in_array( strtolower( $name ), $skip, true ) || in_array( strtolower( $value ), $none, true ) ) {
				continue;
			}
			$name = self::option_name( str_replace( '_', ' ', $name ) );
			if ( isset( $out[ $name ] ) ) {
				if ( false === stripos( $out[ $name ], $value ) ) {
					$out[ $name ] .= ', ' . $value;
				}
				continue;
			}
			$out[ $name ] = $value;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/** Short description: the first few specifics as a list. */
	public static function short_description( array $specs, $count = 5 ) {
		if ( ! $specs ) {
			return '';
		}
		$li = '';
		foreach ( array_slice( $specs, 0, $count, true ) as $name => $value ) {
			$li .= '<li><strong>' . esc_html( $name ) . ':</strong> ' . esc_html( $value ) . '</li>';
		}
		return '<ul>' . $li . '</ul>';
	}
}

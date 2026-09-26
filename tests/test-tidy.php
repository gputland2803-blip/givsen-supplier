<?php
// Title, option, description and specifics tidy-up (GSUP_Tidy) and description photo helpers (GSUP_Creator).
define( 'ABSPATH', '/' );
function apply_filters( $h, $v ) { return $v; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( $s ) ); }
function esc_attr( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( $s ); }
function wp_kses_post( $s ) { return $s; }
function wp_kses( $s, $allowed ) { $t = ''; foreach ( array_keys( $allowed ) as $a ) { $t .= "<$a>"; } return strip_tags( $s, $t ); }
function force_balance_tags( $s ) { return $s; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
require __DIR__ . '/../givsen-supplier/includes/class-gsup-tidy.php';
require __DIR__ . '/../givsen-supplier/includes/class-gsup-creator.php';
$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }

$titles = array(
	'2025 New Hot Sale Women Summer Dress Casual Loose Dress Free Shipping 1PC' => 'Women Summer Dress Casual Loose',
	'LED STRIP LIGHTS RGB 5050 USB BLUETOOTH APP CONTROL FOR ROOM DECORATION'   => 'LED Strip Lights RGB 5050 USB Bluetooth App Control for Room Decoration',
	'Scented Candle - Lavender / Vanilla Soy Wax Candles Gift Set 3pcs'         => 'Scented Candle - Lavender / Vanilla Soy Wax Candles Gift Set',
	'Hot Water Bottle Rubber 2L With Knitted Cover'                             => 'Hot Water Bottle Rubber 2L With Knitted Cover',
	'2000 Lumen LED Torch Rechargeable'                                         => '2000 Lumen LED Torch Rechargeable',
	'New Balance Style Running Shoes'                                           => 'New Balance Style Running Shoes',
	'2025 NEW Hot Sale 2PCS Silicone Baking Mat Free Shipping'                  => 'Silicone Baking Mat',
);
foreach ( $titles as $in => $want ) {
	$got = GSUP_Tidy::title( $in );
	ok( $got === $want, "title: $got" );
}
foreach ( array( '1PC-RED' => 'Red', 'Red-1pcs' => 'Red', 'BLACK' => 'Black', 'sky blue' => 'Sky Blue', 'XL' => 'XL', '2 pcs white' => 'White' ) as $in => $want ) {
	ok( GSUP_Tidy::option_value( $in ) === $want, "option value $in → $want" );
}

$ae = '<div style="width:750px"><p><span style="font-size:14px"><strong>Soy wax candle</strong> with lavender oil.</span></p><p>Burn time: 40 hours</p><img src="//ae01.alicdn.com/kf/a.jpg"><table><tr><td>Material</td><td>Soy wax</td></tr><tr><td>Weight</td><td>200g</td></tr></table><p>Welcome to our store! Please leave 5 stars feedback.</p><p>If you have any problem please contact us before opening a dispute.</p><ul><li>Hand poured</li><li>Cotton wick</li></ul><a href="https://www.aliexpress.com/store/1">Visit AliExpress store</a></div>';
$text = GSUP_Tidy::description_text( $ae );
ok( false !== strpos( $text, '<strong>Soy wax candle</strong> with lavender oil.' ), 'text: wording kept' );
ok( false !== strpos( $text, '<p>Material: Soy wax</p>' ) && false !== strpos( $text, '<p>Weight: 200g</p>' ), 'text: table rows kept one per line' );
ok( false !== strpos( $text, '<li>Cotton wick</li>' ), 'text: lists kept' );
ok( false === stripos( $text, 'feedback' ) && false === stripos( $text, 'dispute' ) && false === stripos( $text, 'aliexpress' ) && false === strpos( $text, '<img' ) && false === strpos( $text, 'style=' ), 'text: no seller notes, AliExpress, images or styling' );
$clean = GSUP_Tidy::description( $ae, 'Candle' );
ok( false !== strpos( $clean, 'src="https://ae01.alicdn.com/kf/a.jpg" alt="Candle" loading="lazy"' ) && false === strpos( $clean, 'style=' ), 'clean: images kept (https, lazy, alt), styling removed' );
ok( false === stripos( $clean, 'feedback' ) && false === stripos( $clean, 'href=' ), 'clean: seller notes and AliExpress links removed' );

$specs = GSUP_Tidy::specs( array( array( 'Brand Name', 'NONE' ), array( 'Origin', 'Mainland China' ), array( 'Material', 'Ceramic' ), array( 'Capacity', '350ml' ), array( 'is_customized', 'Yes' ), array( 'Material', 'Glaze' ) ) );
ok( $specs === array( 'Material' => 'Ceramic, Glaze', 'Capacity' => '350ml' ), 'specifics: noise dropped, repeats merged' );

$imgs = GSUP_Creator::description_images( '<p><img src="//ae01.alicdn.com/kf/A1.jpg"><img src="https://ae01.alicdn.com/kf/banner.gif"><img src="https://evil.example/x.jpg"><img src="//ae01.alicdn.com/kf/A1.jpg"><img src=https://ae-pic-a1.aliexpress-media.com/kf/B2.png></p>' );
ok( $imgs === array( 'https://ae01.alicdn.com/kf/A1.jpg', 'https://ae-pic-a1.aliexpress-media.com/kf/B2.png' ), 'description photos: AliExpress hosts only, no GIFs, no repeats' );
$k = new ReflectionMethod( 'GSUP_Creator', 'image_key' );
$k->setAccessible( true );
ok( $k->invoke( null, 'https://ae01.alicdn.com/kf/A1.jpg_640x640.jpg' ) === $k->invoke( null, 'https://ae01.alicdn.com/kf/A1.jpg' ), 'same photo recognised at another size' );

echo $fail ? "$fail FAILED\n" : "ALL PASSED\n";
exit( $fail ? 1 : 0 );

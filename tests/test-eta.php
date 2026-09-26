<?php
// Delivery estimate wording on product pages (GSUP_Eta).
define( 'ABSPATH', '/' );
$GLOBALS['opts'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function apply_filters( $h, $v ) { return $v; }
function wp_timezone() { return new DateTimeZone( 'Australia/Sydney' ); }
function wp_date( $f, $t ) { return ( new DateTimeImmutable( '@' . $t ) )->setTimezone( wp_timezone() )->format( $f ); }
require __DIR__ . '/../givsen-supplier/includes/class-gsup-eta.php';
$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }

$GLOBALS['opts'] = array( 'gsup_eta_format' => 'days', 'gsup_eta_processing' => 1, 'gsup_eta_business' => 'yes' );
ok( GSUP_Eta::text( array( 2, 5 ) ) === 'Delivered in 3–6 business days', 'days wording with processing time: ' . GSUP_Eta::text( array( 2, 5 ) ) );
$GLOBALS['opts']['gsup_eta_business'] = 'no';
ok( GSUP_Eta::text( array( 4, 4 ) ) === 'Delivered in 5 days', 'single figure: ' . GSUP_Eta::text( array( 4, 4 ) ) );

$GLOBALS['opts'] = array( 'gsup_eta_format' => 'dates', 'gsup_eta_processing' => 0, 'gsup_eta_business' => 'yes' );
$t = GSUP_Eta::text( array( 1, 5 ) );
ok( 0 === strpos( $t, 'Estimated delivery: ' ) && false !== strpos( $t, ' – ' ), 'dates wording: ' . $t );
// Business days never land on a weekend.
preg_match_all( '/(Mon|Tue|Wed|Thu|Fri|Sat|Sun) \d+ \w+/', $t, $m );
ok( $m[1] && ! array_intersect( $m[1], array( 'Sat', 'Sun' ) ), 'no weekend dates when counting business days' );

echo $fail ? "$fail FAILED\n" : "ALL PASSED\n";
exit( $fail ? 1 : 0 );

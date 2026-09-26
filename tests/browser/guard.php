<?php
// Prints the page-cache country check for a country (used by guard.test.js).
define( 'ABSPATH', '/' );
function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_SLASHES ); }
require __DIR__ . '/../../givsen-supplier/includes/class-gsup-visitor.php';
echo GSUP_Visitor::guard_js( $argv[1], '/wp-json/givsen-supplier/v1/country' );

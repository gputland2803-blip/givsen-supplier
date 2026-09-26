<?php
// Rewrite with AI (GSUP_AI): the request sent to Claude, and reading its replies.
define( 'ABSPATH', '/' );
$GLOBALS['opts'] = array( 'gsup_ai_key' => 'sk-ant-test', 'gsup_ai_voice' => 'Warm and friendly.', 'gsup_ai_title_max' => 60 );
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function get_bloginfo() { return 'Givsen'; }
function wp_specialchars_decode( $s ) { return $s; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( $s ) ); }
function wp_kses( $s, $allowed ) { $t = ''; foreach ( array_keys( $allowed ) as $a ) { $t .= "<$a>"; } return strip_tags( preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $s ), $t ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error { public $c, $m; function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; } function get_error_code() { return $this->c; } function get_error_message() { return $this->m; } }
function wc_attribute_label( $n ) { return $n; }
function wc_get_product_terms( $id, $tax ) { return 'product_cat' === $tax ? array( 'Candles' ) : array(); }
class Attr { function get_name() { return 'Scent'; } function is_taxonomy() { return false; } function get_options() { return array( 'Lavender', 'Vanilla' ); } }
class WC_Product { function get_id() { return 7; } function get_name() { return '2025 Soy Candle Lavender'; } function get_description() { return '<p>Soy wax candle.</p><p>Burn time 40 hours.</p>'; } function get_short_description() { return ''; } function get_attributes() { return array( new Attr() ); } }
$GLOBALS['reply'] = null; $GLOBALS['sent'] = null;
function wp_remote_post( $url, $args ) { $GLOBALS['sent'] = array( $url, $args ); return $GLOBALS['reply']; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
require __DIR__ . '/../givsen-supplier/includes/class-gsup-ai.php';
$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }
function reply( $code, $body ) { $GLOBALS['reply'] = array( 'code' => $code, 'body' => json_encode( $body ) ); }

$good = array(
	'title'             => 'Lavender Soy Candle',
	'description_html'  => '<p>A calming <strong>soy wax</strong> candle.</p><script>alert(1)</script><ul><li>Burn time: 40 hours</li></ul><a href="https://x">link</a><img src="x">',
	'short_description' => 'A calming lavender candle.',
);
reply( 200, array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => json_encode( $good ) ) ), 'usage' => array( 'input_tokens' => 1000, 'output_tokens' => 400 ) ) );
$r = GSUP_AI::rewrite( new WC_Product() );
ok( is_array( $r ) && 'Lavender Soy Candle' === $r['title'] && 'A calming lavender candle.' === $r['short'], 'reply read' );
ok( is_array( $r ) && false === strpos( $r['description'], '<script' ) && false === strpos( $r['description'], '<a' ) && false === strpos( $r['description'], '<img' ) && false !== strpos( $r['description'], '<li>Burn time: 40 hours</li>' ), 'description limited to p/ul/li/strong' );
ok( is_array( $r ) && abs( $r['cost'] - 0.003 ) < 0.00001, 'cost worked out from usage (Haiku 4.5: 1000 in + 400 out = $0.003)' );

list( $url, $args ) = $GLOBALS['sent'];
$body = json_decode( $args['body'], true );
ok( 'https://api.anthropic.com/v1/messages' === $url && 'sk-ant-test' === $args['headers']['x-api-key'] && '2023-06-01' === $args['headers']['anthropic-version'], 'endpoint and headers' );
ok( 'claude-haiku-4-5' === $body['model'] && 'json_schema' === $body['output_config']['format']['type'] && false === $body['output_config']['format']['schema']['additionalProperties'], 'cheapest model and structured JSON output' );
ok( false !== strpos( $body['system'], 'Never mention AliExpress' ) && false !== strpos( $body['system'], 'at most 60 characters' ) && false !== strpos( $body['system'], 'Warm and friendly.' ) && false !== strpos( $body['system'], 'Never invent' ), 'prompt: voice, title length, no sourcing, no invented facts' );
ok( false !== strpos( $body['messages'][0]['content'], 'Scent: Lavender, Vanilla' ) && false !== strpos( $body['messages'][0]['content'], 'Candles' ) && false !== strpos( $body['messages'][0]['content'], 'Burn time 40 hours' ), 'product facts sent (title, description, options, category)' );

reply( 200, array( 'stop_reason' => 'refusal', 'content' => array() ) );
ok( is_wp_error( GSUP_AI::rewrite( new WC_Product() ) ), 'refusal handled' );
reply( 200, array( 'stop_reason' => 'max_tokens', 'content' => array( array( 'type' => 'text', 'text' => '{"title":"Lav' ) ) ) );
ok( is_wp_error( GSUP_AI::rewrite( new WC_Product() ) ), 'cut-off reply handled' );
reply( 401, array( 'type' => 'error', 'error' => array( 'message' => 'invalid x-api-key' ) ) );
$e = GSUP_AI::rewrite( new WC_Product() );
ok( is_wp_error( $e ) && false !== strpos( $e->get_error_message(), 'API key' ), 'bad key explained: ' . ( is_wp_error( $e ) ? $e->get_error_message() : '' ) );
reply( 429, array( 'type' => 'error', 'error' => array( 'message' => 'rate limited' ) ) );
$e = GSUP_AI::rewrite( new WC_Product() );
ok( is_wp_error( $e ) && false !== strpos( $e->get_error_message(), 'wait a minute' ), 'rate limit explained' );
$GLOBALS['opts']['gsup_ai_key'] = '';
ok( is_wp_error( GSUP_AI::rewrite( new WC_Product() ) ), 'no key: asks for one' );

echo $fail ? "$fail FAILED\n" : "ALL PASSED\n";
exit( $fail ? 1 : 0 );

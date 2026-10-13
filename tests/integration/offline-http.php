<?php
/** Test-only HTTP fixtures. Installed into the temporary WASM site as an MU-plugin. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['lingora_fixture_requests'] = array();
$GLOBALS['lingora_fixture_responses'] = array();

/** Queue an exact HTTP response (or WP_Error) for a regression test. */
function lingora_fixture_push( $response ): void {
	$GLOBALS['lingora_fixture_responses'][] = $response;
}

function lingora_fixture_json( array $body, int $status = 200 ): array {
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode( $body ),
		'response' => array( 'code' => $status, 'message' => 'Offline fixture' ),
		'cookies'  => array(),
	);
}

add_filter( 'pre_http_request', static function ( $preempt, $args, $url ) {
	// Allow regression suites to provide their own earlier fixtures.
	if ( false !== $preempt ) {
		return $preempt;
	}
	$GLOBALS['lingora_fixture_requests'][] = array( 'url' => $url, 'args' => $args );
	if ( $GLOBALS['lingora_fixture_responses'] ) {
		return array_shift( $GLOBALS['lingora_fixture_responses'] );
	}
	if ( 'fixtures.lingora.invalid' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
		return new WP_Error( 'lingora_offline', 'External HTTP is disabled in the Lingora test sandbox.' );
	}
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( str_ends_with( $path, '/models' ) ) {
		return lingora_fixture_json( array( 'data' => array( array( 'id' => 'lingora-fixture-model' ) ) ) );
	}
	$body = json_decode( (string) ( $args['body'] ?? '{}' ), true );
	$messages = $body['messages'] ?? array();
	$text = '';
	$system = (string) ( $body['system'] ?? '' );
	foreach ( $messages as $message ) {
		if ( 'user' === ( $message['role'] ?? '' ) ) {
			$text = (string) $message['content'];
		} elseif ( 'system' === ( $message['role'] ?? '' ) ) {
			$system = (string) $message['content'];
		}
	}
	$translated = '[fixture] ' . $text;
	if ( str_contains( $system, 'JSON array' ) ) {
		$translated = wp_json_encode( array_map( static function ( $line ) {
			return '[fixture] ' . preg_replace( '/^\d+\.\s*/', '', $line );
		}, explode( "\n", $text ) ) );
	}
	return lingora_fixture_json( str_ends_with( $path, '/messages' )
		? array( 'content' => array( array( 'type' => 'text', 'text' => $translated ) ) )
		: array( 'choices' => array( array( 'message' => array( 'content' => $translated ) ) ) ) );
}, PHP_INT_MAX, 3 );

add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'automatic_updater_disabled', '__return_true' );

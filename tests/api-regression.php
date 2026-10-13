<?php
require '/wordpress/wp-load.php';
$checks = array();
function lingora_api_check( bool $pass, string $label ): void {
	$GLOBALS['checks'][] = array( 'check' => $label, 'pass' => $pass );
	if ( ! $pass ) { echo "FAIL: $label\n"; }
}
function lingora_api_call( string $method, string $path, ?array $body = null ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/lingora/v1/' . $path );
	if ( null !== $body ) { $request->set_header( 'content-type', 'application/json' ); $request->set_body( wp_json_encode( (object) $body ) ); }
	return rest_do_request( $request );
}
$options = lingora()->options;
$options->update( array( 'auto_translate' => false, 'ai_consent' => false, 'translate_slugs' => true ) );
$options->save_languages( array(
	array( 'code' => 'en', 'name' => 'English', 'native' => 'English', 'locale' => 'en_US', 'enabled' => true, 'is_default' => true ),
	array( 'code' => 'de', 'name' => 'German', 'native' => 'Deutsch', 'locale' => 'de_DE', 'enabled' => true, 'is_default' => false ),
) );
wp_set_current_user( 0 );
lingora_api_check( 401 === lingora_api_call( 'GET', 'bootstrap' )->get_status(), 'anonymous API access denied' );
$subscriber = wp_insert_user( array( 'user_login' => 'api-fixture-subscriber', 'user_pass' => 'sandbox-only', 'role' => 'subscriber' ) );
wp_set_current_user( $subscriber );
lingora_api_check( 403 === lingora_api_call( 'POST', 'settings', array( 'auto_translate' => true ) )->get_status(), 'subscriber writes denied' );
wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
$before = $options->all();
$response = lingora_api_call( 'POST', 'settings', array( 'url_mode' => 'invalid', 'ai_consent' => true, 'ai' => array( 'model' => 'changed' ) ) );
lingora_api_check( 400 === $response->get_status() && $before === $options->all(), 'invalid settings have no partial writes' );
foreach ( array( array( 'ai_consent' => 'false' ), array( 'on_fly_limit' => array( 1 ) ), array( 'url_mode' => array() ), array( 'exclude_selectors' => 'body .private' ) ) as $invalid ) {
	lingora_api_check( 400 === lingora_api_call( 'POST', 'settings', $invalid )->get_status(), 'invalid typed setting rejected ' . wp_json_encode( $invalid ) );
}
$ai = array( 'provider_id' => 'custom', 'auth_mode' => 'apiKey', 'api_key' => 'test-secret-123456', 'project_key' => '', 'base_url' => 'https://fixtures.lingora.invalid/v1', 'model' => 'fixture-model' );
lingora_api_check( 200 === lingora_api_call( 'POST', 'settings', array( 'ai_consent' => false, 'ai' => $ai ) )->get_status(), 'connection can be saved without consent' );
$GLOBALS['lingora_fixture_requests'] = array();
$response = lingora_api_call( 'POST', 'translate', array( 'text' => 'Hello', 'language' => 'de' ) );
lingora_api_check( 400 === $response->get_status() && ! $GLOBALS['lingora_fixture_requests'], 'consent off sends no AI request' );
$response = lingora_api_call( 'POST', 'models', array( 'ai_consent' => true, 'ai' => $ai ) );
lingora_api_check( 200 === $response->get_status() && true === $options->get( 'ai_consent' ), 'models applies explicit consent atomically' );
$bootstrap = lingora_api_call( 'GET', 'bootstrap' )->get_data();
lingora_api_check( ! str_contains( wp_json_encode( $bootstrap ), 'test-secret-123456' ), 'bootstrap masks full credentials' );
lingora_api_call( 'POST', 'settings', array( 'ai' => array( 'api_key' => $bootstrap['settings']['ai']['api_key'] ) ) );
lingora_api_check( 'test-secret-123456' === $options->ai()['api_key'], 'masked key roundtrip preserves same connection' );
lingora_api_call( 'POST', 'settings', array( 'ai' => array( 'base_url' => 'https://another.lingora.invalid/v1', 'api_key' => $bootstrap['settings']['ai']['api_key'] ) ) );
lingora_api_check( '' === $options->ai()['api_key'] && false === $options->get( 'ai_consent' ), 'new endpoint clears old masked key and consent' );
lingora_api_call( 'POST', 'settings', array( 'ai_consent' => true, 'ai' => $ai ) );
foreach ( array( 'http://example.com', 'https://localhost', 'https://127.0.0.1', 'https://10.0.0.1', 'https://user:pass@example.com', 'https://example.com/?token=secret', 'https://example.com/#frag', 'https://example.com:8443' ) as $url ) {
	lingora_api_check( '' !== Lingora_Ai_Client::base_url_error( $url ), 'unsafe endpoint rejected ' . $url );
}
lingora_api_check( '' !== Lingora_Ai_Client::header_name_error( 'Host' ) && '' !== Lingora_Ai_Client::header_name_error( "Bad\r\nHeader" ), 'reserved and malformed headers rejected' );
$id = lingora()->db->upsert_string( 'Authoritative stored text', 'html', 'home' );
$response = lingora_api_call( 'POST', 'translate', array( 'string_id' => $id, 'language' => 'de', 'text' => 'Forged client text' ) );
$data = $response->get_data();
lingora_api_check( 200 === $response->get_status() && true === $data['saved'] && str_contains( $data['text'], 'Authoritative stored text' ), 'AI uses stored original and reports persistence' );
$args = end( $GLOBALS['lingora_fixture_requests'] )['args'];
lingora_api_check( 0 === $args['redirection'] && $args['reject_unsafe_urls'] && $args['limit_response_size'] <= 1048577, 'safe HTTP flags and size limit present' );
lingora()->db->save_translation( $id, 'de', 'Human wins', 'human' );
$data = lingora_api_call( 'POST', 'translate', array( 'string_id' => $id, 'language' => 'de' ) )->get_data();
lingora_api_check( false === $data['saved'], 'AI returns preview for human row' );
$request = new WP_REST_Request( 'GET', '/lingora/v1/strings/' . $id );
$request->set_query_params( array( 'language' => 'de' ) );
$data = rest_do_request( $request )->get_data();
lingora_api_check( 'Human wins' === $data['translated'] && 'human' === $data['status'], 'exact target lookup preserves human text' );
lingora_api_check( 400 === lingora_api_call( 'GET', 'strings/' . $id )->get_status(), 'exact lookup requires language' );
foreach ( array( 'en', 'xx', array( 'de' ) ) as $language ) {
	lingora_api_check( 400 === lingora_api_call( 'POST', 'strings/' . $id, array( 'language' => $language, 'translated' => 'Hello' ) )->get_status(), 'invalid target write rejected ' . wp_json_encode( $language ) );
}
lingora_api_check( 404 === lingora_api_call( 'POST', 'translate', array( 'string_id' => 9999999, 'language' => 'de' ) )->get_status(), 'missing ID cannot be translated' );
$slug = lingora()->db->upsert_string( 'source-slug', 'slug', 'home' );
lingora_api_call( 'POST', 'strings/' . $slug, array( 'language' => 'de', 'translated' => '"你好 / World"' ) );
$map = lingora()->db->map_for_language( array( lingora()->db->hash( 'source-slug' ) ), 'de', 'slug' );
lingora_api_check( '你好-world' === reset( $map )['translated'], 'human slug normalized and stored decoded' );
$prior = $options->languages();
$invalid = $prior;
$invalid[0]['enabled'] = false;
lingora_api_check( 400 === lingora_api_call( 'POST', 'languages', array( 'languages' => $invalid ) )->get_status() && $prior === $options->languages(), 'disabled default rejected without mutation' );
$invalid = $prior;
$invalid[] = $prior[1];
lingora_api_check( 400 === lingora_api_call( 'POST', 'languages', array( 'languages' => $invalid ) )->get_status(), 'duplicate language rejected' );
foreach ( array( 'de_DE' => 'de', 'pt_BR' => 'pt-br', 'zh_TW' => 'zh-tw', 'fr_FR' => 'fr', '' => 'en' ) as $locale => $expected ) {
	$site_locale = static fn() => $locale;
	add_filter( 'pre_option_WPLANG', $site_locale, PHP_INT_MAX );
	lingora_api_check( $expected === Lingora_Languages::default_set()[0]['code'], 'site locale initializes ' . $locale );
	remove_filter( 'pre_option_WPLANG', $site_locale, PHP_INT_MAX );
}
lingora_fixture_push( lingora_fixture_json( array( 'choices' => array( array( 'message' => array( 'content' => '["Good", 3]' ) ) ) ) ) );
$count = count( $GLOBALS['lingora_fixture_requests'] );
$rejected = false;
try { lingora()->ai->translate_batch( array( 'First', 'Second' ), 'de' ); } catch ( RuntimeException $e ) { $rejected = true; }
lingora_api_check( $rejected && count( $GLOBALS['lingora_fixture_requests'] ) === $count + 1, 'malformed batch fails once without sequential fallback' );
lingora_fixture_push( lingora_fixture_json( array( 'error' => array( 'message' => 'key test-secret-123456 ' . str_repeat( 'a', 1000 ) ) ), 401 ) );
$message = '';
try { lingora()->ai->translate_text( 'Hello', 'de' ); } catch ( RuntimeException $e ) { $message = $e->getMessage(); }
lingora_api_check( ! str_contains( $message, 'test-secret-123456' ) && Lingora_Text::length( $message ) <= 500, 'provider errors redact and bound secrets' );
$options->update( array( 'ai_consent' => false ) );
$count = count( $GLOBALS['lingora_fixture_requests'] );
lingora_api_check( 202 === lingora_api_call( 'POST', 'queue', array() )->get_status() && 202 === lingora_api_call( 'POST', 'scan', array() )->get_status(), 'bulk endpoints accept async work' );
lingora_api_check( $count === count( $GLOBALS['lingora_fixture_requests'] ), 'bulk endpoints do no network work' );
$jobs = lingora_api_call( 'GET', 'jobs' )->get_data();
lingora_api_check( isset( $jobs['queue']['pending'], $jobs['scan']['status'], $jobs['stats']['strings'] ), 'jobs returns complete UI schema' );
echo wp_json_encode( array( 'suite' => 'api', 'checks' => $checks ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
exit( in_array( false, array_column( $checks, 'pass' ), true ) ? 1 : 0 );

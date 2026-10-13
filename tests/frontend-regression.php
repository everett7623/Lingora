<?php
require '/wordpress/wp-load.php';

$checks = array();
function lingora_front_check( bool $ok, string $label ): void {
	$GLOBALS['checks'][] = array( 'check' => $label, 'pass' => $ok );
	if ( ! $ok ) { echo "FAIL: $label\n"; }
}
$options = lingora()->options;
$options->update( array( 'auto_translate' => false, 'ai_consent' => false, 'detect_browser' => false ) );
$options->save_languages( array(
	array( 'code' => 'en', 'name' => 'English', 'native' => 'English', 'locale' => 'en_US', 'enabled' => true, 'is_default' => true ),
	array( 'code' => 'de', 'name' => 'German', 'native' => 'Deutsch', 'locale' => 'de_DE', 'enabled' => true, 'is_default' => false ),
) );
$flag_markup = Lingora_Flags::markup( 'de' );
lingora_front_check( str_contains( $flag_markup, '/flag-icons/4x3/de.svg' ) && str_contains( $flag_markup, '<img' ), 'switcher uses local SVG country flags' );
foreach ( Lingora_Languages::catalog() as $catalog_language ) {
	$flag_file = LINGORA_PATH . 'public/vendor/flag-icons/4x3/' . $catalog_language['flag'] . '.svg';
	lingora_front_check( file_exists( $flag_file ), 'catalog flag exists for ' . $catalog_language['code'] );
}
$GLOBALS['lingora_language'] = 'de';
$_SERVER['REQUEST_METHOD'] = 'GET';
wp_set_current_user( 0 );
$post_id = wp_insert_post( array( 'post_title' => 'Public fixture', 'post_content' => 'Public text', 'post_status' => 'publish' ) );
$GLOBALS['wp_query'] = new WP_Query( array( 'p' => $post_id ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
lingora_front_check( Lingora_Content::is_public_request(), 'public anonymous request is eligible' );
$db = lingora()->db;
$id = $db->upsert_string( 'Hello', 'html', 'post:' . $post_id );
$db->save_translation( $id, 'de', 'Grüße & <world>', 'human' );
$attr_id = $db->upsert_string( 'Picture label', 'html', 'post:' . $post_id );
$db->save_translation( $attr_id, 'de', 'Bild & Größe', 'human' );
$html = new Lingora_Html( lingora()->translator, lingora()->languages, $options, $db );
$input = '<!doctype html><html lang="ar" dir="rtl"><head><title>Hello</title><meta property="og:type" content="article"><meta property="og:title" content="Hello"></head><body><p id="greeting">Hello <a href="/">world</a></p><img alt="Picture label" title="Hello"><input type="text" value="Secret value" placeholder="Picture label"><script>Secret script</script><p class="x\tnotranslate">Private skip</p></body></html>';
$output = $html->translate( $input, 'de', 'post:' . $post_id );
$dom = new DOMDocument();
@$dom->loadHTML( $output );
$xpath = new DOMXPath( $dom );
lingora_front_check( str_contains( $output, 'dir="ltr"' ), 'LTR language clears stale RTL direction' );
lingora_front_check( 'Grüße & <world> ' === $xpath->query( '//p[@id="greeting"]/text()' )->item(0)->nodeValue, 'translation is literal text with preserved edge space' );
lingora_front_check( 0 === $xpath->query( '//world' )->length, 'translated markup does not become executable DOM' );
lingora_front_check( 'article' === $xpath->query( '//meta[@property="og:type"]' )->item(0)->getAttribute('content'), 'metadata machine values remain unchanged' );
lingora_front_check( 'Secret value' === $xpath->query( '//input' )->item(0)->getAttribute('value'), 'text input values remain private' );
lingora_front_check( 0 === count( $GLOBALS['lingora_fixture_requests'] ), 'offline rendering sends no external requests' );
$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin->ID );
$before = $db->stats()['strings'];
$output = $html->translate( str_replace( '</body>', '<p>Unique account balance secret</p></body>', $input ), 'de', 'post:' . $post_id, true );
lingora_front_check( $before === $db->stats()['strings'], 'authenticated editor does not collect new personalized text' );
@$dom->loadHTML( $output );
$xpath = new DOMXPath( $dom );
lingora_front_check( 0 === $xpath->query( '//title/span' )->length, 'editor does not wrap title in invalid span' );
lingora_front_check( 1 === $xpath->query( '//p[@id="greeting"]/span[@data-lingora-id]' )->length, 'known text has exact editable string ID' );
$attrs = json_decode( $xpath->query('//img')->item(0)->getAttribute('data-lingora-attrs'), true );
lingora_front_check( 2 === count( $attrs ) && $attrs[0]['id'] === $attr_id, 'multiple attributes carry separate exact IDs' );
$_GET['lingora-force'] = '1';
lingora_front_check( ! Lingora_Editor::is_force(), 'force action rejects missing nonce' );
$_GET['_lingora_nonce'] = 'bad';
lingora_front_check( ! Lingora_Editor::is_force(), 'force action rejects invalid nonce' );
$_GET['_lingora_nonce'] = wp_create_nonce( 'lingora_force' );
lingora_front_check( Lingora_Editor::is_force(), 'force action accepts authorized nonce' );
unset( $_GET['lingora-force'], $_GET['_lingora_nonce'] );
foreach ( array( 'draft', 'private', 'publish' ) as $status ) {
	$protected = new WP_Post( (object) array( 'ID' => 900, 'post_status' => $status, 'post_type' => 'post', 'post_password' => 'publish' === $status ? 'secret' : '' ) );
	lingora_front_check( ! Lingora_Content::is_public_post( $protected ), 'exclude ' . $status . ( 'publish' === $status ? ' password protected' : '' ) );
}
$_SERVER['REQUEST_METHOD'] = 'POST';
lingora_front_check( ! Lingora_Content::is_public_request(), 'POST responses cannot be collected' );
$_SERVER['REQUEST_METHOD'] = 'GET';
lingora_front_check( 3 === Lingora_Text::length( '中文é' ) && '文é' === Lingora_Text::substring( '中文é', 1, 2 ), 'UTF8 text helpers preserve code points' );
echo json_encode( array( 'suite' => 'frontend', 'checks' => $checks ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
exit( in_array( false, array_column( $checks, 'pass' ), true ) ? 1 : 0 );

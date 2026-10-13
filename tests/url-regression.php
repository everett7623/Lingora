<?php
require '/wordpress/wp-load.php';
$checks = array();
function lingora_url_check( bool $pass, string $label, mixed $actual = null ): void {
	$GLOBALS['checks'][] = array( 'check' => $label, 'pass' => $pass );
	if ( ! $pass ) { echo $label . ': ' . json_encode( $actual ) . "\n"; }
}
$options = lingora()->options;
$old_home = get_option( 'home' );
$old_site = get_option( 'siteurl' );
$options->update( array( 'auto_translate' => false, 'detect_browser' => false, 'translate_slugs' => false ) );
$options->save_languages( array(
	array( 'code' => 'en', 'native' => 'English', 'name' => 'English', 'locale' => 'en_US', 'enabled' => true, 'is_default' => true ),
	array( 'code' => 'de', 'native' => 'Deutsch', 'name' => 'German', 'locale' => 'de_DE', 'enabled' => true, 'is_default' => false ),
	array( 'code' => 'fr', 'native' => 'Français', 'name' => 'French', 'locale' => 'fr_FR', 'enabled' => true, 'is_default' => false ),
) );
remove_filter( 'home_url', array( lingora()->rewrite, 'filter_home_url' ), 10 );
$base = 'https://example.test';
$GLOBALS['lingora_test_home'] = $base;
$home_filter = static fn() => $GLOBALS['lingora_test_home'];
add_filter( 'pre_option_home', $home_filter, PHP_INT_MAX );
update_option( 'home', $base );
update_option( 'siteurl', $base );
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['REQUEST_METHOD'] = 'GET';
$rewrite = new Lingora_Rewrite( lingora()->languages, $options );
$options->update( array( 'url_mode' => 'directory', 'hide_default' => true ) );
foreach ( array(
	array( 'de', $base . '/about/?q=a%20b&x=1&x=2#section', $base . '/de/about/?q=a%20b&x=1&x=2#section' ),
	array( 'de', $base . '/de/about/?lang=fr&x=1', $base . '/de/about/?x=1' ),
	array( 'en', $base . '/de/about/?lang=fr&x=1', $base . '/about/?x=1' ),
	array( 'fr', $base . '/de/de/?x=1', $base . '/fr/de/?x=1' ),
) as [$lang,$input,$expected] ) {
	$actual = $rewrite->language_url( $lang, $input );
	lingora_url_check( $actual === $expected, 'directory conversion ' . $input, $actual );
	lingora_url_check( $rewrite->language_url( $lang, $actual ) === $actual, 'directory idempotence ' . $input );
}
foreach ( array( 'mailto:person@example.test', 'javascript:alert(1)', '#title', 'https://external.test/about/', 'https://example.test:444/about/', $base . '/wp-json', $base . '/wp-admin/', $base . '/wp-content/a.css', $base . '/?rest_route=%2Fwp%2Fv2&lang=de', $base . '/?%72est_route=%2Fwp', 'https://user:password@example.test/about/' ) as $input ) {
	lingora_url_check( $rewrite->language_url( 'de', $input ) === $input, 'non-public or external URL left intact ' . $input );
}
$options->update( array( 'url_mode' => 'query' ) );
$actual = $rewrite->language_url( 'de', $base . '/fr/about/?x=a%2Fb&lang=fr&x=2&lang=en#anchor' );
lingora_url_check( $actual === $base . '/about/?x=a%2Fb&lang=de&x=2#anchor', 'query mode keeps other pairs in order', $actual );
lingora_url_check( $rewrite->language_url( 'de', $actual ) === $actual, 'query mode idempotent' );
lingora_url_check( $rewrite->language_url( 'en', $actual ) === $base . '/about/?x=a%2Fb&x=2#anchor', 'hidden default query language removes lang' );
$GLOBALS['lingora_test_home'] = $base . '/de';
update_option( 'home', $base . '/de' );
$options->update( array( 'url_mode' => 'directory' ) );
$actual = $rewrite->language_url( 'de', $base . '/de/about/?s=term' );
lingora_url_check( $actual === $base . '/de/de/about/?s=term', 'home subdirectory not duplicated', $actual );
lingora_url_check( $rewrite->language_url( 'de', '/de/about/?s=term' ) === $actual, 'root-relative subdirectory URL follows same contract' );
$_GET = array();
$_SERVER['REQUEST_URI'] = '/de/de/about/?q=1';
$_SERVER['PATH_INFO'] = '/de/about/';
$routing = new Lingora_Rewrite( lingora()->languages, $options );
$routing->detect();
lingora_url_check( '/de/about/?q=1' === $_SERVER['REQUEST_URI'], 'language stripping preserves same-named home directory', $_SERVER['REQUEST_URI'] );
lingora_url_check( $base . '/de/de/about/?q=1' === $routing->current_url(), 'current_url retains original prefix and query', $routing->current_url() );
$_SERVER['REQUEST_URI'] = '/de';
$routing = new Lingora_Rewrite( lingora()->languages, $options );
$routing->detect();
lingora_url_check( 'en' === $routing->current(), 'home directory equal to language is not a language prefix' );
$_GET = array( 'lang' => array( 'de' ) );
$_SERVER['REQUEST_URI'] = '/de/fr/about/?lang[]=de';
$routing = new Lingora_Rewrite( lingora()->languages, $options );
$routing->detect();
lingora_url_check( 'fr' === $routing->current() && '/de/about/?lang[]=de' === $_SERVER['REQUEST_URI'], 'array language does not bypass directory detection' );
$_GET = array( 'lang' => 'de' );
$_SERVER['REQUEST_URI'] = '/de/wp-json/?lang=de';
$routing = new Lingora_Rewrite( lingora()->languages, $options );
$routing->detect();
lingora_url_check( 'en' === $routing->current(), 'REST exclusion precedes language query' );
$_SERVER['HTTP_HOST'] = 'foreign.example';
lingora_url_check( str_starts_with( $routing->current_url(), $base . '/' ), 'untrusted Host cannot change editor origin', $routing->current_url() );
$_SERVER['HTTP_HOST'] = 'example.test';
$_GET = array();
$clean = Lingora_Rewrite::strip_editor_params( $base . '/?q=term&lingora-edit=1&lingora-force=1&_lingora_nonce=secret&_wpnonce=other' );
lingora_url_check( $clean === $base . '/?q=term', 'editor nonces removed from public links' );
// Root install (empty home path) prefix stripping (regression: double-slash pattern).
update_option( 'home', $base );
$GLOBALS['lingora_test_home'] = $base;
$_GET = array();
$_SERVER['PATH_INFO'] = '';
$_SERVER['REQUEST_URI'] = '/de/about/?q=1';
$routing = new Lingora_Rewrite( lingora()->languages, $options );
$routing->detect();
lingora_url_check( 'de' === $routing->current() && '/about/?q=1' === $_SERVER['REQUEST_URI'], 'root install strips language prefix', $_SERVER['REQUEST_URI'] );
$_SERVER['REQUEST_URI'] = '/de';
$routing = new Lingora_Rewrite( lingora()->languages, $options );
$routing->detect();
lingora_url_check( 'de' === $routing->current() && '/' === $_SERVER['REQUEST_URI'], 'root install bare prefix routes to home', $_SERVER['REQUEST_URI'] );
$_SERVER['REQUEST_URI'] = '/deutschland/';
$routing = new Lingora_Rewrite( lingora()->languages, $options );
$routing->detect();
lingora_url_check( 'en' === $routing->current() && '/deutschland/' === $_SERVER['REQUEST_URI'], 'root install non-language prefix untouched' );
$_SERVER['REQUEST_URI'] = '/';
$detect = new Lingora_Detect( lingora()->languages, $rewrite, $options );
$method = new ReflectionMethod( $detect, 'from_header' );
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de;q=0,fr;q=0.8,en;q=0.5';
lingora_url_check( 'fr' === $method->invoke( $detect ), 'Accept-Language q=0 is excluded' );
$options->update( array( 'remember_language' => false ) );
unset( $_COOKIE[Lingora_Detect::COOKIE] );
$detect->set_cookie( 'de' );
lingora_url_check( ! isset( $_COOKIE[Lingora_Detect::COOKIE] ), 'remember=false prevents server cookie writes' );
remove_filter( 'pre_option_home', $home_filter, PHP_INT_MAX );
update_option( 'home', $old_home );
update_option( 'siteurl', $old_site );
$GLOBALS['lingora_language'] = 'de';
wp_set_current_user( 0 );
$db = lingora()->db;
$public = wp_insert_post( array( 'post_title' => 'Public unrelated original', 'post_status' => 'publish' ) );
$protected = wp_insert_post( array( 'post_title' => 'Protected unrelated original', 'post_status' => 'publish', 'post_password' => 'secret' ) );
$private = wp_insert_post( array( 'post_title' => 'Private unrelated original', 'post_status' => 'private' ) );
foreach ( array( $public, $protected, $private ) as $id ) {
	$string = $db->upsert_string( 'Unique public label ' . $id, 'html', 'post:' . $id );
	$db->save_translation( $string, 'de', 'Suchtreffer', 'human' );
}
$query = new WP_Query();
$GLOBALS['wp_the_query'] = $query;
$GLOBALS['wp_query'] = $query;
$posts = $query->query( array( 's' => 'Suchtreffer', 'post_type' => 'post', 'posts_per_page' => -1 ) );
$ids = wp_list_pluck( $posts, 'ID' );
lingora_url_check( in_array( $public, $ids, true ), 'translated search includes public matches', $ids );
lingora_url_check( ! in_array( $protected, $ids, true ) && ! in_array( $private, $ids, true ), 'translated search excludes protected and private matches', $ids );
echo json_encode( array( 'suite' => 'url', 'checks' => $checks ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
exit( in_array( false, array_column( $checks, 'pass' ), true ) ? 1 : 0 );

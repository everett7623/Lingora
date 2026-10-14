<?php
/** 在隔离 WordPress 中验证语言标签、国旗资源与缓存版本。 */
require '/wordpress/wp-load.php';

$checks = array();
function lingora_switcher_check( bool $pass, string $label ): void {
	$GLOBALS['checks'][] = array( 'check' => $label, 'pass' => $pass );
}

foreach ( array( 'en-gb' => 'gb', 'en_US' => 'us', 'zh-hant-tw' => 'tw', 'zh-hk' => 'hk', 'pt-br' => 'br', 'fr-ca' => 'ca', 'de' => 'de', 'zz' => 'xx' ) as $code => $country ) {
	lingora_switcher_check( str_ends_with( Lingora_Flags::asset_url( $code ), '/' . $country . '.svg' ), 'regional country mapping: ' . $code );
}
lingora_switcher_check( str_ends_with( Lingora_Flags::asset_url( 'en', 'gb' ), '/gb.svg' ), 'explicit country overrides the language default' );
lingora_switcher_check( str_ends_with( Lingora_Flags::asset_url( 'en', '../../secret' ), '/us.svg' ), 'invalid country cannot escape the asset directory' );
lingora_switcher_check( str_ends_with( Lingora_Flags::asset_url( 'en', 'zz' ), '/xx.svg' ), 'missing country uses the bundled neutral flag' );
lingora_switcher_check( 'ZH' === Lingora_Flags::iso_label( 'zh' ) && 'ZH-TW' === Lingora_Flags::iso_label( 'zh-tw' ) && 'PT-BR' === Lingora_Flags::iso_label( 'pt-br' ), 'regional language labels remain distinct' );

$options = lingora()->options;
$prior_settings = $options->all();
$prior_languages = $options->languages();
$prior_current = $GLOBALS['lingora_language'] ?? null;
$options->save_languages( array(
	array( 'code' => 'en', 'name' => 'English', 'native' => '', 'enabled' => true, 'is_default' => true ),
	array( 'code' => 'zh-tw', 'name' => '', 'native' => '', 'enabled' => true, 'is_default' => false ),
) );
$GLOBALS['lingora_language'] = 'en';
$options->update( array( 'switcher_show_flag' => false, 'switcher_show_code' => false, 'switcher_show_name' => false ) );
$markup = lingora()->switcher->markup();
$dom = new DOMDocument();
@$dom->loadHTML( $markup );
$xpath = new DOMXPath( $dom );
lingora_switcher_check( 'English' === trim( $xpath->query( '//button' )->item(0)->textContent ), 'empty native name falls back to the language name' );
$link = $xpath->query( '//a[@data-lingora-lang]' )->item(0);
lingora_switcher_check( 'zh-tw' === trim( $link->textContent ) && 'zh-tw' === $link->getAttribute( 'aria-label' ), 'hidden display fields still produce a visible and accessible language label' );
$first_id = $xpath->query( '//ul' )->item(0)->getAttribute( 'id' );
@$dom->loadHTML( lingora()->switcher->markup() );
lingora_switcher_check( $first_id !== ( new DOMXPath( $dom ) )->query( '//ul' )->item(0)->getAttribute( 'id' ), 'multiple switchers keep unique controls IDs' );
lingora_switcher_check( str_contains( $markup, 'data-lingora-version="' . LINGORA_VERSION . '"' ), 'rendered switcher exposes the installed version for diagnostics' );

lingora()->switcher->enqueue();
lingora_switcher_check( LINGORA_VERSION === wp_styles()->registered['lingora-frontend']->ver && LINGORA_VERSION === wp_scripts()->registered['lingora-switcher']->ver, 'frontend assets carry the release cache version' );
$style_path = wp_parse_url( wp_styles()->registered['lingora-frontend']->src, PHP_URL_PATH );
lingora_switcher_check( str_ends_with( $style_path, '/frontend-' . LINGORA_VERSION . '.css' ) && is_file( LINGORA_PATH . 'public/css/frontend-' . LINGORA_VERSION . '.css' ), 'CSS cache identity survives removal of the version query parameter' );
lingora()->admin->enqueue( 'lingora-languages' );
$inline = wp_scripts()->get_data( 'lingora-admin', 'data' );
lingora_switcher_check( str_contains( $inline, 'flagUrls' ) && str_contains( $inline, '/us.svg' ) && str_contains( $inline, '/tw.svg' ), 'legacy language records without flags receive resolved admin SVG URLs' );

$options->update( $prior_settings );
$options->save_languages( $prior_languages );
if ( null === $prior_current ) {
	unset( $GLOBALS['lingora_language'] );
} else {
	$GLOBALS['lingora_language'] = $prior_current;
}
echo wp_json_encode( array( 'suite' => 'switcher', 'checks' => $checks ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
exit( in_array( false, array_column( $checks, 'pass' ), true ) ? 1 : 0 );

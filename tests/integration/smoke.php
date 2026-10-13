<?php
/** Infrastructure smoke checks, executed only inside the WASM WordPress site. */
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$checks = array();
$checks['wordpress_installed'] = is_blog_installed();
$checks['plugin_active'] = is_plugin_active( 'lingora/lingora.php' );
$checks['plugin_booted'] = function_exists( 'lingora' ) && lingora() instanceof Lingora_Plugin;
$checks['sqlite_database'] = defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE;
$checks['dom'] = class_exists( 'DOMDocument' );
$checks['utf8_helpers'] = 3 === Lingora_Text::length( '中文é' ) && '文é' === Lingora_Text::substring( '中文é', 1, 2 );

$dom = new DOMDocument();
$dom->loadHTML( '<!doctype html><html><head><meta charset="UTF-8"></head><body><p>中文é</p></body></html>' );
$checks['dom_unicode'] = '中文é' === $dom->getElementsByTagName( 'p' )->item( 0 )->textContent;

$tables = $wpdb->get_col( 'SHOW TABLES' );
foreach ( array( 'strings', 'translations', 'queue' ) as $table ) {
	$checks[ 'table_' . $table ] = in_array( $wpdb->prefix . 'lingora_' . $table, $tables, true );
}
$blocked = wp_remote_get( 'https://external.lingora.invalid/no-real-request' );
$checks['http_blocked'] = is_wp_error( $blocked ) && 'lingora_offline' === $blocked->get_error_code();
$models = wp_remote_get( 'https://fixtures.lingora.invalid/v1/models' );
$checks['http_fixture'] = 200 === wp_remote_retrieve_response_code( $models );
$checks['host_filesystem_unmounted'] = ! file_exists( '/host' ) && ! file_exists( 'D:/EvenFrank/Workspace/Plugins/Wordpress/Lingora/lingora.php' );

$result = array(
	'wordpress' => $GLOBALS['wp_version'],
	'php' => PHP_VERSION,
	'plugin' => LINGORA_VERSION,
	'database' => $wpdb->db_version(),
	'extensions' => get_loaded_extensions(),
	'checks' => $checks,
);
echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
if ( in_array( false, $checks, true ) ) {
	exit( 1 );
}

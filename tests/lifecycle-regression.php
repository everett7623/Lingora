<?php
/** Lifecycle checks run only in the disposable WASM site, after other suites. */
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$db = lingora()->db;
$id = $db->upsert_string( 'Lifecycle retained original', 'html', 'home' );
$db->save_translation( $id, 'de', 'Retained human translation', 'human' );
lingora()->scanner->trigger_scan();
$before = lingora()->options->all();
deactivate_plugins( 'lingora/lingora.php' );
$checks = array();
$checks['deactivation_keeps_settings'] = $before === get_option( 'lingora_settings' );
$checks['deactivation_keeps_human_translation'] = 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE string_id=%d AND status=%s', Lingora_Db::translations_table(), $id, 'human' ) );
foreach ( array( 'lingora_process_queue', 'lingora_run_queue', 'lingora_scan_published', 'lingora_scan_step' ) as $hook ) {
	$checks[ 'deactivate_clears_' . $hook ] = false === wp_next_scheduled( $hook );
}
uninstall_plugin( 'lingora/lingora.php' );
$tables = $wpdb->get_col( 'SHOW TABLES' );
foreach ( array( 'strings', 'translations', 'queue' ) as $suffix ) {
	$checks[ 'uninstall_drops_' . $suffix ] = ! in_array( $wpdb->prefix . 'lingora_' . $suffix, $tables, true );
}
foreach ( array( 'settings', 'languages', 'db_version', 'ai_version', 'ui_version', 'cron_version', 'scan_state' ) as $suffix ) {
	$checks[ 'uninstall_deletes_' . $suffix ] = false === get_option( 'lingora_' . $suffix, false );
}
echo wp_json_encode( array( 'suite' => 'lifecycle', 'checks' => $checks ), JSON_PRETTY_PRINT );
exit( in_array( false, $checks, true ) ? 1 : 0 );

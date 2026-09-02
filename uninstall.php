<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Lingora
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'lingora_strings',
	$wpdb->prefix . 'lingora_translations',
	$wpdb->prefix . 'lingora_queue',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from prefix only.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

delete_option( 'lingora_settings' );
delete_option( 'lingora_languages' );
delete_option( 'lingora_db_version' );
delete_option( 'lingora_cron_version' );
delete_option( 'lingora_ui_version' );

wp_clear_scheduled_hook( 'lingora_process_queue' );

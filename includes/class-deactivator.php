<?php
/**
 * Deactivation.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Deactivator {
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'lingora_process_queue' );
		wp_clear_scheduled_hook( 'lingora_scan_published' );
		wp_clear_scheduled_hook( 'lingora_scan_step' );
		wp_clear_scheduled_hook( 'lingora_run_queue' );
		delete_option( 'lingora_scan_state' );
		flush_rewrite_rules();
	}
}

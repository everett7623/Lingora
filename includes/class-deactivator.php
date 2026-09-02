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
		flush_rewrite_rules();
	}
}

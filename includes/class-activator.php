<?php
/**
 * Activation.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Activator {
	public static function activate(): void {
		Lingora_Db::install();
		Lingora_Options::seed();

		add_filter( 'cron_schedules', array( self::class, 'schedules' ) );
		wp_clear_scheduled_hook( 'lingora_process_queue' );
		wp_schedule_event( time() + 60, 'lingora_five_minutes', 'lingora_process_queue' );
		update_option( 'lingora_cron_version', '5min', false );

		flush_rewrite_rules();
	}

	/**
	 * @param array<string, array{interval:int, display:string}> $schedules
	 * @return array<string, array{interval:int, display:string}>
	 */
	public static function schedules( array $schedules ): array {
		$schedules['lingora_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 minutes', 'lingora' ),
		);
		return $schedules;
	}
}

<?php
/**
 * Resumable discovery of static public content.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Scanner {
	private const STATE_KEY = 'lingora_scan_state';
	private const BATCH = 20;
	private const LEASE = 900;
	private Lingora_Translator $translator;
	private Lingora_Languages $languages;
	private Lingora_Options $options;

	public function __construct( Lingora_Translator $translator, Lingora_Languages $languages, Lingora_Options $options ) {
		$this->translator = $translator;
		$this->languages = $languages;
		$this->options = $options;
	}

	public function register(): void {
		add_action( 'lingora_scan_published', array( $this, 'cron_scan' ) );
		add_action( 'lingora_scan_step', array( $this, 'scan_step' ) );
		if ( $this->options->get( 'scan_published', true ) ) {
			if ( ! wp_next_scheduled( 'lingora_scan_published' ) ) {
				wp_schedule_event( time() + 120, 'daily', 'lingora_scan_published' );
			}
		} else {
			wp_clear_scheduled_hook( 'lingora_scan_published' );
		}
	}

	public function cron_scan(): void {
		if ( $this->options->get( 'scan_published', true ) ) {
			$this->trigger_scan();
		}
	}

	public function trigger_scan(): array {
		global $wpdb;
		$previous = $this->read_state();
		if ( 'running' === ( $previous['status'] ?? '' ) && time() - (int) $previous['heartbeat'] < self::LEASE ) {
			$this->schedule_step();
			return array_merge( $this->scan_status(), array( 'started' => false, 'already_running' => true ) );
		}
		$state = array(
			'run' => wp_generate_uuid4(), 'status' => 'running', 'cursor' => 0,
			'ceiling' => (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ),
			'scanned' => 0, 'strings' => 0, 'heartbeat' => time(), 'lock' => '', 'error' => '',
		);
		$started = $this->replace_state( $previous, $state );
		if ( $started ) {
			try {
				$this->schedule_step();
			} catch ( Throwable $e ) {
				$failed = $state;
				$failed['status'] = 'stalled';
				$failed['error'] = $e->getMessage();
				$this->replace_state( $state, $failed );
				throw $e;
			}
		}
		return array_merge( $this->scan_status(), array( 'started' => $started, 'already_running' => ! $started ) );
	}

	public function scan_status(): array {
		$state = $this->read_state();
		$status = $state['status'] ?? 'idle';
		if ( 'running' === $status && time() - (int) $state['heartbeat'] >= self::LEASE ) { $status = 'stalled'; }
		$messages = array(
			'idle' => __( '当前没有进行中的扫描。', 'lingora' ),
			'running' => __( '公开内容扫描正在后台进行。', 'lingora' ),
			'done' => __( '公开内容扫描已完成。', 'lingora' ),
			'stalled' => __( '扫描未能继续，请重新提交。', 'lingora' ),
		);
		return array(
			'ok' => true, 'status' => $status, 'scanned' => (int) ( $state['scanned'] ?? 0 ),
			'strings' => (int) ( $state['strings'] ?? 0 ),
			'updated_at' => empty( $state['heartbeat'] ) ? null : gmdate( 'Y-m-d H:i:s', $state['heartbeat'] ),
			'message' => ! empty( $state['error'] ) ? $state['error'] : ( $messages[ $status ] ?? $messages['idle'] ),
		);
	}

	public function scan_step(): void {
		global $wpdb;
		$state = $this->read_state();
		if ( 'running' !== ( $state['status'] ?? '' ) || ( ! empty( $state['lock'] ) && time() - (int) $state['heartbeat'] < self::LEASE ) ) { return; }
		$claimed = $state;
		$claimed['lock'] = wp_generate_uuid4();
		$claimed['heartbeat'] = time();
		if ( ! $this->replace_state( $state, $claimed ) ) { return; }
		$next = $claimed;
		try {
			$types = get_post_types( array( 'public' => true ) );
			$types = array_values( array_diff( $types, array( 'attachment' ) ) );
			$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$ids = $types ? $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND ID <= %d AND post_status = 'publish' AND post_password = '' AND post_type IN ({$placeholders}) ORDER BY ID ASC LIMIT %d", ...array_merge( array( $state['cursor'], $state['ceiling'] ), $types, array( self::BATCH ) ) ) ) : array();
			if ( '' !== $wpdb->last_error ) { throw new RuntimeException( __( '无法读取公开内容，请检查数据库。', 'lingora' ) ); }
			foreach ( $ids as $id ) {
				$post = get_post( (int) $id );
				$next['cursor'] = (int) $id;
				if ( ! $post instanceof WP_Post || ! Lingora_Content::is_public_post( $post ) ) { continue; }
				$texts = $this->from_post( $post );
				lingora()->slugs->queue_post( (int) $post->ID, $post );
				foreach ( $this->targets() as $language ) {
					$this->translator->enqueue_originals( $texts, $language, 'post:' . $id );
				}
				++$next['scanned'];
				$next['strings'] += count( $texts );
			}
			if ( count( $ids ) < self::BATCH ) {
				$next['strings'] += $this->scan_menus();
				$next['status'] = 'done';
			}
		} catch ( Throwable $e ) {
			$next['status'] = 'stalled';
			$next['error'] = __( '扫描失败，请检查数据库和 PHP DOM 扩展后重新提交。', 'lingora' );
		}
		$next['heartbeat'] = time();
		$next['lock'] = '';
		if ( $this->replace_state( $claimed, $next ) && 'running' === $next['status'] ) {
			try { $this->schedule_step(); } catch ( Throwable $e ) {
				$stalled = $next;
				$stalled['status'] = 'stalled';
				$stalled['error'] = $e->getMessage();
				$this->replace_state( $next, $stalled );
			}
		}
	}

	/** Legacy entry point also only schedules work. */
	public function scan_published( int $limit = 40, bool $translate_now = false ): array {
		$state = $this->trigger_scan();
		return array( 'posts' => $state['scanned'], 'strings' => $state['strings'], 'languages' => count( $this->targets() ) );
	}

	private function from_post( WP_Post $post ): array {
		if ( ! class_exists( 'DOMDocument' ) ) { throw new RuntimeException( 'DOM unavailable' ); }
		$html = new Lingora_Html( $this->translator, $this->languages, $this->options );
		$fragments = $this->static_fragments( parse_blocks( $post->post_content ) );
		$fragments[] = '<p>' . esc_html( $post->post_title ) . '</p>';
		if ( ! str_contains( $post->post_excerpt, '[' ) ) { $fragments[] = $post->post_excerpt; }
		$texts = array();
		foreach ( $fragments as $fragment ) {
			// Shortcodes and dynamic blocks may conceal private content; never execute them.
			if ( ! str_contains( $fragment, '[' ) ) { $texts = array_merge( $texts, $html->extract( $fragment ) ); }
		}
		return array_values( array_unique( $texts ) );
	}

	private function static_fragments( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			$type = WP_Block_Type_Registry::get_instance()->get_registered( $block['blockName'] ?? '' );
			if ( 'core/shortcode' === ( $block['blockName'] ?? '' ) || ( $type && $type->is_dynamic() ) ) { continue; }
			if ( ! empty( $block['innerHTML'] ) ) { $out[] = $block['innerHTML']; }
			$out = array_merge( $out, $this->static_fragments( $block['innerBlocks'] ?? array() ) );
		}
		return $out;
	}

	private function scan_menus(): int {
		$count = 0;
		foreach ( array_unique( get_nav_menu_locations() ) as $menu ) {
			foreach ( wp_get_nav_menu_items( (int) $menu ) ?: array() as $item ) {
				if ( 'post_type' === $item->type ) {
					$post = get_post( (int) $item->object_id );
					if ( ! $post instanceof WP_Post || ! Lingora_Content::is_public_post( $post ) ) { continue; }
					$source = 'post:' . $post->ID;
				} else { $source = 'menu'; }
				foreach ( $this->targets() as $language ) { $this->translator->enqueue_originals( array( $item->title ), $language, $source ); }
				if ( ++$count >= 200 ) { return $count; }
			}
		}
		return $count;
	}

	private function targets(): array {
		return array_values( array_map( static fn( $lang ) => $lang['code'], array_filter( $this->languages->enabled(), fn( $lang ) => ! $this->languages->is_default( $lang['code'] ) ) ) );
	}

	private function read_state(): array {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::STATE_KEY ) );
		$state = is_string( $value ) ? maybe_unserialize( $value ) : array();
		return is_array( $state ) ? $state : array();
	}

	private function replace_state( array $previous, array $next ): bool {
		global $wpdb;
		if ( ! $previous ) { return add_option( self::STATE_KEY, $next, '', false ); }
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize( $next ), self::STATE_KEY, maybe_serialize( $previous ) ) );
		wp_cache_delete( self::STATE_KEY, 'options' );
		return 1 === $result;
	}

	private function schedule_step(): void {
		if ( ! wp_next_scheduled( 'lingora_scan_step' ) ) {
			$result = wp_schedule_single_event( time() + 5, 'lingora_scan_step', array(), true );
			if ( is_wp_error( $result ) || false === $result ) { throw new RuntimeException( __( '扫描调度失败，请重试。', 'lingora' ) ); }
		}
	}
}

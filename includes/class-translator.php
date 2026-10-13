<?php
/**
 * String store and bounded background translation.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Translator {
	private Lingora_Db $db;
	private Lingora_Ai_Client $ai;
	private Lingora_Options $options;
	private Lingora_Languages $languages;

	public function __construct( Lingora_Db $db, Lingora_Ai_Client $ai, Lingora_Options $options, Lingora_Languages $languages ) {
		$this->db        = $db;
		$this->ai        = $ai;
		$this->options   = $options;
		$this->languages = $languages;
	}

	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'schedules' ) );
		add_action( 'lingora_process_queue', array( $this, 'process_queue' ) );
		add_action( 'lingora_run_queue', array( $this, 'process_queue' ) );
		add_action( 'save_post', array( $this, 'post_changed' ), 5, 2 );
		add_action( 'before_delete_post', array( $this, 'post_deleted' ) );
		if ( ! wp_next_scheduled( 'lingora_process_queue' ) ) {
			wp_schedule_event( time() + 60, 'lingora_five_minutes', 'lingora_process_queue' );
		}
	}

	public function schedules( array $schedules ): array {
		$schedules['lingora_five_minutes'] = array( 'interval' => 300, 'display' => __( '每 5 分钟', 'lingora' ) );
		return $schedules;
	}

	public static function is_translatable( string $text ): bool {
		$text = trim( $text );
		$length = Lingora_Text::length( $text );
		return $length > 0 && $length <= 5000
			&& ( $length > 1 || preg_match( '/^[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]$/u', $text ) )
			&& preg_match( '/\p{L}/u', $text )
			&& ! preg_match( '#^(https?:)?//#i', $text ) && ! is_email( $text );
	}

	public static function source_is_public( string $source ): bool {
		if ( ! str_starts_with( $source, 'post:' ) ) {
			return true;
		}
		if ( ! preg_match( '/^post:(\d+)$/', $source, $match ) ) {
			return false;
		}
		$post = get_post( (int) $match[1] );
		return $post instanceof WP_Post && Lingora_Content::is_public_post( $post );
	}

	public function post_changed( int $id, WP_Post $post ): void {
		if ( ! wp_is_post_revision( $id ) && ! Lingora_Content::is_public_post( $post ) ) {
			$this->post_deleted( $id );
		}
	}

	public function post_deleted( int $id ): void {
		$this->db->remove_source( 'post:' . $id );
	}

	/** @param list<string> $originals @return array<string,string> */
	public function resolve( array $originals, string $language, string $source = '', bool $allow_on_fly = true, int $limit_override = -1 ): array {
		if ( ! $this->languages->is_enabled( $language ) || ! self::source_is_public( $source ) ) {
			return array();
		}
		$unique = array();
		foreach ( $originals as $text ) {
			if ( is_string( $text ) && self::is_translatable( $text ) ) {
				$unique[ $this->db->hash( $text ) ] = $text;
			}
		}
		$out = $unique;
		$map = $this->db->map_for_language( array_keys( $unique ), $language );
		$missing = array();
		foreach ( $unique as $hash => $text ) {
			if ( ! isset( $map[ $hash ] ) ) {
				$id = $this->db->upsert_string( $text, 'html', $source );
				if ( $id <= 0 ) {
					continue;
				}
				$map[ $hash ] = array( 'id' => $id, 'original' => $text, 'translated' => null, 'status' => null );
			}
			if ( $this->languages->is_default( $language ) ) {
				continue;
			}
			if ( null !== $map[ $hash ]['translated'] && '' !== $map[ $hash ]['translated'] ) {
				$out[ $hash ] = $map[ $hash ]['translated'];
			} else {
				$missing[ $hash ] = $map[ $hash ];
			}
		}
		if ( ! $missing || ! $this->options->get( 'auto_translate', true ) ) {
			return $out;
		}
		$limit = max( 0, min( 40, $limit_override >= 0 ? $limit_override : (int) $this->options->get( 'on_fly_limit', 8 ) ) );
		$on_fly = $allow_on_fly && $this->options->get( 'translate_on_fly', true ) && $this->options->can_send_ai();
		$batch = array();
		foreach ( $missing as $hash => $row ) {
			if ( $on_fly && count( $batch ) < $limit ) {
				$batch[ $hash ] = $row;
			} else {
				$this->db->queue( (int) $row['id'], $language, 'html', $source );
			}
		}
		if ( $batch ) {
			try {
				$results = $this->ai->translate_batch( array_column( array_values( $batch ), 'original' ), $language, $this->languages->label( $language ), false, Lingora_Ai_Client::TIMEOUT_ON_FLY );
				foreach ( array_keys( $batch ) as $index => $hash ) {
					$row = $batch[ $hash ];
					$value = trim( $results[ $index ] ?? '' );
					if ( ! self::source_is_public( $source ) || ! $this->valid_target( $language ) || ! $this->options->can_send_ai() ) {
					break;
					}
					if ( '' !== $value && $this->db->save_translation( (int) $row['id'], $language, $value ) ) {
						$out[ $hash ] = $value;
					} else {
						$this->db->queue( (int) $row['id'], $language, 'html', $source );
					}
				}
				// An editor may have saved a human version while the AI request was in flight.
				foreach ( $this->db->map_for_language( array_keys( $batch ), $language ) as $hash => $row ) {
					if ( null !== $row['translated'] && '' !== $row['translated'] ) {
						$out[ $hash ] = $row['translated'];
					}
				}
			} catch ( Throwable $e ) {
				foreach ( $batch as $row ) {
					$this->db->queue( (int) $row['id'], $language, 'html', $source );
				}
			}
		}
		return $out;
	}

	public function save_human( int $string_id, string $language, string $translated ): bool {
		return $this->valid_target( $language ) && $this->db->save_translation( $string_id, $language, $translated, 'human' );
	}

	public function request_queue( ?string $language = null ): array {
		$targets = array();
		foreach ( $this->languages->enabled() as $lang ) {
			if ( $this->valid_target( $lang['code'] ) && ( null === $language || $language === $lang['code'] ) ) {
				$targets[] = $lang['code'];
			}
		}
		if ( ! $targets ) {
			return array( 'ok' => false, 'queued' => 0, 'languages' => array(), 'scheduled' => false, 'message' => __( '请先启用至少一种目标语言。', 'lingora' ) );
		}
		$queued = 0;
		foreach ( $targets as $target ) {
			$queued += $this->db->request_manual( $target );
			$queued += $this->translate_missing( $target, 100 )['queued'];
		}
		$this->schedule_queue();
		return array( 'ok' => true, 'queued' => $queued, 'languages' => $targets, 'scheduled' => true, 'message' => __( '已请求后台处理队列；完成时间取决于 WordPress 调度和服务响应。', 'lingora' ) );
	}

	private function schedule_queue(): void {
		if ( ! wp_next_scheduled( 'lingora_run_queue' ) ) {
			$result = wp_schedule_single_event( time() + 5, 'lingora_run_queue', array(), true );
			if ( is_wp_error( $result ) || false === $result ) {
				throw new RuntimeException( __( '任务已入队，但后台调度失败，请重试。', 'lingora' ) );
			}
		}
	}

	public function queue_status(): array {
		return $this->db->queue_status();
	}

	public function process_queue( int $rounds = 1, int $time_budget = 20 ): int {
		if ( ! $this->options->can_send_ai() ) {
			return 0;
		}
		$deadline = microtime( true ) + max( 1, min( 25, $time_budget ) );
		$done = 0;
		for ( $round = 0; $round < max( 1, min( 8, $rounds ) ) && microtime( true ) < $deadline; ++$round ) {
			$items = $this->db->claim_queue( 12, (bool) $this->options->get( 'auto_translate', true ) );
			$rows = array();
			foreach ( $items as $item ) {
				$valid = $this->valid_target( $item->language ) && is_string( $item->original )
					&& self::source_is_public( (string) $item->source )
					&& self::source_is_public( (string) $item->original_source )
					&& ( 'slug' !== $item->context || $this->options->get( 'translate_slugs', true ) );
				if ( ! $valid ) {
					$this->db->complete_queue( (int) $item->id, $item->claim_token );
					continue;
				}
				$rows[] = $item;
			}
			if ( ! $rows ) {
				if ( ! $items ) { break; }
				continue;
			}
			$language = $rows[0]->language;
			try {
				$results = $this->ai->translate_batch( array_map( static fn( $row ) => $row->original, $rows ), $language, $this->languages->label( $language ), false, max( 1, (int) floor( $deadline - microtime( true ) ) ) );
				foreach ( $rows as $index => $row ) {
					if ( ! self::source_is_public( (string) $row->source ) || ! self::source_is_public( (string) $row->original_source ) || ! $this->valid_target( $language ) || ! $this->options->can_send_ai() ) {
						$this->db->complete_queue( (int) $row->id, $row->claim_token );
						continue;
					}
					$value = trim( $results[ $index ] ?? '' );
					if ( 'slug' === $row->context ) { $value = Lingora_Slugs::normalize_slug( $value ); }
					if ( '' === $value ) {
						$this->db->fail_queue( (int) $row->id, $row->claim_token, __( '模型返回空译文。', 'lingora' ) );
					} elseif ( $this->db->complete_queue( (int) $row->id, $row->claim_token, $value ) ) {
						++$done;
					}
				}
			} catch ( Throwable $e ) {
				foreach ( $rows as $row ) { $this->db->fail_queue( (int) $row->id, $row->claim_token, $e->getMessage() ); }
			}
		}
		return $done;
	}

	public function translate_missing( string $language, int $limit = 20 ): array {
		$queued = 0;
		if ( $this->valid_target( $language ) ) {
			foreach ( $this->db->missing_for_language( $language, $limit ) as $row ) {
				if ( self::source_is_public( $row->source ) && ( 'slug' !== $row->context || $this->options->get( 'translate_slugs', true ) ) ) {
					$queued += (int) $this->db->queue_manual( (int) $row->id, $language, $row->context, $row->source );
				}
			}
		}
		return array( 'translated' => 0, 'queued' => $queued );
	}

	public function enqueue_originals( array $originals, string $language, string $source = '', bool $manual = false ): int {
		if ( ! $this->valid_target( $language ) || ! self::source_is_public( $source ) ) {
			return 0;
		}
		$queued = 0;
		foreach ( array_unique( $originals ) as $original ) {
			if ( ! is_string( $original ) || ! self::is_translatable( $original ) ) { continue; }
			$id = $this->db->upsert_string( $original, 'html', $source );
			if ( $id > 0 && ( $manual || $this->options->get( 'auto_translate', true ) ) ) {
				$queued += (int) $this->db->queue( $id, $language, 'html', $source, $manual );
			}
		}
		if ( $manual ) { $this->schedule_queue(); }
		return $queued;
	}

	private function valid_target( string $language ): bool {
		return $this->languages->is_enabled( $language ) && ! $this->languages->is_default( $language );
	}
}

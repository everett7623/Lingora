<?php
/**
 * String store, on-the-fly translation, and queue worker.
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
		$this->ensure_schedule();
	}

	/**
	 * @param array<string, array{interval:int, display:string}> $schedules
	 * @return array<string, array{interval:int, display:string}>
	 */
	public function schedules( array $schedules ): array {
		$schedules['lingora_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 minutes', 'lingora' ),
		);
		return $schedules;
	}

	private function ensure_schedule(): void {
		if ( '5min' === get_option( 'lingora_cron_version' ) && wp_next_scheduled( 'lingora_process_queue' ) ) {
			return;
		}
		wp_clear_scheduled_hook( 'lingora_process_queue' );
		wp_schedule_event( time() + 60, 'lingora_five_minutes', 'lingora_process_queue' );
		update_option( 'lingora_cron_version', '5min', false );
	}

	public static function is_translatable( string $text ): bool {
		$trimmed = trim( $text );
		if ( mb_strlen( $trimmed ) < 2 ) {
			return false;
		}
		if ( ! preg_match( '/\p{L}/u', $trimmed ) ) {
			return false;
		}
		if ( preg_match( '#^(https?:)?//#i', $trimmed ) ) {
			return false;
		}
		if ( is_email( $trimmed ) ) {
			return false;
		}
		if ( mb_strlen( $trimmed ) > 5000 ) {
			return false;
		}
		return true;
	}

	/**
	 * Resolve translations for original strings. Missing ones are stored, optionally
	 * translated immediately, otherwise queued.
	 *
	 * @param list<string> $originals
	 * @return array<string, string> hash => translated (or original)
	 */
	public function resolve( array $originals, string $language, string $source = '', bool $allow_on_fly = true ): array {
		$out = array();
		if ( ! $originals ) {
			return $out;
		}

		$unique = array();
		foreach ( $originals as $text ) {
			if ( ! self::is_translatable( $text ) ) {
				continue;
			}
			$hash = $this->db->hash( $text );
			$unique[ $hash ] = $text;
		}

		$hashes = array_keys( $unique );
		$map    = $hashes ? $this->db->map_for_language( $hashes, $language ) : array();
		$missing = array();

		foreach ( $unique as $hash => $text ) {
			if ( ! isset( $map[ $hash ] ) ) {
				$id = $this->db->upsert_string( $text, 'html', $source );
				$map[ $hash ] = array(
					'id'         => $id,
					'original'   => $text,
					'translated' => null,
					'status'     => null,
				);
			}
			if ( $this->languages->is_default( $language ) ) {
				$out[ $hash ] = $text;
				continue;
			}
			if ( null !== $map[ $hash ]['translated'] && '' !== $map[ $hash ]['translated'] ) {
				$out[ $hash ] = $map[ $hash ]['translated'];
				continue;
			}
			$missing[ $hash ] = $map[ $hash ];
			$out[ $hash ]     = $text;
		}

		if ( ! $missing || ! $this->options->get( 'auto_translate', true ) ) {
			return $out;
		}

		$limit   = max( 0, (int) $this->options->get( 'on_fly_limit', 8 ) );
		$on_fly  = $allow_on_fly && $this->options->get( 'translate_on_fly', true ) && $this->options->has_ai_credential();
		$batch = array();

		foreach ( $missing as $hash => $row ) {
			if ( $on_fly && count( $batch ) < $limit ) {
				$batch[ $hash ] = $row;
			} else {
				$this->db->queue( (int) $row['id'], $language );
			}
		}

		if ( $batch ) {
			try {
				$texts   = array_map( static fn( $row ) => (string) $row['original'], $batch );
				$results = $this->ai->translate_batch( array_values( $texts ), $language, $this->languages->label( $language ) );
				$i       = 0;
				foreach ( $batch as $hash => $row ) {
					$translated = trim( (string) ( $results[ $i ] ?? '' ) );
					++$i;
					if ( '' === $translated ) {
						$this->db->queue( (int) $row['id'], $language );
						continue;
					}
					$this->db->save_translation( (int) $row['id'], $language, $translated, 'auto' );
					$out[ $hash ] = $translated;
				}
			} catch ( Throwable $e ) {
				foreach ( $batch as $row ) {
					$this->db->queue( (int) $row['id'], $language );
				}
			}
		}

		return $out;
	}

	public function save_human( int $string_id, string $language, string $translated ): void {
		$this->db->save_translation( $string_id, $language, $translated, 'human' );
	}

	public function process_queue(): void {
		if ( ! $this->options->has_ai_credential() ) {
			return;
		}
		$items = $this->db->pull_queue( 12 );
		if ( ! $items ) {
			return;
		}
		$groups = array();
		foreach ( $items as $item ) {
			$groups[ $item->language ][] = $item;
		}
		foreach ( $groups as $language => $rows ) {
			try {
				$texts   = array_map( static fn( $row ) => (string) $row->original, $rows );
				$results = $this->ai->translate_batch( $texts, $language, $this->languages->label( $language ) );
				foreach ( $rows as $i => $row ) {
					$translated = trim( (string) ( $results[ $i ] ?? '' ) );
					if ( '' === $translated ) {
						$this->db->bump_queue_item( (int) $row->id );
						continue;
					}
					$this->db->save_translation( (int) $row->string_id, $language, $translated, 'auto' );
					$this->db->drop_queue_item( (int) $row->id );
				}
			} catch ( Throwable $e ) {
				foreach ( $rows as $row ) {
					$this->db->bump_queue_item( (int) $row->id );
				}
			}
		}
	}
}

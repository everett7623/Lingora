<?php
/**
 * Custom tables for strings and translations.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Db {
	public const DB_VERSION = '0.0.1';

	public static function strings_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lingora_strings';
	}

	public static function translations_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lingora_translations';
	}

	public static function queue_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lingora_queue';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$strings  = self::strings_table();
		$trans    = self::translations_table();
		$queue    = self::queue_table();

		dbDelta(
			"CREATE TABLE {$strings} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				hash char(32) NOT NULL,
				original longtext NOT NULL,
				context varchar(32) NOT NULL DEFAULT 'html',
				source varchar(191) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY hash_context (hash, context),
				KEY created_at (created_at)
			) {$charset};\n"
		);

		dbDelta(
			"CREATE TABLE {$trans} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				string_id bigint(20) unsigned NOT NULL,
				language varchar(12) NOT NULL,
				translated longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'auto',
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY string_lang (string_id, language),
				KEY language (language),
				KEY status (status)
			) {$charset};\n"
		);

		dbDelta(
			"CREATE TABLE {$queue} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				string_id bigint(20) unsigned NOT NULL,
				language varchar(12) NOT NULL,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY string_lang (string_id, language)
			) {$charset};\n"
		);

		update_option( 'lingora_db_version', self::DB_VERSION );
	}

	public function hash( string $text ): string {
		return md5( html_entity_decode( trim( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * @return object{id: int, hash: string, original: string, context: string}|null
	 */
	public function get_string_by_hash( string $hash, string $context = 'html' ): ?object {
		global $wpdb;
		$table  = self::strings_table();
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, hash, original, context FROM {$table} WHERE hash = %s AND context = %s",
				$hash,
				$context
			)
		);
		return $row ?: null;
	}

	public function upsert_string( string $original, string $context = 'html', string $source = '' ): int {
		global $wpdb;
		$hash = $this->hash( $original );
		$existing = $this->get_string_by_hash( $hash, $context );
		if ( $existing ) {
			return (int) $existing->id;
		}

		$wpdb->insert(
			self::strings_table(),
			array(
				'hash'       => $hash,
				'original'   => $original,
				'context'    => $context,
				'source'     => mb_substr( $source, 0, 191 ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param list<string> $hashes
	 * @return array<string, array{id:int, original:string, translated:?string, status:?string}>
	 */
	public function map_for_language( array $hashes, string $language, string $context = 'html' ): array {
		if ( ! $hashes ) {
			return array();
		}

		global $wpdb;
		$strings_table = self::strings_table();
		$trans_table   = self::translations_table();
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$sql          = $wpdb->prepare(
			"SELECT s.hash, s.id, s.original, t.translated, t.status
			FROM {$strings_table} s
			LEFT JOIN {$trans_table} t ON t.string_id = s.id AND t.language = %s
			WHERE s.context = %s AND s.hash IN ({$placeholders})",
			...array_merge( array( $language, $context ), $hashes )
		);

		$rows = $wpdb->get_results( $sql );
		$map  = array();
		foreach ( $rows as $row ) {
			$map[ $row->hash ] = array(
				'id'         => (int) $row->id,
				'original'   => (string) $row->original,
				'translated' => null === $row->translated ? null : (string) $row->translated,
				'status'     => $row->status,
			);
		}
		return $map;
	}

	public function save_translation( int $string_id, string $language, string $translated, string $status = 'auto' ): void {
		global $wpdb;
		$table = self::translations_table();
		$now   = current_time( 'mysql' );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (string_id, language, translated, status, updated_at)
				VALUES (%d, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE translated = VALUES(translated), status = VALUES(status), updated_at = VALUES(updated_at)",
				$string_id,
				$language,
				$translated,
				$status,
				$now
			)
		);
	}

	public function queue( int $string_id, string $language ): void {
		global $wpdb;
		$table = self::queue_table();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (string_id, language, attempts, created_at) VALUES (%d, %s, 0, %s)",
				$string_id,
				$language,
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * @return list<object>
	 */
	public function pull_queue( int $limit = 20 ): array {
		global $wpdb;
		$table = self::queue_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT q.id, q.string_id, q.language, q.attempts, s.original
				FROM {$table} q
				INNER JOIN " . self::strings_table() . " s ON s.id = q.string_id
				ORDER BY q.id ASC
				LIMIT %d",
				$limit
			)
		);
	}

	public function drop_queue_item( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::queue_table(), array( 'id' => $id ), array( '%d' ) );
	}

	public function bump_queue_item( int $id ): void {
		global $wpdb;
		$table = self::queue_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d", $id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND attempts >= 5", $id ) );
	}

	/**
	 * @return array{strings:int, translations:int, human:int, auto:int, queued:int}
	 */
	public function stats(): array {
		global $wpdb;
		return array(
			'strings'      => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::strings_table() ),
			'translations' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::translations_table() ),
			'human'        => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::translations_table() . ' WHERE status = %s', 'human' ) ),
			'auto'         => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::translations_table() . ' WHERE status = %s', 'auto' ) ),
			'queued'       => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::queue_table() ),
		);
	}

	/**
	 * @return array{items: list<object>, total: int}
	 */
	public function search_strings( string $search, string $language, int $page = 1, int $per_page = 20 ): array {
		global $wpdb;
		$offset        = max( 0, ( $page - 1 ) * $per_page );
		$strings_table = self::strings_table();
		$trans_table   = self::translations_table();
		$like          = '%' . $wpdb->esc_like( $search ) . '%';

		if ( $search ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$strings_table} WHERE original LIKE %s",
					$like
				)
			);
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.id, s.hash, s.original, s.created_at, t.translated, t.status
					FROM {$strings_table} s
					LEFT JOIN {$trans_table} t ON t.string_id = s.id AND t.language = %s
					WHERE s.original LIKE %s
					ORDER BY s.id DESC
					LIMIT %d OFFSET %d",
					$language,
					$like,
					$per_page,
					$offset
				)
			);
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$strings_table}" );
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.id, s.hash, s.original, s.created_at, t.translated, t.status
					FROM {$strings_table} s
					LEFT JOIN {$trans_table} t ON t.string_id = s.id AND t.language = %s
					ORDER BY s.id DESC
					LIMIT %d OFFSET %d",
					$language,
					$per_page,
					$offset
				)
			);
		}

		return array(
			'items' => $items ?: array(),
			'total' => $total,
		);
	}

	public function original_for_translation( string $translated, string $language, string $context = 'html' ): string {
		global $wpdb;
		$strings_table = self::strings_table();
		$trans_table   = self::translations_table();
		$value         = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT s.original
				FROM {$strings_table} s
				INNER JOIN {$trans_table} t ON t.string_id = s.id
				WHERE s.context = %s AND t.language = %s AND t.translated = %s
				LIMIT 1",
				$context,
				$language,
				$translated
			)
		);
		return is_string( $value ) ? $value : '';
	}

	/**
	 * @return list<int>
	 */
	public function post_ids_for_translated_search( string $term, string $language ): array {
		global $wpdb;
		$strings_table = self::strings_table();
		$trans_table   = self::translations_table();
		$like          = '%' . $wpdb->esc_like( $term ) . '%';
		$sources       = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT s.source
				FROM {$strings_table} s
				INNER JOIN {$trans_table} t ON t.string_id = s.id
				WHERE t.language = %s AND t.translated LIKE %s AND s.source LIKE %s
				LIMIT 50",
				$language,
				$like,
				'post:%'
			)
		);
		$ids = array();
		foreach ( $sources as $source ) {
			if ( preg_match( '/^post:(\d+)$/', (string) $source, $match ) ) {
				$ids[] = (int) $match[1];
			}
		}
		return array_values( array_unique( $ids ) );
	}
}

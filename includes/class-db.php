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
	public const DB_VERSION = '0.0.2';
	public const QUEUE_LEASE = 900;

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
		if ( self::DB_VERSION === get_option( 'lingora_db_version' ) ) {
			return;
		}
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
				context varchar(32) NOT NULL DEFAULT 'html',
				source varchar(191) NOT NULL DEFAULT '',
				manual tinyint(1) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'pending',
				claim_token varchar(36) NOT NULL DEFAULT '',
				claimed_at datetime DEFAULT NULL,
				available_at datetime DEFAULT NULL,
				last_error text DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY string_lang (string_id, language),
				KEY queue_ready (status, available_at),
				KEY claim_token (claim_token)
			) {$charset};\n"
		);

		// Backfill legacy queue provenance without touching translations or retry counts.
		$wpdb->query( "UPDATE {$queue} SET context = (SELECT context FROM {$strings} WHERE id = {$queue}.string_id), source = (SELECT source FROM {$strings} WHERE id = {$queue}.string_id) WHERE EXISTS (SELECT id FROM {$strings} WHERE id = {$queue}.string_id)" );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( __( '翻译数据库升级失败，请检查数据库权限后重试。', 'lingora' ) );
		}
		update_option( 'lingora_db_version', self::DB_VERSION, false );
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
				"SELECT id, hash, original, context, source FROM {$table} WHERE hash = %s AND context = %s",
				$hash,
				$context
			)
		);
		return $row ?: null;
	}

	public function get_string( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, hash, original, context, source FROM %i WHERE id = %d', self::strings_table(), $id ) );
		return $row ?: null;
	}

	/**
	 * 返回原文 ID；唯一键处理并发，查询结果而非连接上的旧 insert_id 决定返回值。
	 */
	public function upsert_string( string $original, string $context = 'html', string $source = '' ): int {
		global $wpdb;
		$hash = $this->hash( $original );
		$existing = $this->get_string_by_hash( $hash, $context );
		if ( $existing ) {
			if ( '' !== $source && Lingora_Translator::source_is_public( $source ) && ! Lingora_Translator::source_is_public( $existing->source ) ) {
				$wpdb->update( self::strings_table(), array( 'source' => $source ), array( 'id' => $existing->id ), array( '%s' ), array( '%d' ) );
			}
			return (int) $existing->id;
		}
		$table = self::strings_table();
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (hash, original, context, source, created_at) VALUES (%s, %s, %s, %s, %s)",
				$hash, $original, $context, Lingora_Text::substring( $source, 0, 191 ), gmdate( 'Y-m-d H:i:s' )
			)
		);
		if ( false === $result ) {
			throw new RuntimeException( __( '原文保存失败，请检查数据库后重试。', 'lingora' ) );
		}
		$row = $this->get_string_by_hash( $hash, $context );
		return $row ? (int) $row->id : 0;
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
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The IN clause contains generated %s placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.hash, s.id, s.original, s.source, t.translated, t.status
			FROM %i s LEFT JOIN %i t ON t.string_id = s.id AND t.language = %s
			WHERE s.context = %s AND s.hash IN ({$placeholders})",
			array_merge( array( $strings_table, $trans_table, $language, $context ), $hashes )
		) );
		$map  = array();
		foreach ( $rows as $row ) {
			if ( ! Lingora_Translator::source_is_public( $row->source ) ) { continue; }
			$map[ $row->hash ] = array(
				'id'         => (int) $row->id,
				'original'   => (string) $row->original,
				'translated' => null === $row->translated ? null : (string) $row->translated,
				'status'     => $row->status,
			);
		}
		return $map;
	}

	/** Automatic writes check human ownership in the SQL statement, including in-flight AI races. */
	public function save_translation( int $string_id, string $language, string $translated, string $status = 'auto' ): bool {
		if ( $string_id <= 0 || '' === trim( $translated ) || ! Lingora_Languages::is_valid_code( $language ) || ! in_array( $status, array( 'auto', 'human' ), true ) ) {
			return false;
		}
		$source = $this->get_string( $string_id );
		if ( ! $source || ! Lingora_Translator::source_is_public( $source->source ) ) { return false; }
		return $this->write_translation( $string_id, $language, $translated, $status );
	}

	private function write_translation( int $string_id, string $language, string $translated, string $status, int $queue_id = 0, string $token = '' ): bool {
		global $wpdb;
		$table = self::translations_table();
		$strings = self::strings_table();
		$status = 'human' === $status ? 'human' : 'auto';
		$fence = '';
		if ( $queue_id ) {
			$fence = $wpdb->prepare( " AND EXISTS (SELECT id FROM %i WHERE id = %d AND claim_token = %s AND status = 'claimed' AND claimed_at > %s)", self::queue_table(), $queue_id, $token, gmdate( 'Y-m-d H:i:s', time() - self::QUEUE_LEASE ) );
		}
		$fence .= $wpdb->prepare( " AND (s.source NOT LIKE %s OR EXISTS (SELECT ID FROM {$wpdb->posts} WHERE ID = CAST(SUBSTRING(s.source, 6) AS UNSIGNED) AND post_status = %s AND post_password = %s))", 'post:%', 'publish', '' );
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (string_id, language, translated, status, updated_at)
				SELECT s.id, %s, %s, %s, %s FROM {$strings} s WHERE s.id = %d {$fence}
				ON DUPLICATE KEY UPDATE
				translated = CASE WHEN status = 'human' AND VALUES(status) = 'auto' THEN translated ELSE VALUES(translated) END,
				updated_at = CASE WHEN status = 'human' AND VALUES(status) = 'auto' THEN updated_at ELSE VALUES(updated_at) END,
				status = CASE WHEN status = 'human' AND VALUES(status) = 'auto' THEN status ELSE VALUES(status) END",
				$language, $translated, $status, gmdate( 'Y-m-d H:i:s' ), $string_id
			)
		);
		if ( false === $result ) {
			throw new RuntimeException( __( '译文保存失败，请检查数据库后重试。', 'lingora' ) );
		}
		// A repeated identical human save succeeds; affected_rows alone cannot express this.
		$saved = $wpdb->get_row( $wpdb->prepare( "SELECT t.status, t.translated FROM {$table} t INNER JOIN {$strings} s ON s.id = t.string_id WHERE t.string_id = %d AND t.language = %s", $string_id, $language ) );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( __( '无法确认译文保存结果，请重试。', 'lingora' ) );
		}
		$persisted = $saved && $saved->status === $status && $saved->translated === $translated;
		if ( $persisted && 'human' === $status ) {
			if ( false === $wpdb->delete( self::queue_table(), array( 'string_id' => $string_id, 'language' => $language ), array( '%d', '%s' ) ) ) {
				throw new RuntimeException( __( '译文已保存，但待译任务清理失败，请重试。', 'lingora' ) );
			}
		}
		return (bool) $persisted;
	}

	public function queue( int $string_id, string $language, string $context = 'html', string $source = '', bool $manual = false ): bool {
		global $wpdb;
		$table = self::queue_table();
		$strings = self::strings_table();
		$trans = self::translations_table();
		$result = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (string_id, language, context, source, manual, attempts, status, created_at)
			SELECT s.id, %s, s.context, s.source, %d, 0, 'pending', %s FROM {$strings} s
			LEFT JOIN {$trans} t ON t.string_id = s.id AND t.language = %s
			WHERE s.id = %d AND (t.id IS NULL OR (t.translated = '' AND t.status <> 'human'))",
			$language, (int) $manual, gmdate( 'Y-m-d H:i:s' ), $language, $string_id
		) );
		if ( false === $result ) {
			throw new RuntimeException( __( '待译任务保存失败，请检查数据库后重试。', 'lingora' ) );
		}
		if ( $result > 0 ) {
			return true;
		}
		if ( ! $manual ) {
			return false;
		}
		// Only an explicit request revives a terminal failure; normal page views never reset retries.
		$revived = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET manual = 1, attempts = 0, status = 'pending', claim_token = '', claimed_at = NULL, available_at = NULL, last_error = NULL
			WHERE string_id = %d AND language = %s AND status = 'failed'
			AND EXISTS (SELECT id FROM {$strings} WHERE id = %d)
			AND NOT EXISTS (SELECT id FROM {$trans} WHERE string_id = %d AND language = %s AND (status = 'human' OR translated <> ''))",
			$string_id, $language, $string_id, $string_id, $language
		) );
		$promoted = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET manual = 1 WHERE string_id = %d AND language = %s AND manual = 0", $string_id, $language ) );
		if ( false === $revived || false === $promoted ) {
			throw new RuntimeException( __( '待译任务更新失败，请重试。', 'lingora' ) );
		}
		return $revived > 0 || $promoted > 0;
	}

	public function queue_manual( int $string_id, string $language, string $context = 'html', string $source = '' ): bool {
		return $this->queue( $string_id, $language, $context, $source, true );
	}

	public function request_manual( string $language ): int {
		global $wpdb;
		$table = self::queue_table();
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET manual = 1, attempts = CASE WHEN status = 'failed' THEN 0 ELSE attempts END, available_at = CASE WHEN status = 'failed' THEN NULL ELSE available_at END, last_error = CASE WHEN status = 'failed' THEN NULL ELSE last_error END, status = 'pending' WHERE language = %s AND (status = 'failed' OR (status = 'pending' AND manual = 0))", $language ) );
		if ( false === $result ) {
			throw new RuntimeException( __( '无法请求手动处理队列，请重试。', 'lingora' ) );
		}
		return (int) $result;
	}

	/** Claim one homogeneous batch. The conditional UPDATE is the concurrency boundary. */
	public function claim_queue( int $limit = 12, bool $include_automatic = true ): array {
		global $wpdb;
		$table = self::queue_table();
		$now = gmdate( 'Y-m-d H:i:s' );
		$expired = gmdate( 'Y-m-d H:i:s', time() - self::QUEUE_LEASE );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'failed', claim_token = '', claimed_at = NULL, last_error = %s WHERE status = 'claimed' AND claimed_at <= %s AND attempts >= 5", __( '任务租约到期，已达到重试上限。', 'lingora' ), $expired ) );
		$ready = $wpdb->prepare( "attempts < 5 AND ((status = 'pending' AND (available_at IS NULL OR available_at <= %s)) OR (status = 'claimed' AND claimed_at <= %s))", $now, $expired );
		if ( ! $include_automatic ) {
			$ready .= ' AND manual = 1';
		}
		$first = $wpdb->get_row( "SELECT language, context FROM {$table} WHERE {$ready} ORDER BY id ASC LIMIT 1" );
		if ( ! $first ) {
			return array();
		}
		$group = $wpdb->prepare( ' AND language = %s AND context = %s', $first->language, $first->context );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE {$ready} {$group} ORDER BY id ASC LIMIT %d", max( 1, min( 40, $limit ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$ids_sql = implode( ',', array_map( 'intval', $ids ) );
		$token = wp_generate_uuid4();
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'claimed', claim_token = %s, claimed_at = %s, attempts = attempts + 1 WHERE id IN ({$ids_sql}) AND {$ready}", $token, $now ) );
		if ( false === $result ) {
			throw new RuntimeException( __( '无法领取待译任务，请检查数据库后重试。', 'lingora' ) );
		}
		return $wpdb->get_results( $wpdb->prepare( 'SELECT q.*, s.original, s.source AS original_source, s.context AS original_context FROM %i q LEFT JOIN %i s ON s.id = q.string_id WHERE q.claim_token = %s ORDER BY q.id ASC', $table, self::strings_table(), $token ) ) ?: array();
	}

	/** Optional translated text is persisted under the same live claim fence. */
	public function complete_queue( int $id, string $token, ?string $translated = null ): bool {
		global $wpdb;
		$table = self::queue_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT string_id, language FROM {$table} WHERE id = %d AND claim_token = %s AND status = 'claimed' AND claimed_at > %s", $id, $token, gmdate( 'Y-m-d H:i:s', time() - self::QUEUE_LEASE ) ) );
		if ( ! $row || '' === $token ) {
			return false;
		}
		$saved = null === $translated || $this->write_translation( (int) $row->string_id, $row->language, $translated, 'auto', $id, $token );
		$removed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND claim_token = %s AND status = 'claimed' AND claimed_at > %s", $id, $token, gmdate( 'Y-m-d H:i:s', time() - self::QUEUE_LEASE ) ) );
		return $saved && $removed > 0;
	}

	public function fail_queue( int $id, string $token, string $error ): bool {
		global $wpdb;
		$table = self::queue_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d AND claim_token = %s AND status = 'claimed'", $id, $token ) );
		if ( ! $row || '' === $token ) {
			return false;
		}
		$attempts = (int) $row->attempts;
		$result = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = %s, claim_token = '', claimed_at = NULL, available_at = %s, last_error = %s WHERE id = %d AND claim_token = %s AND status = 'claimed' AND claimed_at > %s",
			$attempts >= 5 ? 'failed' : 'pending', gmdate( 'Y-m-d H:i:s', time() + 60 * ( 2 ** max( 0, min( 3, $attempts - 1 ) ) ) ),
			Lingora_Text::substring( sanitize_text_field( $error ), 0, 1000 ), $id, $token, gmdate( 'Y-m-d H:i:s', time() - self::QUEUE_LEASE )
		) );
		return $result > 0;
	}

	public function queue_status(): array {
		global $wpdb;
		$table = self::queue_table();
		$now = gmdate( 'Y-m-d H:i:s' );
		$expired = gmdate( 'Y-m-d H:i:s', time() - self::QUEUE_LEASE );
		$conditions = array(
			'pending' => $wpdb->prepare( "(status = 'pending' AND (available_at IS NULL OR available_at <= %s)) OR (status = 'claimed' AND claimed_at <= %s AND attempts < 5)", $now, $expired ),
			'delayed' => $wpdb->prepare( "status = 'pending' AND available_at > %s", $now ),
			'claimed' => $wpdb->prepare( "status = 'claimed' AND claimed_at > %s", $expired ),
			'failed' => $wpdb->prepare( "status = 'failed' OR (status = 'claimed' AND claimed_at <= %s AND attempts >= 5)", $expired ),
			'manual' => "manual = 1 AND status = 'pending'",
		);
		$out = array();
		foreach ( $conditions as $key => $condition ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name comes from the static WordPress prefix helper; all values are prepared above.
			$value = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$condition}" );
			if ( '' !== $wpdb->last_error ) { throw new RuntimeException( __( '无法读取队列状态，请检查数据库。', 'lingora' ) ); }
			$out[ $key ] = (int) $value;
		}
		$out['oldest'] = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(created_at) FROM %i WHERE status = %s', $table, 'pending' ) );
		return $out;
	}

	/** Stop work for a withdrawn source without deleting its saved translations. */
	public function remove_source( string $source ): void {
		global $wpdb;
		$strings = self::strings_table();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$strings} WHERE source = %s", $source ) );
		$wpdb->delete( self::queue_table(), array( 'source' => $source ), array( '%s' ) );
		foreach ( array_chunk( $ids, 100 ) as $chunk ) {
			$ids_sql = implode( ',', array_map( 'intval', $chunk ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE string_id IN ({$ids_sql})", self::queue_table() ) );
		}
	}

	/** @return array{strings:int, translations:int, human:int, auto:int, queued:int, failed:int} */
	public function stats(): array {
		global $wpdb;
		$queue = $this->queue_status();
		return array(
			'strings'      => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::strings_table() ) ),
			'translations' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::translations_table() ) ),
			'human'        => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', self::translations_table(), 'human' ) ),
			'auto'         => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', self::translations_table(), 'auto' ) ),
			'queued'       => $queue['pending'] + $queue['delayed'] + $queue['claimed'],
			'failed'       => $queue['failed'],
		);
	}

	/**
	 * @return array{items: list<object>, total: int}
	 */
	public function search_strings( string $search, string $language, int $page = 1, int $per_page = 20, string $status = '', string $kind = '' ): array {
		global $wpdb;
		$per_page      = max( 1, min( 100, $per_page ) );
		$offset        = max( 0, ( $page - 1 ) * $per_page );
		$strings_table = self::strings_table();
		$trans_table   = self::translations_table();

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $search ) {
			$where[]  = '(s.original LIKE %s OR t.translated LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		if ( 'slug' === $kind ) {
			$where[]  = 's.context = %s';
			$params[] = 'slug';
		} elseif ( 'text' === $kind ) {
			$where[]  = 's.context <> %s';
			$params[] = 'slug';
		}

		if ( 'missing' === $status ) {
			$where[] = '(t.id IS NULL OR t.translated = \'\')';
		} elseif ( 'auto' === $status ) {
			$where[]  = 't.status = %s';
			$params[] = 'auto';
		} elseif ( 'human' === $status ) {
			$where[]  = 't.status = %s';
			$params[] = 'human';
		}

		$where_sql = implode( ' AND ', $where );
		$from_sql  = "FROM {$strings_table} s LEFT JOIN {$trans_table} t ON t.string_id = s.id AND t.language = %s";

		$count_sql    = "SELECT COUNT(*) {$from_sql} WHERE {$where_sql}";
		$count_params = array_merge( array( $language ), $params );
		$total        = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$count_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$item_sql    = "SELECT s.id, s.hash, s.original, s.context, s.created_at, t.translated, t.status {$from_sql} WHERE {$where_sql} ORDER BY s.id DESC LIMIT %d OFFSET %d";
		$item_params = array_merge( array( $language ), $params, array( $per_page, $offset ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $item_sql, ...$item_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items' => $items ?: array(),
			'total' => $total,
		);
	}

	public function original_for_translation( string $translated, string $language, string $context = 'html' ): string {
		global $wpdb;
		$strings_table = self::strings_table();
		$trans_table   = self::translations_table();
		// 译文可能碰撞（多个原文同一译文）：按 id 升序取候补，并跳过已撤回来源，
		// 避免反向映射到错误页面或暴露私密内容。
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.original, s.source
				FROM %i s
				INNER JOIN %i t ON t.string_id = s.id
				WHERE s.context = %s AND t.language = %s AND t.translated = %s
				ORDER BY s.id ASC
				LIMIT 10",
				$strings_table,
				$trans_table,
				$context,
				$language,
				$translated
			)
		);
		if ( '' !== $wpdb->last_error ) {
			return '';
		}
		foreach ( $rows ?: array() as $row ) {
			if ( Lingora_Translator::source_is_public( (string) $row->source ) ) {
				return (string) $row->original;
			}
		}
		return '';
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
				FROM %i s
				INNER JOIN %i t ON t.string_id = s.id
				WHERE t.language = %s AND t.translated LIKE %s AND s.source LIKE %s
				LIMIT 50",
				$strings_table,
				$trans_table,
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

	/**
	 * @return list<object{id:int, original:string}>
	 */
	public function missing_for_language( string $language, int $limit = 20 ): array {
		global $wpdb;
		$strings_table = self::strings_table();
		$trans_table   = self::translations_table();
		$limit         = max( 1, min( 100, $limit ) );
		$queue_table   = self::queue_table();
		$rows          = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.original, s.context, s.source
				FROM %i s
				LEFT JOIN %i t ON t.string_id = s.id AND t.language = %s
				WHERE (t.id IS NULL OR (t.translated = '' AND t.status <> 'human'))
				AND NOT EXISTS (SELECT id FROM %i q WHERE q.string_id = s.id AND q.language = %s AND q.status <> 'failed' AND q.manual = 1)
				ORDER BY s.id DESC
				LIMIT %d",
				$strings_table,
				$trans_table,
				$language,
				$queue_table,
				$language,
				$limit
			)
		);
		return $rows ?: array();
	}
}

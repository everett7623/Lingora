<?php
/**
 * Include translated copy when visitors search.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Search {
	private Lingora_Db $db;
	private Lingora_Languages $languages;
	private Lingora_Options $options;

	public function __construct( Lingora_Db $db, Lingora_Languages $languages, Lingora_Options $options ) {
		$this->db        = $db;
		$this->languages = $languages;
		$this->options   = $options;
	}

	public function register(): void {
		if ( ! $this->options->get( 'search_translations', true ) ) {
			return;
		}
		add_filter( 'posts_search', array( $this, 'posts_search' ), 20, 2 );
	}

	/**
	 * 把命中译文的文章 ID 并进搜索子句。
	 *
	 * 必须挂 posts_search 而不是 posts_where：posts_where 的末尾追加 " OR ..." 会因为
	 * OR 优先级低于 AND，把同在 WHERE 里的 post_status / post_type 条件一起短路掉，
	 * 导致草稿和私密文章出现在公开搜索结果里。posts_search 只覆盖搜索这一段，
	 * 状态与类型过滤仍然以 AND 生效。
	 */
	public function posts_search( string $search, WP_Query $query ): string {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return $search;
		}
		if ( $this->languages->is_default() ) {
			return $search;
		}
		$term = $query->get( 's' );
		if ( ! is_string( $term ) || '' === trim( $term ) ) {
			return $search;
		}
		// WP 生成的搜索子句形如 " AND (((post_title LIKE ...) OR ...))"。
		// 拿不到这个结构就原样返回，不去猜。
		if ( ! preg_match( '/^\s*AND\s+(.+)$/is', $search, $match ) ) {
			return $search;
		}
		$ids = $this->db->post_ids_for_translated_search( $term, $this->languages->current() );
		if ( ! $ids ) {
			return $search;
		}
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ), static function ( int $id ): bool {
			$post = get_post( $id );
			return $post instanceof WP_Post && Lingora_Content::is_public_post( $post );
		} ) );
		if ( ! $ids ) {
			return $search;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$translated = $wpdb->prepare( "{$wpdb->posts}.ID IN ({$placeholders}) AND {$wpdb->posts}.post_status = %s AND {$wpdb->posts}.post_password = %s", ...array_merge( $ids, array( 'publish', '' ) ) );
		return ' AND ( (' . $match[1] . ') OR (' . $translated . ') )';
	}
}

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
		add_filter( 'posts_where', array( $this, 'posts_where' ), 20, 2 );
	}

	public function posts_where( string $where, WP_Query $query ): string {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return $where;
		}
		if ( $this->languages->is_default() ) {
			return $where;
		}
		$term = $query->get( 's' );
		if ( ! is_string( $term ) || '' === trim( $term ) ) {
			return $where;
		}
		$ids = $this->db->post_ids_for_translated_search( $term, $this->languages->current() );
		if ( ! $ids ) {
			return $where;
		}
		global $wpdb;
		$list = implode( ',', array_map( 'absint', $ids ) );
		$where .= " OR {$wpdb->posts}.ID IN ({$list})";
		return $where;
	}
}

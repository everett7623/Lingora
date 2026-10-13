<?php
/**
 * Dictionary-backed public post and page slugs.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Slugs {
	private Lingora_Db $db;
	private Lingora_Languages $languages;
	private Lingora_Options $options;
	/** @var list<string>|null */
	private ?array $slug_query_vars = null;

	public function __construct( Lingora_Db $db, Lingora_Ai_Client $ai, Lingora_Languages $languages, Lingora_Options $options ) {
		$this->db = $db;
		$this->languages = $languages;
		$this->options = $options;
	}

	public static function normalize_slug( string $value ): string {
		$value = trim( rawurldecode( $value ), " \t\r\n\"'" );
		$value = str_replace( array( '/', '\\' ), ' ', $value );
		return trim( rawurldecode( sanitize_title( $value ) ), '-' );
	}

	public function register(): void {
		add_action( 'save_post', array( $this, 'queue_post' ), 20, 2 );
		add_filter( 'request', array( $this, 'map_request' ) );
		add_filter( 'post_link', array( $this, 'filter_permalink' ), 30, 2 );
		add_filter( 'page_link', array( $this, 'filter_page_link' ), 30, 2 );
		add_filter( 'post_type_link', array( $this, 'filter_permalink' ), 30, 2 );
	}

	public function queue_post( int $post_id, WP_Post $post ): void {
		if ( ! $this->options->get( 'translate_slugs', true ) || wp_is_post_revision( $post_id ) || ! Lingora_Content::is_public_post( $post ) || '' === $post->post_name ) { return; }
		$id = $this->db->upsert_string( $post->post_name, 'slug', 'post:' . $post_id );
		if ( ! $this->options->get( 'auto_translate', true ) ) { return; }
		foreach ( $this->languages->enabled() as $lang ) {
			if ( ! $this->languages->is_default( $lang['code'] ) ) { $this->db->queue( $id, $lang['code'], 'slug', 'post:' . $post_id ); }
		}
	}

	/**
	 * 请求侧还原需覆盖所有公开类型的查询变量：post 用 name、page 用 pagename，
	 * 其余公开 CPT 默认 query_var 即类型名。硬编码三键会让其它 CPT 的译文链接 404。
	 *
	 * @return list<string>
	 */
	private function slug_query_vars(): array {
		if ( null === $this->slug_query_vars ) {
			$keys = array( 'name', 'pagename' );
			foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
				if ( ! $type instanceof WP_Post_Type || 'attachment' === $type->name ) {
					continue;
				}
				if ( ! empty( $type->query_var ) && is_string( $type->query_var ) ) {
					$keys[] = $type->query_var;
				}
			}
			$this->slug_query_vars = array_values( array_unique( $keys ) );
		}
		return $this->slug_query_vars;
	}

	public function map_request( array $vars ): array {
		if ( is_admin() || ! $this->options->get( 'translate_slugs', true ) || $this->languages->is_default() ) { return $vars; }
		foreach ( $this->slug_query_vars() as $key ) {
			if ( empty( $vars[ $key ] ) || ! is_string( $vars[ $key ] ) ) { continue; }
			$segments = explode( '/', $vars[ $key ] );
			foreach ( $segments as &$segment ) {
				$original = $this->db->original_for_translation( self::normalize_slug( $segment ), $this->languages->current(), 'slug' );
				if ( '' !== $original ) { $segment = $original; }
			}
			unset( $segment );
			$vars[ $key ] = implode( '/', $segments );
		}
		return $vars;
	}

	public function filter_permalink( string $url, $post ): string {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post || ! Lingora_Content::is_public_post( $post ) || is_admin() || ! $this->options->get( 'translate_slugs', true ) || $this->languages->is_default() ) { return $url; }
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) ) { return $url; }
		$chain = array_reverse( get_post_ancestors( $post ) );
		$chain[] = $post->ID;
		$segments = explode( '/', $path );
		$position = count( $segments ) - 1;
		while ( $position >= 0 && '' === $segments[ $position ] ) { --$position; }
		foreach ( array_reverse( $chain ) as $id ) {
			$part = get_post( $id );
			if ( ! $part instanceof WP_Post || ! Lingora_Content::is_public_post( $part ) ) { continue; }
			$translated = $this->translated_slug( $part->post_name, $this->languages->current() );
			for ( ; $position >= 0; --$position ) {
				$decoded = rawurldecode( $segments[ $position ] );
				if ( $decoded === rawurldecode( $part->post_name ) || ( '' !== $translated && $decoded === $translated ) ) {
					if ( '' !== $translated ) { $segments[ $position ] = rawurlencode( $translated ); }
					--$position;
					break;
				}
			}
		}
		$new_path = implode( '/', $segments );
		$parts = wp_parse_url( $url );
		$prefix = isset( $parts['host'] ) ? ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '//' ) . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) : '';
		return $prefix . $new_path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	public function filter_page_link( string $url, $post_id ): string {
		return $this->filter_permalink( $url, $post_id );
	}

	public function path_for_language( string $path, string $source_language, string $target ): string {
		if ( ! $this->options->get( 'translate_slugs', true ) ) { return $path; }
		$segments = explode( '/', $path );
		foreach ( $segments as &$segment ) {
			if ( '' === $segment ) { continue; }
			$original = $segment;
			if ( ! $this->languages->is_default( $source_language ) ) {
				$found = $this->db->original_for_translation( self::normalize_slug( $segment ), $source_language, 'slug' );
				if ( '' !== $found ) { $original = $found; }
			}
			$translated = $this->languages->is_default( $target ) ? '' : $this->translated_slug( $original, $target );
			$segment = '' !== $translated ? rawurlencode( $translated ) : $original;
		}
		unset( $segment );
		return implode( '/', $segments );
	}

	private function translated_slug( string $slug, string $language ): string {
		$hash = $this->db->hash( $slug );
		$map = $this->db->map_for_language( array( $hash ), $language, 'slug' );
		return empty( $map[ $hash ]['translated'] ) ? '' : self::normalize_slug( $map[ $hash ]['translated'] );
	}
}

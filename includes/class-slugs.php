<?php
/**
 * Translate post/page slugs so /de/about/ can become /de/ueber-uns/.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Slugs {
	private Lingora_Db $db;
	private Lingora_Ai_Client $ai;
	private Lingora_Languages $languages;
	private Lingora_Options $options;

	public function __construct(
		Lingora_Db $db,
		Lingora_Ai_Client $ai,
		Lingora_Languages $languages,
		Lingora_Options $options
	) {
		$this->db        = $db;
		$this->ai        = $ai;
		$this->languages = $languages;
		$this->options   = $options;
	}

	public function register(): void {
		if ( ! $this->options->get( 'translate_slugs', true ) ) {
			return;
		}
		add_action( 'save_post', array( $this, 'queue_post' ), 20, 2 );
		add_filter( 'request', array( $this, 'map_request' ) );
		add_filter( 'post_link', array( $this, 'filter_permalink' ), 30, 2 );
		add_filter( 'page_link', array( $this, 'filter_page_link' ), 30, 2 );
		add_filter( 'post_type_link', array( $this, 'filter_permalink' ), 30, 2 );
	}

	public function queue_post( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		$slug = $post->post_name;
		if ( '' === $slug ) {
			return;
		}
		$id = $this->db->upsert_string( $slug, 'slug', 'post:' . $post_id );
		$default = $this->languages->default_code();
		foreach ( $this->languages->enabled() as $lang ) {
			$code = (string) $lang['code'];
			if ( $code === $default ) {
				continue;
			}
			$this->db->queue( $id, $code );
		}
	}

	/**
	 * @param array<string, mixed> $vars
	 * @return array<string, mixed>
	 */
	public function map_request( array $vars ): array {
		if ( is_admin() || $this->languages->is_default() ) {
			return $vars;
		}
		$lang = $this->languages->current();
		foreach ( array( 'name', 'pagename', 'product' ) as $key ) {
			if ( empty( $vars[ $key ] ) || ! is_string( $vars[ $key ] ) ) {
				continue;
			}
			$original = $this->original_slug( $vars[ $key ], $lang );
			if ( $original ) {
				$vars[ $key ] = $original;
			}
		}
		return $vars;
	}

	public function filter_permalink( string $url, $post ): string { // phpcs:ignore
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return $url;
		}
		return $this->swap_slug( $url, $post );
	}

	public function filter_page_link( string $url, $post_id ): string { // phpcs:ignore
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $url;
		}
		return $this->swap_slug( $url, $post );
	}

	private function swap_slug( string $url, WP_Post $post ): string {
		if ( is_admin() || $this->languages->is_default() ) {
			return $url;
		}
		$slug = $post->post_name;
		if ( '' === $slug ) {
			return $url;
		}
		$translated = $this->translated_slug( $slug, $this->languages->current(), (int) $post->ID );
		if ( ! $translated || $translated === $slug ) {
			return $url;
		}
		$path = wp_parse_url( $url, PHP_URL_PATH ) ?: '';
		$path = preg_replace( '#/' . preg_quote( $slug, '#' ) . '(/|$)#', '/' . rawurlencode( $translated ) . '$1', $path, 1 );
		if ( ! is_string( $path ) ) {
			return $url;
		}
		$parts         = wp_parse_url( $url );
		$parts['path'] = $path;
		return $this->build_url( $parts );
	}

	private function translated_slug( string $slug, string $language, int $post_id ): string {
		$id  = $this->db->upsert_string( $slug, 'slug', 'post:' . $post_id );
		$map = $this->db->map_for_language( array( $this->db->hash( $slug ) ), $language, 'slug' );
		$hash = $this->db->hash( $slug );
		if ( ! empty( $map[ $hash ]['translated'] ) ) {
			return sanitize_title( (string) $map[ $hash ]['translated'] );
		}
		if ( ! $this->options->has_ai_credential() || ! $this->options->get( 'translate_on_fly', true ) ) {
			$this->db->queue( $id, $language );
			return $slug;
		}
		try {
			$raw = $this->ai->translate_text(
				$slug,
				$language,
				$this->languages->label( $language ),
				'Translate this URL slug into ' . $this->languages->label( $language ) . '. Return only a lowercase hyphenated slug. No quotes, spaces, or slashes.'
			);
			$clean = sanitize_title( $raw );
			if ( '' === $clean ) {
				$this->db->queue( $id, $language );
				return $slug;
			}
			$this->db->save_translation( $id, $language, $clean, 'auto' );
			return $clean;
		} catch ( Throwable $e ) {
			$this->db->queue( $id, $language );
			return $slug;
		}
	}

	private function original_slug( string $translated, string $language ): string {
		$found = $this->db->original_for_translation( $translated, $language, 'slug' );
		return $found ?: '';
	}

	/**
	 * @param array<string, mixed> $parts
	 */
	private function build_url( array $parts ): string {
		$scheme = $parts['scheme'] ?? 'https';
		$host   = $parts['host'] ?? '';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path   = $parts['path'] ?? '/';
		$query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$frag   = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';
		$user   = '';
		if ( ! empty( $parts['user'] ) ) {
			$user = $parts['user'];
			if ( ! empty( $parts['pass'] ) ) {
				$user .= ':' . $parts['pass'];
			}
			$user .= '@';
		}
		return $scheme . '://' . $user . $host . $port . $path . $query . $frag;
	}
}

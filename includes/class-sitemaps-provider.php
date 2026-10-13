<?php
/**
 * Sitemap provider listing prefixed language URLs.
 *
 * Bounded output: at most 2000 URLs per page regardless of how many languages
 * are enabled (posts per page = floor(2000 / target languages)). Only public,
 * unprotected, published posts are listed, and no AI work is ever triggered.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Sitemaps_Provider extends WP_Sitemaps_Provider {
	private const MAX_URLS_PER_PAGE = 2000;

	private Lingora_Languages $languages;
	private Lingora_Rewrite $rewrite;

	public function __construct( Lingora_Languages $languages, Lingora_Rewrite $rewrite ) {
		$this->name        = 'lingora';
		$this->object_type = 'translated';
		$this->languages   = $languages;
		$this->rewrite     = $rewrite;
	}

	public function get_url_list( $page_num, $object_subtype = '' ): array { // phpcs:ignore
		$per_page = $this->posts_per_page();
		if ( $per_page < 1 ) {
			return array();
		}

		$page = max( 1, (int) $page_num );
		if ( $page > $this->get_max_num_pages() ) {
			return array();
		}

		$post_types = array( 'post', 'page' );
		if ( post_type_exists( 'product' ) ) {
			$post_types[] = 'product';
		}

		$posts = get_posts(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => $per_page,
				'offset'                 => ( $page - 1 ) * $per_page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$saved = $GLOBALS['lingora_language'] ?? null;
		$GLOBALS['lingora_language'] = $this->languages->default_code();

		try {
			$urls = array();
			foreach ( $posts as $post ) {
				if ( ! $this->post_is_public( $post ) ) {
					continue;
				}
				$base = get_permalink( $post );
				if ( ! is_string( $base ) || '' === $base ) {
					continue;
				}
				foreach ( $this->languages->enabled() as $lang ) {
					$code = (string) $lang['code'];
					if ( $this->languages->is_default( $code ) ) {
						continue;
					}
					$urls[] = array( 'loc' => $this->rewrite->language_url( $code, $base ) );
					if ( count( $urls ) >= self::MAX_URLS_PER_PAGE ) {
						break 2;
					}
				}
			}
		} finally {
			if ( null === $saved ) {
				unset( $GLOBALS['lingora_language'] );
			} else {
				$GLOBALS['lingora_language'] = $saved;
			}
		}

		return $urls;
	}

	public function get_max_num_pages( $object_subtype = '' ): int { // phpcs:ignore
		$per_page = $this->posts_per_page();
		if ( $per_page < 1 ) {
			return 0;
		}
		$total = $this->published_post_count();
		if ( $total < 1 ) {
			return 0;
		}
		return (int) ceil( $total / $per_page );
	}

	/**
	 * Number of posts per sitemap page so that posts x languages <= 2000.
	 */
	private function posts_per_page(): int {
		$targets = 0;
		$default = $this->languages->default_code();
		foreach ( $this->languages->enabled() as $lang ) {
			if ( (string) $lang['code'] !== $default ) {
				$targets++;
			}
		}
		if ( $targets < 1 ) {
			return 0;
		}
		return max( 1, (int) floor( self::MAX_URLS_PER_PAGE / $targets ) );
	}

	private function published_post_count(): int {
		$count = (int) wp_count_posts( 'post' )->publish + (int) wp_count_posts( 'page' )->publish;
		if ( post_type_exists( 'product' ) ) {
			$counts = wp_count_posts( 'product' );
			$count += (int) ( $counts->publish ?? 0 );
		}
		return $count;
	}

	/**
	 * Shared visibility gate when available; local fallback keeps the same rules.
	 */
	private function post_is_public( $post ): bool {
		if ( class_exists( 'Lingora_Content' ) && method_exists( 'Lingora_Content', 'is_public_post' ) && $post instanceof WP_Post ) {
			return (bool) Lingora_Content::is_public_post( $post );
		}
		return 'publish' === get_post_status( $post ) && ! post_password_required( $post );
	}
}

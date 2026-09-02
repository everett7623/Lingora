<?php
/**
 * Sitemap provider listing prefixed language URLs.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Sitemaps_Provider extends WP_Sitemaps_Provider {
	private Lingora_Languages $languages;
	private Lingora_Rewrite $rewrite;

	public function __construct( Lingora_Languages $languages, Lingora_Rewrite $rewrite ) {
		$this->name        = 'lingora';
		$this->object_type = 'translated';
		$this->languages   = $languages;
		$this->rewrite     = $rewrite;
	}

	public function get_url_list( $page_num, $object_subtype = '' ): array { // phpcs:ignore
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page', 'product' ),
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'offset'         => max( 0, ( (int) $page_num - 1 ) * 200 ),
				'no_found_rows'  => true,
			)
		);
		$saved = $GLOBALS['lingora_language'] ?? null;
		$GLOBALS['lingora_language'] = $this->languages->default_code();
		$urls = array();
		foreach ( $posts as $post ) {
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
			}
		}
		if ( null === $saved ) {
			unset( $GLOBALS['lingora_language'] );
		} else {
			$GLOBALS['lingora_language'] = $saved;
		}
		return $urls;
	}

	public function get_max_num_pages( $object_subtype = '' ): int { // phpcs:ignore
		$count = (int) wp_count_posts( 'post' )->publish + (int) wp_count_posts( 'page' )->publish;
		if ( post_type_exists( 'product' ) ) {
			$counts = wp_count_posts( 'product' );
			$count += (int) ( $counts->publish ?? 0 );
		}
		return max( 1, (int) ceil( $count / 200 ) );
	}
}

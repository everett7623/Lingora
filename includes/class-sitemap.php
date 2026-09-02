<?php
/**
 * Extra sitemap URLs for each language prefix (/de/, /fr/, …).
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Sitemap {
	private Lingora_Languages $languages;
	private Lingora_Rewrite $rewrite;
	private Lingora_Options $options;

	public function __construct( Lingora_Languages $languages, Lingora_Rewrite $rewrite, Lingora_Options $options ) {
		$this->languages = $languages;
		$this->rewrite   = $rewrite;
		$this->options   = $options;
	}

	public function register(): void {
		if ( ! $this->options->get( 'multilingual_sitemap', true ) ) {
			return;
		}
		add_action( 'wp_sitemaps_init', array( $this, 'boot_provider' ) );
		add_filter( 'wp_sitemaps_posts_entry', array( $this, 'localize_entry' ), 10, 2 );
	}

	public function boot_provider(): void {
		if ( ! function_exists( 'wp_register_sitemap_provider' ) || ! class_exists( 'WP_Sitemaps_Provider' ) ) {
			return;
		}
		require_once LINGORA_PATH . 'includes/class-sitemaps-provider.php';
		wp_register_sitemap_provider( 'lingora', new Lingora_Sitemaps_Provider( $this->languages, $this->rewrite ) );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	public function localize_entry( array $entry, $post ): array { // phpcs:ignore
		unset( $post );
		if ( empty( $entry['loc'] ) || ! is_string( $entry['loc'] ) ) {
			return $entry;
		}
		$entry['loc'] = $this->rewrite->language_url( $this->languages->default_code(), $entry['loc'] );
		return $entry;
	}
}

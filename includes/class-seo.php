<?php
/**
 * hreflang and document language hints.
 *
 * URLs are derived from Lingora_Rewrite::current_url() (the visitor's original
 * request) with editor/force/nonce parameters removed; canonical localization
 * only ever applies to internal site URLs on public, non-preview requests.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Seo {
	private Lingora_Languages $languages;
	private Lingora_Rewrite $rewrite;
	private Lingora_Options $options;

	public function __construct( Lingora_Languages $languages, Lingora_Rewrite $rewrite, Lingora_Options $options ) {
		$this->languages = $languages;
		$this->rewrite   = $rewrite;
		$this->options   = $options;
	}

	public function register(): void {
		add_action( 'wp_head', array( $this, 'hreflang' ), 1 );
		add_filter( 'language_attributes', array( $this, 'language_attributes' ) );
		add_filter( 'get_canonical_url', array( $this, 'canonical' ) );
		add_filter( 'wpseo_canonical', array( $this, 'canonical' ) );
		add_filter( 'rank_math/frontend/canonical', array( $this, 'canonical' ) );
	}

	public function hreflang(): void {
		if ( ! $this->options->get( 'add_hreflang', true ) ) {
			return;
		}
		if ( ! $this->request_is_public() ) {
			return;
		}
		$langs = $this->languages->enabled();
		if ( count( $langs ) < 2 ) {
			return;
		}
		$request = $this->rewrite->strip_editor_params( $this->rewrite->current_url() );
		foreach ( $langs as $lang ) {
			$code = (string) $lang['code'];
			$url  = $this->rewrite->language_url( $code, $request );
			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( str_replace( '_', '-', $this->languages->locale( $code ) ) ),
				esc_url( $url )
			);
		}
		printf(
			'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
			esc_url( $this->rewrite->language_url( $this->languages->default_code(), $request ) )
		);
	}

	/**
	 * Localize canonical URLs for the current language. External or sensitive
	 * URLs (previews, password-protected/private content, commerce pages) pass
	 * through untouched; editor/force/nonce parameters never survive.
	 */
	public function canonical( $url ) { // phpcs:ignore
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		if ( ! $this->request_is_public() ) {
			return $url;
		}
		return $this->rewrite->localize_url( $this->rewrite->strip_editor_params( $url ) );
	}

	/**
	 * Correct lang and dir in the <html> tag for the current language —
	 * an existing dir="ltr" is replaced when the current language is RTL, and vice versa.
	 */
	public function language_attributes( string $output ): string {
		$lang = $this->languages->current();
		$loc  = str_replace( '_', '-', $this->languages->locale( $lang ) );
		$dir  = $this->languages->is_rtl( $lang ) ? 'rtl' : 'ltr';

		$output = (string) preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( $loc ) . '"', $output );
		if ( preg_match( '/dir="[^"]*"/', $output ) ) {
			$output = (string) preg_replace( '/dir="[^"]*"/', 'dir="' . esc_attr( $dir ) . '"', $output );
		} else {
			$output .= ' dir="' . esc_attr( $dir ) . '"';
		}
		return $output;
	}

	/**
	 * Shared public-request gate when available; local fallback keeps the same
	 * exclusions that matter for meta output (previews, private/password content,
	 * commerce pages, non-GET requests).
	 */
	private function request_is_public(): bool {
		if ( class_exists( 'Lingora_Content' ) && method_exists( 'Lingora_Content', 'is_public_request' ) ) {
			return (bool) Lingora_Content::is_public_request();
		}

		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return false;
		}
		if ( is_preview() || post_password_required() ) {
			return false;
		}
		if ( is_singular() && 'private' === get_post_status() ) {
			return false;
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return false;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return false;
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return false;
		}
		return true;
	}
}

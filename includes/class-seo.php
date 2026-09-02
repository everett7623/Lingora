<?php
/**
 * hreflang and document language hints.
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
		$langs = $this->languages->enabled();
		if ( count( $langs ) < 2 ) {
			return;
		}
		$request = home_url( add_query_arg( array() ) );
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

	public function canonical( $url ) { // phpcs:ignore
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		return $this->rewrite->language_url( $this->languages->current(), $url );
	}

	public function language_attributes( string $output ): string {
		$lang = $this->languages->current();
		$loc  = str_replace( '_', '-', $this->languages->locale( $lang ) );
		$dir  = $this->languages->is_rtl( $lang ) ? 'rtl' : 'ltr';
		$output = preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( $loc ) . '"', $output ) ?? $output;
		if ( ! str_contains( $output, 'dir=' ) ) {
			$output .= ' dir="' . esc_attr( $dir ) . '"';
		}
		return $output;
	}
}

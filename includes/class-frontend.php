<?php
/**
 * Frontend output buffer.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Frontend {
	private Lingora_Translator $translator;
	private Lingora_Languages $languages;
	private Lingora_Options $options;
	private ?Lingora_Html $html = null;
	private bool $buffering = false;

	public function __construct( Lingora_Translator $translator, Lingora_Languages $languages, Lingora_Options $options ) {
		$this->translator = $translator;
		$this->languages  = $languages;
		$this->options    = $options;
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'start_buffer' ), 0 );
	}

	public function start_buffer(): void {
		if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() || is_robots() || is_trackback() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( $this->is_excluded_path() ) {
			return;
		}
		$this->buffering = true;
		ob_start( array( $this, 'translate_html' ) );
	}

	public function translate_html( string $html ): string {
		try {
			if ( '' === trim( $html ) || ! str_contains( $html, '<html' ) ) {
				return $html;
			}
			$language = $this->languages->current();
			$source   = $this->current_source();
			$editor   = Lingora_Editor::is_active();
			return $this->html()->translate( $html, $language, $source, $editor );
		} catch ( Throwable $e ) {
			return $html;
		}
	}

	private function html(): Lingora_Html {
		if ( ! $this->html ) {
			$this->html = new Lingora_Html( $this->translator, $this->languages );
		}
		return $this->html;
	}

	private function current_source(): string {
		if ( is_singular() ) {
			return 'post:' . get_queried_object_id();
		}
		if ( is_front_page() ) {
			return 'home';
		}
		$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
		return is_string( $path ) ? $path : '/';
	}

	private function is_excluded_path(): bool {
		$raw     = (string) ( $GLOBALS['lingora_raw_path'] ?? ( $_SERVER['REQUEST_URI'] ?? '/' ) );
		$exclude = (string) $this->options->get( 'exclude_paths', '' );
		foreach ( explode( ',', $exclude ) as $path ) {
			$path = trim( $path );
			if ( '' !== $path && str_contains( $raw, $path ) ) {
				return true;
			}
		}
		return false;
	}
}

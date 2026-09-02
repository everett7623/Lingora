<?php
/**
 * Browser language detection and language cookie.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Detect {
	public const COOKIE = 'lingora_lang';

	private Lingora_Languages $languages;
	private Lingora_Rewrite $rewrite;
	private Lingora_Options $options;

	public function __construct( Lingora_Languages $languages, Lingora_Rewrite $rewrite, Lingora_Options $options ) {
		$this->languages = $languages;
		$this->rewrite   = $rewrite;
		$this->options   = $options;
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
		add_action( 'template_redirect', array( $this, 'remember' ), 2 );
	}

	public function maybe_redirect(): void {
		if ( ! $this->options->get( 'detect_browser', true ) ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_robots() || is_preview() || wp_is_json_request() ) {
			return;
		}
		if ( isset( $_GET['lingora-edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( $this->is_bot() ) {
			return;
		}
		if ( ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}
		if ( $this->url_has_language() ) {
			return;
		}

		$match = $this->from_header();
		if ( ! $match || $this->languages->is_default( $match ) ) {
			return;
		}

		$this->set_cookie( $match );
		wp_safe_redirect( $this->rewrite->language_url( $match, $this->current_unlocalized() ), 302 );
		exit;
	}

	public function remember(): void {
		if ( ! $this->options->get( 'remember_language', true ) ) {
			return;
		}
		$code = $this->languages->current();
		if ( empty( $_COOKIE[ self::COOKIE ] ) || $_COOKIE[ self::COOKIE ] !== $code ) {
			$this->set_cookie( $code );
		}
	}

	public function set_cookie( string $code ): void {
		if ( headers_sent() ) {
			return;
		}
		$path = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		setcookie(
			self::COOKIE,
			$code,
			array(
				'expires'  => time() + ( 30 * DAY_IN_SECONDS ),
				'path'     => $path,
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE ] = $code;
	}

	private function url_has_language(): bool {
		$raw = $this->rewrite->raw_path();
		foreach ( $this->languages->enabled() as $lang ) {
			$code = (string) $lang['code'];
			if ( preg_match( '#^/' . preg_quote( $code, '#' ) . '(/|$)#i', $raw ) ) {
				return true;
			}
		}
		return isset( $_GET['lang'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	private function from_header(): string {
		$header = (string) ( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '' );
		if ( '' === $header ) {
			return '';
		}
		$parts = array();
		foreach ( explode( ',', $header ) as $piece ) {
			$piece = trim( $piece );
			if ( '' === $piece ) {
				continue;
			}
			$bits = explode( ';', $piece );
			$tag  = strtolower( trim( $bits[0] ) );
			$q    = 1.0;
			if ( isset( $bits[1] ) && str_starts_with( trim( $bits[1] ), 'q=' ) ) {
				$q = (float) substr( trim( $bits[1] ), 2 );
			}
			$parts[ $tag ] = $q;
		}
		arsort( $parts, SORT_NUMERIC );

		$enabled = array();
		foreach ( $this->languages->enabled() as $lang ) {
			$enabled[ strtolower( (string) $lang['code'] ) ] = (string) $lang['code'];
			$locale = strtolower( str_replace( '_', '-', (string) ( $lang['locale'] ?? '' ) ) );
			if ( $locale ) {
				$enabled[ $locale ] = (string) $lang['code'];
			}
		}

		foreach ( $parts as $tag => $q ) {
			unset( $q );
			if ( isset( $enabled[ $tag ] ) ) {
				return $enabled[ $tag ];
			}
			$short = substr( $tag, 0, 2 );
			if ( isset( $enabled[ $short ] ) ) {
				return $enabled[ $short ];
			}
		}
		return '';
	}

	private function current_unlocalized(): string {
		$path = $this->rewrite->strip_language( $this->rewrite->raw_path() );
		return untrailingslashit( $this->rewrite->unlocalized_home() ) . ( '/' === $path ? '/' : $path );
	}

	private function is_bot(): bool {
		$ua = strtolower( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		if ( '' === $ua ) {
			return true;
		}
		$bots = array( 'bot', 'spider', 'crawl', 'slurp', 'facebookexternalhit', 'preview', 'lighthouse', 'pingdom', 'uptimerobot' );
		foreach ( $bots as $bot ) {
			if ( str_contains( $ua, $bot ) ) {
				return true;
			}
		}
		return false;
	}
}

<?php
/**
 * Browser language detection and language cookie.
 *
 * Redirects only happen on safe public GET/HTML requests (never POST, previews,
 * private/password content, commerce account/cart/checkout, REST or editor views),
 * always carry the full original query, and never target the request's own URL,
 * so clients with cookies disabled cannot loop.
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
		if ( isset( $_GET['lingora-edit'] ) || isset( $_GET['lingora-force'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( $this->is_bot() ) {
			return;
		}
		if ( $this->options->get( 'remember_language', true ) && isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) && $this->languages->is_enabled( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}
		if ( $this->url_has_language() ) {
			return;
		}
		if ( ! $this->request_is_public() ) {
			return;
		}

		$match = $this->from_header();
		if ( '' === $match || $this->languages->is_default( $match ) ) {
			return;
		}

		// Keep the original query; normalize any lang leftovers; drop editor params.
		$current = $this->rewrite->current_url();
		$target  = Lingora_Rewrite::strip_editor_params( $this->rewrite->language_url( $match, $current ) );

		// The target always carries the language, so a second pass would bail at
		// url_has_language(). Refuse to redirect onto ourselves regardless —
		// that is the hard loop stop for clients with cookies disabled.
		if ( '' === $target || $target === $current ) {
			return;
		}

		if ( $this->options->get( 'remember_language', true ) ) {
			$this->set_cookie( $match );
		}
		wp_safe_redirect( $target, 302 );
		exit;
	}

	public function remember(): void {
		if ( ! $this->options->get( 'remember_language', true ) || ! $this->request_is_public() ) {
			return;
		}
		$code = $this->languages->current();
		if ( empty( $_COOKIE[ self::COOKIE ] ) || ! is_string( $_COOKIE[ self::COOKIE ] ) || $_COOKIE[ self::COOKIE ] !== $code ) {
			$this->set_cookie( $code );
		}
	}

	public function set_cookie( string $code ): void {
		if ( headers_sent() || ! $this->options->get( 'remember_language', true ) || ! $this->languages->is_enabled( $code ) ) {
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
			if ( '' !== $code && preg_match( '#^/' . preg_quote( $code, '#' ) . '(/|$)#i', $raw ) ) {
				return true;
			}
		}
		return isset( $_GET['lang'] ) && is_string( $_GET['lang'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Shared public-request gate when available; local fallback keeps the same
	 * exclusions (POST, previews, private/password content, account/cart/checkout).
	 */
	private function request_is_public(): bool {
		if ( class_exists( 'Lingora_Content' ) && method_exists( 'Lingora_Content', 'is_public_request' ) ) {
			return (bool) Lingora_Content::is_public_request();
		}

		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return false;
		}
		if ( post_password_required() ) {
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

	/**
	 * Parse Accept-Language honoring q values: q=0 means "do not serve this",
	 * including for the two-letter prefix fallback.
	 */
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
			$tag  = strtolower( trim( (string) $bits[0] ) );
			if ( '' === $tag || '*' === $tag ) {
				continue;
			}
			$q = 1.0;
			for ( $i = 1; $i < count( $bits ); $i++ ) {
				$param = trim( (string) $bits[ $i ] );
				if ( str_starts_with( $param, 'q=' ) ) {
					$q = (float) substr( $param, 2 );
					break;
				}
			}
			if ( ! isset( $parts[ $tag ] ) ) {
				$parts[ $tag ] = $q;
			}
		}
		arsort( $parts, SORT_NUMERIC );

		$enabled = array();
		foreach ( $this->languages->enabled() as $lang ) {
			$enabled[ strtolower( (string) $lang['code'] ) ] = (string) $lang['code'];
			$locale = strtolower( str_replace( '_', '-', (string) ( $lang['locale'] ?? '' ) ) );
			if ( '' !== $locale ) {
				$enabled[ $locale ] = (string) $lang['code'];
			}
		}

		foreach ( $parts as $tag => $q ) {
			if ( $q <= 0.0 ) {
				continue; // q=0: explicitly not acceptable.
			}
			if ( isset( $enabled[ $tag ] ) ) {
				return $enabled[ $tag ];
			}
			$short = substr( $tag, 0, 2 );
			if ( '' !== $short && isset( $enabled[ $short ] ) ) {
				return $enabled[ $short ];
			}
		}
		return '';
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

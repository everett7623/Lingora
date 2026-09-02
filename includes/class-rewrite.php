<?php
/**
 * Directory-prefix URLs: https://example.com/de/about/
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Rewrite {
	private Lingora_Languages $languages;
	private Lingora_Options $options;
	private bool $filtering = false;

	public function __construct( Lingora_Languages $languages, Lingora_Options $options ) {
		$this->languages = $languages;
		$this->options   = $options;
	}

	public function register(): void {
		$this->detect();
		add_filter( 'home_url', array( $this, 'filter_home_url' ), 10, 4 );
		add_filter( 'locale', array( $this, 'filter_locale' ), 1 );
		add_filter( 'redirect_canonical', array( $this, 'keep_language_on_canonical' ), 10, 2 );
		add_filter( 'post_link', array( $this, 'filter_generic_link' ), 20 );
		add_filter( 'page_link', array( $this, 'filter_generic_link' ), 20 );
		add_filter( 'post_type_link', array( $this, 'filter_generic_link' ), 20 );
		add_filter( 'term_link', array( $this, 'filter_generic_link' ), 20 );
		add_filter( 'year_link', array( $this, 'filter_generic_link' ), 20 );
		add_filter( 'month_link', array( $this, 'filter_generic_link' ), 20 );
		add_filter( 'day_link', array( $this, 'filter_generic_link' ), 20 );
		add_filter( 'author_link', array( $this, 'filter_generic_link' ), 20 );
		add_filter( 'post_type_archive_link', array( $this, 'filter_generic_link' ), 20 );
		add_filter( 'nav_menu_link_attributes', array( $this, 'filter_menu_link' ), 20 );
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 0 );
	}

	public function detect(): void {
		if ( is_admin() || wp_installing() ) {
			$GLOBALS['lingora_language'] = $this->languages->default_code();
			return;
		}

		$code = $this->from_request();
		$GLOBALS['lingora_language'] = $code;
	}

	public function current(): string {
		return (string) ( $GLOBALS['lingora_language'] ?? $this->languages->default_code() );
	}

	public function language_url( string $language, ?string $absolute = null ): string {
		$base  = $absolute ?? $this->unlocalized_home();
		$path  = wp_parse_url( $base, PHP_URL_PATH ) ?: '/';
		$path  = $this->strip_language( $path );
		$home  = untrailingslashit( $this->unlocalized_home() );
		$query = wp_parse_url( $base, PHP_URL_QUERY );
		$frag  = wp_parse_url( $base, PHP_URL_FRAGMENT );

		if ( 'query' === $this->options->get( 'url_mode', 'directory' ) ) {
			$url = $home . ( '/' === $path ? '/' : $path );
			$url = add_query_arg( 'lang', $language, $url );
			if ( $frag ) {
				$url .= '#' . $frag;
			}
			return $url;
		}

		$hide = (bool) $this->options->get( 'hide_default', true );
		if ( $hide && $language === $this->languages->default_code() ) {
			$url = $home . ( '/' === $path ? '/' : $path );
		} else {
			$suffix = '/' === $path ? '/' : $path;
			$url    = $home . '/' . rawurlencode( $language ) . $suffix;
		}
		if ( $query ) {
			$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . $query;
		}
		if ( $frag ) {
			$url .= '#' . $frag;
		}
		return $url;
	}

	public function localize_url( string $url, ?string $language = null ): string {
		$language = $language ?? $this->current();
		if ( $this->should_skip_url( $url ) ) {
			return $url;
		}
		if ( ! $this->is_internal( $url ) ) {
			return $url;
		}
		return $this->language_url( $language, $url );
	}

	/**
	 * @param string      $url
	 * @param string      $path
	 * @param string|null $scheme
	 * @param int|null    $blog_id
	 */
	public function filter_home_url( $url, $path, $scheme, $blog_id ) { // phpcs:ignore
		unset( $path, $scheme, $blog_id );
		if ( $this->filtering || is_admin() ) {
			return $url;
		}
		if ( $this->should_skip_url( $url ) ) {
			return $url;
		}
		$language = $this->current();
		if ( $this->options->get( 'hide_default', true ) && $language === $this->languages->default_code() ) {
			return $url;
		}
		if ( $this->already_localized( $url ) ) {
			return $url;
		}
		$this->filtering = true;
		$localized       = $this->language_url( $language, $url );
		$this->filtering = false;
		return $localized;
	}

	public function filter_generic_link( $url ) { // phpcs:ignore
		if ( ! is_string( $url ) || is_admin() ) {
			return $url;
		}
		return $this->localize_url( $url );
	}

	/**
	 * @param array<string, mixed> $atts
	 * @return array<string, mixed>
	 */
	public function filter_menu_link( array $atts ): array {
		if ( empty( $atts['href'] ) || ! is_string( $atts['href'] ) ) {
			return $atts;
		}
		$atts['href'] = $this->localize_url( $atts['href'] );
		return $atts;
	}

	public function filter_locale( string $locale ): string {
		if ( is_admin() ) {
			return $locale;
		}
		return $this->languages->locale( $this->current() );
	}

	public function keep_language_on_canonical( $redirect, $requested ) { // phpcs:ignore
		if ( ! $redirect ) {
			return $redirect;
		}
		$lang = $this->current();
		if ( $this->options->get( 'hide_default', true ) && $lang === $this->languages->default_code() ) {
			return $this->language_url( $lang, $redirect );
		}
		return $this->language_url( $lang, $redirect );
	}

	public function maybe_redirect(): void {
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_robots() || wp_is_json_request() ) {
			return;
		}
		if ( 'directory' !== $this->options->get( 'url_mode', 'directory' ) ) {
			return;
		}
		$raw = (string) ( $GLOBALS['lingora_raw_path'] ?? '' );
		if ( '' === $raw ) {
			return;
		}

		$default = $this->languages->default_code();
		if ( $this->options->get( 'hide_default', true ) && preg_match( '#^/' . preg_quote( $default, '#' ) . '(/|$)#i', $raw ) ) {
			$stripped = $this->strip_language( $raw );
			wp_safe_redirect( $this->language_url( $default, $this->unlocalized_home_with_path( $stripped ) ), 301 );
			exit;
		}
	}

	public function unlocalized_home(): string {
		$this->filtering = true;
		$home            = home_url( '/' );
		$this->filtering = false;
		return $home;
	}

	public function raw_path(): string {
		return (string) ( $GLOBALS['lingora_raw_path'] ?? '/' );
	}

	private function unlocalized_home_with_path( string $path ): string {
		$home = untrailingslashit( $this->unlocalized_home() );
		return $home . ( '/' === $path ? '/' : $path );
	}

	private function from_request(): string {
		$default = $this->languages->default_code();
		if ( isset( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$q = sanitize_text_field( wp_unslash( $_GET['lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $this->languages->is_enabled( $q ) ) {
				$GLOBALS['lingora_raw_path'] = $this->request_path();
				return $q;
			}
		}

		$uri = $_SERVER['REQUEST_URI'] ?? '/';
		if ( $this->should_skip_uri( $uri ) ) {
			$GLOBALS['lingora_raw_path'] = $this->request_path();
			return $default;
		}

		$path = $this->request_path();
		$GLOBALS['lingora_raw_path'] = $path;

		foreach ( $this->languages->enabled() as $lang ) {
			$code = (string) $lang['code'];
			if ( preg_match( '#^/' . preg_quote( $code, '#' ) . '(/|$)#i', $path ) ) {
				$_SERVER['REQUEST_URI'] = preg_replace(
					'#/' . preg_quote( $code, '#' ) . '(/|$)#i',
					'/',
					$uri,
					1
				);
				if ( ! empty( $_SERVER['PATH_INFO'] ) && is_string( $_SERVER['PATH_INFO'] ) ) {
					$_SERVER['PATH_INFO'] = preg_replace(
						'#/' . preg_quote( $code, '#' ) . '(/|$)#i',
						'/',
						$_SERVER['PATH_INFO'],
						1
					);
				}
				return $code;
			}
		}

		return $default;
	}

	private function request_path(): string {
		$uri  = $_SERVER['REQUEST_URI'] ?? '/';
		$path = wp_parse_url( $uri, PHP_URL_PATH ) ?: '/';
		$home = wp_parse_url( $this->unlocalized_home(), PHP_URL_PATH ) ?: '';
		$home = untrailingslashit( $home );
		if ( $home && str_starts_with( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) ) ?: '/';
		}
		return '/' . ltrim( $path, '/' );
	}

	public function strip_language( string $path ): string {
		$path = '/' . ltrim( $path, '/' );
		foreach ( $this->languages->enabled() as $lang ) {
			$code = (string) $lang['code'];
			$path = preg_replace( '#^/' . preg_quote( $code, '#' ) . '(/|$)#i', '/', $path ) ?? $path;
		}
		return $path ?: '/';
	}

	private function already_localized( string $url ): bool {
		$path = wp_parse_url( $url, PHP_URL_PATH ) ?: '/';
		foreach ( $this->languages->enabled() as $lang ) {
			$code = (string) $lang['code'];
			if ( preg_match( '#/' . preg_quote( $code, '#' ) . '(/|$)#i', $path ) ) {
				return true;
			}
		}
		return false;
	}

	private function should_skip_uri( string $uri ): bool {
		$needles = array( '/wp-json/', '/wp-admin/', 'wp-login.php', 'wp-cron.php', 'xmlrpc.php', '/wp-content/', '/wp-includes/' );
		foreach ( $needles as $needle ) {
			if ( str_contains( $uri, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private function should_skip_url( string $url ): bool {
		return $this->should_skip_uri( $url );
	}

	private function is_internal( string $url ): bool {
		if ( str_starts_with( $url, '#' ) || str_starts_with( $url, 'mailto:' ) || str_starts_with( $url, 'tel:' ) ) {
			return false;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return true;
		}
		$site = wp_parse_url( $this->unlocalized_home(), PHP_URL_HOST );
		return 0 === strcasecmp( (string) $host, (string) $site );
	}
}

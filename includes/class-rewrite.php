<?php
/**
 * Directory-prefix URLs: https://example.com/de/about/
 *
 * URL contract:
 * - Conversion keeps scheme, host, port, query and fragment of the input URL.
 * - It is idempotent: converting an already converted URL changes nothing.
 * - Exactly one leading language prefix is stripped / added, never more.
 * - Only internal HTTP(S) URLs are rewritten; everything else is returned as-is.
 * - REST (/wp-json, ?rest_route=), admin, login/cron/xmlrpc and asset paths are never touched.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Rewrite {
	private Lingora_Languages $languages;
	private Lingora_Options $options;
	private int $filter_depth = 0;

	/**
	 * REQUEST_URI captured before the language prefix is stripped,
	 * so current_url() can rebuild what the visitor actually asked for.
	 *
	 * @var string|null
	 */
	private ?string $original_request_uri = null;

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
		$this->snapshot_request();
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

	/**
	 * The actual original request URL (scheme + host[:port] + original path + query),
	 * reconstructed before the language prefix was stripped from REQUEST_URI.
	 * Fragments never reach the server, so they cannot appear here.
	 * No parameter stripping happens inside; callers clean up what they must.
	 */
	public function current_url(): string {
		$uri = null !== $this->original_request_uri
			? $this->original_request_uri
			: (string) ( $_SERVER['REQUEST_URI'] ?? '' );

		if ( '' === $uri ) {
			return $this->unlocalized_home();
		}

		if ( '/' !== $uri[0] ) {
			$uri = '/' . $uri;
		}

		return $this->request_origin() . $uri;
	}

	/**
	 * Remove visual-translation editor / force / nonce parameters from a URL.
	 * Editor links use _lingora_nonce (action 'lingora_force'); _wpnonce covers preview links.
	 */
	public static function strip_editor_params( string $url ): string {
		$keys    = array( 'lingora-edit', 'lingora-force', '_lingora_nonce', '_wpnonce' );
		$stripped = remove_query_arg( $keys, $url );
		return is_string( $stripped ) ? $stripped : $url;
	}

	/**
	 * Localize a URL for $language.
	 *
	 * Directory mode: /de/about/ (default language stays bare when hide_default is on).
	 * Query mode: ?lang=de (the lang param is normalized in every mode).
	 * Scheme, host, port, remaining query params and the fragment survive untouched.
	 */
	public function language_url( string $language, ?string $absolute = null ): string {
		$base = is_string( $absolute ) && '' !== $absolute ? $absolute : $this->unlocalized_home();

		if ( ! $this->languages->is_enabled( $language ) || ! $this->is_internal( $base ) || $this->should_skip_url( $base ) ) {
			return $base;
		}

		$home = wp_parse_url( $this->unlocalized_home() );
		if ( ! is_array( $home ) ) {
			return $base;
		}

		$parts = wp_parse_url( $base );
		if ( ! is_array( $parts ) ) {
			return $base;
		}

		$home_path = $this->normalized_home_path( (string) ( $home['path'] ?? '' ) );
		$path      = array_key_exists( 'path', $parts ) ? '/' . ltrim( (string) $parts['path'], '/' ) : '/';
		$query     = isset( $parts['query'] ) ? (string) $parts['query'] : '';
		$frag      = isset( $parts['fragment'] ) ? (string) $parts['fragment'] : '';

		if ( ! empty( $parts['host'] ) ) {
			if ( ! $this->is_internal( $base ) ) {
				return $base;
			}
			if ( ! $this->path_under_home( $path, $home_path ) ) {
				return $base;
			}

			$scheme    = (string) ( $parts['scheme'] ?? '' );
			if ( '' === $scheme ) {
				$scheme = (string) ( $home['scheme'] ?? 'https' );
			}
			$authority = (string) $parts['host'];
			if ( ! empty( $parts['port'] ) ) {
				$authority .= ':' . (int) $parts['port'];
			}
			$relative = $this->strip_home_path( $path, $home_path );
		} else {
			$scheme    = (string) ( $home['scheme'] ?? 'https' );
			$authority = (string) ( $home['host'] ?? '' );
			if ( ! empty( $home['port'] ) ) {
				$authority .= ':' . (int) $home['port'];
			}
			$relative = $this->strip_home_path( $path, $home_path );
		}

		$source_language = $this->languages->default_code();
		foreach ( $this->languages->enabled() as $lang ) {
			if ( preg_match( '#^/' . preg_quote( $lang['code'], '#' ) . '(/|$)#i', $relative ) ) { $source_language = $lang['code']; break; }
		}
		parse_str( $query, $source_query );
		if ( isset( $source_query['lang'] ) && is_string( $source_query['lang'] ) && $this->languages->is_enabled( $source_query['lang'] ) ) { $source_language = $source_query['lang']; }
		$relative = '/' . ltrim( $this->strip_language( $relative ), '/' );
		if ( did_action( 'init' ) && Lingora_Db::DB_VERSION === get_option( 'lingora_db_version' ) ) {
			$relative = lingora()->slugs->path_for_language( $relative, $source_language, $language );
		}

		$mode = (string) $this->options->get( 'url_mode', 'directory' );
		$hide = (bool) $this->options->get( 'hide_default', true );

		if ( 'query' === $mode ) {
			$out_path = $home_path . $relative;
			$query    = $this->normalize_query( $query, $hide && $language === $this->languages->default_code() ? null : $language, true );
		} elseif ( $hide && $language === $this->languages->default_code() ) {
			$out_path = $home_path . $relative;
			$query    = $this->normalize_query( $query, null, false );
		} else {
			$suffix   = ( '/' === $relative ) ? '/' : $relative;
			$out_path = $home_path . '/' . rawurlencode( $language ) . $suffix;
			$query    = $this->normalize_query( $query, null, false );
		}

		$url = $scheme . '://' . $authority . $out_path;
		if ( '' !== $query ) {
			$url .= '?' . $query;
		}
		if ( '' !== $frag ) {
			$url .= '#' . $frag;
		}
		return $url;
	}

	public function localize_url( string $url, ?string $language = null ): string {
		$language = $language ?? $this->current();
		if ( '' === $url ) {
			return $url;
		}
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
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		if ( $this->filter_depth > 0 || is_admin() ) {
			return $url;
		}
		if ( $this->should_skip_url( $url ) ) {
			return $url;
		}
		return $this->language_url( $this->current(), $url );
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
		unset( $requested );
		if ( ! is_string( $redirect ) || '' === $redirect ) {
			return $redirect;
		}
		if ( is_admin() ) {
			return $redirect;
		}
		if ( $this->should_skip_url( $redirect ) ) {
			return $redirect;
		}
		$target = $this->strip_editor_params( $this->language_url( $this->current(), $redirect ) );
		return '' !== $target ? $target : $redirect;
	}

	/**
	 * With hide_default, /<default>/… is redundant: 301 to the bare URL, keeping query.
	 */
	public function maybe_redirect(): void {
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_robots() || wp_is_json_request() ) {
			return;
		}
		if ( ! in_array( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ), array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		if ( 'directory' !== $this->options->get( 'url_mode', 'directory' ) ) {
			return;
		}
		if ( ! $this->options->get( 'hide_default', true ) ) {
			return;
		}

		$default = $this->languages->default_code();
		$raw     = $this->raw_path();
		if ( '' === $default || ! preg_match( '#^/' . preg_quote( $default, '#' ) . '(/|$)#i', $raw ) ) {
			return;
		}

		$current = $this->current_url();
		$target  = $this->strip_editor_params( $this->language_url( $default, $current ) );
		if ( '' === $target || $target === $current ) {
			return;
		}
		wp_safe_redirect( $target, 301 );
		exit;
	}

	public function unlocalized_home(): string {
		++$this->filter_depth;
		try {
			$home = home_url( '/' );
			return is_string( $home ) ? $home : '';
		} finally {
			--$this->filter_depth;
		}
	}

	public function raw_path(): string {
		return (string) ( $GLOBALS['lingora_raw_path'] ?? '/' );
	}

	/**
	 * Strip exactly one leading language segment: '/de/about/' => '/about/', '/de' => '/'.
	 * Middle segments ('/about/de/') and second prefixes ('/de/de/') stay.
	 */
	public function strip_language( string $path ): string {
		$path = '/' . ltrim( $path, '/' );
		foreach ( $this->languages->enabled() as $lang ) {
			$code = (string) $lang['code'];
			if ( '' === $code ) {
				continue;
			}
			$stripped = preg_replace( '#^/' . preg_quote( $code, '#' ) . '(/|$)#i', '/', $path, 1 );
			if ( is_string( $stripped ) && $stripped !== $path ) {
				return $stripped ?: '/';
			}
		}
		return $path ?: '/';
	}

	private function snapshot_request(): void {
		if ( null !== $this->original_request_uri ) {
			return;
		}
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		$this->original_request_uri = is_string( $uri ) ? $uri : '';
	}

	private function request_origin(): string {
		$home = wp_parse_url( $this->unlocalized_home() );
		if ( ! is_array( $home ) ) {
			return '';
		}

		$host = $this->sanitize_host( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
		if ( '' === $host || 0 !== strcasecmp( (string) wp_parse_url( 'http://' . $host, PHP_URL_HOST ), (string) ( $home['host'] ?? '' ) ) ) {
			$scheme = (string) ( $home['scheme'] ?? 'https' );
			$authority = (string) ( $home['host'] ?? '' );
			if ( ! empty( $home['port'] ) ) {
				$authority .= ':' . (int) $home['port'];
			}
			return $scheme . '://' . $authority;
		}

		$scheme = is_ssl() ? 'https' : 'http';
		if ( str_starts_with( $host, '[' ) ) {
			// IPv6 literal, possibly with a port.
			$closed = strpos( $host, ']' );
			$h = substr( $host, 0, (int) $closed + 1 );
			$rest = substr( $host, (int) $closed + 1 );
			$port = str_starts_with( $rest, ':' ) ? (int) substr( $rest, 1 ) : 0;
		} else {
			$colon = strrpos( $host, ':' );
			if ( false !== $colon ) {
				$h = substr( $host, 0, $colon );
				$port = (int) substr( $host, $colon + 1 );
			} else {
				$h = $host;
				$port = 0;
			}
		}
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}
		return $scheme . '://' . $h . ( $port > 0 ? ':' . $port : '' );
	}

	/**
	 * Only host-like values survive; anything else falls back to the site home.
	 */
	private function sanitize_host( string $host ): string {
		$host = trim( $host );
		if ( '' === $host ) {
			return '';
		}
		if ( preg_match( '/^\[[0-9A-Fa-f:]+\](?::[0-9]{1,5})?$/', $host ) ) {
			return $host;
		}
		if ( preg_match( '/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host ) ) {
			return $host;
		}
		return '';
	}

	private function normalized_home_path( string $path ): string {
		$path = untrailingslashit( '/' . ltrim( $path, '/' ) );
		return '' === $path ? '' : $path;
	}

	private function path_under_home( string $path, string $home_path ): bool {
		if ( '' === $home_path ) {
			return true;
		}
		return $path === $home_path || str_starts_with( $path, $home_path . '/' );
	}

	private function strip_home_path( string $path, string $home_path ): string {
		if ( '' === $home_path ) {
			return $path;
		}
		if ( $path === $home_path ) {
			return '/';
		}
		if ( str_starts_with( $path, $home_path . '/' ) ) {
			$rest = substr( $path, strlen( $home_path ) );
			return '' === $rest ? '/' : $rest;
		}
		return $path;
	}

	/**
	 * Normalize the lang parameter:
	 * - query mode: exactly one lang param carrying $language,
	 * - directory mode: no lang param at all.
	 * All other pairs keep their original encoding and order.
	 */
	private function normalize_query( string $query, ?string $language, bool $is_query_mode ): string {
		$out       = array();
		$lang_seen = false;

		if ( '' !== $query ) {
			foreach ( explode( '&', $query ) as $pair ) {
				if ( '' === $pair ) {
					continue;
				}
				$eq  = strpos( $pair, '=' );
				$key = false === $eq ? $pair : substr( $pair, 0, $eq );
				if ( 'lang' === rawurldecode( $key ) ) {
					if ( $is_query_mode && null !== $language && ! $lang_seen ) {
						$out[]     = 'lang=' . rawurlencode( $language );
						$lang_seen = true;
					}
					continue;
				}
				$out[] = $pair;
			}
		}

		if ( $is_query_mode && null !== $language && ! $lang_seen ) {
			$out[] = 'lang=' . rawurlencode( $language );
		}

		return implode( '&', $out );
	}

	private function from_request(): string {
		$default = $this->languages->default_code();
		$uri = $_SERVER['REQUEST_URI'] ?? '/';
		$uri = is_string( $uri ) ? $uri : '/';
		$path = $this->request_path();
		$GLOBALS['lingora_raw_path'] = $path;
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || $this->should_skip_uri( $uri ) ) {
			return $default;
		}
		$query_code = '';
		if ( isset( $_GET['lang'] ) && is_string( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only language selection.
			$candidate = strtolower( sanitize_text_field( wp_unslash( $_GET['lang'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $this->languages->is_enabled( $candidate ) ) {
				$query_code = $candidate;
			}
		}
		$home_path = $this->normalized_home_path( (string) ( wp_parse_url( $this->unlocalized_home(), PHP_URL_PATH ) ?: '' ) );
		$absolute_path = (string) ( wp_parse_url( $uri, PHP_URL_PATH ) ?: '/' );
		if ( ! $this->path_under_home( $absolute_path, $home_path ) ) {
			return $default;
		}
		foreach ( $this->languages->enabled() as $lang ) {
			$code = (string) $lang['code'];
			if ( '' !== $code && preg_match( '#^/' . preg_quote( $code, '#' ) . '(/|$)#i', $path ) ) {
				// 根目录安装 home_path 为空，preg_quote('') 会产出 “^//code” 双斜杠导致永不匹配；
				// 空前缀必须整体省略。
				$prefix  = '' === $home_path ? '' : preg_quote( $home_path, '#' );
				$pattern = '#^' . $prefix . '/' . preg_quote( $code, '#' ) . '(?=/|\?|$)#i';
				$_SERVER['REQUEST_URI'] = preg_replace( $pattern, $home_path, $uri, 1 ) ?: '/';
				if ( $home_path === $_SERVER['REQUEST_URI'] || str_starts_with( $_SERVER['REQUEST_URI'], $home_path . '?' ) ) {
					$_SERVER['REQUEST_URI'] = $home_path . '/' . substr( $_SERVER['REQUEST_URI'], strlen( $home_path ) );
				}
				if ( isset( $_SERVER['PATH_INFO'] ) && is_string( $_SERVER['PATH_INFO'] ) ) {
					$_SERVER['PATH_INFO'] = preg_replace( '#^/' . preg_quote( $code, '#' ) . '(?=/|$)#i', '', $_SERVER['PATH_INFO'], 1 ) ?: '/';
				}
				return 'query' === $this->options->get( 'url_mode' ) && '' !== $query_code ? $query_code : $code;
			}
		}
		return '' !== $query_code ? $query_code : $default;
	}

	private function request_path(): string {
		$uri  = $_SERVER['REQUEST_URI'] ?? '/';
		$uri  = is_string( $uri ) ? $uri : '/';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		$path = is_string( $path ) && '' !== $path ? $path : '/';
		$home = $this->normalized_home_path( (string) ( wp_parse_url( $this->unlocalized_home(), PHP_URL_PATH ) ?: '' ) );
		$path = $this->strip_home_path( $path, $home );
		return '/' . ltrim( $path, '/' );
	}

	/**
	 * REST (?rest_route=), admin, login/cron/xmlrpc endpoints and asset folders never localize.
	 */
	private function should_skip_uri( string $uri ): bool {
		$path  = (string) ( wp_parse_url( $uri, PHP_URL_PATH ) ?: '/' );
		$query = (string) ( wp_parse_url( $uri, PHP_URL_QUERY ) ?: '' );

		parse_str( $query, $parameters );
		if ( array_key_exists( 'rest_route', $parameters ) ) {
			return true;
		}
		if ( preg_match( '#(^|/)(wp-admin|wp-json|wp-content|wp-includes)(/|$)#', $path ) ) {
			return true;
		}
		if ( preg_match( '#/(wp-login\.php|wp-register\.php|wp-cron\.php|wp-trackback\.php|wp-mail\.php|wp-activate\.php|wp-signup\.php|wp-links-opml\.php|xmlrpc\.php)(/|$)#', $path ) ) {
			return true;
		}
		return false;
	}

	private function should_skip_url( string $url ): bool {
		return $this->should_skip_uri( $url );
	}

	/**
	 * Internal = same HTTP(S) origin as the site home (host case-insensitive, ports compared
	 * with scheme defaults applied) or a site-relative path. Everything else stays untouched.
	 */
	private function is_internal( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}
		if ( '#' === $url[0] ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( '' !== $scheme && 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}

		$host = (string) ( $parts['host'] ?? '' );
		if ( '' === $host ) {
			return true;
		}

		$home = wp_parse_url( $this->unlocalized_home() );
		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return false;
		}
		if ( 0 !== strcasecmp( $host, (string) $home['host'] ) ) {
			return false;
		}

		$home_scheme = (string) ( $home['scheme'] ?? 'https' );
		return $this->effective_port( isset( $parts['port'] ) ? (int) $parts['port'] : null, '' !== $scheme ? $scheme : $home_scheme )
			=== $this->effective_port( isset( $home['port'] ) ? (int) $home['port'] : null, $home_scheme );
	}

	private function effective_port( ?int $port, string $scheme ): int {
		if ( null !== $port && $port > 0 ) {
			return $port;
		}
		return 'https' === $scheme ? 443 : 80;
	}
}

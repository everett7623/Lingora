<?php
/**
 * Settings stored in wp_options.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Options {
	public const SETTINGS_KEY  = 'lingora_settings';
	public const LANGUAGES_KEY = 'lingora_languages';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'url_mode'               => 'directory',
			'hide_default'           => true,
			'auto_translate'         => true,
			'translate_on_fly'       => true,
			'on_fly_limit'           => 8,
			'add_hreflang'           => true,
			'detect_browser'         => true,
			'remember_language'      => true,
			'translate_slugs'        => true,
			'translate_gettext'      => true,
			'multilingual_sitemap'   => true,
			'search_translations'    => true,
			'switcher_enabled'       => true,
			'switcher_style'         => 'nav',
			'switcher_position'      => 'top-right',
			'switcher_in_menu'       => true,
			'switcher_show_flag'     => true,
			'switcher_show_code'     => true,
			'switcher_show_name'     => false,
			'exclude_selectors'      => '.notranslate, .lingora-skip, code, pre, kbd',
			'exclude_paths'          => '/wp-admin,/wp-login.php,/feed',
			'ai'                 => array(
				'provider_id'         => 'deepseek',
				'auth_mode'           => 'apiKey',
				'api_key'             => '',
				'project_key'         => '',
				'project_key_header'  => 'Authorization',
				'project_key_scheme'  => 'bearer',
				'base_url'            => 'https://api.deepseek.com',
				'model'               => 'deepseek-v4-flash',
				'transcribe_model'    => '',
			),
		);
	}

	public static function seed(): void {
		if ( false === get_option( self::SETTINGS_KEY, false ) ) {
			add_option( self::SETTINGS_KEY, self::defaults(), '', false );
		}
		if ( false === get_option( self::LANGUAGES_KEY, false ) ) {
			add_option( self::LANGUAGES_KEY, Lingora_Languages::default_set(), '', false );
		}
		self::migrate_ui();
	}

	public static function migrate_ui(): void {
		if ( '0.0.1' === get_option( 'lingora_ui_version' ) ) {
			return;
		}
		$settings = get_option( self::SETTINGS_KEY, array() );
		if ( is_array( $settings ) ) {
			$style    = $settings['switcher_style'] ?? 'floating';
			$position = $settings['switcher_position'] ?? 'bottom-right';
			if ( 'floating' === $style && 'bottom-right' === $position ) {
				$settings['switcher_style']     = 'nav';
				$settings['switcher_position']  = 'top-right';
				$settings['switcher_show_name'] = false;
				$settings['switcher_show_code'] = true;
				$settings['switcher_in_menu']   = true;
				update_option( self::SETTINGS_KEY, $settings, false );
			}
		}
		update_option( 'lingora_ui_version', '0.0.1', false );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::SETTINGS_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return $this->deep_merge( self::defaults(), $stored );
	}

	public function get( string $key, mixed $fallback = null ): mixed {
		$all = $this->all();
		return $all[ $key ] ?? $fallback;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function ai(): array {
		$ai = $this->get( 'ai', array() );
		return is_array( $ai ) ? $ai : array();
	}

	/**
	 * @param array<string, mixed> $patch
	 */
	public function update( array $patch ): void {
		$next = $this->deep_merge( $this->all(), $patch );
		update_option( self::SETTINGS_KEY, $next, false );
	}

	/**
	 * @param array<string, mixed> $ai
	 */
	public function update_ai( array $ai ): void {
		$current = $this->all();
		$current['ai'] = array_merge( $this->ai(), $ai );
		update_option( self::SETTINGS_KEY, $current, false );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function languages(): array {
		$stored = get_option( self::LANGUAGES_KEY, array() );
		return is_array( $stored ) && $stored ? $stored : Lingora_Languages::default_set();
	}

	/**
	 * @param list<array<string, mixed>> $languages
	 */
	public function save_languages( array $languages ): void {
		update_option( self::LANGUAGES_KEY, array_values( $languages ), false );
	}

	public function has_ai_credential(): bool {
		$ai      = $this->ai();
		$mode    = (string) ( $ai['auth_mode'] ?? 'apiKey' );
		$secret  = 'projectKey' === $mode ? (string) ( $ai['project_key'] ?? '' ) : (string) ( $ai['api_key'] ?? '' );
		$base    = trim( (string) ( $ai['base_url'] ?? '' ) );
		return '' !== trim( $secret ) && '' !== $base;
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $overlay
	 * @return array<string, mixed>
	 */
	private function deep_merge( array $base, array $overlay ): array {
		foreach ( $overlay as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && $this->is_assoc( $base[ $key ] ) ) {
				$base[ $key ] = $this->deep_merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	/**
	 * @param array<mixed, mixed> $array
	 */
	private function is_assoc( array $array ): bool {
		return array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}
}

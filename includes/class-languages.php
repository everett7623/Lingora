<?php
/**
 * Language catalog and current language.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Languages {
	private Lingora_Options $options;

	public function __construct( Lingora_Options $options ) {
		$this->options = $options;
	}

	/**
	 * @return list<array{code:string,name:string,native:string,locale:string,rtl:bool}>
	 */
	public static function catalog(): array {
		return array(
			array( 'code' => 'en', 'name' => 'English', 'native' => 'English', 'locale' => 'en_US', 'rtl' => false, 'flag' => 'us' ),
			array( 'code' => 'zh', 'name' => 'Chinese (Simplified)', 'native' => '简体中文', 'locale' => 'zh_CN', 'rtl' => false, 'flag' => 'cn' ),
			array( 'code' => 'zh-tw', 'name' => 'Chinese (Traditional)', 'native' => '繁體中文', 'locale' => 'zh_TW', 'rtl' => false, 'flag' => 'tw' ),
			array( 'code' => 'ja', 'name' => 'Japanese', 'native' => '日本語', 'locale' => 'ja', 'rtl' => false, 'flag' => 'jp' ),
			array( 'code' => 'ko', 'name' => 'Korean', 'native' => '한국어', 'locale' => 'ko_KR', 'rtl' => false, 'flag' => 'kr' ),
			array( 'code' => 'es', 'name' => 'Spanish', 'native' => 'Español', 'locale' => 'es_ES', 'rtl' => false, 'flag' => 'es' ),
			array( 'code' => 'fr', 'name' => 'French', 'native' => 'Français', 'locale' => 'fr_FR', 'rtl' => false, 'flag' => 'fr' ),
			array( 'code' => 'de', 'name' => 'German', 'native' => 'Deutsch', 'locale' => 'de_DE', 'rtl' => false, 'flag' => 'de' ),
			array( 'code' => 'pt', 'name' => 'Portuguese', 'native' => 'Português', 'locale' => 'pt_PT', 'rtl' => false, 'flag' => 'pt' ),
			array( 'code' => 'pt-br', 'name' => 'Portuguese (Brazil)', 'native' => 'Português (Brasil)', 'locale' => 'pt_BR', 'rtl' => false, 'flag' => 'br' ),
			array( 'code' => 'it', 'name' => 'Italian', 'native' => 'Italiano', 'locale' => 'it_IT', 'rtl' => false, 'flag' => 'it' ),
			array( 'code' => 'ru', 'name' => 'Russian', 'native' => 'Русский', 'locale' => 'ru_RU', 'rtl' => false, 'flag' => 'ru' ),
			array( 'code' => 'ar', 'name' => 'Arabic', 'native' => 'العربية', 'locale' => 'ar', 'rtl' => true, 'flag' => 'sa' ),
			array( 'code' => 'hi', 'name' => 'Hindi', 'native' => 'हिन्दी', 'locale' => 'hi_IN', 'rtl' => false, 'flag' => 'in' ),
			array( 'code' => 'th', 'name' => 'Thai', 'native' => 'ไทย', 'locale' => 'th', 'rtl' => false, 'flag' => 'th' ),
			array( 'code' => 'vi', 'name' => 'Vietnamese', 'native' => 'Tiếng Việt', 'locale' => 'vi', 'rtl' => false, 'flag' => 'vn' ),
			array( 'code' => 'id', 'name' => 'Indonesian', 'native' => 'Bahasa Indonesia', 'locale' => 'id_ID', 'rtl' => false, 'flag' => 'id' ),
			array( 'code' => 'tr', 'name' => 'Turkish', 'native' => 'Türkçe', 'locale' => 'tr_TR', 'rtl' => false, 'flag' => 'tr' ),
			array( 'code' => 'nl', 'name' => 'Dutch', 'native' => 'Nederlands', 'locale' => 'nl_NL', 'rtl' => false, 'flag' => 'nl' ),
			array( 'code' => 'pl', 'name' => 'Polish', 'native' => 'Polski', 'locale' => 'pl_PL', 'rtl' => false, 'flag' => 'pl' ),
			array( 'code' => 'sv', 'name' => 'Swedish', 'native' => 'Svenska', 'locale' => 'sv_SE', 'rtl' => false, 'flag' => 'se' ),
			array( 'code' => 'uk', 'name' => 'Ukrainian', 'native' => 'Українська', 'locale' => 'uk', 'rtl' => false, 'flag' => 'ua' ),
			array( 'code' => 'he', 'name' => 'Hebrew', 'native' => 'עברית', 'locale' => 'he_IL', 'rtl' => true, 'flag' => 'il' ),
			array( 'code' => 'fa', 'name' => 'Persian', 'native' => 'فارسی', 'locale' => 'fa_IR', 'rtl' => true, 'flag' => 'ir' ),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function default_set(): array {
		$site = determine_locale();
		$code = str_starts_with( strtolower( $site ), 'zh' ) ? 'zh' : 'en';
		$second = 'zh' === $code ? 'en' : 'zh';
		$by_code = array();
		foreach ( self::catalog() as $item ) {
			$by_code[ $item['code'] ] = $item;
		}

		return array(
			array_merge( $by_code[ $code ], array( 'enabled' => true, 'is_default' => true ) ),
			array_merge( $by_code[ $second ], array( 'enabled' => true, 'is_default' => false ) ),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function enabled(): array {
		return array_values(
			array_filter(
				$this->options->languages(),
				static fn( $lang ) => ! empty( $lang['enabled'] )
			)
		);
	}

	public function default_code(): string {
		foreach ( $this->options->languages() as $lang ) {
			if ( ! empty( $lang['is_default'] ) ) {
				return (string) $lang['code'];
			}
		}
		$enabled = $this->enabled();
		return (string) ( $enabled[0]['code'] ?? 'en' );
	}

	public function current(): string {
		$code = $this->rewrite_language();
		return $code ?: $this->default_code();
	}

	public function is_default( ?string $code = null ): bool {
		return ( $code ?? $this->current() ) === $this->default_code();
	}

	public function is_enabled( string $code ): bool {
		foreach ( $this->enabled() as $lang ) {
			if ( $lang['code'] === $code ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( string $code ): ?array {
		foreach ( $this->options->languages() as $lang ) {
			if ( (string) $lang['code'] === $code ) {
				return $lang;
			}
		}
		foreach ( self::catalog() as $lang ) {
			if ( $lang['code'] === $code ) {
				return $lang;
			}
		}
		return null;
	}

	public function label( string $code ): string {
		$item = $this->find( $code );
		return $item['native'] ?? $item['name'] ?? $code;
	}

	public function locale( string $code ): string {
		$item = $this->find( $code );
		return (string) ( $item['locale'] ?? $code );
	}

	public function is_rtl( string $code ): bool {
		$item = $this->find( $code );
		return ! empty( $item['rtl'] );
	}

	public function flag( string $code ): string {
		$item = $this->find( $code );
		if ( ! empty( $item['flag'] ) ) {
			return (string) $item['flag'];
		}
		return Lingora_Flags::country( $code );
	}

	public function iso_label( string $code ): string {
		return Lingora_Flags::iso_label( $code );
	}

	private function rewrite_language(): string {
		return (string) ( $GLOBALS['lingora_language'] ?? '' );
	}
}

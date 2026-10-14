<?php
/**
 * Language ISO labels and flag country codes (evenmed-style switcher).
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Flags {
	/**
	 * @var array<string, string>
	 */
	private const COUNTRY = array(
		'en'    => 'us',
		'zh'    => 'cn',
		'zh-tw' => 'tw',
		'ja'    => 'jp',
		'ko'    => 'kr',
		'es'    => 'es',
		'fr'    => 'fr',
		'de'    => 'de',
		'pt'    => 'pt',
		'pt-br' => 'br',
		'it'    => 'it',
		'ru'    => 'ru',
		'ar'    => 'sa',
		'hi'    => 'in',
		'th'    => 'th',
		'vi'    => 'vn',
		'id'    => 'id',
		'tr'    => 'tr',
		'nl'    => 'nl',
		'pl'    => 'pl',
		'sv'    => 'se',
		'uk'    => 'ua',
		'he'    => 'il',
		'fa'    => 'ir',
	);

	public static function country( string $code ): string {
		$code = strtolower( str_replace( '_', '-', $code ) );
		if ( str_contains( $code, '-' ) && preg_match( '/-([a-z]{2})$/', $code, $match ) ) {
			return $match[1];
		}
		return self::COUNTRY[ $code ] ?? self::COUNTRY[ explode( '-', $code )[0] ] ?? 'xx';
	}

	public static function iso_label( string $code ): string {
		return strtoupper( str_replace( '_', '-', $code ) );
	}

	public static function asset_url( string $code, string $flag = '' ): string {
		$country = strtolower( $flag !== '' ? $flag : self::country( $code ) );
		if ( 1 !== preg_match( '/^[a-z]{2}$/', $country ) ) {
			$country = self::country( $code );
		}
		if ( ! is_file( LINGORA_PATH . 'public/vendor/flag-icons/4x3/' . $country . '.svg' ) ) {
			$country = 'xx';
		}
		return LINGORA_URL . 'public/vendor/flag-icons/4x3/' . $country . '.svg';
	}

	public static function markup( string $code, string $flag = '' ): string {
		return sprintf(
			'<img class="lingora-flag" src="%s" width="20" height="15" alt="" aria-hidden="true" decoding="async">',
			esc_url( self::asset_url( $code, $flag ) )
		);
	}
}

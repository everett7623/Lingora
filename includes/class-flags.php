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
		$code = strtolower( $code );
		return self::COUNTRY[ $code ] ?? substr( $code, 0, 2 );
	}

	public static function iso_label( string $code ): string {
		$map = array(
			'zh-tw' => 'ZH',
			'pt-br' => 'BR',
		);
		$code = strtolower( $code );
		return $map[ $code ] ?? strtoupper( $code );
	}

	public static function markup( string $code, string $flag = '' ): string {
		$country = strtolower( $flag !== '' ? $flag : self::country( $code ) );
		if ( 1 !== preg_match( '/^[a-z]{2}$/', $country ) ) {
			$country = self::country( $code );
		}
		$src = LINGORA_URL . 'public/vendor/flag-icons/4x3/' . $country . '.svg';
		return sprintf(
			'<img class="lingora-flag" src="%s" width="20" height="15" alt="" aria-hidden="true" decoding="async">',
			esc_url( $src ),
		);
	}
}

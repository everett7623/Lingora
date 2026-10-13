<?php
/**
 * UTF-8 text helpers with an optional mbstring fast path.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Text {
	public static function length( string $text ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' );
		}
		$count = preg_match_all( '/./us', $text );
		return false === $count ? strlen( $text ) : $count;
	}

	public static function substring( string $text, int $start, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, $start, $length, 'UTF-8' );
		}
		$characters = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $characters ) ? implode( '', array_slice( $characters, $start, $length ) ) : substr( $text, $start, $length );
	}
}

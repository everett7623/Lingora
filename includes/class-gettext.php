<?php
/**
 * Frontend gettext translation for themes and plugins.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Gettext {
	private static bool $busy = false;

	private Lingora_Translator $translator;
	private Lingora_Languages $languages;
	private Lingora_Options $options;

	/**
	 * @var array<string, string>
	 */
	private array $cache = array();

	public function __construct( Lingora_Translator $translator, Lingora_Languages $languages, Lingora_Options $options ) {
		$this->translator = $translator;
		$this->languages  = $languages;
		$this->options    = $options;
	}

	public function register(): void {
		if ( ! $this->options->get( 'translate_gettext', true ) ) {
			return;
		}
		add_filter( 'gettext', array( $this, 'gettext' ), 20, 3 );
		add_filter( 'gettext_with_context', array( $this, 'gettext_context' ), 20, 4 );
		add_filter( 'ngettext', array( $this, 'ngettext' ), 20, 5 );
	}

	public function gettext( string $translation, string $text, string $domain ): string {
		if ( $translation !== $text ) {
			return $translation;
		}
		return $this->translate( $text, $domain );
	}

	public function gettext_context( string $translation, string $text, string $context, string $domain ): string {
		unset( $context );
		if ( $translation !== $text ) {
			return $translation;
		}
		return $this->translate( $text, $domain );
	}

	public function ngettext( string $translation, string $single, string $plural, int $number, string $domain ): string {
		$source = 1 === $number ? $single : $plural;
		if ( $translation !== $source ) {
			return $translation;
		}
		return $this->translate( $source, $domain );
	}

	private function translate( string $text, string $domain ): string {
		if ( self::$busy ) {
			return $text;
		}
		if ( ! did_action( 'wp' ) || ! Lingora_Content::is_public_request() ) {
			return $text;
		}
		if ( 'lingora' === $domain ) {
			return $text;
		}
		if ( $this->languages->is_default() ) {
			return $text;
		}
		if ( ! Lingora_Translator::is_translatable( $text ) ) {
			return $text;
		}

		$hash = lingora()->db->hash( $text );
		if ( isset( $this->cache[ $hash ] ) ) {
			return $this->cache[ $hash ];
		}

		self::$busy = true;
		try {
			if ( Lingora_Content::can_collect_request() ) {
				$map = $this->translator->resolve( array( $text ), $this->languages->current(), 'gettext:' . $domain, false );
				$out = $map[ $hash ] ?? $text;
			} else {
				$rows = lingora()->db->map_for_language( array( $hash ), $this->languages->current() );
				$out  = $rows[ $hash ]['translated'] ?? $text;
			}
			$out = '' !== $out ? wp_kses_post( $out ) : $text;
		} catch ( Throwable $e ) {
			$out = $text;
		} finally {
			self::$busy = false;
		}
		$this->cache[ $hash ] = $out;
		return $out;
	}
}

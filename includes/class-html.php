<?php
/**
 * Translate rendered text and safe presentation attributes.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Html {
	private const SKIP_TAGS = array(
		'script', 'style', 'code', 'pre', 'textarea', 'svg', 'noscript',
		'iframe', 'kbd', 'samp', 'math', 'template',
	);
	private const ATTRS = array( 'alt', 'title', 'placeholder', 'aria-label', 'aria-placeholder' );
	private const META_NAMES = array( 'description', 'og:title', 'og:description', 'twitter:title', 'twitter:description' );
	private const NO_WRAPPER = array( 'title', 'option', 'optgroup', 'head' );

	private Lingora_Translator $translator;
	private Lingora_Languages $languages;
	private Lingora_Options $options;
	private Lingora_Db $db;
	private array $exclude_classes = array();
	private array $exclude_ids = array();
	private array $exclude_tags = array();

	public function __construct( Lingora_Translator $translator, Lingora_Languages $languages, ?Lingora_Options $options = null, ?Lingora_Db $db = null ) {
		$this->translator = $translator;
		$this->languages  = $languages;
		$this->options    = $options ?? new Lingora_Options();
		$this->db         = $db ?? new Lingora_Db();
	}

	/** @return list<string> */
	public function extract( string $html ): array {
		$dom = $this->document( $html );
		if ( ! $dom ) {
			return array();
		}
		$this->prime_exclusions();
		$nodes = array();
		$texts = array();
		$this->collect( $dom->documentElement, $nodes, $texts );
		return array_values( array_unique( $texts ) );
	}

	public function translate( string $html, string $language, string $source = '', bool $wrap_editor = false, bool $force = false ): string {
		$store_only = $this->languages->is_default( $language ) && ! $wrap_editor;
		$dom        = $this->document( $html );
		if ( ! $dom ) {
			return $html;
		}
		$this->prime_exclusions();
		$nodes = array();
		$texts = array();
		$this->collect( $dom->documentElement, $nodes, $texts );

		$map = array();
		if ( $texts && Lingora_Content::can_collect_request() ) {
			$map = $this->translator->resolve( $texts, $language, $source, ! $wrap_editor && ! $store_only && ! $force );
		}
		$hashes = array_values( array_unique( array_map( array( $this->db, 'hash' ), $texts ) ) );
		$rows   = $hashes ? $this->db->map_for_language( $hashes, $language ) : array();
		if ( $force && ! $this->languages->is_default( $language ) ) {
			$known = array_map( static fn( $row ) => (string) $row['original'], $rows );
			$this->translator->enqueue_originals( array_values( $known ), $language, $source, true );
		}
		if ( $store_only ) {
			return $html;
		}

		foreach ( $nodes as $item ) {
			$hash       = $this->db->hash( $item['text'] );
			$row        = $rows[ $hash ] ?? null;
			$translated = $this->languages->is_default( $language ) ? $item['text'] : ( $row['translated'] ?? $map[ $hash ] ?? $item['text'] );
			if ( '' === $translated ) {
				$translated = $item['text'];
			}
			if ( 'text' === $item['type'] ) {
				if ( $wrap_editor && $row && ! in_array( strtolower( $item['node']->parentNode->nodeName ?? '' ), self::NO_WRAPPER, true ) ) {
					$this->wrap_text_node( $item['node'], $hash, $translated, (int) $row['id'], (string) $row['original'] );
				} else {
					$item['node']->data = $this->keep_edge_space( $item['text'], $translated );
				}
			} else {
				$item['node']->setAttribute( $item['attr'], $translated );
				if ( $wrap_editor && $row ) {
					$existing = json_decode( $item['node']->getAttribute( 'data-lingora-attrs' ), true );
					$attrs    = is_array( $existing ) ? $existing : array();
					$attrs[]  = array(
						'id'         => (int) $row['id'],
						'hash'       => $hash,
						'attr'       => $item['attr'],
						'original'   => (string) $row['original'],
						'translated' => $translated,
					);
					$item['node']->setAttribute( 'data-lingora-attrs', (string) wp_json_encode( $attrs ) );
				}
			}
		}
		return $this->finalize( $dom, $html, $language );
	}

	private function document( string $html ): ?DOMDocument {
		if ( ! class_exists( 'DOMDocument' ) || '' === trim( $html ) ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		try {
			$dom    = new DOMDocument( '1.0', 'UTF-8' );
			$loaded = $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NODEFDTD | LIBXML_NONET );
			return $loaded ? $dom : null;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	private function collect( ?DOMNode $node, array &$nodes, array &$texts ): void {
		if ( ! $node ) {
			return;
		}
		if ( $node instanceof DOMElement ) {
			$tag = strtolower( $node->nodeName );
			if ( in_array( $tag, self::SKIP_TAGS, true ) || $this->should_skip_element( $node ) ) {
				return;
			}
			$attributes = self::ATTRS;
			if ( 'meta' === $tag ) {
				$name = strtolower( $node->getAttribute( 'name' ) ?: $node->getAttribute( 'property' ) );
				if ( in_array( $name, self::META_NAMES, true ) ) {
					$attributes[] = 'content';
				}
			}
			if ( 'input' === $tag && in_array( strtolower( $node->getAttribute( 'type' ) ), array( 'submit', 'button', 'reset' ), true ) ) {
				$attributes[] = 'value';
			}
			foreach ( $attributes as $attr ) {
				$value = $node->getAttribute( $attr );
				if ( Lingora_Translator::is_translatable( $value ) ) {
					$nodes[] = array( 'type' => 'attr', 'node' => $node, 'attr' => $attr, 'text' => $value );
					$texts[] = $value;
				}
			}
		}
		if ( $node instanceof DOMText ) {
			$text = $node->data;
			if ( Lingora_Translator::is_translatable( $text ) ) {
				$nodes[] = array( 'type' => 'text', 'node' => $node, 'text' => $text );
				$texts[] = $text;
			}
			return;
		}
		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			$this->collect( $child, $nodes, $texts );
		}
	}

	private function should_skip_element( DOMElement $el ): bool {
		$class = ' ' . ( preg_replace( '/\s+/u', ' ', trim( $el->getAttribute( 'class' ) ) ) ?? '' ) . ' ';
		$id    = $el->getAttribute( 'id' );
		if ( str_contains( $class, ' notranslate ' ) || str_contains( $class, ' lingora-skip ' ) || 'no' === strtolower( $el->getAttribute( 'translate' ) ) || $el->hasAttribute( 'data-no-translation' ) ) {
			return true;
		}
		if ( in_array( $id, array( 'wpadminbar', 'wp-admin-bar-root', 'lingora-editor' ), true ) ) {
			return true;
		}
		foreach ( $this->exclude_classes as $token ) {
			if ( str_contains( $class, $token ) ) {
				return true;
			}
		}
		return ( '' !== $id && in_array( $id, $this->exclude_ids, true ) ) || in_array( strtolower( $el->nodeName ), $this->exclude_tags, true );
	}

	private function prime_exclusions(): void {
		$this->exclude_classes = array();
		$this->exclude_ids     = array();
		$this->exclude_tags    = array();
		foreach ( explode( ',', (string) $this->options->get( 'exclude_selectors', '' ) ) as $selector ) {
			$selector = trim( $selector );
			if ( '' === $selector ) {
				continue;
			}
			if ( str_starts_with( $selector, '.' ) ) {
				$this->exclude_classes[] = ' ' . substr( $selector, 1 ) . ' ';
			} elseif ( str_starts_with( $selector, '#' ) ) {
				$this->exclude_ids[] = substr( $selector, 1 );
			} else {
				$this->exclude_tags[] = strtolower( $selector );
			}
		}
	}

	private function keep_edge_space( string $original, string $translated ): string {
		preg_match( '/^\s*/u', $original, $leading );
		preg_match( '/\s*$/u', $original, $trailing );
		$core = preg_replace( '/^\s+|\s+$/u', '', $translated ) ?? $translated;
		return ( $leading[0] ?? '' ) . $core . ( $trailing[0] ?? '' );
	}

	private function wrap_text_node( DOMText $node, string $hash, string $translated, int $string_id, string $original ): void {
		$span = $node->ownerDocument->createElement( 'span' );
		$span->setAttribute( 'class', 'lingora-node' );
		$span->setAttribute( 'data-lingora-hash', $hash );
		$span->setAttribute( 'data-lingora-original', $original );
		$span->setAttribute( 'data-lingora-id', (string) $string_id );
		$span->appendChild( $node->ownerDocument->createTextNode( $this->keep_edge_space( $node->data, $translated ) ) );
		$node->parentNode?->replaceChild( $span, $node );
	}

	private function finalize( DOMDocument $dom, string $original, string $language ): string {
		$html_el = $dom->getElementsByTagName( 'html' )->item( 0 );
		if ( $html_el instanceof DOMElement ) {
			$html_el->setAttribute( 'lang', str_replace( '_', '-', $this->languages->locale( $language ) ) );
			$html_el->setAttribute( 'dir', $this->languages->is_rtl( $language ) ? 'rtl' : 'ltr' );
		}
		$out = $dom->saveHTML();
		return is_string( $out ) && '' !== $out ? ( preg_replace( '/^<\?xml[^>]*>\s*/i', '', $out ) ?? $out ) : $original;
	}
}

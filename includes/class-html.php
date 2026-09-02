<?php
/**
 * TranslatePress-style HTML walker.
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

	private Lingora_Translator $translator;
	private Lingora_Languages $languages;

	public function __construct( Lingora_Translator $translator, Lingora_Languages $languages ) {
		$this->translator = $translator;
		$this->languages  = $languages;
	}

	public function translate( string $html, string $language, string $source = '', bool $wrap_editor = false ): string {
		$store_only = $this->languages->is_default( $language ) && ! $wrap_editor;

		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument( '1.0', 'UTF-8' );
		$loaded   = $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $html;
		}

		$nodes = array();
		$texts = array();
		$this->collect( $dom->documentElement, $nodes, $texts );

		if ( $texts ) {
			$map = $this->translator->resolve( $texts, $language, $source, ! $wrap_editor && ! $store_only );
		} else {
			$map = array();
		}

		if ( $store_only ) {
			return $html;
		}

		if ( ! $texts ) {
			return $this->finalize( $dom, $html, $language );
		}

		foreach ( $nodes as $item ) {
			$hash = lingora()->db->hash( $item['text'] );
			$translated = $map[ $hash ] ?? $item['text'];
			if ( 'text' === $item['type'] ) {
				if ( $wrap_editor ) {
					$row = lingora()->db->get_string_by_hash( $hash );
					$this->wrap_text_node( $item['node'], $hash, $translated, $row ? (int) $row->id : 0 );
					continue;
				}
				$item['node']->nodeValue = $translated;
			} else {
				$item['node']->setAttribute( $item['attr'], $translated );
				if ( $wrap_editor ) {
					$item['node']->setAttribute( 'data-lingora-hash', $hash );
				}
			}
		}

		return $this->finalize( $dom, $html, $language );
	}

	/**
	 * @param list<array<string, mixed>> $nodes
	 * @param list<string>               $texts
	 */
	private function collect( ?DOMNode $node, array &$nodes, array &$texts ): void {
		if ( ! $node ) {
			return;
		}
		if ( XML_ELEMENT_NODE === $node->nodeType ) {
			$tag = strtolower( $node->nodeName );
			if ( in_array( $tag, self::SKIP_TAGS, true ) ) {
				return;
			}
			if ( $node instanceof DOMElement && $this->should_skip_element( $node ) ) {
				return;
			}
			if ( $node instanceof DOMElement ) {
				foreach ( self::ATTRS as $attr ) {
					if ( $node->hasAttribute( $attr ) ) {
						$value = $node->getAttribute( $attr );
						if ( Lingora_Translator::is_translatable( $value ) ) {
							$nodes[] = array(
								'type' => 'attr',
								'node' => $node,
								'attr' => $attr,
								'text' => $value,
							);
							$texts[] = $value;
						}
					}
				}
				if ( 'meta' === $tag ) {
					$name = strtolower( $node->getAttribute( 'name' ) . ' ' . $node->getAttribute( 'property' ) );
					if ( str_contains( $name, 'description' ) || str_contains( $name, 'title' ) || str_contains( $name, 'og:' ) ) {
						$value = $node->getAttribute( 'content' );
						if ( Lingora_Translator::is_translatable( $value ) ) {
							$nodes[] = array(
								'type' => 'attr',
								'node' => $node,
								'attr' => 'content',
								'text' => $value,
							);
							$texts[] = $value;
						}
					}
				}
				if ( in_array( $tag, array( 'input', 'button' ), true ) && in_array( strtolower( $node->getAttribute( 'type' ) ?: 'submit' ), array( 'submit', 'button', 'reset' ), true ) ) {
					$value = $node->getAttribute( 'value' );
					if ( Lingora_Translator::is_translatable( $value ) ) {
						$nodes[] = array(
							'type' => 'attr',
							'node' => $node,
							'attr' => 'value',
							'text' => $value,
						);
						$texts[] = $value;
					}
				}
			}
		}

		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = $node->nodeValue ?? '';
			if ( Lingora_Translator::is_translatable( $text ) ) {
				$nodes[] = array(
					'type' => 'text',
					'node' => $node,
					'text' => $text,
				);
				$texts[] = $text;
			}
			return;
		}

		if ( $node->hasChildNodes() ) {
			foreach ( iterator_to_array( $node->childNodes ) as $child ) {
				$this->collect( $child, $nodes, $texts );
			}
		}
	}

	private function should_skip_element( DOMElement $el ): bool {
		$class = ' ' . $el->getAttribute( 'class' ) . ' ';
		if ( str_contains( $class, ' notranslate ' ) || str_contains( $class, ' lingora-skip ' ) ) {
			return true;
		}
		if ( 'true' === $el->getAttribute( 'translate' ) ) {
			return false;
		}
		if ( 'no' === $el->getAttribute( 'translate' ) ) {
			return true;
		}
		if ( $el->hasAttribute( 'data-no-translation' ) ) {
			return true;
		}
		$id = $el->getAttribute( 'id' );
		if ( in_array( $id, array( 'wpadminbar', 'wp-admin-bar-root' ), true ) ) {
			return true;
		}
		return $this->matches_exclude_selector( $el, $class, $id );
	}

	private function matches_exclude_selector( DOMElement $el, string $class, string $id ): bool {
		$raw = (string) lingora()->options->get( 'exclude_selectors', '' );
		if ( '' === trim( $raw ) ) {
			return false;
		}
		foreach ( explode( ',', $raw ) as $selector ) {
			$selector = trim( $selector );
			if ( '' === $selector ) {
				continue;
			}
			if ( str_starts_with( $selector, '.' ) ) {
				$token = ' ' . ltrim( $selector, '.' ) . ' ';
				if ( str_contains( $class, $token ) ) {
					return true;
				}
				continue;
			}
			if ( str_starts_with( $selector, '#' ) && $id === ltrim( $selector, '#' ) ) {
				return true;
			}
			if ( strtolower( $el->nodeName ) === strtolower( $selector ) ) {
				return true;
			}
		}
		return false;
	}

	private function wrap_text_node( DOMText $node, string $hash, string $translated, int $string_id ): void {
		$span = $node->ownerDocument->createElement( 'span' );
		$span->setAttribute( 'class', 'lingora-node' );
		$span->setAttribute( 'data-lingora-hash', $hash );
		$span->setAttribute( 'data-lingora-original', $node->nodeValue ?? '' );
		if ( $string_id ) {
			$span->setAttribute( 'data-lingora-id', (string) $string_id );
		}
		$span->appendChild( $node->ownerDocument->createTextNode( $translated ) );
		$node->parentNode?->replaceChild( $span, $node );
	}

	private function finalize( DOMDocument $dom, string $original, string $language ): string {
		$html_el = $dom->getElementsByTagName( 'html' )->item( 0 );
		if ( $html_el instanceof DOMElement ) {
			$html_el->setAttribute( 'lang', str_replace( '_', '-', $this->languages->locale( $language ) ) );
			if ( $this->languages->is_rtl( $language ) ) {
				$html_el->setAttribute( 'dir', 'rtl' );
			}
		}
		$out = $dom->saveHTML();
		if ( ! is_string( $out ) || '' === $out ) {
			return $original;
		}
		$out = preg_replace( '/^<\?xml[^>]*>/i', '', $out ) ?? $out;
		return $out;
	}
}

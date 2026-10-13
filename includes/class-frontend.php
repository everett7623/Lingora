<?php
/**
 * Frontend output buffer.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Frontend {
	private Lingora_Translator $translator;
	private Lingora_Languages $languages;
	private Lingora_Options $options;
	private ?Lingora_Html $html = null;
	private bool $buffering = false;

	public function __construct( Lingora_Translator $translator, Lingora_Languages $languages, Lingora_Options $options ) {
		$this->translator = $translator;
		$this->languages  = $languages;
		$this->options    = $options;
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'start_buffer' ), 0 );
	}

	public function start_buffer(): void {
		if ( $this->buffering || ! class_exists( 'DOMDocument' ) || ! Lingora_Content::is_public_request() ) {
			return;
		}
		if ( $this->is_excluded_path() ) {
			return;
		}
		if ( ! $this->needs_buffer() ) {
			return;
		}
		if ( Lingora_Editor::is_active() || Lingora_Editor::is_force() ) {
			nocache_headers();
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
		}
		$this->buffering = true;
		ob_start( array( $this, 'translate_html' ) );
	}

	/**
	 * 默认语言的页面既不改写 HTML 也不产出任何译文——Lingora_Html 会在 $store_only
	 * 分支里原样返回。为此对每个请求做一次整页 DOMDocument 解析 + 全树遍历纯属浪费，
	 * 而默认语言通常占了站点大部分流量。
	 *
	 * 字典的填充不依赖这条路径：每日的 Lingora_Scanner 会扫描文章、页面和菜单，
	 * 目标语言的访问同样会把渲染出的文案入库（主题外壳在两种语言下是同一批字符串）。
	 */
	private function needs_buffer(): bool {
		if ( Lingora_Editor::is_active() || Lingora_Editor::is_force() ) {
			return true;
		}
		if ( count( $this->languages->enabled() ) < 2 ) {
			return false;
		}
		return ! $this->languages->is_default();
	}

	public function translate_html( string $html ): string {
		try {
			if ( ! Lingora_Content::is_public_request() || '' === trim( $html ) || false === stripos( $html, '<html' ) ) {
				return $html;
			}
			$language = $this->languages->current();
			$source   = $this->current_source();
			$editor   = Lingora_Editor::is_active();
			$force    = Lingora_Editor::is_force();
			return $this->html()->translate( $html, $language, $source, $editor, $force );
		} catch ( Throwable $e ) {
			return $html;
		}
	}

	private function html(): Lingora_Html {
		if ( ! $this->html ) {
			$this->html = new Lingora_Html( $this->translator, $this->languages, $this->options );
		}
		return $this->html;
	}

	private function current_source(): string {
		if ( is_singular() ) {
			return 'post:' . get_queried_object_id();
		}
		if ( is_front_page() ) {
			return 'home';
		}
		$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
		return is_string( $path ) ? $path : '/';
	}

	private function is_excluded_path(): bool {
		$raw     = (string) ( $GLOBALS['lingora_raw_path'] ?? ( $_SERVER['REQUEST_URI'] ?? '/' ) );
		$exclude = (string) $this->options->get( 'exclude_paths', '' );
		foreach ( explode( ',', $exclude ) as $path ) {
			$path = trim( $path );
			if ( '' !== $path && str_contains( $raw, $path ) ) {
				return true;
			}
		}
		return false;
	}
}

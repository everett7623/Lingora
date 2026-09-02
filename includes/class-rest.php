<?php
/**
 * REST API for admin and visual editor.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Rest {
	private Lingora_Options $options;
	private Lingora_Languages $languages;
	private Lingora_Providers $providers;
	private Lingora_Ai_Client $ai;
	private Lingora_Translator $translator;
	private Lingora_Db $db;

	public function __construct(
		Lingora_Options $options,
		Lingora_Languages $languages,
		Lingora_Providers $providers,
		Lingora_Ai_Client $ai,
		Lingora_Translator $translator,
		Lingora_Db $db
	) {
		$this->options    = $options;
		$this->languages  = $languages;
		$this->providers  = $providers;
		$this->ai         = $ai;
		$this->translator = $translator;
		$this->db         = $db;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$ns = 'lingora/v1';
		register_rest_route( $ns, '/bootstrap', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'bootstrap' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );
		register_rest_route( $ns, '/settings', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_settings' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );
		register_rest_route( $ns, '/languages', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_languages' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );
		register_rest_route( $ns, '/test', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'test_connection' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );
		register_rest_route( $ns, '/models', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'list_models' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );
		register_rest_route( $ns, '/strings', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'strings' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );
		register_rest_route( $ns, '/strings/(?P<id>\d+)', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_string' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );
		register_rest_route( $ns, '/translate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'translate_now' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );
		register_rest_route( $ns, '/stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'stats' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public function bootstrap(): WP_REST_Response {
		$settings = $this->options->all();
		$ai       = $settings['ai'];
		$ai['api_key']     = $this->mask( (string) $ai['api_key'] );
		$ai['project_key'] = $this->mask( (string) $ai['project_key'] );
		$ai['has_key']     = $this->options->has_ai_credential();
		$settings['ai']    = $ai;

		return new WP_REST_Response(
			array(
				'settings'   => $settings,
				'languages'  => $this->options->languages(),
				'catalog'    => Lingora_Languages::catalog(),
				'providers'  => $this->providers->public_list(),
				'stats'      => $this->db->stats(),
				'default'    => $this->languages->default_code(),
			)
		);
	}

	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( array( 'message' => __( '无效请求', 'lingora' ) ), 400 );
		}
		$patch = array();
		foreach ( array( 'url_mode', 'switcher_style', 'switcher_position', 'exclude_selectors', 'exclude_paths' ) as $key ) {
			if ( isset( $body[ $key ] ) ) {
				$patch[ $key ] = sanitize_text_field( (string) $body[ $key ] );
			}
		}
		foreach ( array(
			'hide_default',
			'auto_translate',
			'translate_on_fly',
			'add_hreflang',
			'detect_browser',
			'remember_language',
			'translate_slugs',
			'translate_gettext',
			'multilingual_sitemap',
			'search_translations',
			'switcher_enabled',
			'switcher_in_menu',
			'switcher_show_flag',
			'switcher_show_code',
			'switcher_show_name',
		) as $key ) {
			if ( isset( $body[ $key ] ) ) {
				$patch[ $key ] = (bool) $body[ $key ];
			}
		}
		if ( isset( $body['on_fly_limit'] ) ) {
			$patch['on_fly_limit'] = max( 0, min( 40, absint( $body['on_fly_limit'] ) ) );
		}
		if ( isset( $body['ai'] ) && is_array( $body['ai'] ) ) {
			$this->save_ai( $body['ai'] );
		}
		if ( $patch ) {
			$this->options->update( $patch );
		}
		return $this->bootstrap();
	}

	public function save_languages( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		$list = $body['languages'] ?? null;
		if ( ! is_array( $list ) || ! $list ) {
			return new WP_REST_Response( array( 'message' => __( '至少保留一种语言', 'lingora' ) ), 400 );
		}
		$clean   = array();
		$default = '';
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) || empty( $item['code'] ) ) {
				continue;
			}
			$code = sanitize_title( (string) $item['code'] );
			$meta = $this->languages->find( $code ) ?? array(
				'code'   => $code,
				'name'   => $code,
				'native' => $code,
				'locale' => $code,
				'rtl'    => false,
			);
			$is_default = ! empty( $item['is_default'] );
			if ( $is_default ) {
				$default = $code;
			}
			$clean[] = array(
				'code'       => $code,
				'name'       => sanitize_text_field( (string) ( $item['name'] ?? $meta['name'] ) ),
				'native'     => sanitize_text_field( (string) ( $item['native'] ?? $meta['native'] ) ),
				'locale'     => sanitize_text_field( (string) ( $item['locale'] ?? $meta['locale'] ) ),
				'rtl'        => ! empty( $item['rtl'] ) || ! empty( $meta['rtl'] ),
				'enabled'    => ! isset( $item['enabled'] ) || ! empty( $item['enabled'] ),
				'is_default' => $is_default,
			);
		}
		if ( ! $clean ) {
			return new WP_REST_Response( array( 'message' => __( '没有有效语言', 'lingora' ) ), 400 );
		}
		if ( '' === $default ) {
			$clean[0]['is_default'] = true;
		}
		$this->options->save_languages( $clean );
		flush_rewrite_rules( false );
		return $this->bootstrap();
	}

	public function test_connection( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( isset( $body['ai'] ) && is_array( $body['ai'] ) ) {
			$this->save_ai( $body['ai'] );
		}
		try {
			$text = $this->ai->test_connection();
			return new WP_REST_Response( array( 'ok' => true, 'text' => $text ) );
		} catch ( Throwable $e ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => $e->getMessage() ), 400 );
		}
	}

	public function list_models( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( isset( $body['ai'] ) && is_array( $body['ai'] ) ) {
			$this->save_ai( $body['ai'] );
		}
		try {
			$models = $this->ai->list_models();
			return new WP_REST_Response( array( 'ok' => true, 'models' => $models ) );
		} catch ( Throwable $e ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => $e->getMessage() ), 400 );
		}
	}

	public function strings( WP_REST_Request $request ): WP_REST_Response {
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$language = sanitize_text_field( (string) $request->get_param( 'language' ) ) ?: $this->first_target();
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$result   = $this->db->search_strings( $search, $language, $page, 20 );
		return new WP_REST_Response(
			array(
				'items'    => $result['items'],
				'total'    => $result['total'],
				'page'     => $page,
				'language' => $language,
			)
		);
	}

	public function save_string( WP_REST_Request $request ): WP_REST_Response {
		$id        = absint( $request['id'] );
		$body      = $request->get_json_params();
		$language  = sanitize_text_field( (string) ( $body['language'] ?? $this->first_target() ) );
		$translated = isset( $body['translated'] ) ? wp_kses_post( (string) $body['translated'] ) : '';
		if ( ! $id || '' === $translated ) {
			return new WP_REST_Response( array( 'message' => __( '译文不能为空', 'lingora' ) ), 400 );
		}
		$this->translator->save_human( $id, $language, $translated );
		return new WP_REST_Response( array( 'ok' => true ) );
	}

	public function translate_now( WP_REST_Request $request ): WP_REST_Response {
		$body     = $request->get_json_params();
		$text     = (string) ( $body['text'] ?? '' );
		$language = sanitize_text_field( (string) ( $body['language'] ?? $this->first_target() ) );
		if ( '' === trim( $text ) ) {
			return new WP_REST_Response( array( 'message' => __( '没有可翻译的文本', 'lingora' ) ), 400 );
		}
		try {
			$translated = $this->ai->translate_text( $text, $language, $this->languages->label( $language ) );
			if ( ! empty( $body['string_id'] ) ) {
				$this->db->save_translation( absint( $body['string_id'] ), $language, $translated, 'auto' );
			}
			return new WP_REST_Response( array( 'ok' => true, 'text' => $translated ) );
		} catch ( Throwable $e ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => $e->getMessage() ), 400 );
		}
	}

	public function stats(): WP_REST_Response {
		return new WP_REST_Response( $this->db->stats() );
	}

	/**
	 * @param array<string, mixed> $ai
	 */
	private function save_ai( array $ai ): void {
		$current = $this->options->ai();
		$next    = array();
		if ( isset( $ai['provider_id'] ) ) {
			$seed = $this->providers->seed( (string) $ai['provider_id'] );
			$next = array_merge( $current, $seed );
		} else {
			$next = $current;
		}
		foreach ( array( 'auth_mode', 'base_url', 'model', 'transcribe_model', 'project_key_header', 'project_key_scheme' ) as $key ) {
			if ( isset( $ai[ $key ] ) ) {
				$next[ $key ] = sanitize_text_field( (string) $ai[ $key ] );
			}
		}
		foreach ( array( 'api_key', 'project_key' ) as $secret ) {
			if ( ! isset( $ai[ $secret ] ) ) {
				continue;
			}
			$value = (string) $ai[ $secret ];
			if ( $this->is_masked( $value ) ) {
				continue;
			}
			$next[ $secret ] = sanitize_text_field( $value );
		}
		$this->options->update_ai( $next );
	}

	private function mask( string $value ): string {
		$trim = trim( $value );
		if ( '' === $trim ) {
			return '';
		}
		$tail = substr( $trim, -4 );
		return '••••••••' . $tail;
	}

	private function is_masked( string $value ): bool {
		return str_starts_with( $value, '••••' );
	}

	private function first_target(): string {
		$default = $this->languages->default_code();
		foreach ( $this->languages->enabled() as $lang ) {
			if ( $lang['code'] !== $default ) {
				return (string) $lang['code'];
			}
		}
		return $default;
	}
}

<?php
/**
 * Administrator REST API and visual editor contracts.
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
	private Lingora_Scanner $scanner;

	public function __construct( Lingora_Options $options, Lingora_Languages $languages, Lingora_Providers $providers, Lingora_Ai_Client $ai, Lingora_Translator $translator, Lingora_Db $db, Lingora_Scanner $scanner ) {
		$this->options = $options;
		$this->languages = $languages;
		$this->providers = $providers;
		$this->ai = $ai;
		$this->translator = $translator;
		$this->db = $db;
		$this->scanner = $scanner;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$routes = array(
			array( '/bootstrap', 'GET', 'bootstrap' ), array( '/settings', 'POST', 'save_settings' ),
			array( '/languages', 'POST', 'save_languages' ), array( '/test', 'POST', 'test_connection' ),
			array( '/models', 'POST', 'list_models' ), array( '/strings', 'GET', 'strings' ),
			array( '/strings/(?P<id>\d+)', 'GET', 'string_item' ), array( '/strings/(?P<id>\d+)', 'POST', 'save_string' ),
			array( '/translate', 'POST', 'translate_now' ), array( '/stats', 'GET', 'stats' ),
			array( '/scan', 'POST', 'scan' ), array( '/queue', 'POST', 'run_queue' ),
			array( '/jobs', 'GET', 'jobs' ), array( '/missing', 'POST', 'translate_missing' ),
		);
		foreach ( $routes as [ $route, $method, $callback ] ) {
			register_rest_route( 'lingora/v1', $route, array( 'methods' => $method, 'callback' => array( $this, $callback ), 'permission_callback' => array( $this, 'can_manage' ) ) );
		}
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public function bootstrap(): WP_REST_Response {
		$settings = $this->options->all();
		foreach ( array( 'api_key', 'project_key' ) as $key ) { $settings['ai'][ $key ] = $this->mask( (string) $settings['ai'][ $key ] ); }
		$settings['ai']['has_key'] = $this->options->has_ai_credential();
		$settings['can_send_ai'] = $this->options->can_send_ai();
		return new WP_REST_Response( array( 'settings' => $settings, 'languages' => $this->options->languages(), 'catalog' => Lingora_Languages::catalog(), 'providers' => $this->providers->public_list(), 'stats' => $this->db->stats(), 'default' => $this->languages->default_code() ) );
	}

	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		return $this->guard( function () use ( $request ) {
			$body = $this->body( $request );
			$patch = array();
			$enums = array( 'url_mode' => array( 'directory', 'query' ), 'switcher_style' => array( 'nav', 'floating', 'both' ), 'switcher_position' => array( 'top-right', 'top-left', 'bottom-right', 'bottom-left' ) );
			foreach ( $enums as $key => $values ) {
				if ( array_key_exists( $key, $body ) ) {
					if ( ! in_array( $body[ $key ], $values, true ) ) { throw new InvalidArgumentException( __( '设置选项无效。', 'lingora' ) ); }
					$patch[ $key ] = $body[ $key ];
				}
			}
			foreach ( array( 'hide_default', 'auto_translate', 'translate_on_fly', 'add_hreflang', 'detect_browser', 'remember_language', 'translate_slugs', 'translate_gettext', 'multilingual_sitemap', 'search_translations', 'scan_published', 'switcher_enabled', 'switcher_in_menu', 'switcher_show_flag', 'switcher_show_code', 'switcher_show_name', 'ai_consent' ) as $key ) {
				if ( array_key_exists( $key, $body ) ) {
					if ( ! is_bool( $body[ $key ] ) ) { throw new InvalidArgumentException( __( '开关设置必须是布尔值。', 'lingora' ) ); }
					$patch[ $key ] = $body[ $key ];
				}
			}
			foreach ( array( 'exclude_selectors', 'exclude_paths' ) as $key ) {
				if ( array_key_exists( $key, $body ) ) { $patch[ $key ] = sanitize_text_field( $this->text( $body[ $key ], 2000 ) ); }
			}
			if ( isset( $patch['exclude_selectors'] ) ) {
				foreach ( explode( ',', $patch['exclude_selectors'] ) as $selector ) {
					if ( '' !== trim( $selector ) && ! preg_match( '/^[.#]?[A-Za-z_][A-Za-z0-9_-]*$/', trim( $selector ) ) ) { throw new InvalidArgumentException( __( '排除选择器仅支持标签、.class 或 #id，请用逗号分隔。', 'lingora' ) ); }
				}
			}
			if ( array_key_exists( 'on_fly_limit', $body ) ) {
				if ( ! is_int( $body['on_fly_limit'] ) || $body['on_fly_limit'] < 0 || $body['on_fly_limit'] > 40 ) { throw new InvalidArgumentException( __( '即时翻译上限必须是 0–40 的整数。', 'lingora' ) ); }
				$patch['on_fly_limit'] = $body['on_fly_limit'];
			}
			$patch = array_merge( $patch, $this->connection_patch( $body ) );
			if ( $patch ) { $this->options->update( $patch ); }
			return $this->bootstrap();
		} );
	}

	public function save_languages( WP_REST_Request $request ): WP_REST_Response {
		return $this->guard( function () use ( $request ) {
			$body = $this->body( $request );
			$list = $body['languages'] ?? null;
			if ( ! is_array( $list ) || array_values( $list ) !== $list || ! $list || count( $list ) > 64 ) { throw new InvalidArgumentException( __( '请提供 1–64 种语言。', 'lingora' ) ); }
			$clean = array();
			$seen = array();
			$defaults = 0;
			foreach ( $list as $item ) {
				if ( ! is_array( $item ) ) { throw new InvalidArgumentException( __( '语言条目无效。', 'lingora' ) ); }
				$code = strtolower( $this->text( $item['code'] ?? null, 12 ) );
				if ( ! Lingora_Languages::is_valid_code( $code ) || isset( $seen[ $code ] ) ) { throw new InvalidArgumentException( __( '语言代码无效或重复。', 'lingora' ) ); }
				$seen[ $code ] = true;
				$meta = $this->languages->find( $code ) ?? array( 'name' => $code, 'native' => $code, 'locale' => $code, 'rtl' => false, 'flag' => '' );
				foreach ( array( 'enabled', 'is_default', 'rtl' ) as $key ) {
					if ( array_key_exists( $key, $item ) && ! is_bool( $item[ $key ] ) ) { throw new InvalidArgumentException( __( '语言开关必须是布尔值。', 'lingora' ) ); }
				}
				$enabled = $item['enabled'] ?? true;
				$default = $item['is_default'] ?? false;
				if ( $default && ! $enabled ) { throw new InvalidArgumentException( __( '默认语言必须启用。', 'lingora' ) ); }
				$defaults += (int) $default;
				$locale = $this->text( $item['locale'] ?? $meta['locale'], 35 );
				if ( ! preg_match( '/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{1,12})*$/', $locale ) ) { throw new InvalidArgumentException( __( '语言区域代码无效。', 'lingora' ) ); }
				$flag = $this->text( $item['flag'] ?? ( $meta['flag'] ?? '' ), 12 );
				if ( '' !== $flag && ! preg_match( '/^[a-z]{2}$/', $flag ) ) { throw new InvalidArgumentException( __( '旗帜代码无效。', 'lingora' ) ); }
				$clean[] = array( 'code' => $code, 'name' => sanitize_text_field( $this->text( $item['name'] ?? $meta['name'], 100 ) ), 'native' => sanitize_text_field( $this->text( $item['native'] ?? $meta['native'], 100 ) ), 'locale' => $locale, 'rtl' => $item['rtl'] ?? ( $meta['rtl'] ?? false ), 'enabled' => $enabled, 'is_default' => $default, 'flag' => $flag );
			}
			if ( 1 !== $defaults ) { throw new InvalidArgumentException( __( '必须且只能设置一种默认语言。', 'lingora' ) ); }
			$this->options->save_languages( $clean );
			return $this->bootstrap();
		} );
	}

	public function test_connection( WP_REST_Request $request ): WP_REST_Response {
		return $this->guard( function () use ( $request ) {
			$patch = $this->connection_patch( $this->body( $request ) );
			if ( $patch ) { $this->options->update( $patch ); }
			return new WP_REST_Response( array( 'ok' => true, 'text' => $this->ai->test_connection() ) );
		} );
	}

	public function list_models( WP_REST_Request $request ): WP_REST_Response {
		return $this->guard( function () use ( $request ) {
			$patch = $this->connection_patch( $this->body( $request ) );
			if ( $patch ) { $this->options->update( $patch ); }
			return new WP_REST_Response( array( 'ok' => true, 'models' => $this->ai->list_models() ) );
		} );
	}

	public function strings( WP_REST_Request $request ): WP_REST_Response {
		return $this->guard( function () use ( $request ) {
			$language = $this->language( $request->get_param( 'language' ) ?? $this->first_target() );
			$search = sanitize_text_field( $this->text( $request->get_param( 'search' ) ?? '', 500 ) );
			$status = $this->text( $request->get_param( 'status' ) ?? '', 20 );
			$kind = $this->text( $request->get_param( 'kind' ) ?? '', 20 );
			if ( ! in_array( $status, array( '', 'missing', 'auto', 'human' ), true ) || ! in_array( $kind, array( '', 'text', 'slug' ), true ) ) { throw new InvalidArgumentException( __( '筛选值无效。', 'lingora' ) ); }
			$page = $request->get_param( 'page' ) ?? 1;
			if ( ( ! is_int( $page ) && ! is_string( $page ) ) || ! ctype_digit( (string) $page ) || (int) $page < 1 || (int) $page > 1000000 ) { throw new InvalidArgumentException( __( '页码无效。', 'lingora' ) ); }
			$result = $this->db->search_strings( $search, $language, (int) $page, 20, $status, $kind );
			return new WP_REST_Response( array( 'items' => $result['items'], 'total' => $result['total'], 'page' => (int) $page, 'pages' => max( 1, (int) ceil( $result['total'] / 20 ) ), 'language' => $language ) );
		} );
	}

	public function string_item( WP_REST_Request $request ): WP_REST_Response {
		return $this->guard( function () use ( $request ) {
			$language = $this->language( $request->get_param( 'language' ) );
			$row = $this->db->get_string( absint( $request['id'] ) );
			if ( ! $row ) { return $this->error( __( '字符串不存在。', 'lingora' ), 404 ); }
			$map = $this->db->map_for_language( array( $row->hash ), $language, $row->context );
			return new WP_REST_Response( array( 'id' => (int) $row->id, 'hash' => $row->hash, 'original' => $row->original, 'context' => $row->context, 'source' => $row->source, 'language' => $language, 'translated' => $map[ $row->hash ]['translated'] ?? null, 'status' => $map[ $row->hash ]['status'] ?? null ) );
		} );
	}

	public function save_string( WP_REST_Request $request ): WP_REST_Response {
		return $this->guard( function () use ( $request ) {
			$body = $this->body( $request );
			$language = $this->language( $body['language'] ?? null );
			$value = $this->text( $body['translated'] ?? null, 20000 );
			$row = $this->db->get_string( absint( $request['id'] ) );
			if ( ! $row ) { return $this->error( __( '字符串不存在。', 'lingora' ), 404 ); }
			$value = 'slug' === $row->context ? Lingora_Slugs::normalize_slug( $value ) : wp_kses_post( $value );
			if ( '' === $value ) { throw new InvalidArgumentException( __( '译文不能为空。', 'lingora' ) ); }
			if ( ! $this->translator->save_human( (int) $row->id, $language, $value ) ) { throw new RuntimeException( __( '译文未保存，请刷新条目后重试。', 'lingora' ) ); }
			return new WP_REST_Response( array( 'ok' => true, 'text' => $value ) );
		} );
	}

	public function translate_now( WP_REST_Request $request ): WP_REST_Response {
		return $this->guard( function () use ( $request ) {
			$body = $this->body( $request );
			$language = $this->language( $body['language'] ?? null );
			$row = null;
			if ( array_key_exists( 'string_id', $body ) ) {
				if ( ! is_int( $body['string_id'] ) || $body['string_id'] < 1 ) { throw new InvalidArgumentException( __( '字符串 ID 无效。', 'lingora' ) ); }
				$row = $this->db->get_string( $body['string_id'] );
				if ( ! $row ) { return $this->error( __( '字符串不存在。', 'lingora' ), 404 ); }
				if ( ! Lingora_Translator::source_is_public( $row->source ) ) { return $this->error( __( '此内容已不再公开，不能发送给 AI 服务。', 'lingora' ), 403 ); }
			}
			$original = $row ? $row->original : $this->text( $body['text'] ?? null, 5000 );
			if ( '' === $original ) { throw new InvalidArgumentException( __( '没有可翻译的文本。', 'lingora' ) ); }
			$system = $row && 'slug' === $row->context ? 'Translate this URL slug into ' . $this->languages->label( $language ) . '. Return only a lowercase hyphenated slug without quotes or slashes.' : '';
			$value = $this->ai->translate_text( $original, $language, $this->languages->label( $language ), $system );
			if ( $row && 'slug' === $row->context ) { $value = Lingora_Slugs::normalize_slug( $value ); }
			if ( '' === $value ) { throw new RuntimeException( __( '服务返回空译文。', 'lingora' ) ); }
			if ( $row && ( ! Lingora_Translator::source_is_public( $row->source ) || ! $this->db->get_string( (int) $row->id ) ) ) { return $this->error( __( '原文已变更，译文未保存。', 'lingora' ), 409 ); }
			$saved = $row ? $this->db->save_translation( (int) $row->id, $language, $value, 'auto' ) : false;
			return new WP_REST_Response( array( 'ok' => true, 'text' => $value, 'saved' => $saved ) );
		} );
	}

	public function stats(): WP_REST_Response { return new WP_REST_Response( $this->db->stats() ); }

	public function scan(): WP_REST_Response {
		return $this->guard( fn() => new WP_REST_Response( array( 'ok' => true, 'scan' => $this->scanner->trigger_scan() ), 202 ) );
	}

	public function run_queue(): WP_REST_Response {
		return $this->guard( function () {
			$queue = $this->translator->request_queue();
			return new WP_REST_Response( array( 'ok' => $queue['ok'], 'queue' => $queue, 'message' => $queue['message'] ), $queue['ok'] ? 202 : 400 );
		} );
	}

	public function jobs(): WP_REST_Response {
		return $this->guard( fn() => new WP_REST_Response( array( 'queue' => $this->translator->queue_status(), 'scan' => $this->scanner->scan_status(), 'stats' => $this->db->stats() ) ) );
	}

	public function translate_missing( WP_REST_Request $request ): WP_REST_Response {
		return $this->guard( function () use ( $request ) {
			$language = $this->language( $this->body( $request )['language'] ?? null );
			$result = $this->translator->request_queue( $language );
			return new WP_REST_Response( array( 'ok' => true, 'translated' => 0, 'queued' => $result['queued'] ), 202 );
		} );
	}

	private function connection_patch( array $body ): array {
		$patch = array();
		if ( array_key_exists( 'ai_consent', $body ) ) {
			if ( ! is_bool( $body['ai_consent'] ) ) { throw new InvalidArgumentException( __( 'AI 同意状态必须是布尔值。', 'lingora' ) ); }
			$patch['ai_consent'] = $body['ai_consent'];
		}
		if ( ! array_key_exists( 'ai', $body ) ) { return $patch; }
		$ai = $body['ai'];
		if ( ! is_array( $ai ) || ( $ai && array_values( $ai ) === $ai ) ) { throw new InvalidArgumentException( __( 'AI 设置无效。', 'lingora' ) ); }
		$current = $this->options->ai();
		$id = $this->text( $ai['provider_id'] ?? $current['provider_id'], 30 );
		if ( ! $this->providers->exists( $id ) ) { throw new InvalidArgumentException( __( '未知的 AI 服务商。', 'lingora' ) ); }
		$provider = $this->providers->get( $id );
		$changed = $id !== $current['provider_id'];
		$next = $changed ? array_merge( $current, $this->providers->seed( $id ) ) : $current;
		$next['provider_id'] = $id;
		foreach ( array( 'auth_mode' => array( 'apiKey', 'projectKey' ), 'project_key_scheme' => array( 'bearer', 'raw' ) ) as $key => $allowed ) {
			if ( array_key_exists( $key, $ai ) ) {
				if ( ! in_array( $ai[ $key ], $allowed, true ) ) { throw new InvalidArgumentException( __( '鉴权方式无效。', 'lingora' ) ); }
				$next[ $key ] = $ai[ $key ];
			}
		}
		if ( empty( $provider['configurable_auth'] ) ) { $next['auth_mode'] = $provider['preferred_auth_mode']; }
		if ( array_key_exists( 'base_url', $ai ) ) { $next['base_url'] = rtrim( $this->text( $ai['base_url'], 2000 ), '/' ); }
		if ( empty( $provider['configurable_base_url'] ) ) { $next['base_url'] = $provider['base_url']; }
		if ( '' !== $next['base_url'] ) {
			$error = Lingora_Ai_Client::base_url_error( $next['base_url'] );
			if ( '' !== $error ) { throw new InvalidArgumentException( $error ); }
		}
		$changed = $changed || rtrim( $current['base_url'], '/' ) !== $next['base_url'];
		if ( array_key_exists( 'project_key_header', $ai ) ) {
			$next['project_key_header'] = $this->text( $ai['project_key_header'], 100 );
			$error = Lingora_Ai_Client::header_name_error( $next['project_key_header'] );
			if ( '' !== $error ) { throw new InvalidArgumentException( $error ); }
		}
		$next['model'] = array_key_exists( 'model', $ai ) ? $this->text( $ai['model'], 200 ) : ( $changed ? '' : $next['model'] );
		foreach ( array( 'api_key', 'project_key' ) as $key ) {
			$value = array_key_exists( $key, $ai ) ? $this->text( $ai[ $key ], 1000 ) : null;
			if ( null === $value || str_starts_with( $value, '••••' ) ) {
				if ( $changed ) { $next[ $key ] = ''; }
			} else { $next[ $key ] = $value; }
		}
		if ( $changed && ! array_key_exists( 'ai_consent', $body ) ) { $patch['ai_consent'] = false; }
		$patch['ai'] = $next;
		return $patch;
	}

	private function body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! is_object( json_decode( $request->get_body() ) ) ) { throw new InvalidArgumentException( __( '请求正文必须是 JSON 对象。', 'lingora' ) ); }
		return $body;
	}

	private function text( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) || Lingora_Text::length( $value ) > $maximum || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value ) ) { throw new InvalidArgumentException( __( '文本类型或长度无效。', 'lingora' ) ); }
		return trim( $value );
	}

	private function language( mixed $value ): string {
		$language = strtolower( $this->text( $value, 12 ) );
		if ( ! Lingora_Languages::is_valid_code( $language ) || ! $this->languages->is_enabled( $language ) || $this->languages->is_default( $language ) ) { throw new InvalidArgumentException( __( '请选择已启用的非默认目标语言。', 'lingora' ) ); }
		return $language;
	}

	private function guard( callable $callback ): WP_REST_Response {
		try { return $callback(); } catch ( InvalidArgumentException $e ) { return $this->error( $e->getMessage(), 400 ); }
		catch ( RuntimeException $e ) { return $this->error( $e->getMessage(), 400 ); }
		catch ( Throwable $e ) { return $this->error( __( '操作失败，请检查站点错误日志后重试。', 'lingora' ), 500 ); }
	}

	private function error( string $message, int $status ): WP_REST_Response { return new WP_REST_Response( array( 'message' => $message ), $status ); }
	private function mask( string $value ): string { return '' === trim( $value ) ? '' : '••••••••' . ( strlen( $value ) > 4 ? substr( $value, -4 ) : '' ); }
	private function first_target(): string {
		foreach ( $this->languages->enabled() as $lang ) { if ( ! $this->languages->is_default( $lang['code'] ) ) { return $lang['code']; } }
		return '';
	}
}

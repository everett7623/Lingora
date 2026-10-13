<?php
/**
 * AI HTTP client for website translation.
 *
 * Transport rules: HTTPS only, no credentials/query/fragment, no private or
 * loopback hosts, zero redirects, capped response size, secrets redacted from
 * every error message. No AI traffic leaves the site until the admin has
 * granted explicit consent (Lingora_Options::ai_consent).
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Ai_Client {
	private const TIMEOUT_TEXT   = 45;
	private const TIMEOUT_MODELS = 30;

	/**
	 * 前台渲染路径的硬超时。访客的请求线程绝不能挂在 AI 接口上，
	 * 超时即降级为原文并入队，由 lingora_process_queue 补翻。
	 */
	public const TIMEOUT_ON_FLY = 10;

	/**
	 * 上限 + 1：达到上限即视为被截断的非法响应（limit_response_size 截到上限值）。
	 */
	private const MAX_BODY_BYTES = 1048576;

	/**
	 * 不允许通过自定义请求头覆盖的保留头（小写）。
	 */
	private const RESERVED_HEADERS = array(
		'host',
		'content-length',
		'content-type',
		'connection',
		'transfer-encoding',
		'cookie',
		'expect',
		'te',
		'trailer',
		'upgrade',
	);

	private Lingora_Options $options;
	private Lingora_Providers $providers;

	public function __construct( Lingora_Options $options, Lingora_Providers $providers ) {
		$this->options   = $options;
		$this->providers = $providers;
	}

	/**
	 * @param int $timeout 单次请求超时（秒）；0 表示使用默认值。
	 * @throws RuntimeException
	 */
	public function translate_text( string $text, string $target_lang, string $target_label = '', string $system = '', int $timeout = 0 ): string {
		if ( strlen( $text ) > 220000 || ! Lingora_Languages::is_valid_code( $target_lang ) ) {
			throw new InvalidArgumentException( __( '翻译请求长度或语言无效。', 'lingora' ) );
		}
		$settings = $this->settings();
		$this->require_consent();
		$this->require_text_settings( $settings );
		$provider = $this->providers->get( (string) $settings['provider_id'] );
		$label    = $target_label !== '' ? $target_label : $target_lang;
		$prompt   = $system !== '' ? $system : $this->translator_prompt( $label );
		$body     = $this->chat_body( $provider, $settings, $prompt, $text );
		$data     = $this->post_json( $this->provider_url( $settings, (string) $provider['chat_path'] ), $settings, $body, $timeout > 0 ? $timeout : self::TIMEOUT_TEXT );
		$content  = $this->translation_from_response( $data, (string) $provider['chat_protocol'] );
		if ( '' === $content ) {
			throw new RuntimeException( __( '模型没有返回可识别的译文', 'lingora' ) );
		}
		return $content;
	}

	/**
	 * Translate many strings in one call.
	 *
	 * The model must answer with a JSON array of strings whose length matches
	 * the request exactly. Anything else (wrong count, numbers, nested objects)
	 * is a failure — values are never coerced with strval(). Sequential retry
	 * is opt-in only: a visitor-facing request must not degrade into N serial
	 * AI calls, so the default is false.
	 *
	 * @param list<string> $texts
	 * @param bool         $sequential_fallback 批量失败时是否逐条重试。默认 false；
	 *                                          前台渲染路径必须保持 false。
	 * @param int          $timeout             单次请求超时（秒）；0 表示使用默认值。
	 * @return list<string>
	 * @throws RuntimeException 当 $sequential_fallback 为 false 且批量调用失败时。
	 */
	public function translate_batch( array $texts, string $target_lang, string $target_label = '', bool $sequential_fallback = false, int $timeout = 0 ): array {
		if ( ! $texts ) {
			return array();
		}
		if ( 1 === count( $texts ) ) {
			return array( $this->translate_text( $texts[0], $target_lang, $target_label, '', $timeout ) );
		}

		$label  = $target_label !== '' ? $target_label : $target_lang;
		$system = 'You are a professional WordPress website translator. Translate each numbered string into ' . $label . '. '
			. 'Return a JSON array of strings in the same order, with no markdown. '
			. 'Preserve HTML tags, shortcodes, placeholders (%s, %d, {name}), brand names, numbers, and line breaks. '
			. 'Keep the tone of website UI copy. If a string is already in the target language, return it unchanged.';
		$payload = array();
		foreach ( $texts as $i => $text ) {
			$payload[] = ( $i + 1 ) . '. ' . $text;
		}

		try {
			$raw  = $this->translate_text( implode( "\n", $payload ), $target_lang, $target_label, $system, $timeout );
			$json = $this->extract_json_array( $raw );
			if ( is_array( $json ) && count( $json ) === count( $texts ) ) {
				$out = array();
				foreach ( $json as $item ) {
					if ( ! is_string( $item ) ) {
						$out = null;
						break;
					}
					$out[] = $item;
				}
				if ( is_array( $out ) ) {
					return $out;
				}
			}
			$error = new RuntimeException( __( '批量翻译返回的条目数或类型与请求不符', 'lingora' ) );
		} catch ( Throwable $e ) {
			$error = $e;
		}

		if ( ! $sequential_fallback ) {
			throw $error instanceof RuntimeException ? $error : new RuntimeException( $error->getMessage() );
		}

		$out = array();
		foreach ( $texts as $text ) {
			$out[] = $this->translate_text( $text, $target_lang, $target_label, '', $timeout );
		}
		return $out;
	}

	/**
	 * @return list<string>
	 * @throws RuntimeException
	 */
	public function list_models(): array {
		$settings = $this->settings();
		$this->require_consent();
		$this->require_credential( $settings );
		$provider = $this->providers->get( (string) $settings['provider_id'] );
		if ( empty( $provider['models_path'] ) ) {
			return $provider['model_options'] ?? array();
		}

		$data    = $this->get_json( $this->provider_url( $settings, (string) $provider['models_path'] ), $settings, self::TIMEOUT_MODELS );
		$source  = array();
		if ( is_array( $data ) ) {
			if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
				$source = $data['data'];
			} elseif ( isset( $data['models'] ) && is_array( $data['models'] ) ) {
				$source = $data['models'];
			}
		}
		$models = array();
		foreach ( $source as $item ) {
			$id = $this->model_id( $item );
			if ( '' !== $id ) {
				$models[ $id ] = $id;
			}
		}
		$models = array_values( $models );
		sort( $models, SORT_NATURAL | SORT_FLAG_CASE );
		if ( ! $models ) {
			throw new RuntimeException( __( '接口没有返回可识别的模型列表，请手动填写模型名', 'lingora' ) );
		}
		return $models;
	}

	public function test_connection(): string {
		$target = lingora()->languages->default_code() === 'zh' ? 'zh' : 'en';
		$label  = 'zh' === $target ? '中文' : 'English';
		return $this->translate_text( 'hello', $target, $label );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$ai = $this->options->ai();
		$ai['provider_id'] = $this->providers->normalize_id( (string) ( $ai['provider_id'] ?? 'deepseek' ) );
		$provider          = $this->providers->get( (string) $ai['provider_id'] );
		// 模型名按存储值原样读取：不做任何全局改写（无 legacy remap）。
		$ai['model'] = (string) ( $ai['model'] ?? '' );
		if ( empty( $provider['configurable_base_url'] ) && ! empty( $provider['base_url'] ) ) {
			$ai['base_url'] = $provider['base_url'];
		}
		return $ai;
	}

	/**
	 * 校验 Base URL（非空时必须 HTTPS、无凭据/查询/锚点、非内网主机）。
	 * 返回错误消息，空字符串表示通过。REST 保存设置与客户端发请求共用。
	 */
	public static function base_url_error( string $url ): string {
		return self::url_error( $url );
	}

	/**
	 * 校验自定义请求头名称：RFC 7230 token，且不得覆盖保留头。
	 * 返回错误消息，空字符串表示通过。
	 */
	public static function header_name_error( string $name ): string {
		$name = trim( $name );
		if ( '' === $name ) {
			return __( '请求头名称不能为空', 'lingora' );
		}
		if ( 1 !== preg_match( "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $name ) ) {
			return __( '请求头名称包含非法字符', 'lingora' );
		}
		if ( in_array( strtolower( $name ), self::RESERVED_HEADERS, true ) ) {
			return __( '不允许覆盖保留请求头（Host、Content-Type 等）', 'lingora' );
		}
		return '';
	}

	/**
	 * 站长未勾选 AI 数据处理同意前，一律拒绝联网。
	 */
	private function require_consent(): void {
		if ( true !== $this->options->get( 'ai_consent', false ) ) {
			throw new RuntimeException( __( '请先在设置页勾选“同意将内容发送给 AI 服务商”，插件才会发起 AI 请求。', 'lingora' ) );
		}
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function uses_project_key( array $settings ): bool {
		$provider = $this->providers->get( (string) $settings['provider_id'] );
		$mode     = ! empty( $provider['configurable_auth'] )
			? ( $settings['auth_mode'] ?? 'apiKey' )
			: ( $provider['preferred_auth_mode'] ?? 'apiKey' );
		return 'projectKey' === $mode;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function require_credential( array $settings ): void {
		foreach ( array( 'api_key', 'project_key' ) as $key ) {
			if ( preg_match( '/[\x00-\x20\x7F]/', (string) ( $settings[ $key ] ?? '' ) ) ) {
				throw new InvalidArgumentException( __( '密钥包含无效空白或控制字符。', 'lingora' ) );
			}
		}
		if ( $this->uses_project_key( $settings ) ) {
			if ( '' === trim( (string) ( $settings['project_key'] ?? '' ) ) ) {
				throw new RuntimeException( __( '请先填写网关 / Project Key', 'lingora' ) );
			}
		} elseif ( '' === trim( (string) ( $settings['api_key'] ?? '' ) ) ) {
			throw new RuntimeException( __( '请先填写 API Key', 'lingora' ) );
		}
		if ( '' === trim( (string) ( $settings['base_url'] ?? '' ) ) ) {
			throw new RuntimeException( __( '请填写 Base URL', 'lingora' ) );
		}
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function require_text_settings( array $settings ): void {
		$this->require_credential( $settings );
		if ( '' === trim( (string) ( $settings['model'] ?? '' ) ) ) {
			throw new RuntimeException( __( '请填写文本模型名，或先读取接口模型列表', 'lingora' ) );
		}
	}

	private function translator_prompt( string $target ): string {
		return 'You are a WordPress website translator. Translate the user\'s message into ' . $target . '. '
			. 'Return only the translation, with no quotes, labels, or explanation. '
			. 'If the text is already in the target language, return it unchanged. '
			. 'Preserve HTML, shortcodes, placeholders, brand names, numbers, and line breaks. '
			. 'Use natural phrasing for website pages and UI copy.';
	}

	/**
	 * @param array<string, mixed> $provider
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private function chat_body( array $provider, array $settings, string $system, string $text ): array {
		$model = trim( (string) $settings['model'] );
		if ( 'anthropic-messages' === $provider['chat_protocol'] ) {
			return array(
				'model'      => $model,
				'max_tokens' => 4096,
				'system'     => $system,
				'messages'   => array( array( 'role' => 'user', 'content' => $text ) ),
				'stream'     => false,
			);
		}
		return array(
			'model'    => $model,
			'messages' => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $text ),
			),
			'stream'   => false,
		);
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function provider_url( array $settings, string $path ): string {
		$provider = $this->providers->get( (string) $settings['provider_id'] );
		$base     = rtrim( trim( (string) $settings['base_url'] ), '/' );
		if ( $path !== (string) $provider['chat_path'] ) {
			$chat_suffix = '/' . trim( (string) $provider['chat_path'], '/' );
			if ( str_ends_with( strtolower( $base ), strtolower( $chat_suffix ) ) ) {
				$base = substr( $base, 0, -strlen( $chat_suffix ) );
			}
		}
		return $this->join_url( $base, $path );
	}

	private function join_url( string $base, string $path ): string {
		$base_parts = explode( '/', rtrim( trim( $base ), '/' ) );
		$path_parts = explode( '/', trim( $path, '/' ) );
		$overlap    = min( count( $base_parts ), count( $path_parts ) );
		while ( $overlap > 0 ) {
			$suffix = strtolower( implode( '/', array_slice( $base_parts, -$overlap ) ) );
			$prefix = strtolower( implode( '/', array_slice( $path_parts, 0, $overlap ) ) );
			if ( $suffix === $prefix ) {
				break;
			}
			--$overlap;
		}
		return implode( '/', array_merge( $base_parts, array_slice( $path_parts, $overlap ) ) );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, string>
	 */
	private function auth_headers( array $settings, bool $for_get = false ): array {
		$provider = $this->providers->get( (string) $settings['provider_id'] );
		$headers  = $for_get ? array() : array( 'Content-Type' => 'application/json' );

		foreach ( array( 'api_key', 'project_key' ) as $key ) {
			if ( preg_match( '/[\x00-\x20\x7F]/', (string) ( $settings[ $key ] ?? '' ) ) ) {
				throw new InvalidArgumentException( __( '密钥包含无效空白或控制字符。', 'lingora' ) );
			}
		}
		if ( $this->uses_project_key( $settings ) ) {
			$token  = trim( (string) $settings['project_key'] );
			$header = trim( (string) ( $settings['project_key_header'] ?: 'Authorization' ) );
			$error  = self::header_name_error( $header );
			if ( '' !== $error ) {
				throw new RuntimeException( $error );
			}
			$scheme = (string) ( $settings['project_key_scheme'] ?? 'bearer' );
			$headers[ $header ] = 'raw' === $scheme ? $token : 'Bearer ' . $token;
		} else {
			$token  = trim( (string) $settings['api_key'] );
			$header = (string) ( $provider['api_key_header'] ?? 'Authorization' );
			$scheme = (string) ( $provider['api_key_scheme'] ?? 'bearer' );
			$headers[ $header ] = 'raw' === $scheme ? $token : 'Bearer ' . $token;
		}

		if ( 'anthropic-messages' === ( $provider['chat_protocol'] ?? '' ) ) {
			$headers['anthropic-version'] = '2023-06-01';
		}

		return $headers;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function post_json( string $url, array $settings, array $body, int $timeout ): array {
		$this->assert_safe_url( $url );
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'              => $timeout,
				'redirection'          => 0,
				'limit_response_size'  => self::MAX_BODY_BYTES + 1,
				'headers'              => $this->auth_headers( $settings ),
				'body'                 => wp_json_encode( $body ),
			)
		);
		return $this->decode_response( $response, $settings );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private function get_json( string $url, array $settings, int $timeout ): array {
		$this->assert_safe_url( $url );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'              => $timeout,
				'redirection'          => 0,
				'limit_response_size'  => self::MAX_BODY_BYTES + 1,
				'headers'              => $this->auth_headers( $settings, true ),
			)
		);
		return $this->decode_response( $response, $settings );
	}

	/**
	 * @throws RuntimeException 地址不安全时。
	 */
	private function assert_safe_url( string $url ): void {
		$error = self::url_error( $url );
		if ( '' !== $error ) {
			throw new RuntimeException( $error );
		}
	}

	/**
	 * 统一 URL 安全校验：HTTPS、无凭据、无查询、无锚点、仅 443 端口、
	 * 主机名合法且不是内网 / 回环 / 保留地址。返回错误消息或空字符串。
	 */
	private static function url_error( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return __( '接口地址不能为空', 'lingora' );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return __( '接口地址格式无效', 'lingora' );
		}
		if ( 'https' !== ( $parts['scheme'] ?? '' ) ) {
			return __( '接口地址必须使用 HTTPS', 'lingora' );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return __( '接口地址不允许携带账号密码', 'lingora' );
		}
		if ( isset( $parts['query'] ) ) {
			return __( '接口地址不允许携带查询参数', 'lingora' );
		}
		if ( isset( $parts['fragment'] ) ) {
			return __( '接口地址不允许携带锚点', 'lingora' );
		}
		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return __( '接口地址仅支持 443 端口', 'lingora' );
		}
		$host = strtolower( trim( (string) ( $parts['host'] ?? '' ) ) );
		if ( '' === $host ) {
			return __( '接口地址缺少主机名', 'lingora' );
		}
		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) ) {
			return __( '接口地址不允许使用内网或本机地址', 'lingora' );
		}
		$is_ip = false !== filter_var( $host, FILTER_VALIDATE_IP );
		if ( $is_ip ) {
			if ( false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return __( '接口地址不允许使用内网或保留地址', 'lingora' );
			}
		} elseif ( false === filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ) {
			return __( '接口地址主机名无效', 'lingora' );
		}
		return '';
	}

	/**
	 * @param array<string, mixed>|WP_Error $response
	 * @param array<string, mixed>          $settings
	 * @return array<string, mixed>
	 */
	private function decode_response( $response, array $settings ): array {
		if ( is_wp_error( $response ) ) {
			/* translators: %s: redacted network error. */
			$message = $this->redact_secrets( sprintf( __( '网络请求失败：%s', 'lingora' ), $response->get_error_message() ), $settings );
			throw new RuntimeException( $message );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		if ( strlen( $raw ) > self::MAX_BODY_BYTES ) {
			throw new RuntimeException( __( '接口响应超过长度限制', 'lingora' ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			if ( str_contains( $type, 'text/html' ) || preg_match( '/^\s*</', $raw ) ) {
				/* translators: %d: HTTP response status code. */
				$message = sprintf( __( 'HTTP %d: API 返回网页而不是 JSON。请检查 Base URL、接口路径和鉴权方式。', 'lingora' ), $code );
				throw new RuntimeException( $message );
			}
			$detail = $this->compact_error_body( $raw );
			$message = $this->redact_secrets( 'HTTP ' . $code . ( $detail ? ': ' . $detail : '' ), $settings );
			throw new RuntimeException( $message );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( __( 'API 返回无法解析的响应，请确认它兼容所选供应商协议。', 'lingora' ) );
		}
		return $data;
	}

	/**
	 * 错误消息里绝不回显密钥本体。
	 *
	 * @param array<string, mixed> $settings
	 */
	private function redact_secrets( string $message, array $settings ): string {
		foreach ( array( 'api_key', 'project_key' ) as $key ) {
			$secret = trim( (string) ( $settings[ $key ] ?? '' ) );
			if ( '' !== $secret && str_contains( $message, $secret ) ) {
				$message = str_replace( $secret, '***', $message );
			}
		}
		return Lingora_Text::substring( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $message ) ) ?? '', 0, 500 );
	}

	private function compact_error_body( string $raw ): string {
		$text = trim( $raw );
		if ( '' === $text ) {
			return '';
		}
		$data = json_decode( $text, true );
		if ( is_array( $data ) ) {
			if ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				return $data['error'];
			}
			if ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
				return $data['error']['message'];
			}
			if ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
				return $data['message'];
			}
			if ( isset( $data['detail'] ) && is_string( $data['detail'] ) ) {
				return $data['detail'];
			}
		}
		// mbstring 在部分主机上缺失，统一走 Lingora_Text 兜底。
		$collapsed = preg_replace( '/\s+/', ' ', $text ) ?? $text;
		return Lingora_Text::substring( $collapsed, 0, 300 );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function translation_from_response( array $data, string $protocol ): string {
		if ( 'anthropic-messages' === $protocol ) {
			return $this->content_text( $data['content'] ?? null );
		}
		$choice = $data['choices'][0]['message']['content'] ?? null;
		$text   = $this->content_text( $choice );
		if ( '' === $text && isset( $data['output_text'] ) && is_string( $data['output_text'] ) ) {
			return trim( $data['output_text'] );
		}
		return $text;
	}

	private function content_text( mixed $value ): string {
		if ( is_string( $value ) ) {
			return trim( $value );
		}
		if ( ! is_array( $value ) ) {
			return '';
		}
		$parts = array();
		foreach ( $value as $part ) {
			if ( is_string( $part ) ) {
				$parts[] = $part;
				continue;
			}
			if ( is_array( $part ) ) {
				if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
					$parts[] = $part['text'];
				} elseif ( isset( $part['content'] ) && is_string( $part['content'] ) ) {
					$parts[] = $part['content'];
				}
			}
		}
		return trim( implode( "\n", array_filter( $parts ) ) );
	}

	private function model_id( mixed $item ): string {
		if ( is_string( $item ) ) {
			return trim( $item );
		}
		if ( ! is_array( $item ) ) {
			return '';
		}
		foreach ( array( 'id', 'slug', 'name' ) as $key ) {
			if ( isset( $item[ $key ] ) && is_string( $item[ $key ] ) ) {
				return trim( preg_replace( '#^models/#', '', $item[ $key ] ) ?? $item[ $key ] );
			}
		}
		return '';
	}

	/**
	 * @return list<mixed>|null
	 */
	private function extract_json_array( string $raw ): ?array {
		$raw = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', trim( $raw ) ) ?? '';
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) && array_values( $decoded ) === $decoded ? $decoded : null;
	}
}

<?php
/**
 * AI HTTP client. Protocol handling follows WAMofa.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Ai_Client {
	private const TIMEOUT_TEXT   = 45;
	private const TIMEOUT_MODELS = 30;

	private Lingora_Options $options;
	private Lingora_Providers $providers;

	public function __construct( Lingora_Options $options, Lingora_Providers $providers ) {
		$this->options   = $options;
		$this->providers = $providers;
	}

	/**
	 * @throws RuntimeException
	 */
	public function translate_text( string $text, string $target_lang, string $target_label = '', string $system = '' ): string {
		$settings = $this->settings();
		$this->require_text_settings( $settings );
		$provider = $this->providers->get( (string) $settings['provider_id'] );
		$label    = $target_label !== '' ? $target_label : $target_lang;
		$prompt   = $system !== '' ? $system : $this->translator_prompt( $label );
		$body     = $this->chat_body( $provider, $settings, $prompt, $text );
		$data     = $this->post_json( $this->provider_url( $settings, (string) $provider['chat_path'] ), $settings, $body, self::TIMEOUT_TEXT );
		$content  = $this->translation_from_response( $data, (string) $provider['chat_protocol'] );
		if ( '' === $content ) {
			throw new RuntimeException( __( '模型没有返回可识别的译文', 'lingora' ) );
		}
		return $content;
	}

	/**
	 * Translate many strings in one call. Falls back to sequential on parse failure.
	 *
	 * @param list<string> $texts
	 * @return list<string>
	 */
	public function translate_batch( array $texts, string $target_lang, string $target_label = '' ): array {
		if ( ! $texts ) {
			return array();
		}
		if ( 1 === count( $texts ) ) {
			return array( $this->translate_text( $texts[0], $target_lang, $target_label ) );
		}

		$label  = $target_label !== '' ? $target_label : $target_lang;
		$system = 'You are a professional website translator. Translate each numbered string into ' . $label . '. '
			. 'Return a JSON array of strings in the same order, with no markdown. '
			. 'Preserve HTML tags, placeholders (%s, %d, {name}), brand names, numbers, and line breaks. '
			. 'If a string is already in the target language, return it unchanged.';
		$payload = array();
		foreach ( $texts as $i => $text ) {
			$payload[] = ( $i + 1 ) . '. ' . $text;
		}

		try {
			$raw  = $this->translate_text( implode( "\n", $payload ), $target_lang, $target_label, $system );
			$json = $this->extract_json_array( $raw );
			if ( is_array( $json ) && count( $json ) === count( $texts ) ) {
				return array_map( 'strval', $json );
			}
		} catch ( Throwable $e ) {
			// Sequential fallback below.
		}

		$out = array();
		foreach ( $texts as $text ) {
			$out[] = $this->translate_text( $text, $target_lang, $target_label );
		}
		return $out;
	}

	/**
	 * @return list<string>
	 * @throws RuntimeException
	 */
	public function list_models(): array {
		$settings = $this->settings();
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
		$settings = $this->settings();
		$target   = lingora()->languages->default_code() === 'zh' ? 'zh' : 'en';
		$label    = 'zh' === $target ? '中文' : 'English';
		return $this->translate_text( 'hello', $target, $label );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$ai = $this->options->ai();
		$ai['provider_id'] = $this->providers->normalize_id( (string) ( $ai['provider_id'] ?? 'deepseek' ) );
		$ai['model']       = $this->providers->normalize_model( (string) $ai['provider_id'], (string) ( $ai['model'] ?? '' ) );
		return $ai;
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
		return 'You are a translator. Translate the user\'s message into ' . $target . '. '
			. 'Return only the translation, with no quotes, labels, or explanation. '
			. 'If the text is already in the target language, return it unchanged. '
			. 'Preserve names, numbers, emojis, HTML, placeholders, and line breaks. '
			. 'Use natural phrasing for website UI while preserving the original meaning.';
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

		if ( $this->uses_project_key( $settings ) ) {
			$token  = trim( (string) $settings['project_key'] );
			$header = trim( (string) ( $settings['project_key_header'] ?: 'Authorization' ) );
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
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => $this->auth_headers( $settings ),
				'body'    => wp_json_encode( $body ),
			)
		);
		return $this->decode_response( $response );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private function get_json( string $url, array $settings, int $timeout ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => $this->auth_headers( $settings, true ),
			)
		);
		return $this->decode_response( $response );
	}

	/**
	 * @param array<string, mixed>|WP_Error $response
	 * @return array<string, mixed>
	 */
	private function decode_response( $response ): array {
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( sprintf( __( '网络请求失败：%s', 'lingora' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		if ( $code < 200 || $code >= 300 ) {
			if ( str_contains( $type, 'text/html' ) || preg_match( '/^\s*</', $raw ) ) {
				throw new RuntimeException(
					sprintf( __( 'HTTP %d: API 返回网页而不是 JSON。请检查 Base URL、接口路径和鉴权方式。', 'lingora' ), $code )
				);
			}
			$detail = $this->compact_error_body( $raw );
			throw new RuntimeException( 'HTTP ' . $code . ( $detail ? ': ' . $detail : '' ) );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( __( 'API 返回无法解析的响应，请确认它兼容所选供应商协议。', 'lingora' ) );
		}
		return $data;
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
		return mb_substr( preg_replace( '/\s+/', ' ', $text ) ?? $text, 0, 300 );
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
		$raw = trim( $raw );
		if ( preg_match( '/\[[\s\S]*\]/', $raw, $match ) ) {
			$decoded = json_decode( $match[0], true );
			return is_array( $decoded ) ? $decoded : null;
		}
		return null;
	}
}

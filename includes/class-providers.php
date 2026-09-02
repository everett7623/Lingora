<?php
/**
 * AI provider presets ported from WAMofa.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Providers {
	/**
	 * @return list<array<string, mixed>>
	 */
	public function all(): array {
		return array(
			array(
				'id'                       => 'openai',
				'name'                     => 'OpenAI',
				'base_url'                 => 'https://api.openai.com',
				'model'                    => 'gpt-5.6-luna',
				'chat_protocol'            => 'openai-chat',
				'chat_path'                => 'v1/chat/completions',
				'models_path'              => 'v1/models',
				'transcribe_model'         => 'gpt-4o-mini-transcribe',
				'transcribe_path'          => 'v1/audio/transcriptions',
				'transcribe_protocol'      => 'openai-transcriptions',
				'transcribe_model_required'=> true,
				'supports_transcribe'      => true,
				'hint'                     => 'OpenAI 官方接口；文本走 Chat Completions。',
				'preferred_auth_mode'      => 'apiKey',
				'model_options'            => array( 'gpt-5.6-luna', 'gpt-5.6-terra', 'gpt-5.6-sol', 'gpt-4.1-mini', 'gpt-4o-mini' ),
				'transcribe_model_options' => array( 'gpt-4o-mini-transcribe', 'gpt-4o-transcribe', 'whisper-1' ),
			),
			array(
				'id'                  => 'claude',
				'name'                => 'Claude',
				'base_url'            => 'https://api.anthropic.com',
				'model'               => 'claude-sonnet-5',
				'chat_protocol'       => 'anthropic-messages',
				'chat_path'           => 'v1/messages',
				'models_path'         => 'v1/models',
				'transcribe_model'    => '',
				'supports_transcribe' => false,
				'hint'                => 'Anthropic 官方 Messages API；使用 x-api-key 和 anthropic-version 请求头。',
				'preferred_auth_mode' => 'apiKey',
				'api_key_header'      => 'x-api-key',
				'api_key_scheme'      => 'raw',
				'model_options'       => array( 'claude-sonnet-5', 'claude-opus-5', 'claude-fable-5', 'claude-haiku-4-5' ),
				'legacy_model_map'    => array(
					'claude-3-5-sonnet-20241022' => 'claude-sonnet-5',
					'claude-3-7-sonnet-latest'   => 'claude-sonnet-5',
				),
			),
			array(
				'id'                  => 'deepseek',
				'name'                => 'DeepSeek',
				'base_url'            => 'https://api.deepseek.com',
				'model'               => 'deepseek-v4-flash',
				'chat_protocol'       => 'openai-chat',
				'chat_path'           => 'chat/completions',
				'models_path'         => 'models',
				'transcribe_model'    => '',
				'supports_transcribe' => false,
				'hint'                => 'DeepSeek OpenAI 兼容接口；V4 Flash 适合高频翻译，V4 Pro 适合更复杂文本。',
				'preferred_auth_mode' => 'apiKey',
				'model_options'       => array( 'deepseek-v4-flash', 'deepseek-v4-pro' ),
				'legacy_model_map'    => array(
					'deepseek-chat'     => 'deepseek-v4-flash',
					'deepseek-reasoner' => 'deepseek-v4-flash',
				),
			),
			array(
				'id'                  => 'gemini',
				'name'                => 'Gemini',
				'base_url'            => 'https://generativelanguage.googleapis.com/v1beta/openai',
				'model'               => 'gemini-3.7-flash',
				'chat_protocol'       => 'openai-chat',
				'chat_path'           => 'chat/completions',
				'models_path'         => 'models',
				'transcribe_model'    => '',
				'supports_transcribe' => false,
				'hint'                => 'Google Gemini 的 OpenAI 兼容接口；使用 Authorization Bearer 鉴权。',
				'preferred_auth_mode' => 'apiKey',
				'model_options'       => array( 'gemini-3.7-flash', 'gemini-3.6-flash', 'gemini-3.5-flash', 'gemini-3.5-flash-lite' ),
				'legacy_model_map'    => array(
					'gemini-2.0-flash-exp' => 'gemini-3.7-flash',
					'gemini-2.0-flash'     => 'gemini-3.7-flash',
				),
			),
			array(
				'id'                       => 'grok',
				'name'                     => 'Grok（xAI）',
				'base_url'                 => 'https://api.x.ai',
				'model'                    => 'grok-4.6',
				'chat_protocol'            => 'openai-chat',
				'chat_path'                => 'v1/chat/completions',
				'models_path'              => 'v1/models',
				'transcribe_model'         => '',
				'transcribe_path'          => 'v1/stt',
				'transcribe_protocol'      => 'xai-stt',
				'transcribe_model_required'=> false,
				'supports_transcribe'      => true,
				'hint'                     => 'xAI 官方接口；支持 Grok 文本模型。',
				'preferred_auth_mode'      => 'apiKey',
				'model_options'            => array( 'grok-4.6', 'grok-4.5', 'grok-4.3' ),
				'legacy_model_map'         => array(
					'grok-beta'     => 'grok-4.6',
					'grok-4-latest' => 'grok-4.6',
				),
			),
			array(
				'id'                  => 'dots',
				'name'                => 'Dots AI',
				'base_url'            => 'https://note3-prev-api.askdiandian.com',
				'model'               => 'dots3-note-prev',
				'chat_protocol'       => 'openai-chat',
				'chat_path'           => 'v1/chat/completions',
				'transcribe_model'    => '',
				'supports_transcribe' => false,
				'hint'                => 'Dots OpenAI 兼容接口；请求 /v1/chat/completions，并通过 api-key 请求头认证。',
				'preferred_auth_mode' => 'apiKey',
				'api_key_header'      => 'api-key',
				'api_key_scheme'      => 'raw',
				'model_options'       => array( 'dots3-note-prev' ),
			),
			array(
				'id'                    => 'sub2api',
				'name'                  => 'Sub2API（自建网关）',
				'base_url'              => '',
				'model'                 => '',
				'chat_protocol'         => 'openai-chat',
				'chat_path'             => 'v1/chat/completions',
				'models_path'           => 'v1/models',
				'transcribe_model'      => '',
				'supports_transcribe'   => false,
				'hint'                  => '填写你的 Sub2API 根地址或 /v1 地址；网关会按 API Key 分组和模型名自动路由。',
				'preferred_auth_mode'   => 'apiKey',
				'configurable_auth'     => true,
				'configurable_base_url' => true,
				'api_key_header'        => 'Authorization',
				'api_key_scheme'        => 'bearer',
				'project_key_header'    => 'Authorization',
				'project_key_scheme'    => 'bearer',
				'model_options'         => array( 'gpt-5.6-luna', 'gpt-5.6-terra', 'gpt-5.6-sol', 'claude-sonnet-5', 'gemini-3.7-flash', 'grok-4.6', 'deepseek-v4-flash' ),
			),
			array(
				'id'                       => 'custom',
				'name'                     => '其他 OpenAI 兼容接口（高级）',
				'base_url'                 => '',
				'model'                    => '',
				'chat_protocol'            => 'openai-chat',
				'chat_path'                => 'chat/completions',
				'models_path'              => 'models',
				'transcribe_model'         => '',
				'transcribe_path'          => 'audio/transcriptions',
				'transcribe_protocol'      => 'openai-transcriptions',
				'transcribe_model_required'=> true,
				'supports_transcribe'      => true,
				'hint'                     => '填写包含版本前缀的 Base URL（通常以 /v1 结尾），也可直接填写完整 chat/completions 地址。',
				'preferred_auth_mode'      => 'apiKey',
				'configurable_auth'        => true,
				'configurable_base_url'    => true,
				'model_options'            => array( 'gpt-5.6-luna', 'claude-sonnet-5', 'gemini-3.7-flash', 'grok-4.6', 'deepseek-v4-flash' ),
				'transcribe_model_options' => array( 'gpt-4o-mini-transcribe', 'gpt-4o-transcribe', 'whisper-1' ),
			),
		);
	}

	public function normalize_id( string $id ): string {
		return in_array( $id, array( 'groq', 'grop' ), true ) ? 'grok' : $id;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get( string $id ): array {
		$id = $this->normalize_id( $id );
		foreach ( $this->all() as $provider ) {
			if ( $provider['id'] === $id ) {
				return $provider;
			}
		}
		return $this->all()[0];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function seed( string $id ): array {
		$preset = $this->get( $id );
		return array(
			'provider_id'        => $preset['id'],
			'auth_mode'          => $preset['preferred_auth_mode'] ?? 'apiKey',
			'project_key_header' => $preset['project_key_header'] ?? 'Authorization',
			'project_key_scheme' => $preset['project_key_scheme'] ?? 'bearer',
			'base_url'           => $preset['base_url'],
			'model'              => $this->normalize_model( $preset['id'], (string) $preset['model'] ),
			'transcribe_model'   => ! empty( $preset['supports_transcribe'] ) ? (string) $preset['transcribe_model'] : '',
		);
	}

	public function normalize_model( string $provider_id, string $model ): string {
		$provider = $this->get( $provider_id );
		$map      = $provider['legacy_model_map'] ?? array();
		return $map[ $model ] ?? $model;
	}

	/**
	 * Public metadata for admin UI. Never includes secrets.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function public_list(): array {
		$out = array();
		foreach ( $this->all() as $provider ) {
			$out[] = array(
				'id'                    => $provider['id'],
				'name'                  => $provider['name'],
				'hint'                  => $provider['hint'],
				'base_url'              => $provider['base_url'],
				'model'                 => $provider['model'],
				'models_path'           => $provider['models_path'] ?? '',
				'configurable_auth'     => ! empty( $provider['configurable_auth'] ),
				'configurable_base_url' => ! empty( $provider['configurable_base_url'] ),
				'supports_transcribe'   => ! empty( $provider['supports_transcribe'] ),
				'model_options'         => $provider['model_options'] ?? array(),
				'transcribe_model_options' => $provider['transcribe_model_options'] ?? array(),
				'transcribe_model_required' => $provider['transcribe_model_required'] ?? true,
				'preferred_auth_mode'   => $provider['preferred_auth_mode'] ?? 'apiKey',
			);
		}
		return $out;
	}
}

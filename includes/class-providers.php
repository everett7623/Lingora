<?php
/**
 * AI provider presets for website translation.
 *
 * Connection layer follows the same providers, auth, and model lists
 * as a standard OpenAI / Anthropic compatible client. Voice / STT is omitted.
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
				'id'                  => 'openai',
				'name'                => 'OpenAI',
				'base_url'            => 'https://api.openai.com',
				'model'               => '',
				'chat_protocol'       => 'openai-chat',
				'chat_path'           => 'v1/chat/completions',
				'models_path'         => 'v1/models',
				'hint'                => __( 'OpenAI 官方接口；文本走 Chat Completions。', 'lingora' ),
				'preferred_auth_mode' => 'apiKey',
				'model_options'       => array(),
				'service_url'         => 'https://openai.com',
				'terms_url'           => 'https://openai.com/policies/business-terms/',
				'privacy_url'         => 'https://openai.com/policies/privacy-policy',
			),
			array(
				'id'                  => 'claude',
				'name'                => 'Claude',
				'base_url'            => 'https://api.anthropic.com',
				'model'               => '',
				'chat_protocol'       => 'anthropic-messages',
				'chat_path'           => 'v1/messages',
				'models_path'         => 'v1/models',
				'hint'                => __( 'Anthropic 官方 Messages API；使用 x-api-key 和 anthropic-version 请求头。', 'lingora' ),
				'preferred_auth_mode' => 'apiKey',
				'api_key_header'      => 'x-api-key',
				'api_key_scheme'      => 'raw',
				'model_options'       => array(),
				'service_url'         => 'https://www.anthropic.com',
				'terms_url'           => 'https://www.anthropic.com/legal/commercial-terms',
				'privacy_url'         => 'https://www.anthropic.com/legal/privacy',
			),
			array(
				'id'                  => 'deepseek',
				'name'                => 'DeepSeek',
				'base_url'            => 'https://api.deepseek.com',
				'model'               => '',
				'chat_protocol'       => 'openai-chat',
				'chat_path'           => 'chat/completions',
				'models_path'         => 'models',
				'hint'                => __( 'DeepSeek OpenAI 兼容接口；请读取账户可用模型或手动填写模型 ID。', 'lingora' ),
				'preferred_auth_mode' => 'apiKey',
				'model_options'       => array(),
				'service_url'         => 'https://www.deepseek.com',
				'terms_url'           => 'https://cdn.deepseek.com/policies/zh-CN/deepseek-terms-of-use.html',
				'privacy_url'         => 'https://cdn.deepseek.com/policies/zh-CN/deepseek-privacy-policy.html',
			),
			array(
				'id'                  => 'gemini',
				'name'                => 'Gemini',
				'base_url'            => 'https://generativelanguage.googleapis.com/v1beta/openai',
				'model'               => '',
				'chat_protocol'       => 'openai-chat',
				'chat_path'           => 'chat/completions',
				'models_path'         => 'models',
				'hint'                => __( 'Google Gemini 的 OpenAI 兼容接口；使用 Authorization Bearer 鉴权。', 'lingora' ),
				'preferred_auth_mode' => 'apiKey',
				'model_options'       => array(),
				'service_url'         => 'https://ai.google.dev',
				'terms_url'           => 'https://ai.google.dev/gemini-api/terms',
				'privacy_url'         => 'https://policies.google.com/privacy',
			),
			array(
				'id'                  => 'grok',
				'name'                => 'Grok（xAI）',
				'base_url'            => 'https://api.x.ai',
				'model'               => '',
				'chat_protocol'       => 'openai-chat',
				'chat_path'           => 'v1/chat/completions',
				'models_path'         => 'v1/models',
				'hint'                => __( 'xAI 官方接口；支持 Grok 文本模型。', 'lingora' ),
				'preferred_auth_mode' => 'apiKey',
				'model_options'       => array(),
				'service_url'         => 'https://x.ai',
				'terms_url'           => 'https://x.ai/legal/terms-of-service-enterprise',
				'privacy_url'         => 'https://x.ai/legal/privacy-policy',
			),
			array(
				'id'                  => 'dots',
				'name'                => 'Dots AI',
				'base_url'            => 'https://note3-prev-api.askdiandian.com',
				'model'               => '',
				'chat_protocol'       => 'openai-chat',
				'chat_path'           => 'v1/chat/completions',
				'hint'                => __( 'Dots OpenAI 兼容接口；请求 /v1/chat/completions，并通过 api-key 请求头认证。服务商官网与条款地址未能核实，均标记为未知。', 'lingora' ),
				'preferred_auth_mode' => 'apiKey',
				'api_key_header'      => 'api-key',
				'api_key_scheme'      => 'raw',
				'model_options'       => array(),
				// 无法核实：显式 unknown（null），绝不编造链接。
				'service_url'         => null,
				'terms_url'           => null,
				'privacy_url'         => null,
			),
			array(
				'id'                    => 'sub2api',
				'name'                  => 'Sub2API（自建网关）',
				'base_url'              => '',
				'model'                 => '',
				'chat_protocol'         => 'openai-chat',
				'chat_path'             => 'v1/chat/completions',
				'models_path'           => 'v1/models',
				'hint'                  => __( '填写你的 Sub2API 根地址或 /v1 地址；网关会按 API Key 分组和模型名自动路由。', 'lingora' ),
				'preferred_auth_mode'   => 'apiKey',
				'configurable_auth'     => true,
				'configurable_base_url' => true,
				'api_key_header'        => 'Authorization',
				'api_key_scheme'        => 'bearer',
				'project_key_header'    => 'Authorization',
				'project_key_scheme'    => 'bearer',
				'model_options'         => array(),
				// 自建网关：地址由站长自行掌握。
				'service_url'           => null,
				'terms_url'             => null,
				'privacy_url'           => null,
			),
			array(
				'id'                    => 'custom',
				'name'                  => '其他 OpenAI 兼容接口（高级）',
				'base_url'              => '',
				'model'                 => '',
				'chat_protocol'         => 'openai-chat',
				'chat_path'             => 'chat/completions',
				'models_path'           => 'models',
				'hint'                  => __( '填写包含版本前缀的 Base URL（通常以 /v1 结尾），也可直接填写完整 chat/completions 地址。', 'lingora' ),
				'preferred_auth_mode'   => 'apiKey',
				'configurable_auth'     => true,
				'configurable_base_url' => true,
				'model_options'         => array(),
				'service_url'           => null,
				'terms_url'             => null,
				'privacy_url'           => null,
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
	 * 严格校验用：ID 是否真实存在（get() 对未知 ID 会静默回退到第一个预设）。
	 */
	public function exists( string $id ): bool {
		$id = $this->normalize_id( $id );
		foreach ( $this->all() as $provider ) {
			if ( $provider['id'] === $id ) {
				return true;
			}
		}
		return false;
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
			// 只作为新建连接的建议值；不做任何 legacy model 改写。
			'model'              => (string) $preset['model'],
		);
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
				'model_options'         => $provider['model_options'] ?? array(),
				'preferred_auth_mode'   => $provider['preferred_auth_mode'] ?? 'apiKey',
				// 服务商官网 / 条款 / 隐私页；无法核实的供应商为 null（未知），前端需按“未知”呈现。
				'service_url'           => $provider['service_url'] ?? null,
				'terms_url'             => $provider['terms_url'] ?? null,
				'privacy_url'           => $provider['privacy_url'] ?? null,
			);
		}
		return $out;
	}
}

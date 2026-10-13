<?php
/**
 * AI connection for website translation.
 *
 * @package Lingora
 *
 * @var array $settings
 * @var array $providers
 * @var bool  $connected
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ai = $settings['ai'];
?>
<header class="lingora-hero">
	<div>
		<span class="lingora-eyebrow"><?php esc_html_e( '接口', 'lingora' ); ?></span>
		<h1><?php esc_html_e( 'AI 接口', 'lingora' ); ?></h1>
		<p><?php esc_html_e( '配置网站页面翻译所使用的服务商。', 'lingora' ); ?></p>
	</div>
</header>

<section class="lingora-panel" id="lingora-connection">
	<label class="lingora-field">
		<span><?php esc_html_e( '服务商', 'lingora' ); ?></span>
		<select id="lingora-provider" class="lingora-input">
			<?php foreach ( $providers as $provider ) : ?>
				<option value="<?php echo esc_attr( $provider['id'] ); ?>" <?php selected( $ai['provider_id'], $provider['id'] ); ?>>
					<?php echo esc_html( $provider['name'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</label>
	<p class="lingora-hint" id="lingora-provider-hint"></p>
	<p class="lingora-hint" id="lingora-provider-extra" hidden></p>

	<label class="lingora-field" id="lingora-auth-wrap" hidden>
		<span><?php esc_html_e( '鉴权方式', 'lingora' ); ?></span>
		<select id="lingora-auth-mode" class="lingora-input">
			<option value="apiKey" <?php selected( $ai['auth_mode'], 'apiKey' ); ?>><?php esc_html_e( '标准 API Key（使用预设请求头）', 'lingora' ); ?></option>
			<option value="projectKey" <?php selected( $ai['auth_mode'], 'projectKey' ); ?>><?php esc_html_e( '自定义请求头 / Project Key', 'lingora' ); ?></option>
		</select>
	</label>

	<div class="lingora-note" id="lingora-project-help" hidden>
		<strong><?php esc_html_e( '网关 / Project Key 模式', 'lingora' ); ?></strong>
		<p><?php esc_html_e( '适用于 API 中转服务。通常配置：', 'lingora' ); ?></p>
		<ul>
			<li>Header: <code>Authorization</code></li>
			<li><?php esc_html_e( '格式：', 'lingora' ); ?><code>Bearer &lt;ProjectKey&gt;</code></li>
			<li>Base URL: <?php esc_html_e( '填写中转服务地址（如', 'lingora' ); ?> <code>https://api.your-domain.com/v1</code>）</li>
		</ul>
	</div>

	<div class="lingora-grid lingora-grid--2" id="lingora-project-wrap" hidden>
		<label class="lingora-field">
			<span><?php esc_html_e( 'Header 名称', 'lingora' ); ?></span>
			<input id="lingora-project-header" class="lingora-input" type="text" value="<?php echo esc_attr( $ai['project_key_header'] ); ?>">
		</label>
		<label class="lingora-field">
			<span><?php esc_html_e( '格式', 'lingora' ); ?></span>
			<select id="lingora-project-scheme" class="lingora-input">
				<option value="bearer" <?php selected( $ai['project_key_scheme'], 'bearer' ); ?>>Bearer &lt;ProjectKey&gt;</option>
				<option value="raw" <?php selected( $ai['project_key_scheme'], 'raw' ); ?>><?php esc_html_e( '直接传入（原始值）', 'lingora' ); ?></option>
			</select>
		</label>
	</div>

	<div class="lingora-field">
		<span id="lingora-secret-label"><?php esc_html_e( 'API Key', 'lingora' ); ?></span>
		<span class="lingora-secret">
			<input id="lingora-api-key" class="lingora-input" type="password" autocomplete="off" aria-labelledby="lingora-secret-label" value="<?php echo esc_attr( $ai['api_key'] ? '••••••••' . substr( (string) $ai['api_key'], -4 ) : '' ); ?>">
			<input id="lingora-project-key" class="lingora-input" type="password" autocomplete="off" aria-labelledby="lingora-secret-label" value="<?php echo esc_attr( $ai['project_key'] ? '••••••••' . substr( (string) $ai['project_key'], -4 ) : '' ); ?>" hidden>
			<button type="button" class="lingora-btn lingora-btn--ghost" id="lingora-reveal" data-reveal="0" aria-pressed="false"><?php esc_html_e( '显示', 'lingora' ); ?></button>
		</span>
	</div>
	<p class="lingora-hint" id="lingora-key-hint" hidden></p>

	<label class="lingora-field" id="lingora-base-wrap" hidden>
		<span>Base URL</span>
		<input id="lingora-base-url" class="lingora-input" type="url" value="<?php echo esc_attr( $ai['base_url'] ); ?>">
	</label>
	<p class="lingora-hint" id="lingora-base-hint"></p>

	<div class="lingora-field">
		<span id="lingora-model-label"><?php esc_html_e( '文本模型', 'lingora' ); ?></span>
		<select id="lingora-model" class="lingora-input" aria-labelledby="lingora-model-label"></select>
		<input id="lingora-model-manual" class="lingora-input" type="text" aria-labelledby="lingora-model-label" value="<?php echo esc_attr( $ai['model'] ); ?>" hidden>
		<div class="lingora-actions" style="margin-top:8px">
			<button type="button" class="lingora-btn lingora-btn--ghost" id="lingora-toggle-model"><?php esc_html_e( '手动填写其他模型', 'lingora' ); ?></button>
			<button type="button" class="lingora-btn lingora-btn--ghost" id="lingora-load-models"><?php esc_html_e( '读取接口模型', 'lingora' ); ?></button>
		</div>
	</div>

	<div class="lingora-note" id="lingora-ai-disclosure">
		<strong><?php esc_html_e( '外部 AI 服务与费用', 'lingora' ); ?></strong>
		<p><?php esc_html_e( '同意后，测试连接会把测试文本 hello 发送到所选服务；读取模型会向该服务发送认证请求。保存同意后，手动 AI 翻译、补齐空缺、队列，以及启用的自动翻译会发送公开网站原文、目标语言和翻译指令。密钥会随请求发送给所配置的服务用于认证。', 'lingora' ); ?></p>
		<p><?php esc_html_e( 'AI 服务通常需要另行注册账户、开通 API 并付费，费用与数据处理规则由服务商决定。使用网关或自定义地址时，网关及其上游服务也可能接收这些内容；请先向运营方确认隐私政策和服务条款。', 'lingora' ); ?></p>
		<p id="lingora-provider-links"></p>
		<p id="lingora-provider-link-hint"></p>
	</div>
	<label class="lingora-check">
		<input type="checkbox" id="lingora-ai-consent" aria-describedby="lingora-ai-disclosure" <?php checked( ! empty( $settings['ai_consent'] ) ); ?>>
		<span><?php esc_html_e( '我已了解上述发送内容、时机和费用，同意连接所选外部 AI 服务。', 'lingora' ); ?></span>
	</label>

	<div class="lingora-actions">
		<button type="button" class="lingora-btn lingora-btn--primary" id="lingora-test" <?php disabled( empty( $settings['ai_consent'] ) ); ?>><?php esc_html_e( '测试翻译 hello', 'lingora' ); ?></button>
		<button type="button" class="lingora-btn" id="lingora-save-ai"><?php esc_html_e( '保存', 'lingora' ); ?></button>
	</div>
</section>

<script type="application/json" id="lingora-providers-json"><?php echo wp_json_encode( $providers ); ?></script>
<script type="application/json" id="lingora-ai-json"><?php echo wp_json_encode( array( 'provider_id' => $ai['provider_id'], 'auth_mode' => $ai['auth_mode'], 'base_url' => $ai['base_url'], 'model' => $ai['model'], 'has_key' => $connected ) ); ?></script>

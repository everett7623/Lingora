<?php
/**
 * AI connection. Field model follows WAMofa options.
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
		<span class="lingora-eyebrow">Connection</span>
		<h1><?php esc_html_e( 'AI 接口', 'lingora' ); ?></h1>
		<p><?php esc_html_e( '配置翻译所使用的服务商。预设与鉴权方式对齐 WAMofa。', 'lingora' ); ?></p>
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

	<label class="lingora-field" id="lingora-auth-wrap" hidden>
		<span><?php esc_html_e( '鉴权方式', 'lingora' ); ?></span>
		<select id="lingora-auth-mode" class="lingora-input">
			<option value="apiKey" <?php selected( $ai['auth_mode'], 'apiKey' ); ?>><?php esc_html_e( '标准 API Key（使用预设请求头）', 'lingora' ); ?></option>
			<option value="projectKey" <?php selected( $ai['auth_mode'], 'projectKey' ); ?>><?php esc_html_e( '自定义请求头 / Project Key', 'lingora' ); ?></option>
		</select>
	</label>

	<div class="lingora-grid lingora-grid--2" id="lingora-project-wrap" hidden>
		<label class="lingora-field">
			<span><?php esc_html_e( 'Header 名称', 'lingora' ); ?></span>
			<input id="lingora-project-header" class="lingora-input" type="text" value="<?php echo esc_attr( $ai['project_key_header'] ); ?>">
		</label>
		<label class="lingora-field">
			<span><?php esc_html_e( '格式', 'lingora' ); ?></span>
			<select id="lingora-project-scheme" class="lingora-input">
				<option value="bearer" <?php selected( $ai['project_key_scheme'], 'bearer' ); ?>>Bearer</option>
				<option value="raw" <?php selected( $ai['project_key_scheme'], 'raw' ); ?>><?php esc_html_e( '直接传入（原始值）', 'lingora' ); ?></option>
			</select>
		</label>
	</div>

	<label class="lingora-field">
		<span id="lingora-secret-label"><?php esc_html_e( 'API Key', 'lingora' ); ?></span>
		<span class="lingora-secret">
			<input id="lingora-api-key" class="lingora-input" type="password" autocomplete="off" value="<?php echo esc_attr( $ai['api_key'] ? '••••••••' . substr( (string) $ai['api_key'], -4 ) : '' ); ?>">
			<input id="lingora-project-key" class="lingora-input" type="password" autocomplete="off" value="<?php echo esc_attr( $ai['project_key'] ? '••••••••' . substr( (string) $ai['project_key'], -4 ) : '' ); ?>" hidden>
			<button type="button" class="lingora-btn lingora-btn--ghost" id="lingora-reveal" data-reveal="0"><?php esc_html_e( '显示', 'lingora' ); ?></button>
		</span>
	</label>

	<label class="lingora-field" id="lingora-base-wrap">
		<span>Base URL</span>
		<input id="lingora-base-url" class="lingora-input" type="url" value="<?php echo esc_attr( $ai['base_url'] ); ?>">
	</label>
	<p class="lingora-hint" id="lingora-base-hint"></p>

	<div class="lingora-grid lingora-grid--2">
		<div class="lingora-field">
			<span><?php esc_html_e( '文本模型', 'lingora' ); ?></span>
			<select id="lingora-model" class="lingora-input"></select>
			<input id="lingora-model-manual" class="lingora-input" type="text" value="<?php echo esc_attr( $ai['model'] ); ?>" hidden>
			<button type="button" class="lingora-btn lingora-btn--ghost" id="lingora-toggle-model"><?php esc_html_e( '手动填写其他模型', 'lingora' ); ?></button>
			<button type="button" class="lingora-btn lingora-btn--ghost" id="lingora-load-models"><?php esc_html_e( '读取接口模型', 'lingora' ); ?></button>
		</div>
		<div class="lingora-field">
			<span><?php esc_html_e( '转写模型', 'lingora' ); ?></span>
			<select id="lingora-transcribe" class="lingora-input"></select>
			<p class="lingora-hint"><?php esc_html_e( '站点翻译只用文本模型。转写选项保留以便与 WAMofa 预设一致。', 'lingora' ); ?></p>
		</div>
	</div>

	<div class="lingora-actions">
		<button type="button" class="lingora-btn lingora-btn--primary" id="lingora-test"><?php esc_html_e( '测试翻译 hello', 'lingora' ); ?></button>
		<button type="button" class="lingora-btn" id="lingora-save-ai"><?php esc_html_e( '保存', 'lingora' ); ?></button>
	</div>
</section>

<script type="application/json" id="lingora-providers-json"><?php echo wp_json_encode( $providers ); ?></script>
<script type="application/json" id="lingora-ai-json"><?php echo wp_json_encode( array( 'provider_id' => $ai['provider_id'], 'auth_mode' => $ai['auth_mode'], 'base_url' => $ai['base_url'], 'model' => $ai['model'], 'transcribe_model' => $ai['transcribe_model'], 'has_key' => $connected ) ); ?></script>

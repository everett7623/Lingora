<?php
/**
 * Language manager.
 *
 * @package Lingora
 *
 * @var array $languages
 * @var array $catalog
 * @var array $settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pair         = Lingora_Languages::example_pair( $languages );
$default_lang = $pair['default'];
$target_lang  = $pair['target'];
$example_code = sanitize_title( (string) ( $target_lang['code'] ?? 'en' ) ) ?: 'en';
$default_name = Lingora_Languages::admin_label(
	(string) ( $default_lang['code'] ?? '' ),
	(string) ( $default_lang['native'] ?? $default_lang['name'] ?? '' )
);
$target_name  = Lingora_Languages::admin_label(
	$example_code,
	(string) ( $target_lang['native'] ?? $target_lang['name'] ?? $example_code )
);
$dir_example  = 'https://example.com/' . $example_code . '/about/';
$hide_label   = sprintf(
	/* translators: 1: default language name, 2: target language name, 3: target language code */
	__( '隐藏默认语言前缀（%1$s首页是 /，%2$s是 /%3$s/）', 'lingora' ),
	$default_name,
	$target_name,
	$example_code
);
$hero_example = sprintf(
	/* translators: %s: language directory prefix example */
	__( '默认语言不复制文章。其它语言走目录前缀，例如 https://example.com/%s/ 。', 'lingora' ),
	$example_code
);
?>
<header class="lingora-hero">
	<div>
		<span class="lingora-eyebrow"><?php esc_html_e( '语言', 'lingora' ); ?></span>
		<h1><?php esc_html_e( '网站语言', 'lingora' ); ?></h1>
		<p id="lingora-lang-hero"><?php echo esc_html( $hero_example ); ?></p>
	</div>
</header>

<section class="lingora-panel">
	<div class="lingora-toolbar">
		<label class="lingora-field lingora-field--inline">
			<span><?php esc_html_e( '添加语言', 'lingora' ); ?></span>
			<select id="lingora-add-language" class="lingora-input">
				<option value=""><?php esc_html_e( '从目录选择…', 'lingora' ); ?></option>
				<?php foreach ( $catalog as $item ) : ?>
					<option value="<?php echo esc_attr( $item['code'] ); ?>"><?php echo esc_html( $item['native'] . ' — ' . $item['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<button type="button" class="lingora-btn lingora-btn--primary" id="lingora-add-lang-btn"><?php esc_html_e( '添加', 'lingora' ); ?></button>
	</div>
	<table class="lingora-table" id="lingora-lang-table">
		<thead>
			<tr>
				<th><?php esc_html_e( '语言', 'lingora' ); ?></th>
				<th><?php esc_html_e( '代码', 'lingora' ); ?></th>
				<th><?php esc_html_e( '默认', 'lingora' ); ?></th>
				<th><?php esc_html_e( '启用', 'lingora' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody></tbody>
	</table>
	<script type="application/json" id="lingora-languages-json"><?php echo wp_json_encode( $languages ); ?></script>
	<script type="application/json" id="lingora-catalog-json"><?php echo wp_json_encode( $catalog ); ?></script>
	<div class="lingora-actions">
		<button type="button" class="lingora-btn lingora-btn--primary" id="lingora-save-languages"><?php esc_html_e( '保存语言', 'lingora' ); ?></button>
	</div>
</section>

<section class="lingora-panel">
	<div class="lingora-panel__head">
		<div>
			<span class="lingora-eyebrow"><?php esc_html_e( '地址', 'lingora' ); ?></span>
			<h2><?php esc_html_e( '地址结构', 'lingora' ); ?></h2>
		</div>
	</div>
	<label class="lingora-field">
		<span><?php esc_html_e( '模式', 'lingora' ); ?></span>
		<select id="lingora-url-mode" class="lingora-input">
			<option value="directory" <?php selected( $settings['url_mode'], 'directory' ); ?>><?php echo esc_html( sprintf( /* translators: %s: example URL */ __( '目录前缀 %s', 'lingora' ), $dir_example ) ); ?></option>
			<option value="query" <?php selected( $settings['url_mode'], 'query' ); ?>><?php echo esc_html( sprintf( /* translators: %s: language code */ __( '查询参数 ?lang=%s', 'lingora' ), $example_code ) ); ?></option>
		</select>
	</label>
	<label class="lingora-check">
		<input type="checkbox" id="lingora-hide-default" <?php checked( $settings['hide_default'] ); ?>>
		<span id="lingora-hide-default-label"><?php echo esc_html( $hide_label ); ?></span>
	</label>
	<div class="lingora-actions">
		<button type="button" class="lingora-btn" id="lingora-save-url"><?php esc_html_e( '保存 URL 设置', 'lingora' ); ?></button>
	</div>
</section>

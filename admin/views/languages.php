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
?>
<header class="lingora-hero">
	<div>
		<span class="lingora-eyebrow">Languages</span>
		<h1><?php esc_html_e( '网站语言', 'lingora' ); ?></h1>
		<p><?php esc_html_e( '默认语言不复制文章。其它语言走目录前缀，例如 https://example.com/de/ 。', 'lingora' ); ?></p>
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
			<span class="lingora-eyebrow">URL</span>
			<h2><?php esc_html_e( '地址结构', 'lingora' ); ?></h2>
		</div>
	</div>
	<label class="lingora-field">
		<span><?php esc_html_e( '模式', 'lingora' ); ?></span>
		<select id="lingora-url-mode" class="lingora-input">
			<option value="directory" <?php selected( $settings['url_mode'], 'directory' ); ?>><?php esc_html_e( '目录前缀 https://example.com/de/about/', 'lingora' ); ?></option>
			<option value="query" <?php selected( $settings['url_mode'], 'query' ); ?>><?php esc_html_e( '查询参数 ?lang=zh', 'lingora' ); ?></option>
		</select>
	</label>
	<label class="lingora-check">
		<input type="checkbox" id="lingora-hide-default" <?php checked( $settings['hide_default'] ); ?>>
		<span><?php esc_html_e( '隐藏默认语言前缀（英文站首页是 / ，德语是 /de/）', 'lingora' ); ?></span>
	</label>
	<div class="lingora-actions">
		<button type="button" class="lingora-btn" id="lingora-save-url"><?php esc_html_e( '保存 URL 设置', 'lingora' ); ?></button>
	</div>
</section>

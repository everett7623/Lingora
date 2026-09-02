<?php
/**
 * Translation dictionary.
 *
 * @package Lingora
 *
 * @var array $languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$targets = array_values( array_filter( $languages, static fn( $lang ) => empty( $lang['is_default'] ) && ! empty( $lang['enabled'] ) ) );
?>
<header class="lingora-hero">
	<div>
		<span class="lingora-eyebrow">Dictionary</span>
		<h1><?php esc_html_e( '字符串', 'lingora' ); ?></h1>
		<p><?php esc_html_e( '前台扫到的原文会落在这里。可以人工改译，或让 AI 补齐空缺。', 'lingora' ); ?></p>
	</div>
</header>

<section class="lingora-panel">
	<div class="lingora-toolbar">
		<input id="lingora-string-search" class="lingora-input" type="search" placeholder="<?php esc_attr_e( '搜索原文…', 'lingora' ); ?>">
		<select id="lingora-string-lang" class="lingora-input">
			<?php foreach ( $targets as $lang ) : ?>
				<option value="<?php echo esc_attr( $lang['code'] ); ?>"><?php echo esc_html( $lang['native'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="button" class="lingora-btn" id="lingora-string-reload"><?php esc_html_e( '刷新', 'lingora' ); ?></button>
	</div>
	<table class="lingora-table" id="lingora-string-table">
		<thead>
			<tr>
				<th><?php esc_html_e( '原文', 'lingora' ); ?></th>
				<th><?php esc_html_e( '译文', 'lingora' ); ?></th>
				<th><?php esc_html_e( '状态', 'lingora' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<tr><td colspan="4"><?php esc_html_e( '加载中…', 'lingora' ); ?></td></tr>
		</tbody>
	</table>
	<div class="lingora-pager">
		<button type="button" class="lingora-btn lingora-btn--ghost" id="lingora-prev"><?php esc_html_e( '上一页', 'lingora' ); ?></button>
		<span id="lingora-page-label">1</span>
		<button type="button" class="lingora-btn lingora-btn--ghost" id="lingora-next"><?php esc_html_e( '下一页', 'lingora' ); ?></button>
	</div>
</section>

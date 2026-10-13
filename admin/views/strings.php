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
		<span class="lingora-eyebrow"><?php esc_html_e( '字符串', 'lingora' ); ?></span>
		<h1><?php esc_html_e( '字符串', 'lingora' ); ?></h1>
		<p><?php esc_html_e( '这里是前台扫到的原文。先选一种目标语言，再改对应译文；空着的条目可以用 AI 一键补齐。带「别名/URL」标记的是文章地址，不是页面上的句子。', 'lingora' ); ?></p>
	</div>
</header>

<section class="lingora-panel">
	<?php if ( ! $targets ) : ?>
		<div class="lingora-empty">
			<p><?php esc_html_e( '还没有目标语言。请先到「语言」页添加并启用一种非默认语言。', 'lingora' ); ?></p>
			<a class="lingora-btn lingora-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=lingora-languages' ) ); ?>"><?php esc_html_e( '前往语言', 'lingora' ); ?></a>
		</div>
	<?php else : ?>
		<div class="lingora-toolbar lingora-toolbar--strings">
			<label class="lingora-field lingora-field--inline lingora-field--grow">
				<span><?php esc_html_e( '搜索', 'lingora' ); ?></span>
				<input id="lingora-string-search" class="lingora-input" type="search" placeholder="<?php esc_attr_e( '搜索原文或译文…', 'lingora' ); ?>">
			</label>
			<label class="lingora-field lingora-field--inline">
				<span><?php esc_html_e( '目标语言', 'lingora' ); ?></span>
				<select id="lingora-string-lang" class="lingora-input">
					<?php foreach ( $targets as $lang ) : ?>
						<option value="<?php echo esc_attr( $lang['code'] ); ?>">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: language native name, 2: language code */
									__( '%1$s（%2$s）', 'lingora' ),
									(string) ( $lang['native'] ?? $lang['name'] ?? $lang['code'] ),
									(string) $lang['code']
								)
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="lingora-field lingora-field--inline">
				<span><?php esc_html_e( '显示范围', 'lingora' ); ?></span>
				<select id="lingora-string-kind" class="lingora-input">
					<option value=""><?php esc_html_e( '含 URL 别名', 'lingora' ); ?></option>
					<option value="text"><?php esc_html_e( '仅正文', 'lingora' ); ?></option>
					<option value="slug"><?php esc_html_e( '仅 URL 别名', 'lingora' ); ?></option>
				</select>
			</label>
			<button type="button" class="lingora-btn" id="lingora-string-reload"><?php esc_html_e( '刷新', 'lingora' ); ?></button>
			<button type="button" class="lingora-btn lingora-btn--primary" id="lingora-translate-missing"><?php esc_html_e( '用 AI 补齐本语言空缺', 'lingora' ); ?></button>
		</div>
			<p class="lingora-hint" id="lingora-string-lang-hint"><?php esc_html_e( '下拉框是正在编辑的目标语言，不是网站的默认语言。补齐空缺会加入后台队列，完成后才会出现译文。', 'lingora' ); ?></p>
			<div class="lingora-jobs" id="lingora-jobs" aria-label="<?php esc_attr_e( '后台任务进度', 'lingora' ); ?>" role="region">
				<p data-job-status="scan" role="status" aria-live="polite" aria-atomic="true"><?php esc_html_e( '正在读取扫描状态…', 'lingora' ); ?></p>
				<progress data-job-progress="scan" max="100" aria-label="<?php esc_attr_e( '扫描进度', 'lingora' ); ?>" hidden></progress>
				<p data-job-status="queue" role="status" aria-live="polite" aria-atomic="true"><?php esc_html_e( '正在读取队列状态…', 'lingora' ); ?></p>
				<progress data-job-progress="queue" max="100" aria-label="<?php esc_attr_e( '翻译队列进度', 'lingora' ); ?>" hidden></progress>
				<p class="lingora-hint" data-job-refresh-note hidden></p>
			</div>
		<div class="lingora-filters" role="group" aria-label="<?php esc_attr_e( '翻译状态', 'lingora' ); ?>">
			<button type="button" class="lingora-chip is-active" data-status="" aria-pressed="true"><?php esc_html_e( '全部', 'lingora' ); ?></button>
			<button type="button" class="lingora-chip" data-status="missing" aria-pressed="false"><?php esc_html_e( '未翻译', 'lingora' ); ?></button>
			<button type="button" class="lingora-chip" data-status="auto" aria-pressed="false"><?php esc_html_e( '已自动翻译', 'lingora' ); ?></button>
			<button type="button" class="lingora-chip" data-status="human" aria-pressed="false"><?php esc_html_e( '已人工修改', 'lingora' ); ?></button>
		</div>
		<table class="lingora-table lingora-table--strings" id="lingora-string-table">
			<thead>
				<tr>
					<th><?php esc_html_e( '原文', 'lingora' ); ?></th>
					<th><?php esc_html_e( '译文', 'lingora' ); ?></th>
					<th><?php esc_html_e( '状态', 'lingora' ); ?></th>
					<th><?php esc_html_e( '操作', 'lingora' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td colspan="4"><?php esc_html_e( '加载中…', 'lingora' ); ?></td></tr>
			</tbody>
		</table>
		<div class="lingora-pager">
			<button type="button" class="lingora-btn lingora-btn--ghost" id="lingora-prev"><?php esc_html_e( '上一页', 'lingora' ); ?></button>
			<span class="lingora-pager__label" id="lingora-page-label"><?php esc_html_e( '第 1 页', 'lingora' ); ?></span>
			<button type="button" class="lingora-btn lingora-btn--ghost" id="lingora-next"><?php esc_html_e( '下一页', 'lingora' ); ?></button>
		</div>
	<?php endif; ?>
</section>

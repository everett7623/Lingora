<?php
/**
 * Plugin settings.
 *
 * @package Lingora
 *
 * @var array $settings
 * @var array $languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$example_code = (string) ( Lingora_Languages::example_pair( $languages ?? array() )['target']['code'] ?? 'en' );
?>
<header class="lingora-hero">
	<div>
		<span class="lingora-eyebrow"><?php esc_html_e( '设置', 'lingora' ); ?></span>
		<h1><?php esc_html_e( '站点设置', 'lingora' ); ?></h1>
		<p><?php esc_html_e( '控制自动翻译、语言切换器、URL 和搜索引擎标记。', 'lingora' ); ?></p>
	</div>
</header>

<section class="lingora-panel">
	<div class="lingora-panel__head">
		<div>
			<span class="lingora-eyebrow"><?php esc_html_e( '翻译', 'lingora' ); ?></span>
			<h2><?php esc_html_e( '翻译行为', 'lingora' ); ?></h2>
		</div>
	</div>
	<p class="lingora-hint"><?php esc_html_e( '自动翻译需要先在「AI 接口」页明确同意连接外部服务。扫描采集的公开文案进入队列后，才由后台任务发送并翻译；未同意时不会发送。', 'lingora' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=lingora-connection' ) ); ?>"><?php esc_html_e( '查看发送内容、费用与授权设置', 'lingora' ); ?></a></p>
	<label class="lingora-check">
		<input type="checkbox" id="opt-auto-translate" <?php checked( $settings['auto_translate'] ); ?>>
		<span><?php esc_html_e( '自动翻译缺失字符串', 'lingora' ); ?></span>
	</label>
	<label class="lingora-check">
		<input type="checkbox" id="opt-on-fly" <?php checked( $settings['translate_on_fly'] ); ?>>
		<span><?php esc_html_e( '首次访问时即时翻译少量句子', 'lingora' ); ?></span>
	</label>
	<label class="lingora-check">
		<input type="checkbox" id="opt-scan-published" <?php checked( $settings['scan_published'] ); ?>>
		<span><?php esc_html_e( '每天扫描已发布文章/页面/菜单，把未译字符串放入队列', 'lingora' ); ?></span>
	</label>
	<label class="lingora-field">
		<span><?php esc_html_e( '即时翻译条数上限', 'lingora' ); ?></span>
		<input id="opt-on-fly-limit" class="lingora-input" type="number" min="0" max="40" value="<?php echo esc_attr( (string) $settings['on_fly_limit'] ); ?>">
	</label>
	<label class="lingora-check">
		<input type="checkbox" id="opt-gettext" <?php checked( $settings['translate_gettext'] ); ?>>
		<span><?php esc_html_e( '翻译主题 / 插件 gettext 字符串', 'lingora' ); ?></span>
	</label>
	<label class="lingora-check">
		<input type="checkbox" id="opt-slugs" <?php checked( $settings['translate_slugs'] ); ?>>
		<span><?php echo esc_html( sprintf( /* translators: %s: example translated slug path */ __( '翻译文章 / 页面 slug（/%s/about/）', 'lingora' ), $example_code ) ); ?></span>
	</label>
	<label class="lingora-check">
		<input type="checkbox" id="opt-search" <?php checked( $settings['search_translations'] ); ?>>
		<span><?php esc_html_e( '前台搜索包含译文', 'lingora' ); ?></span>
	</label>
</section>

<section class="lingora-panel">
	<div class="lingora-panel__head">
		<div>
			<span class="lingora-eyebrow"><?php esc_html_e( '搜索引擎', 'lingora' ); ?></span>
			<h2><?php esc_html_e( '搜索引擎', 'lingora' ); ?></h2>
		</div>
	</div>
	<label class="lingora-check">
		<input type="checkbox" id="opt-hreflang" <?php checked( $settings['add_hreflang'] ); ?>>
		<span><?php esc_html_e( '输出 hreflang', 'lingora' ); ?></span>
	</label>
	<label class="lingora-check">
		<input type="checkbox" id="opt-sitemap" <?php checked( $settings['multilingual_sitemap'] ); ?>>
		<span><?php echo esc_html( sprintf( /* translators: %s: language code */ __( '在站点地图中加入 /%s/ 等语言 URL', 'lingora' ), $example_code ) ); ?></span>
	</label>
	<label class="lingora-check">
		<input type="checkbox" id="opt-detect" <?php checked( $settings['detect_browser'] ); ?>>
		<span><?php esc_html_e( '按浏览器语言首次跳转（搜索引擎爬虫除外）', 'lingora' ); ?></span>
	</label>
	<label class="lingora-check">
		<input type="checkbox" id="opt-remember" <?php checked( $settings['remember_language'] ); ?>>
		<span><?php esc_html_e( '记住访客选择的语言', 'lingora' ); ?></span>
	</label>
</section>

<section class="lingora-panel">
	<div class="lingora-panel__head">
		<div>
			<span class="lingora-eyebrow"><?php esc_html_e( '切换器', 'lingora' ); ?></span>
			<h2><?php esc_html_e( '语言切换器', 'lingora' ); ?></h2>
		</div>
	</div>
	<label class="lingora-check">
		<input type="checkbox" id="opt-switcher" <?php checked( $settings['switcher_enabled'] ); ?>>
		<span><?php esc_html_e( '显示语言切换器', 'lingora' ); ?></span>
	</label>
	<label class="lingora-check">
		<input type="checkbox" id="opt-switcher-menu" <?php checked( $settings['switcher_in_menu'] ); ?>>
		<span><?php esc_html_e( '插入导航菜单右侧（国旗 + EN / DE）', 'lingora' ); ?></span>
	</label>
	<div class="lingora-grid lingora-grid--2">
		<label class="lingora-field">
			<span><?php esc_html_e( '样式', 'lingora' ); ?></span>
			<select id="opt-switcher-style" class="lingora-input">
				<option value="nav" <?php selected( $settings['switcher_style'], 'nav' ); ?>><?php esc_html_e( '顶栏下拉（推荐）', 'lingora' ); ?></option>
				<option value="floating" <?php selected( $settings['switcher_style'], 'floating' ); ?>><?php esc_html_e( '浮动按钮', 'lingora' ); ?></option>
				<option value="both" <?php selected( $settings['switcher_style'], 'both' ); ?>><?php esc_html_e( '菜单 + 浮动', 'lingora' ); ?></option>
			</select>
		</label>
		<label class="lingora-field">
			<span><?php esc_html_e( '回退位置', 'lingora' ); ?></span>
			<select id="opt-switcher-position" class="lingora-input">
				<option value="top-right" <?php selected( $settings['switcher_position'], 'top-right' ); ?>><?php esc_html_e( '右上', 'lingora' ); ?></option>
				<option value="top-left" <?php selected( $settings['switcher_position'], 'top-left' ); ?>><?php esc_html_e( '左上', 'lingora' ); ?></option>
				<option value="bottom-right" <?php selected( $settings['switcher_position'], 'bottom-right' ); ?>><?php esc_html_e( '右下', 'lingora' ); ?></option>
				<option value="bottom-left" <?php selected( $settings['switcher_position'], 'bottom-left' ); ?>><?php esc_html_e( '左下', 'lingora' ); ?></option>
			</select>
		</label>
	</div>
	<label class="lingora-check">
		<input type="checkbox" id="opt-show-flag" <?php checked( $settings['switcher_show_flag'] ); ?>>
		<span><?php esc_html_e( '显示国旗', 'lingora' ); ?></span>
	</label>
	<label class="lingora-check">
		<input type="checkbox" id="opt-show-code" <?php checked( $settings['switcher_show_code'] ); ?>>
		<span><?php esc_html_e( '显示语言代码（EN / DE）', 'lingora' ); ?></span>
	</label>
	<label class="lingora-check">
		<input type="checkbox" id="opt-show-name" <?php checked( $settings['switcher_show_name'] ); ?>>
		<span><?php esc_html_e( '显示语言名称', 'lingora' ); ?></span>
	</label>
	<p class="lingora-hint"><?php esc_html_e( '短代码：[lingora_switcher]  · 小工具「Lingora Language Switcher」。没有菜单时会固定在右上角。', 'lingora' ); ?></p>
</section>

<section class="lingora-panel">
	<div class="lingora-panel__head">
		<div>
			<span class="lingora-eyebrow"><?php esc_html_e( '排除', 'lingora' ); ?></span>
			<h2><?php esc_html_e( '排除', 'lingora' ); ?></h2>
		</div>
	</div>
	<label class="lingora-field">
		<span><?php esc_html_e( '排除选择器', 'lingora' ); ?></span>
		<input id="opt-exclude" class="lingora-input" type="text" value="<?php echo esc_attr( (string) $settings['exclude_selectors'] ); ?>">
	</label>
	<label class="lingora-field">
		<span><?php esc_html_e( '排除路径（逗号分隔）', 'lingora' ); ?></span>
		<input id="opt-exclude-paths" class="lingora-input" type="text" value="<?php echo esc_attr( (string) $settings['exclude_paths'] ); ?>">
	</label>
	<div class="lingora-actions">
		<button type="button" class="lingora-btn lingora-btn--primary" id="lingora-save-settings"><?php esc_html_e( '保存设置', 'lingora' ); ?></button>
	</div>
</section>

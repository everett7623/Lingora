<?php
/** 只在隔离测试站复现 AeroCore 的导航颜色覆盖。 */
require '/wordpress/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) {
	exit;
}
$options = lingora()->options;
$options->update( array( 'auto_translate' => false, 'ai_consent' => false, 'detect_browser' => false, 'remember_language' => false, 'switcher_enabled' => false ) );
$languages = array();
foreach ( Lingora_Languages::catalog() as $item ) {
	if ( isset( $_GET['many'] ) || in_array( $item['code'], array( 'en', 'zh', 'zh-tw', 'pt-br' ), true ) ) {
		unset( $item['flag'] ); // 复现旧记录无国旗字段。
		$languages[] = array_merge( $item, array( 'enabled' => true, 'is_default' => 'zh' === $item['code'] ) );
	}
}
$options->save_languages( $languages );
$GLOBALS['lingora_language'] = 'zh';
$style = isset( $_GET['floating'] ) ? 'floating' : 'nav';
$position = isset( $_GET['position'] ) && in_array( $_GET['position'], array( 'top-left', 'top-right', 'bottom-left', 'bottom-right' ), true ) ? $_GET['position'] : '';
?><!doctype html>
<html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Lingora 主题兼容测试</title>
<?php wp_head(); ?>
<style>
body { margin: 0; font: 14px/1.5 system-ui; background: #142544; color: #fff; }
.site-header { background: #3c4350; padding: 16px 24px; }
.nav-list { display: flex; align-items: center; justify-content: flex-end; gap: 16px; list-style: none; margin: 0; padding: 0; }
.nav-list li a { display: flex; padding: 6px 14px; font-size: 14px; color: rgba(255,255,255,.75); }
.nav-list li a:hover { color: #fff; background: rgba(255,255,255,.1); }
html:not([data-theme="dark"]) .site-header--light-bg .nav-list li a { color: rgba(0,0,0,.7); }
.nav-list ul { display: flex; }
.site-header img { max-width: 100%; height: auto; }
main { padding: 40px 24px; }
</style></head><body>
<header class="site-header"><ul class="nav-list"><li>关于我们</li><li class="lingora-menu-switcher">
<?php echo lingora()->switcher->markup( $style, $position ); ?>
</li></ul></header><main><h1>语言切换器主题兼容测试</h1><p>国旗、本地名称和不同区域的语言代码应完整显示。</p><button type="button" id="outside-focus">菜单外部按钮</button></main>
<?php wp_footer(); ?></body></html>

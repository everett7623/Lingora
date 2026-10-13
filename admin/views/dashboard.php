<?php
/**
 * Overview.
 *
 * @package Lingora
 *
 * @var array $stats
 * @var array $settings
 * @var array $languages
 * @var bool  $connected
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ai           = $settings['ai'];
$enabled      = array_values( array_filter( $languages, static fn( $lang ) => ! empty( $lang['enabled'] ) ) );
$example_code = (string) ( Lingora_Languages::example_pair( $languages )['target']['code'] ?? 'en' );
?>
<header class="lingora-hero">
	<div>
		<span class="lingora-eyebrow"><?php esc_html_e( '总览', 'lingora' ); ?></span>
		<h1><?php esc_html_e( '让网站变成多语言', 'lingora' ); ?></h1>
		<p><?php echo esc_html( sprintf( /* translators: %s: language prefix */ __( '不复制文章。访客打开 /%s/ 时翻译整页；旧内容可扫描进队列，也可在前台强制翻译当前页。', 'lingora' ), $example_code ) ); ?></p>
	</div>
	<a class="lingora-btn lingora-btn--primary" href="<?php echo esc_url( add_query_arg( 'lingora-edit', '1', home_url( '/' ) ) ); ?>">
		<?php esc_html_e( '可视化改译首页', 'lingora' ); ?>
	</a>
</header>

<section class="lingora-overview" aria-label="<?php esc_attr_e( '状态', 'lingora' ); ?>">
	<div class="lingora-overview__card">
		<span class="lingora-overview__icon <?php echo $connected ? 'is-ready' : ''; ?>">AI</span>
		<div>
			<small><?php esc_html_e( 'AI 接口', 'lingora' ); ?></small>
			<strong><?php echo $connected ? esc_html( (string) $ai['provider_id'] ) : esc_html__( '尚未填写密钥', 'lingora' ); ?></strong>
		</div>
	</div>
	<div class="lingora-overview__card">
		<span class="lingora-overview__icon">LN</span>
		<div>
			<small><?php esc_html_e( '语言', 'lingora' ); ?></small>
			<strong><?php echo esc_html( (string) count( $enabled ) ); ?></strong>
		</div>
	</div>
	<div class="lingora-overview__card">
		<span class="lingora-overview__icon">ST</span>
		<div>
			<small><?php esc_html_e( '字符串', 'lingora' ); ?></small>
			<strong data-lingora-stat="strings"><?php echo esc_html( (string) $stats['strings'] ); ?></strong>
		</div>
	</div>
	<div class="lingora-overview__card">
		<span class="lingora-overview__icon">TX</span>
		<div>
			<small><?php esc_html_e( '译文', 'lingora' ); ?></small>
			<strong data-lingora-stat="translations"><?php echo esc_html( (string) $stats['translations'] ); ?></strong>
		</div>
	</div>
</section>

<section class="lingora-panel">
	<div class="lingora-panel__head">
		<div>
			<span class="lingora-eyebrow"><?php esc_html_e( '开始', 'lingora' ); ?></span>
			<h2><?php esc_html_e( '三步开始', 'lingora' ); ?></h2>
		</div>
	</div>
	<ol class="lingora-steps">
		<li>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=lingora-connection' ) ); ?>">
				<strong><?php esc_html_e( '连接服务商', 'lingora' ); ?></strong>
				<span><?php esc_html_e( 'OpenAI、Claude、DeepSeek、Gemini、Grok、Dots AI、Sub2API，或其它 OpenAI 兼容接口。', 'lingora' ); ?></span>
			</a>
		</li>
		<li>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=lingora-languages' ) ); ?>">
				<strong><?php esc_html_e( '选择语言', 'lingora' ); ?></strong>
				<span><?php esc_html_e( '先定默认语言，再添加访客需要看到的语言。', 'lingora' ); ?></span>
			</a>
		</li>
		<li>
			<a href="<?php echo esc_url( add_query_arg( 'lingora-edit', '1', home_url( '/' ) ) ); ?>">
				<strong><?php esc_html_e( '打开可视化改译', 'lingora' ); ?></strong>
				<span><?php esc_html_e( '先选择目标语言，再点已高亮的文案或属性保存译文。没有高亮时，请扫描公开内容，或先匿名访问目标页面。', 'lingora' ); ?></span>
			</a>
		</li>
	</ol>
</section>

<section class="lingora-panel">
	<div class="lingora-panel__head">
		<div>
			<span class="lingora-eyebrow"><?php esc_html_e( '任务', 'lingora' ); ?></span>
			<h2><?php esc_html_e( '翻译任务', 'lingora' ); ?></h2>
			<p class="lingora-hint"><?php esc_html_e( '队列里的空缺每 5 分钟自动处理一次。也可以立刻扫描已发布文章，或马上跑一轮队列。没人访问过的旧页面不会自己出现译文，除非先扫描或打开对应语言 URL。', 'lingora' ); ?></p>
		</div>
	</div>
		<p class="lingora-hint" id="lingora-job-summary" role="status" aria-live="polite" aria-atomic="true"><?php esc_html_e( '正在读取后台任务状态…', 'lingora' ); ?></p>
		<p data-job-status="scan" role="status" aria-live="polite" aria-atomic="true"><?php esc_html_e( '正在读取扫描状态…', 'lingora' ); ?></p>
		<progress data-job-progress="scan" max="100" aria-label="<?php esc_attr_e( '扫描进度', 'lingora' ); ?>" hidden></progress>
		<p data-job-status="queue" role="status" aria-live="polite" aria-atomic="true"><?php esc_html_e( '正在读取队列状态…', 'lingora' ); ?></p>
		<progress data-job-progress="queue" max="100" aria-label="<?php esc_attr_e( '翻译队列进度', 'lingora' ); ?>" hidden></progress>
		<div class="lingora-actions">
		<button type="button" class="lingora-btn lingora-btn--primary" id="lingora-scan-now"><?php esc_html_e( '扫描已发布内容', 'lingora' ); ?></button>
		<button type="button" class="lingora-btn" id="lingora-run-queue"><?php esc_html_e( '立即处理队列', 'lingora' ); ?></button>
	</div>
</section>

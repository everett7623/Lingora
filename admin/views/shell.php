<?php
/**
 * Admin chrome.
 *
 * @package Lingora
 *
 * @var string $view
 * @var array  $stats
 * @var array  $settings
 * @var array  $languages
 * @var array  $providers
 * @var array  $catalog
 * @var bool   $connected
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nav = array(
	'lingora'            => array( '01', __( '总览', 'lingora' ) ),
	'lingora-connection' => array( '02', __( 'AI 接口', 'lingora' ) ),
	'lingora-languages'  => array( '03', __( '语言', 'lingora' ) ),
	'lingora-strings'    => array( '04', __( '字符串', 'lingora' ) ),
	'lingora-settings'   => array( '05', __( '设置', 'lingora' ) ),
);
$page = sanitize_key( $_GET['page'] ?? 'lingora' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="lingora-wrap">
	<div class="lingora-app" data-page="<?php echo esc_attr( $view ); ?>">
		<aside class="lingora-sidebar">
			<div class="lingora-brand">
				<span class="lingora-brand__mark" aria-hidden="true">L</span>
				<div>
					<strong>Lingora</strong>
					<small><?php esc_html_e( '多语言引擎', 'lingora' ); ?></small>
				</div>
			</div>
			<nav class="lingora-nav" aria-label="<?php esc_attr_e( 'Lingora', 'lingora' ); ?>">
				<?php foreach ( $nav as $slug => $item ) : ?>
					<a class="<?php echo $page === $slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>">
						<span><?php echo esc_html( $item[0] ); ?></span><?php echo esc_html( $item[1] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<div class="lingora-sidebar__note">
					<strong id="lingora-ai-status"><?php echo $connected ? ( ! empty( $settings['ai_consent'] ) ? esc_html__( 'AI 已就绪', 'lingora' ) : esc_html__( '请先同意连接 AI 服务', 'lingora' ) ) : esc_html__( '请先连接 AI 服务商', 'lingora' ); ?></strong>
				<small><?php esc_html_e( '密钥只保存在 WordPress 选项中，不会出现在前台。', 'lingora' ); ?></small>
			</div>
		</aside>
		<main class="lingora-main">
				<div id="lingora-toast" class="lingora-toast" role="status" aria-live="polite" aria-atomic="true" hidden></div>
				<div id="lingora-live-status" class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true"></div>
			<?php
			$file = LINGORA_PATH . 'admin/views/' . $view . '.php';
			if ( is_readable( $file ) ) {
				include $file;
			}
			?>
		</main>
	</div>
</div>

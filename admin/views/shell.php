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
	'lingora'            => array( '01', __( 'Overview', 'lingora' ) ),
	'lingora-connection' => array( '02', __( 'AI Connection', 'lingora' ) ),
	'lingora-languages'  => array( '03', __( 'Languages', 'lingora' ) ),
	'lingora-strings'    => array( '04', __( 'Strings', 'lingora' ) ),
	'lingora-settings'   => array( '05', __( 'Settings', 'lingora' ) ),
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
					<small><?php esc_html_e( 'Multilingual engine', 'lingora' ); ?></small>
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
				<strong><?php echo $connected ? esc_html__( 'AI ready', 'lingora' ) : esc_html__( 'Connect an AI provider', 'lingora' ); ?></strong>
				<small><?php esc_html_e( 'Keys stay in WordPress options and never reach the public site.', 'lingora' ); ?></small>
			</div>
		</aside>
		<main class="lingora-main">
			<div id="lingora-toast" class="lingora-toast" hidden></div>
			<?php
			$file = LINGORA_PATH . 'admin/views/' . $view . '.php';
			if ( is_readable( $file ) ) {
				include $file;
			}
			?>
		</main>
	</div>
</div>

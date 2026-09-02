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

$ai      = $settings['ai'];
$enabled = array_values( array_filter( $languages, static fn( $lang ) => ! empty( $lang['enabled'] ) ) );
?>
<header class="lingora-hero">
	<div>
		<span class="lingora-eyebrow">Workspace</span>
		<h1><?php esc_html_e( 'Your site, in every language', 'lingora' ); ?></h1>
		<p><?php esc_html_e( 'Lingora translates the live page like TranslatePress, and talks to AI the way WAMofa does.', 'lingora' ); ?></p>
	</div>
	<a class="lingora-btn lingora-btn--primary" href="<?php echo esc_url( add_query_arg( 'lingora-edit', '1', home_url( '/' ) ) ); ?>">
		<?php esc_html_e( 'Translate homepage', 'lingora' ); ?>
	</a>
</header>

<section class="lingora-overview" aria-label="<?php esc_attr_e( 'Status', 'lingora' ); ?>">
	<div class="lingora-overview__card">
		<span class="lingora-overview__icon <?php echo $connected ? 'is-ready' : ''; ?>">AI</span>
		<div>
			<small><?php esc_html_e( 'AI connection', 'lingora' ); ?></small>
			<strong><?php echo $connected ? esc_html( (string) $ai['provider_id'] ) : esc_html__( 'Waiting for a key', 'lingora' ); ?></strong>
		</div>
	</div>
	<div class="lingora-overview__card">
		<span class="lingora-overview__icon">LN</span>
		<div>
			<small><?php esc_html_e( 'Languages', 'lingora' ); ?></small>
			<strong><?php echo esc_html( (string) count( $enabled ) ); ?></strong>
		</div>
	</div>
	<div class="lingora-overview__card">
		<span class="lingora-overview__icon">ST</span>
		<div>
			<small><?php esc_html_e( 'Strings', 'lingora' ); ?></small>
			<strong><?php echo esc_html( (string) $stats['strings'] ); ?></strong>
		</div>
	</div>
	<div class="lingora-overview__card">
		<span class="lingora-overview__icon">TX</span>
		<div>
			<small><?php esc_html_e( 'Translations', 'lingora' ); ?></small>
			<strong><?php echo esc_html( (string) $stats['translations'] ); ?></strong>
		</div>
	</div>
</section>

<section class="lingora-panel">
	<div class="lingora-panel__head">
		<div>
			<span class="lingora-eyebrow">Start</span>
			<h2><?php esc_html_e( 'Three steps', 'lingora' ); ?></h2>
		</div>
	</div>
	<ol class="lingora-steps">
		<li>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=lingora-connection' ) ); ?>">
				<strong><?php esc_html_e( 'Connect a provider', 'lingora' ); ?></strong>
				<span><?php esc_html_e( 'OpenAI, Claude, DeepSeek, Gemini, Grok, Dots AI, Sub2API or any OpenAI-compatible gateway.', 'lingora' ); ?></span>
			</a>
		</li>
		<li>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=lingora-languages' ) ); ?>">
				<strong><?php esc_html_e( 'Choose languages', 'lingora' ); ?></strong>
				<span><?php esc_html_e( 'Set the default language, then add the locales your visitors should see.', 'lingora' ); ?></span>
			</a>
		</li>
		<li>
			<a href="<?php echo esc_url( add_query_arg( 'lingora-edit', '1', home_url( '/' ) ) ); ?>">
				<strong><?php esc_html_e( 'Open the visual editor', 'lingora' ); ?></strong>
				<span><?php esc_html_e( 'Click any phrase on the live site, polish the AI draft, and save.', 'lingora' ); ?></span>
			</a>
		</li>
	</ol>
</section>

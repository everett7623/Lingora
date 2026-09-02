<?php
/**
 * Core orchestrator.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once LINGORA_PATH . 'includes/class-activator.php';
require_once LINGORA_PATH . 'includes/class-deactivator.php';
require_once LINGORA_PATH . 'includes/class-options.php';
require_once LINGORA_PATH . 'includes/class-db.php';
require_once LINGORA_PATH . 'includes/class-flags.php';
require_once LINGORA_PATH . 'includes/class-languages.php';
require_once LINGORA_PATH . 'includes/class-providers.php';
require_once LINGORA_PATH . 'includes/class-ai-client.php';
require_once LINGORA_PATH . 'includes/class-translator.php';
require_once LINGORA_PATH . 'includes/class-html.php';
require_once LINGORA_PATH . 'includes/class-rewrite.php';
require_once LINGORA_PATH . 'includes/class-detect.php';
require_once LINGORA_PATH . 'includes/class-frontend.php';
require_once LINGORA_PATH . 'includes/class-switcher.php';
require_once LINGORA_PATH . 'includes/class-seo.php';
require_once LINGORA_PATH . 'includes/class-gettext.php';
require_once LINGORA_PATH . 'includes/class-slugs.php';
require_once LINGORA_PATH . 'includes/class-sitemap.php';
require_once LINGORA_PATH . 'includes/class-search.php';
require_once LINGORA_PATH . 'includes/class-woocommerce.php';
require_once LINGORA_PATH . 'includes/class-rest.php';
require_once LINGORA_PATH . 'includes/class-editor.php';
require_once LINGORA_PATH . 'admin/class-admin.php';

final class Lingora_Plugin {
	private static ?self $instance = null;

	public Lingora_Options $options;
	public Lingora_Db $db;
	public Lingora_Languages $languages;
	public Lingora_Providers $providers;
	public Lingora_Ai_Client $ai;
	public Lingora_Translator $translator;
	public Lingora_Rewrite $rewrite;
	public Lingora_Detect $detect;
	public Lingora_Frontend $frontend;
	public Lingora_Switcher $switcher;
	public Lingora_Seo $seo;
	public Lingora_Gettext $gettext;
	public Lingora_Slugs $slugs;
	public Lingora_Sitemap $sitemap;
	public Lingora_Search $search;
	public Lingora_WooCommerce $woocommerce;
	public Lingora_Rest $rest;
	public Lingora_Editor $editor;
	public Lingora_Admin $admin;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->options    = new Lingora_Options();
		$this->db         = new Lingora_Db();
		$this->languages  = new Lingora_Languages( $this->options );
		$this->providers  = new Lingora_Providers();
		$this->ai         = new Lingora_Ai_Client( $this->options, $this->providers );
		$this->translator = new Lingora_Translator( $this->db, $this->ai, $this->options, $this->languages );
		$this->rewrite     = new Lingora_Rewrite( $this->languages, $this->options );
		$this->detect      = new Lingora_Detect( $this->languages, $this->rewrite, $this->options );
		$this->frontend    = new Lingora_Frontend( $this->translator, $this->languages, $this->options );
		$this->switcher    = new Lingora_Switcher( $this->languages, $this->rewrite, $this->options );
		$this->seo         = new Lingora_Seo( $this->languages, $this->rewrite, $this->options );
		$this->gettext     = new Lingora_Gettext( $this->translator, $this->languages, $this->options );
		$this->slugs       = new Lingora_Slugs( $this->db, $this->ai, $this->languages, $this->options );
		$this->sitemap     = new Lingora_Sitemap( $this->languages, $this->rewrite, $this->options );
		$this->search      = new Lingora_Search( $this->db, $this->languages, $this->options );
		$this->woocommerce = new Lingora_WooCommerce( $this->rewrite, $this->languages );
		$this->rest        = new Lingora_Rest( $this->options, $this->languages, $this->providers, $this->ai, $this->translator, $this->db );
		$this->editor     = new Lingora_Editor( $this->languages, $this->translator, $this->options );
		$this->admin      = new Lingora_Admin( $this->options, $this->languages, $this->providers, $this->db );
	}

	public function boot(): void {
		load_plugin_textdomain( 'lingora', false, dirname( LINGORA_BASENAME ) . '/languages' );
		Lingora_Options::migrate_ui();

		$this->rewrite->register();
		$this->detect->register();
		$this->frontend->register();
		$this->switcher->register();
		$this->seo->register();
		$this->gettext->register();
		$this->slugs->register();
		$this->sitemap->register();
		$this->search->register();
		$this->woocommerce->register();
		$this->rest->register();
		$this->editor->register();
		$this->translator->register();

		if ( is_admin() ) {
			$this->admin->register();
		}

		add_filter( 'plugin_action_links_' . LINGORA_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * @param array<string, string> $links
	 * @return array<string, string>
	 */
	public function action_links( array $links ): array {
		$links['settings'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=lingora' ) ),
			esc_html__( 'Open Lingora', 'lingora' )
		);
		return $links;
	}
}

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
require_once LINGORA_PATH . 'includes/class-text.php';
require_once LINGORA_PATH . 'includes/class-content.php';
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
require_once LINGORA_PATH . 'includes/class-scanner.php';
require_once LINGORA_PATH . 'includes/class-rest.php';
require_once LINGORA_PATH . 'includes/class-editor.php';
require_once LINGORA_PATH . 'admin/class-admin.php';

final class Lingora_Plugin {
	private static ?self $instance = null;
	private bool $initialized = false;
	private bool $booted = false;

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
	public Lingora_Scanner $scanner;
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
		$this->scanner     = new Lingora_Scanner( $this->translator, $this->languages, $this->options );
		$this->rest        = new Lingora_Rest( $this->options, $this->languages, $this->providers, $this->ai, $this->translator, $this->db, $this->scanner );
		$this->editor     = new Lingora_Editor( $this->languages, $this->translator, $this->options );
		$this->admin      = new Lingora_Admin( $this->options, $this->languages, $this->providers, $this->db );
	}

	public function boot(): void {
		if ( $this->booted || is_multisite() ) {
			return;
		}
		$this->booted = true;
		$this->rewrite->register();
		add_action( 'init', array( $this, 'initialize' ), 0 );
	}

	public function initialize(): void {
		if ( $this->initialized || is_multisite() ) {
			return;
		}
		$this->initialized = true;
		load_plugin_textdomain( 'lingora', false, dirname( LINGORA_BASENAME ) . '/languages' );
		Lingora_Options::seed();
		Lingora_Db::install();
		add_action( 'admin_notices', array( $this, 'runtime_notice' ) );
		add_action( 'admin_init', array( $this, 'privacy_policy' ) );

		$this->detect->register();
		$this->frontend->register();
		$this->switcher->register();
		$this->seo->register();
		$this->gettext->register();
		$this->slugs->register();
		$this->sitemap->register();
		$this->search->register();
		$this->woocommerce->register();
		$this->scanner->register();
		$this->rest->register();
		$this->editor->register();
		$this->translator->register();

		if ( is_admin() ) {
			$this->admin->register();
		}

		add_filter( 'plugin_action_links_' . LINGORA_BASENAME, array( $this, 'action_links' ) );
	}

	public function runtime_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || class_exists( 'DOMDocument' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Lingora 需要 PHP DOM 扩展来采集和翻译页面。请联系主机服务商启用扩展；现有译文和后台设置仍会保留。', 'lingora' ) . '</p></div>';
	}

	public function privacy_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p>' . esc_html__( '本站使用 Lingora 提供多语言内容。管理员启用 AI 翻译并授权后，待翻译的公开页面文字、指定属性及可选 URL 别名会发送给管理员选择的 AI 服务商，同时发送目标语言和模型信息。服务商可能需要付费账户，数据处理取决于所选服务的条款和隐私政策。请在本站政策中填写实际服务商和相应政策链接。', 'lingora' ) . '</p>';
		$content .= '<p>' . esc_html__( '原文和译文保存在本站数据库中。启用记住语言后，Lingora 使用 lingora_lang Cookie 保存语言代码，期限为 30 天；关闭该选项后不再写入 Cookie。卸载插件会删除其设置、密钥、译文及任务数据，停用则保留这些数据。', 'lingora' ) . '</p>';
		wp_add_privacy_policy_content( 'Lingora', $content );
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

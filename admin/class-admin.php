<?php
/**
 * WordPress admin application shell.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Admin {
	private Lingora_Options $options;
	private Lingora_Languages $languages;
	private Lingora_Providers $providers;
	private Lingora_Db $db;

	public function __construct(
		Lingora_Options $options,
		Lingora_Languages $languages,
		Lingora_Providers $providers,
		Lingora_Db $db
	) {
		$this->options   = $options;
		$this->languages = $languages;
		$this->providers = $providers;
		$this->db        = $db;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function menu(): void {
		add_menu_page(
			'Lingora',
			'Lingora',
			'manage_options',
			'lingora',
			array( $this, 'render' ),
			'dashicons-translation',
			58
		);
		add_submenu_page( 'lingora', __( '总览', 'lingora' ), __( '总览', 'lingora' ), 'manage_options', 'lingora', array( $this, 'render' ) );
		add_submenu_page( 'lingora', __( 'AI 接口', 'lingora' ), __( 'AI 接口', 'lingora' ), 'manage_options', 'lingora-connection', array( $this, 'render' ) );
		add_submenu_page( 'lingora', __( '语言', 'lingora' ), __( '语言', 'lingora' ), 'manage_options', 'lingora-languages', array( $this, 'render' ) );
		add_submenu_page( 'lingora', __( '字符串', 'lingora' ), __( '字符串', 'lingora' ), 'manage_options', 'lingora-strings', array( $this, 'render' ) );
		add_submenu_page( 'lingora', __( '设置', 'lingora' ), __( '设置', 'lingora' ), 'manage_options', 'lingora-settings', array( $this, 'render' ) );
	}

	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, 'lingora' ) ) {
			return;
		}
		wp_enqueue_style( 'lingora-admin', LINGORA_URL . 'admin/css/admin.css', array(), LINGORA_VERSION );
		wp_enqueue_script( 'lingora-admin', LINGORA_URL . 'admin/js/admin.js', array( 'wp-i18n' ), LINGORA_VERSION, true );
		wp_set_script_translations( 'lingora-admin', 'lingora', LINGORA_PATH . 'languages' );
		wp_localize_script(
			'lingora-admin',
			'lingoraAdmin',
			array(
				'restUrl'      => esc_url_raw( rest_url( 'lingora/v1/' ) ),
				'flagBaseUrl'  => esc_url_raw( LINGORA_URL . 'public/vendor/flag-icons/4x3/' ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
					'page'         => sanitize_key( $_GET['page'] ?? 'lingora' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'siteUrl'      => home_url( '/' ),
					'dashboardUrl' => admin_url( 'admin.php?page=lingora' ),
					'aiConsent'    => (bool) $this->options->get( 'ai_consent', false ),
			)
		);
	}

	public function render(): void {
		$page = sanitize_key( $_GET['page'] ?? 'lingora' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = match ( $page ) {
			'lingora-connection' => 'connection',
			'lingora-languages'  => 'languages',
			'lingora-strings'    => 'strings',
			'lingora-settings'   => 'settings',
			default              => 'dashboard',
		};
		$stats     = $this->db->stats();
		$settings  = $this->options->all();
		$languages = $this->options->languages();
		$providers = $this->providers->public_list();
		$catalog   = Lingora_Languages::catalog();
		$connected = $this->options->has_ai_credential();
		include LINGORA_PATH . 'admin/views/shell.php';
	}
}

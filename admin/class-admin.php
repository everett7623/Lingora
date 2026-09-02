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
		add_action( 'admin_head', array( $this, 'hide_notices' ) );
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
		add_submenu_page( 'lingora', __( 'Overview', 'lingora' ), __( 'Overview', 'lingora' ), 'manage_options', 'lingora', array( $this, 'render' ) );
		add_submenu_page( 'lingora', __( 'AI Connection', 'lingora' ), __( 'AI Connection', 'lingora' ), 'manage_options', 'lingora-connection', array( $this, 'render' ) );
		add_submenu_page( 'lingora', __( 'Languages', 'lingora' ), __( 'Languages', 'lingora' ), 'manage_options', 'lingora-languages', array( $this, 'render' ) );
		add_submenu_page( 'lingora', __( 'Strings', 'lingora' ), __( 'Strings', 'lingora' ), 'manage_options', 'lingora-strings', array( $this, 'render' ) );
		add_submenu_page( 'lingora', __( 'Settings', 'lingora' ), __( 'Settings', 'lingora' ), 'manage_options', 'lingora-settings', array( $this, 'render' ) );
	}

	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, 'lingora' ) ) {
			return;
		}
		wp_enqueue_style( 'lingora-admin', LINGORA_URL . 'admin/css/admin.css', array(), LINGORA_VERSION );
		wp_enqueue_script( 'lingora-admin', LINGORA_URL . 'admin/js/admin.js', array(), LINGORA_VERSION, true );
		wp_localize_script(
			'lingora-admin',
			'lingoraAdmin',
			array(
				'restUrl' => esc_url_raw( rest_url( 'lingora/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'page'    => sanitize_key( $_GET['page'] ?? 'lingora' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'siteUrl' => home_url( '/' ),
			)
		);
	}

	public function hide_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! str_contains( (string) $screen->id, 'lingora' ) ) {
			return;
		}
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
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

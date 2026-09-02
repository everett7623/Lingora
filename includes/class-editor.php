<?php
/**
 * Frontend visual translation editor.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Editor {
	private Lingora_Languages $languages;
	private Lingora_Translator $translator;
	private Lingora_Options $options;

	public function __construct( Lingora_Languages $languages, Lingora_Translator $translator, Lingora_Options $options ) {
		$this->languages  = $languages;
		$this->translator = $translator;
		$this->options    = $options;
	}

	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 80 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public static function is_active(): bool {
		if ( is_admin() || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return isset( $_GET['lingora-edit'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public function admin_bar( WP_Admin_Bar $bar ): void {
		if ( is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$current = home_url( add_query_arg( array() ) );
		if ( self::is_active() ) {
			$url   = remove_query_arg( 'lingora-edit', $current );
			$title = __( 'Exit Lingora editor', 'lingora' );
		} else {
			$url   = add_query_arg( 'lingora-edit', '1', $current );
			$title = __( 'Translate this page', 'lingora' );
		}
		$bar->add_node(
			array(
				'id'    => 'lingora-edit',
				'title' => $title,
				'href'  => $url,
			)
		);
	}

	public function enqueue(): void {
		if ( ! self::is_active() ) {
			return;
		}
		wp_enqueue_style(
			'lingora-editor',
			LINGORA_URL . 'public/css/frontend.css',
			array(),
			LINGORA_VERSION
		);
		wp_enqueue_script(
			'lingora-editor',
			LINGORA_URL . 'public/js/editor.js',
			array(),
			LINGORA_VERSION,
			true
		);
		$targets = array();
		$default = $this->languages->default_code();
		foreach ( $this->languages->enabled() as $lang ) {
			if ( $lang['code'] !== $default ) {
				$targets[] = array(
					'code'   => $lang['code'],
					'label'  => $lang['native'] ?? $lang['name'],
				);
			}
		}
		wp_localize_script(
			'lingora-editor',
			'lingoraEditor',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'lingora/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'language' => $this->languages->current(),
				'default'  => $default,
				'targets'  => $targets,
				'i18n'     => array(
					'title'   => __( 'Lingora editor', 'lingora' ),
					'save'    => __( 'Save translation', 'lingora' ),
					'ai'      => __( 'Translate with AI', 'lingora' ),
					'empty'   => __( 'Click a highlighted phrase to edit it.', 'lingora' ),
					'saved'   => __( 'Saved', 'lingora' ),
				),
			)
		);
	}
}

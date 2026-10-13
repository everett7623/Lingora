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
	private Lingora_Options $options;

	public function __construct( Lingora_Languages $languages, Lingora_Translator $translator, Lingora_Options $options ) {
		$this->languages = $languages;
		$this->options   = $options;
	}

	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 80 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public static function is_active(): bool {
		return ! is_admin() && is_user_logged_in() && current_user_can( 'manage_options' ) && isset( $_GET['lingora-edit'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor view; REST writes verify their own nonce.
	}

	public static function is_force(): bool {
		if ( is_admin() || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( ! isset( $_GET['lingora-force'], $_GET['_lingora_nonce'] ) || ! is_string( $_GET['_lingora_nonce'] ) ) {
			return false;
		}
		return (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_lingora_nonce'] ) ), 'lingora_force' );
	}

	public function admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'manage_options' ) || ! Lingora_Content::is_public_request() ) {
			return;
		}
		$current = Lingora_Rewrite::strip_editor_params( lingora()->rewrite->current_url() );
		$bar->add_node(
			array(
				'id'    => 'lingora-edit',
				'title' => self::is_active() ? __( '退出可视化改译', 'lingora' ) : __( '可视化改译本页', 'lingora' ),
				'href'  => self::is_active() ? $current : add_query_arg( 'lingora-edit', '1', $current ),
			)
		);
		if ( ! self::is_active() && ! $this->languages->is_default() ) {
			$bar->add_node(
				array(
					'id'     => 'lingora-force',
					'parent' => 'lingora-edit',
					'title'  => __( '将本页已采集空缺加入翻译队列', 'lingora' ),
					'href'   => wp_nonce_url( add_query_arg( 'lingora-force', '1', $current ), 'lingora_force', '_lingora_nonce' ),
				)
			);
		}
	}

	public function enqueue(): void {
		if ( ! self::is_active() || ! Lingora_Content::is_public_request() ) {
			return;
		}
		wp_enqueue_style( 'lingora-editor', LINGORA_URL . 'public/css/frontend.css', array(), LINGORA_VERSION );
		wp_enqueue_script( 'lingora-editor', LINGORA_URL . 'public/js/editor.js', array( 'wp-i18n' ), LINGORA_VERSION, true );
		wp_set_script_translations( 'lingora-editor', 'lingora', LINGORA_PATH . 'languages' );
		$targets = array();
		$default = $this->languages->default_code();
		$current = Lingora_Rewrite::strip_editor_params( lingora()->rewrite->current_url() );
		foreach ( $this->languages->enabled() as $lang ) {
			if ( $lang['code'] !== $default ) {
				$targets[] = array(
					'code'  => $lang['code'],
					'label' => $lang['native'] ?? $lang['name'],
					'url'   => add_query_arg( 'lingora-edit', '1', lingora()->rewrite->language_url( $lang['code'], $current ) ),
				);
			}
		}
		wp_localize_script(
			'lingora-editor',
			'lingoraEditor',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'lingora/v1/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'language'  => $this->languages->current(),
				'default'   => $default,
				'targets'   => $targets,
				'canSendAi' => $this->options->can_send_ai(),
				'exitUrl'   => $current,
				'i18n'      => array(
					'title' => __( 'Lingora 可视化改译', 'lingora' ),
					'save'  => __( '保存译文', 'lingora' ),
					'ai'    => __( '使用 AI 翻译', 'lingora' ),
					'empty' => __( '点击高亮文案开始编辑；没有高亮时，请先扫描公开内容或匿名访问页面。', 'lingora' ),
					'saved' => __( '已保存', 'lingora' ),
				),
			)
		);
	}
}

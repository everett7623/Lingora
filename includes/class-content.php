<?php
/**
 * Shared boundaries for publicly translatable content.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Content {
	public static function is_public_post( WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status || '' !== (string) $post->post_password || ! is_post_type_viewable( $post->post_type ) ) {
			return false;
		}
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				if ( (int) $post->ID === (int) wc_get_page_id( $page ) ) {
					return false;
				}
			}
		}
		return true;
	}

	public static function is_public_request(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || wp_installing() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_is_json_request() ) {
			return false;
		}
		if ( ! in_array( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ), array( 'GET', 'HEAD' ), true ) ) {
			return false;
		}
		if ( is_feed() || is_robots() || is_trackback() || is_preview() || is_404() || is_embed() || is_search() ) {
			return false;
		}
		foreach ( array( 'rest_route', 'wc-ajax', 'add-to-cart', 'preview', 'preview_id', 'preview_nonce', 'customize_changeset_uuid', 'customize_messenger_channel' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only exclusion of private requests.
				return false;
			}
		}
		foreach ( array( 'is_cart', 'is_checkout', 'is_account_page' ) as $callback ) {
			if ( function_exists( $callback ) && $callback() ) {
				return false;
			}
		}
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post instanceof WP_Post || ! self::is_public_post( $post ) ) {
				return false;
			}
		}
		return true;
	}

	public static function can_collect_request(): bool {
		// Authenticated HTML can contain account-specific copy even on a public URL.
		return self::is_public_request() && ! is_user_logged_in();
	}
}

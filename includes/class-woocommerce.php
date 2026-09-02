<?php
/**
 * WooCommerce URL localization for cart, checkout, and account.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_WooCommerce {
	private Lingora_Rewrite $rewrite;
	private Lingora_Languages $languages;

	public function __construct( Lingora_Rewrite $rewrite, Lingora_Languages $languages ) {
		$this->rewrite   = $rewrite;
		$this->languages = $languages;
	}

	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_filter( 'woocommerce_get_cart_url', array( $this, 'localize' ) );
		add_filter( 'woocommerce_get_checkout_url', array( $this, 'localize' ) );
		add_filter( 'woocommerce_get_endpoint_url', array( $this, 'localize' ) );
		add_filter( 'woocommerce_get_return_url', array( $this, 'localize' ) );
		add_filter( 'woocommerce_get_myaccount_page_permalink', array( $this, 'localize' ) );
		add_filter( 'woocommerce_product_get_permalink', array( $this, 'localize' ) );
	}

	public function localize( $url ) { // phpcs:ignore
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		return $this->rewrite->localize_url( $url, $this->languages->current() );
	}
}

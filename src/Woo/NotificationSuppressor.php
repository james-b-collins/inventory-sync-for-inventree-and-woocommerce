<?php

declare(strict_types=1);

namespace InvenTreeSync\Woo;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to suppress WooCommerce stock notifications during sync
final class NotificationSuppressor {

	private const HOOKS = [
		'woocommerce_low_stock'            => 'low_stock',
		'woocommerce_no_stock'             => 'no_stock',
		'woocommerce_product_on_backorder' => 'backorder',
	];

	// Suppress WooCommerce stock notifications by removing the relevant hooks
	public function suppress(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$mailer = WC()->mailer();
		foreach ( self::HOOKS as $hook => $method ) {
			remove_action( $hook, [ $mailer, $method ] );
		}
	}

	// Restore WooCommerce stock notifications by adding the relevant hooks back
	public function restore(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$mailer = WC()->mailer();
		foreach ( self::HOOKS as $hook => $method ) {
			add_action( $hook, [ $mailer, $method ] );
		}
	}
}

<?php
// This file is loaded by the PHPUnit bootstrap process, and sets up WordPress for testing.
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Load WordPress test environment
$_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );

require_once $_tests_dir . '/includes/functions.php';

// Load the plugin and woocommerce
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require WP_CONTENT_DIR . '/plugins/woocommerce/woocommerce.php';
		update_option( 'inventree_sync_enabled', 1 );
		require dirname( __DIR__ ) . '/inventory-sync-for-inventree-and-woocommerce.php';
	}
);

// Install WooCommerce if it is not already installed
tests_add_filter(
	'setup_theme',
	static function (): void {
		if ( class_exists( \WC_Install::class ) ) {
			\WC_Install::install();
		}
	}
);

require $_tests_dir . '/includes/bootstrap.php';

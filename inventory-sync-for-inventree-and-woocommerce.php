<?php
/**
 * Plugin Name:       Inventory Sync for InvenTree and WooCommerce
 * Description:       Mirrors stock from an InvenTree instance into WooCommerce, and records web orders back into InvenTree as sales orders. InvenTree is the single source of truth for inventory. Not affiliated with the InvenTree project or with Automattic.
 * Version:           0.1.0
 * Requires PHP:      8.0
 * Requires at least: 6.0
 * Requires Plugins:  woocommerce
 * Network:           false
 * License:           GPL-2.0-or-later
 * Text Domain:       inventory-sync-for-inventree-and-woocommerce
 */

declare(strict_types=1);

namespace InvenTreeSync;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit; 
}

// Define plugin constants for version, file path, and directory.
define( 'INVENTREE_SYNC_VERSION', '0.1.0' );
define( 'INVENTREE_SYNC_FILE', __FILE__ );
define( 'INVENTREE_SYNC_DIR', plugin_dir_path( __FILE__ ) );

// Load the Composer autoloader if it exists
$inventree_sync_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $inventree_sync_autoload ) ) {
	require $inventree_sync_autoload;
}

// Load the plugin's own autoloader for its src/ directory if Composer's autoloader is not present
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

// Declare compatibility with WooCommerce's custom order tables feature, if available.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', INVENTREE_SYNC_FILE, true );
		}
	}
);

// Boot after WooCommerce has loaded
add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	},
	20
);

// Activation and deactivation hooks for the plugin
register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

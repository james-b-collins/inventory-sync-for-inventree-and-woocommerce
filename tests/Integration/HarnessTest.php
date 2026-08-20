<?php
// This file tests the integration harness
declare(strict_types=1);

namespace InvenTreeSync\Tests\Integration;

use WP_UnitTestCase;

// this class runs through the integration harness functionality
final class HarnessTest extends WP_UnitTestCase {

	// test that WooCommerce is loaded and the plugin tables exist
	public function test_woocommerce_is_loaded(): void {
		$this->assertTrue( class_exists( 'WooCommerce' ) );
		$this->assertTrue( function_exists( 'wc_get_product' ) );
	}

	// test that the plugin tables exist
	public function test_plugin_tables_exist(): void {
		global $wpdb;
		foreach ( [ 'inventree_reservations', 'inventree_log' ] as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$this->assertSame( $table, $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ), "missing table {$table}" );
		}
	}

	// test that a simple product can be created
	public function test_can_create_a_simple_product(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Harness Product' );
		$product->set_sku( 'HARNESS-1' );
		$product->save();

		$this->assertGreaterThan( 0, $product->get_id() );
		$this->assertSame( $product->get_id(), wc_get_product_id_by_sku( 'HARNESS-1' ) );
	}
}

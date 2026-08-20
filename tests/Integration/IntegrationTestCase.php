<?php
// this file is the base class for all integration tests, and provides helpers for creating products and orders.
declare(strict_types=1);

namespace InvenTreeSync\Tests\Integration;

use InvenTreeSync\Support\Meta;
use WP_UnitTestCase;

// The base class for all integration tests
abstract class IntegrationTestCase extends WP_UnitTestCase {
	// sets up the test environment, and ensures that the plugin is activated.
	protected function setUp(): void {
		parent::setUp();
		$this->set_enabled( true );
	}

	// tears down the test environment
	protected function set_enabled( bool $enabled ): void {
		if ( $enabled ) {
			$value = 1;
		} else {
			$value = 0;
		}
		update_option( \InvenTreeSync\Admin\Settings::ENABLED_OPTION, $value );
	}

	// creates a managed product with the given SKU, InvenTree part ID, and stock quantity.
	protected function make_managed_product( string $sku, int $part_id, int $available ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Managed ' . $sku );
		$product->set_sku( $sku );
		$product->set_status( 'publish' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $available );
		$product->update_meta_data( Meta::PART_ID, $part_id );
		$product->update_meta_data( Meta::QTY, $available );
		$product->save();

		return $product;
	}

	// creates a variable product with the given SKU, InvenTree part ID, stock quantity, and attribute option.
	protected function make_variable_parent( string $sku, string $attribute_name = 'Size' ): \WC_Product_Variable {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( $attribute_name );
		$attribute->set_options( [ 'Small', 'Large' ] );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variable ' . $sku );
		$parent->set_sku( $sku );
		$parent->set_status( 'publish' );
		$parent->set_attributes( [ $attribute ] );
		$parent->save();

		return $parent;
	}

	// creates a product variation with the given SKU, InvenTree part ID, stock quantity, and attribute option.
	protected function make_variation(
		\WC_Product_Variable $parent,
		string $sku,
		int $part_id,
		int $stock,
		string $option = 'Small',
		string $attribute_name = 'size'
	): \WC_Product_Variation {
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes( [ $attribute_name => $option ] );
		$variation->set_sku( $sku );
		$variation->set_status( 'publish' );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( $stock );

		if ( $part_id > 0 ) {
			$variation->update_meta_data( Meta::PART_ID, $part_id );
			$variation->update_meta_data( Meta::QTY, $stock );
		}

		$variation->save();

		return $variation;
	}

	// creates a plain product with the given SKU and stock quantity, but does not set any InvenTree metadata.
	protected function make_plain_product( string $sku, int $stock ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Plain ' . $sku );
		$product->set_sku( $sku );
		$product->set_status( 'publish' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product->save();

		return $product;
	}

	// creates a order with the given lines, where each line is an array of [ product, quantity ].
	protected function make_order( array $lines ): \WC_Order {
		$order = wc_create_order();
		foreach ( $lines as $line ) {
			$order->add_product( $line[0], $line[1] );
		}
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	// gets the reservations for a given order ID.
	protected function reservations_for_order( int $order_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'inventree_reservations';
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d", $order_id ) );
	}

	// gets the pending quantity for a given product ID.
	protected function pending( int $product_id ): int {
		return (int) get_post_meta( $product_id, Meta::PENDING, true );
	}

	// gets the stock quantity for a given product ID.
	protected function stock( int $product_id ): int {
		return (int) wc_get_product( $product_id )->get_stock_quantity();
	}
}

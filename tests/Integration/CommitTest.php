<?php
// This file tests the commit functionality
declare(strict_types=1);

namespace InvenTreeSync\Tests\Integration;

use InvenTreeSync\Support\Meta;

// this class runs through the commit functionality, including reserving stock and ensuring that WooCommerce stock reduction is suppressed for managed products.
final class CommitTest extends IntegrationTestCase {

	// test that processing an order reserves the correct quantity of its line and reduces stock accordingly
	public function test_processing_reserves_the_line_and_reduces_stock(): void {
		$product = $this->make_managed_product( 'SKU-1', 101, 25 );
		$order   = $this->make_order( [ [ $product, 3 ] ] );

		$order->update_status( 'processing' );

		$reservations = $this->reservations_for_order( $order->get_id() );
		$this->assertCount( 1, $reservations );
		$this->assertSame( 3, (int) $reservations[0]->committed_qty );
		$this->assertSame( 3, (int) $reservations[0]->held_qty );
		$this->assertSame( $product->get_id(), (int) $reservations[0]->product_id );

		$this->assertSame( 3, $this->pending( $product->get_id() ) );
		$this->assertSame( 22, $this->stock( $product->get_id() ) ); // 25 available - 3 pending
	}

	// test that processing an order reserves the correct quantity of its line and reduces stock accordingly
	public function test_commit_is_idempotent_across_status_changes(): void {
		$product = $this->make_managed_product( 'SKU-2', 102, 10 );
		$order   = $this->make_order( [ [ $product, 2 ] ] );

		$order->update_status( 'processing' );
		$order->update_status( 'completed' );

		$this->assertCount( 1, $this->reservations_for_order( $order->get_id() ) );
		$this->assertSame( 2, $this->pending( $product->get_id() ) );
		$this->assertSame( 8, $this->stock( $product->get_id() ) );
	}

	// test that WooCommerce stock reduction is suppressed for managed products
	public function test_woocommerce_stock_reduction_is_suppressed_per_line(): void {
		$managed = $this->make_managed_product( 'SKU-3', 103, 25 );
		$plain   = $this->make_plain_product( 'SKU-PLAIN', 25 );
		$order   = $this->make_order(
			[
				[ $managed, 4 ],
				[ $plain, 4 ],
			]
		);

		$order->update_status( 'processing' );

		// Read each line's WooCommerce stock-reduction marker.
		$managed_reduced = null;
		$plain_reduced   = null;
		foreach ( wc_get_order( $order->get_id() )->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			if ( $product->get_id() === $managed->get_id() ) {
				$managed_reduced = $item->get_meta( '_reduced_stock' );
			}
			if ( $product->get_id() === $plain->get_id() ) {
				$plain_reduced = $item->get_meta( '_reduced_stock' );
			}
		}

		// The managed line is skipped by WooCommerce; the store-only line is reduced.
		$this->assertEmpty( $managed_reduced, 'managed line should not be reduced by WooCommerce' );
		$this->assertEquals( 4, $plain_reduced, 'store-only line should be reduced by WooCommerce' );

		$this->assertSame( 21, $this->stock( $managed->get_id() ) ); // plugin formula: 25 - 4
		$this->assertSame( 21, $this->stock( $plain->get_id() ) );   // WooCommerce reduced: 25 - 4
	}
}

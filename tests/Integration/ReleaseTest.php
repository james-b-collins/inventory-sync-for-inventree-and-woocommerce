<?php
// this file contains integration tests for releasing stock when an order is cancelled or refunded
declare(strict_types=1);

namespace InvenTreeSync\Tests\Integration;

// this class tests that releasing stock when an order is cancelled or refunded correctly updates the WooCommerce stock and reservations.
final class ReleaseTest extends IntegrationTestCase {

	// test that cancelling an order releases all held stock and resets the pending quantity.
	public function test_cancelling_releases_everything(): void {
		$product = $this->make_managed_product( 'REL-1', 201, 25 );
		$order   = $this->make_order( [ [ $product, 3 ] ] );

		$order->update_status( 'processing' );
		$this->assertSame( 3, $this->pending( $product->get_id() ) );

		$order->update_status( 'cancelled' );

		$held = 0;
		foreach ( $this->reservations_for_order( $order->get_id() ) as $reservation ) {
			$held += (int) $reservation->held_qty;
		}
		$this->assertSame( 0, $held );
		$this->assertSame( 0, $this->pending( $product->get_id() ) );
		$this->assertSame( 25, $this->stock( $product->get_id() ) );
	}

	// test that partially refunding an order releases the correct amount of held stock and updates the pending quantity.
	public function test_partial_refund_releases_proportionally(): void {
		$product = $this->make_managed_product( 'REL-2', 202, 25 );
		$order   = $this->make_order( [ [ $product, 3 ] ] );

		$order->update_status( 'processing' );
		$this->assertSame( 22, $this->stock( $product->get_id() ) );

		$item_id = array_key_first( $order->get_items() );
		wc_create_refund(
			[
				'order_id'   => $order->get_id(),
				'line_items' => [ $item_id => [ 'qty' => 1, 'refund_total' => 0 ] ],
			]
		);

		$reservations = $this->reservations_for_order( $order->get_id() );
		$this->assertSame( 2, (int) $reservations[0]->held_qty );
		$this->assertSame( 2, $this->pending( $product->get_id() ) );
		$this->assertSame( 23, $this->stock( $product->get_id() ) );
	}
}

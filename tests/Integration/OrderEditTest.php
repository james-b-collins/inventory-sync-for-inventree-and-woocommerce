<?php
// this file contains integration tests for editing orders
declare(strict_types=1);

namespace InvenTreeSync\Tests\Integration;

use InvenTreeSync\Addons\AddonMap;
use InvenTreeSync\Addons\AddonReader;
use InvenTreeSync\Admin\Settings;
use InvenTreeSync\Catalogue\ProductWriter;
use InvenTreeSync\Orders\CommitService;
use InvenTreeSync\Orders\LineItemMapper;
use InvenTreeSync\Stock\PendingLedger;
use InvenTreeSync\Stock\ReservationStore;
use InvenTreeSync\Support\Logger;

// this class tests that editing an order in WooCommerce correctly updates the InvenTree reservations and stock.
final class OrderEditTest extends IntegrationTestCase {

	// creates a commit service with a fresh reservation store, for testing.
	private function commits(): CommitService {
		$store = new ReservationStore();
		return new CommitService(
			new Settings(),
			$store,
			new PendingLedger( $store ),
			new ProductWriter(),
			new AddonReader( new AddonMap(), new Logger() ),
			new Logger(),
			new LineItemMapper()
		);
	}

	// gets the first product line in an order, or fails the test if there are none.
	private function first_item( \WC_Order $order ): \WC_Order_Item_Product {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				return $item;
			}
		}
		$this->fail( 'order has no product line' );
	}

	// Change a line's quantity the way the admin order screen does
	private function set_line_quantity( \WC_Order $order, \WC_Order_Item_Product $item, int $quantity ): void {
		$item->set_quantity( $quantity );
		$item->save();
		$order->save();
	}

	// test that increasing a line's quantity correctly holds more stock.
	public function test_increasing_a_line_holds_more_stock(): void {
		$product = $this->make_managed_product( 'EDIT-1', 501, 20 );
		$order   = $this->make_order( [ [ $product, 2 ] ] );
		$order->update_status( 'processing' );

		$this->assertSame( 2, $this->pending( $product->get_id() ) );
		$this->assertSame( 18, $this->stock( $product->get_id() ) );

		$order = wc_get_order( $order->get_id() );
		$this->set_line_quantity( $order, $this->first_item( $order ), 5 );

		$deltas = $this->commits()->resync_order( wc_get_order( $order->get_id() ) );

		$this->assertSame( [ 501 => 3 ], $deltas );
		$this->assertSame( 5, $this->pending( $product->get_id() ) );
		$this->assertSame( 15, $this->stock( $product->get_id() ) );

		$reservations = $this->reservations_for_order( $order->get_id() );
		$this->assertCount( 1, $reservations, 'the line keeps its single reservation row' );
		$this->assertSame( 5, (int) $reservations[0]->committed_qty );
		$this->assertSame( 5, (int) $reservations[0]->held_qty );
	}

	// test that decreasing a line's quantity correctly releases stock back to the product.
	public function test_decreasing_a_line_gives_stock_back(): void {
		$product = $this->make_managed_product( 'EDIT-2', 502, 20 );
		$order   = $this->make_order( [ [ $product, 6 ] ] );
		$order->update_status( 'processing' );

		$this->assertSame( 14, $this->stock( $product->get_id() ) );

		$order = wc_get_order( $order->get_id() );
		$this->set_line_quantity( $order, $this->first_item( $order ), 2 );

		$deltas = $this->commits()->resync_order( wc_get_order( $order->get_id() ) );

		$this->assertSame( [ 502 => -4 ], $deltas );
		$this->assertSame( 2, $this->pending( $product->get_id() ) );
		$this->assertSame( 18, $this->stock( $product->get_id() ) );
	}

	// test that removing a line entirely releases its stock back to the product.
	public function test_removing_a_line_releases_it_entirely(): void {
		$product = $this->make_managed_product( 'EDIT-3', 503, 20 );
		$order   = $this->make_order( [ [ $product, 4 ] ] );
		$order->update_status( 'processing' );

		$this->assertSame( 16, $this->stock( $product->get_id() ) );

		$order = wc_get_order( $order->get_id() );
		$order->remove_item( $this->first_item( $order )->get_id() );
		$order->save();

		$deltas = $this->commits()->resync_order( wc_get_order( $order->get_id() ) );

		$this->assertSame( [ 503 => -4 ], $deltas );
		$this->assertSame( 0, $this->pending( $product->get_id() ) );
		$this->assertSame( 20, $this->stock( $product->get_id() ), 'stock must go back to the InvenTree figure' );
		$this->assertCount( 0, $this->reservations_for_order( $order->get_id() ) );
	}

	// test that adding a new line to an order correctly reserves stock for it.
	public function test_adding_a_line_reserves_it(): void {
		$first  = $this->make_managed_product( 'EDIT-4', 504, 20 );
		$second = $this->make_managed_product( 'EDIT-5', 505, 30 );
		$order  = $this->make_order( [ [ $first, 1 ] ] );
		$order->update_status( 'processing' );

		$order = wc_get_order( $order->get_id() );
		$order->add_product( $second, 3 );
		$order->save();

		$deltas = $this->commits()->resync_order( wc_get_order( $order->get_id() ) );

		$this->assertSame( [ 505 => 3 ], $deltas );
		$this->assertSame( 3, $this->pending( $second->get_id() ) );
		$this->assertSame( 27, $this->stock( $second->get_id() ) );
		$this->assertSame( 1, $this->pending( $first->get_id() ), 'the untouched line is left alone' );
	}

	// test that resyncing an order without any changes does not alter the reservations or stock.
	public function test_resync_without_changes_does_nothing(): void {
		$product = $this->make_managed_product( 'EDIT-6', 506, 20 );
		$order   = $this->make_order( [ [ $product, 3 ] ] );
		$order->update_status( 'processing' );

		$deltas = $this->commits()->resync_order( wc_get_order( $order->get_id() ) );

		$this->assertSame( [], $deltas );
		$this->assertSame( 3, $this->pending( $product->get_id() ) );
		$this->assertSame( 17, $this->stock( $product->get_id() ) );
	}

	// test that a partly released reservation keeps its release when the line is increased.
	public function test_a_partly_released_reservation_keeps_its_release(): void {
		$product = $this->make_managed_product( 'EDIT-7', 507, 20 );
		$order   = $this->make_order( [ [ $product, 5 ] ] );
		$order->update_status( 'processing' );

		$store       = new ReservationStore();
		$reservation = $this->reservations_for_order( $order->get_id() )[0];
		$store->set_held( (int) $reservation->id, 2 );
		( new PendingLedger( $store ) )->recompute( $product->get_id() );
		( new ProductWriter() )->materialise( $product->get_id() );
		$this->assertSame( 2, $this->pending( $product->get_id() ) );

		$order = wc_get_order( $order->get_id() );
		$this->set_line_quantity( $order, $this->first_item( $order ), 7 );
		$this->commits()->resync_order( wc_get_order( $order->get_id() ) );

		$updated = $this->reservations_for_order( $order->get_id() )[0];
		$this->assertSame( 7, (int) $updated->committed_qty );
		$this->assertSame( 4, (int) $updated->held_qty );
		$this->assertSame( 4, $this->pending( $product->get_id() ) );
	}

	// test that a line's held quantity never exceeds its new committed quantity when the line is decreased.
	public function test_held_never_exceeds_the_new_commitment(): void {
		$product = $this->make_managed_product( 'EDIT-8', 508, 20 );
		$order   = $this->make_order( [ [ $product, 5 ] ] );
		$order->update_status( 'processing' );

		$order = wc_get_order( $order->get_id() );
		$this->set_line_quantity( $order, $this->first_item( $order ), 1 );
		$this->commits()->resync_order( wc_get_order( $order->get_id() ) );

		$updated = $this->reservations_for_order( $order->get_id() )[0];
		$this->assertSame( 1, (int) $updated->committed_qty );
		$this->assertSame( 1, (int) $updated->held_qty );
		$this->assertSame( 19, $this->stock( $product->get_id() ) );
	}

	// test that a line for a product that is not managed by this plugin is ignored when resyncing an order.
	public function test_store_only_lines_are_ignored(): void {
		$managed = $this->make_managed_product( 'EDIT-9', 509, 20 );
		$plain   = $this->make_plain_product( 'EDIT-PLAIN', 20 );
		$order   = $this->make_order( [ [ $managed, 2 ] ] );
		$order->update_status( 'processing' );

		$order = wc_get_order( $order->get_id() );
		$order->add_product( $plain, 4 );
		$order->save();

		$deltas = $this->commits()->resync_order( wc_get_order( $order->get_id() ) );

		$this->assertSame( [], $deltas, 'an unmanaged line is not this plugin\'s business' );
		$this->assertCount( 1, $this->reservations_for_order( $order->get_id() ) );
	}
}

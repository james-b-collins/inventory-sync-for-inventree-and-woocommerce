<?php
// this file contains integration tests for reconciling pending stock
declare(strict_types=1);

namespace InvenTreeSync\Tests\Integration;

use InvenTreeSync\Support\Meta;

// this class tests that reconciling pending stock correctly updates the WooCommerce stock and reservations.
final class ReconcileTest extends IntegrationTestCase {

	// test that reconciling pending stock correctly updates the WooCommerce stock and reservations.
	public function test_reconcile_corrects_drifted_pending(): void {
		$product = $this->make_managed_product( 'REC-1', 301, 25 );
		$order   = $this->make_order( [ [ $product, 4 ] ] );
		$order->update_status( 'processing' );

		$this->assertSame( 4, $this->pending( $product->get_id() ) );

		update_post_meta( $product->get_id(), Meta::PENDING, 999 );
		do_action( 'inventree_reconcile_pending' );

		$this->assertSame( 4, $this->pending( $product->get_id() ) );
		$this->assertSame( 21, $this->stock( $product->get_id() ) );
	}
}

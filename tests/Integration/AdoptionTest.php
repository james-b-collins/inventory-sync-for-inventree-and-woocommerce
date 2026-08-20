<?php
// This file tests the adoption functionality
declare(strict_types=1);

namespace InvenTreeSync\Tests\Integration;

use InvenTreeSync\Catalogue\IdentityResolver;
use InvenTreeSync\Support\Meta;

// this class runs through the adoption functionality, including adopting a product and clearing any stale stock reduction markers.
final class AdoptionTest extends IntegrationTestCase {

	// test that adopting a product clears any stale stock reduction markers
	public function test_adoption_clears_pre_adoption_stock_reduction(): void {
		$product = $this->make_plain_product( 'ADOPT-1', 25 );
		$order   = $this->make_order( [ [ $product, 3 ] ] );
		$order->update_status( 'processing' );

		$item_id = array_key_first( $order->get_items() );
		$item    = wc_get_order( $order->get_id() )->get_item( $item_id );
		$this->assertNotEmpty( $item->get_meta( '_reduced_stock' ), 'WooCommerce should have reduced the un-managed line' );

		$resolution = ( new IdentityResolver() )->resolve( [ 'pk' => 701, 'IPN' => 'ADOPT-1' ] );

		$this->assertSame( IdentityResolver::MATCHED, $resolution['action'] );
		$this->assertSame( 701, (int) get_post_meta( $product->get_id(), Meta::PART_ID, true ) );

		$reloaded = wc_get_order( $order->get_id() )->get_item( $item_id );
		$this->assertEmpty( $reloaded->get_meta( '_reduced_stock' ), 'the stale reduction marker should be cleared on adoption' );
	}
}

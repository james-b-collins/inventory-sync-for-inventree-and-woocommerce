<?php
// this file contains integration tests for the behaviour of variable products and their variations
declare(strict_types=1);

namespace InvenTreeSync\Tests\Integration;

use InvenTreeSync\Catalogue\IdentityResolver;
use InvenTreeSync\Catalogue\ProductWriter;
use InvenTreeSync\Import\ImportScanner;
use InvenTreeSync\InvenTree\Client;
use InvenTreeSync\InvenTree\PartRepository;
use InvenTreeSync\Support\Meta;

// this class tests that variable products and their variations are correctly handled by the plugin, including adoption, stock management, and order processing.
final class VariationTest extends IntegrationTestCase {

	// creates an import scanner with a fake InvenTree client, for testing.
	private function scanner(): ImportScanner {
		return new ImportScanner( new PartRepository( new Client( 'http://inventree.test', 'token' ) ) );
	}

	// tests that a variation product is adopted by the plugin, and its parent variable product is not.
	public function test_a_part_adopts_the_variation_not_its_parent(): void {
		$parent    = $this->make_variable_parent( 'VAR-PARENT-1' );
		$variation = $this->make_variation( $parent, 'VAR-1-SMALL', 0, 12 );

		$resolution = ( new IdentityResolver() )->resolve( [ 'pk' => 601, 'IPN' => 'VAR-1-SMALL' ] );

		$this->assertSame( IdentityResolver::MATCHED, $resolution['action'] );
		$this->assertSame( $variation->get_id(), $resolution['product_id'] );
		$this->assertNotSame( $parent->get_id(), $resolution['product_id'] );
		$this->assertSame( 601, (int) get_post_meta( $variation->get_id(), Meta::PART_ID, true ) );
	}

	// tests that a variable parent product is never adopted by the plugin, even if it has no variations.
	public function test_a_variable_parent_is_never_adopted(): void {
		$parent = $this->make_variable_parent( 'VAR-PARENT-2' );
		$this->make_variation( $parent, 'VAR-2-SMALL', 0, 5 );

		$resolution = ( new IdentityResolver() )->resolve( [ 'pk' => 602, 'IPN' => 'VAR-PARENT-2' ] );

		$this->assertSame( IdentityResolver::SKIP, $resolution['action'] );
		$this->assertSame( '', (string) get_post_meta( $parent->get_id(), Meta::PART_ID, true ) );
	}

	// tests that an existing link between a variation and an InvenTree part ID correctly resolves the variation, even if no IPN is provided.
	public function test_an_existing_link_resolves_the_variation(): void {
		$parent    = $this->make_variable_parent( 'VAR-PARENT-3' );
		$variation = $this->make_variation( $parent, 'VAR-3-SMALL', 603, 8 );

		$resolution = ( new IdentityResolver() )->resolve( [ 'pk' => 603 ] );

		$this->assertSame( IdentityResolver::MATCHED, $resolution['action'] );
		$this->assertSame( $variation->get_id(), $resolution['product_id'] );
	}

	// tests that the plugin correctly writes stock onto a variation product, and updates the WooCommerce stock and meta data.
	public function test_stock_is_written_onto_the_variation(): void {
		$parent    = $this->make_variable_parent( 'VAR-PARENT-4' );
		$variation = $this->make_variation( $parent, 'VAR-4-SMALL', 604, 10 );

		$result = ( new ProductWriter() )->upsert( $variation->get_id(), [ 'pk' => 604 ], 17, 0 );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 17, $this->stock( $variation->get_id() ) );
		$this->assertSame( 17, (int) get_post_meta( $variation->get_id(), Meta::QTY, true ) );
	}

	// tests that the plugin correctly takes over a variation that was previously inheriting its stock from its parent variable product.
	public function test_a_variation_inheriting_parent_stock_is_taken_over(): void {
		$parent = $this->make_variable_parent( 'VAR-PARENT-5' );
		$parent->set_manage_stock( true );
		$parent->set_stock_quantity( 99 );
		$parent->save();

		$variation = $this->make_variation( $parent, 'VAR-5-SMALL', 605, 0 );
		$variation->set_manage_stock( false );
		$variation->save();

		$inheriting = wc_get_product( $variation->get_id() );
		$this->assertSame( 'parent', $inheriting->get_manage_stock(), 'precondition: the variation inherits' );
		$this->assertSame( 99, (int) $inheriting->get_stock_quantity(), 'precondition: it reports the parent figure' );

		( new ProductWriter() )->upsert( $variation->get_id(), [ 'pk' => 605 ], 14, 0 );

		$reloaded = wc_get_product( $variation->get_id() );
		$this->assertTrue( $reloaded->get_manage_stock(), 'the plugin must own the variation stock' );
		$this->assertSame( 14, (int) $reloaded->get_stock_quantity() );
	}

	// tests that committing a variation line holds its stock.
	public function test_committing_a_variation_line_holds_its_stock(): void {
		$parent    = $this->make_variable_parent( 'VAR-PARENT-6' );
		$variation = $this->make_variation( $parent, 'VAR-6-SMALL', 606, 20 );

		$order = $this->make_order( [ [ $variation, 3 ] ] );
		$order->update_status( 'processing' );

		$reservations = $this->reservations_for_order( $order->get_id() );
		$this->assertCount( 1, $reservations );
		$this->assertSame( $variation->get_id(), (int) $reservations[0]->product_id );
		$this->assertSame( 606, (int) $reservations[0]->part_id );

		$this->assertSame( 3, $this->pending( $variation->get_id() ) );
		$this->assertSame( 17, $this->stock( $variation->get_id() ) );
	}

	// tests that cancelling an order releases the stock held by a variation line.
	public function test_cancelling_releases_a_variation_line(): void {
		$parent    = $this->make_variable_parent( 'VAR-PARENT-7' );
		$variation = $this->make_variation( $parent, 'VAR-7-SMALL', 607, 20 );

		$order = $this->make_order( [ [ $variation, 5 ] ] );
		$order->update_status( 'processing' );
		$this->assertSame( 15, $this->stock( $variation->get_id() ) );

		$order->update_status( 'cancelled' );

		$this->assertSame( 0, $this->pending( $variation->get_id() ) );
		$this->assertSame( 20, $this->stock( $variation->get_id() ) );
	}

	// tests that the plugin correctly suppresses WooCommerce's own stock reduction on a variation line, so that stock is only reduced once.
	public function test_woocommerce_stock_reduction_is_suppressed_on_a_variation_line(): void {
		$parent    = $this->make_variable_parent( 'VAR-PARENT-8' );
		$variation = $this->make_variation( $parent, 'VAR-8-SMALL', 608, 20 );

		$order = $this->make_order( [ [ $variation, 4 ] ] );
		$order->update_status( 'processing' );

		foreach ( wc_get_order( $order->get_id() )->get_items() as $item ) {
			$this->assertEmpty(
				$item->get_meta( '_reduced_stock' ),
				'WooCommerce must not also reduce a line the plugin is holding'
			);
		}

		$this->assertSame( 16, $this->stock( $variation->get_id() ) );
	}

	// tests that two variations of the same parent variable product are independent, and holding stock on one does not affect the other.
	public function test_two_variations_of_one_parent_are_independent(): void {
		$parent = $this->make_variable_parent( 'VAR-PARENT-9' );
		$small  = $this->make_variation( $parent, 'VAR-9-SMALL', 609, 10, 'Small' );
		$large  = $this->make_variation( $parent, 'VAR-9-LARGE', 610, 10, 'Large' );

		$order = $this->make_order( [ [ $small, 2 ] ] );
		$order->update_status( 'processing' );

		$this->assertSame( 8, $this->stock( $small->get_id() ) );
		$this->assertSame( 10, $this->stock( $large->get_id() ), 'the sibling variation is untouched' );
		$this->assertSame( 0, $this->pending( $large->get_id() ) );
	}

	// tests that the import scanner correctly adopts a variation product and rejects its parent variable product.
	public function test_the_import_scanner_adopts_a_variation_and_rejects_the_parent(): void {
		$parent = $this->make_variable_parent( 'VAR-PARENT-10' );
		$small  = $this->make_variation( $parent, 'VAR-10-SMALL', 0, 4 );

		$scanner = $this->scanner();

		$variation_result = $scanner->classify( [ 'pk' => 611, 'IPN' => 'VAR-10-SMALL', 'active' => true, 'salable' => true ] );
		$this->assertSame( ImportScanner::STATUS_ADOPT, $variation_result['status'] );
		$this->assertSame( $small->get_id(), $variation_result['product_id'] );

		$parent_result = $scanner->classify( [ 'pk' => 612, 'IPN' => 'VAR-PARENT-10', 'active' => true, 'salable' => true ] );
		$this->assertSame( ImportScanner::STATUS_CONFLICT, $parent_result['status'] );
		$this->assertFalse( ImportScanner::is_importable( $parent_result['status'] ) );
	}
}

<?php
// This file tests the AddonReader class
declare(strict_types=1);

namespace InvenTreeSync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use InvenTreeSync\Addons\AddonMap;
use InvenTreeSync\Addons\AddonReader;
use InvenTreeSync\Support\Logger;
use Mockery;
use PHPUnit\Framework\TestCase;

// this class runs through the add-on reading functionality, including reading the selected add-ons from a line item and producing reservations for them.
// uses Brain Monkey to mock WordPress functions
final class AddonReaderTest extends TestCase {

	// set up brain monkey environment
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	// tear down brain monkey environment
	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	// create a mock order item with the given PAO IDs and quantity
	private function item( $pao_ids, int $qty ) {
		$item = Mockery::mock( 'WC_Order_Item_Product' );
		$item->shouldReceive( 'get_meta' )->with( '_pao_ids' )->andReturn( $pao_ids );
		$item->shouldReceive( 'get_quantity' )->andReturn( $qty );
		return $item;
	}

	// create an AddonReader backed by a fixed array of mappings
	private function reader( array $mappings ): AddonReader {
		Functions\when( 'get_option' )->justReturn( $mappings );
		return new AddonReader( new AddonMap(), new Logger() );
	}

	// return a stubbed set of products for the given SKU to product ID and product ID to part ID mappings
	private function stubProducts( array $sku_to_product_id, array $product_id_to_part ): void {
		Functions\when( 'wc_get_product_id_by_sku' )->alias(
			static fn( $sku ) => $sku_to_product_id[ $sku ] ?? 0
		);
		Functions\when( 'wc_get_product' )->alias(
			static function ( $id ) use ( $product_id_to_part ) {
				if ( ! isset( $product_id_to_part[ $id ] ) ) {
					return null;
				}
				$product = Mockery::mock( 'WC_Product' );
				$product->shouldReceive( 'get_meta' )->with( '_inventree_part_id' )->andReturn( $product_id_to_part[ $id ] );
				return $product;
			}
		);
	}

	// test that a selected mapped add-on produces a reservation with the correct quantity
	public function test_selected_mapped_addon_reserves_line_times_per_unit(): void {
		$this->stubProducts( [ 'TEST-101' => 27 ], [ 27 => 3 ] );
		$reader = $this->reader(
			[ [ 'name' => 'Add-on 1', 'value' => 'Yes', 'ipn' => 'TEST-101', 'qty' => 2 ] ]
		);

		$reservations = $reader->reservations_for(
			$this->item( [ [ 'key' => 'Add-on 1', 'value' => 'Yes', 'id' => '11' ] ], 3 )
		);

		$this->assertCount( 1, $reservations );
		$this->assertSame( 27, $reservations[0]['product_id'] );
		$this->assertSame( 3, $reservations[0]['part_id'] );
		$this->assertSame( 6, $reservations[0]['qty'] );
		$this->assertSame( '11', $reservations[0]['source_key'] );
	}

	// test that a selected mapped add-on with a quantity of zero is floored to one
	public function test_unselected_option_does_not_reserve(): void {
		$this->stubProducts( [ 'TEST-101' => 27 ], [ 27 => 3 ] );
		$reader = $this->reader(
			[ [ 'name' => 'Add-on 1', 'value' => 'Yes', 'ipn' => 'TEST-101', 'qty' => 1 ] ]
		);

		$reservations = $reader->reservations_for(
			$this->item( [ [ 'key' => 'Add-on 1', 'value' => 'No', 'id' => '11' ] ], 2 )
		);

		$this->assertSame( [], $reservations );
	}

	// test that a selected mapped add-on with a quantity of less than zero is floored to one
	public function test_unmapped_addon_is_ignored(): void {
		$this->stubProducts( [ 'TEST-101' => 27 ], [ 27 => 3 ] );
		$reader = $this->reader(
			[ [ 'name' => 'Add-on 1', 'value' => 'Yes', 'ipn' => 'TEST-101', 'qty' => 1 ] ]
		);

		$reservations = $reader->reservations_for(
			$this->item( [ [ 'key' => 'Add-on 2', 'value' => 'Yes', 'id' => '11' ] ], 2 )
		);

		$this->assertSame( [], $reservations );
	}

	// test that a selected mapped add-on with a quantity of less than zero is floored to one
	public function test_mapping_to_unmanaged_ipn_is_skipped(): void {
		// SKU resolves to no product at all.
		$this->stubProducts( [], [] );
		$reader = $this->reader(
			[ [ 'name' => 'Add-on 1', 'value' => 'Yes', 'ipn' => 'TEST-999', 'qty' => 1 ] ]
		);

		$reservations = $reader->reservations_for(
			$this->item( [ [ 'key' => 'Add-on 1', 'value' => 'Yes', 'id' => '11' ] ], 1 )
		);

		$this->assertSame( [], $reservations );
	}

	// test that a selected mapped add-on with a quantity of less than zero is floored to one
	public function test_no_pao_meta_yields_nothing(): void {
		$reader = $this->reader( [ [ 'name' => 'Add-on 1', 'value' => 'Yes', 'ipn' => 'TEST-101', 'qty' => 1 ] ] );
		$this->assertSame( [], $reader->reservations_for( $this->item( '', 2 ) ) );
	}
}

<?php
// This file tests the AddonMap class
declare(strict_types=1);

namespace InvenTreeSync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use InvenTreeSync\Addons\AddonMap;
use PHPUnit\Framework\TestCase;

// this class runs through the add-on mapping functionality, including adding, removing, and matching add-ons.
// uses Brain Monkey to mock WordPress functions
final class AddonMapTest extends TestCase {

	// set up brain monkey environment
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	// tear down brain monkey environment
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// create an AddonMap backed by a fixed array of mappings
	private function withMappings( array $mappings ): AddonMap {
		Functions\when( 'get_option' )->justReturn( $mappings );
		return new AddonMap();
	}

	// create an AddonMap backed by a writable array of mappings
	private function withWritableMappings( array &$mappings ): AddonMap {
		Functions\when( 'get_option' )->alias(
			static function () use ( &$mappings ) {
				return $mappings;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $option, $value ) use ( &$mappings ): bool {
				$mappings = $value;
				return true;
			}
		);
		return new AddonMap();
	}

	// test that a mapping with an exact name and value is matched correctly
	public function test_matches_exact_name_and_value(): void {
		$map = $this->withMappings(
			[
				[ 'name' => 'Add-on 1', 'value' => 'Yes', 'ipn' => 'TEST-101', 'qty' => 1 ],
			]
		);
		$hit = $map->match( 'Add-on 1', 'Yes' );
		$this->assertNotNull( $hit );
		$this->assertSame( 'TEST-101', $hit['ipn'] );
		$this->assertSame( 1, $hit['qty'] );
	}

	// test that a mapping with a name that differs only by case is matched correctly
	public function test_name_match_is_case_insensitive(): void {
		$map = $this->withMappings(
			[
				[ 'name' => 'Add-on 1', 'value' => '', 'ipn' => 'TEST-101', 'qty' => 2 ],
			]
		);
		$this->assertNotNull( $map->match( 'ADD-ON 1', 'anything' ) );
	}

	// test that a mapping with a value that differs only by case is matched correctly
	public function test_blank_mapping_value_matches_any_value(): void {
		$map = $this->withMappings(
			[
				[ 'name' => 'Add-on 2', 'value' => '', 'ipn' => 'TEST-102', 'qty' => 1 ],
			]
		);
		$this->assertNotNull( $map->match( 'Add-on 2', 'anything' ) );
	}

	// test that a mapping with a specific value does not match other values
	public function test_specific_value_does_not_match_other_values(): void {
		$map = $this->withMappings(
			[
				[ 'name' => 'Add-on 1', 'value' => 'Yes', 'ipn' => 'TEST-101', 'qty' => 1 ],
			]
		);
		$this->assertNull( $map->match( 'Add-on 1', 'No' ) );
	}

	// test that a mapping with no matching name returns null
	public function test_no_mapping_returns_null(): void {
		$map = $this->withMappings( [] );
		$this->assertNull( $map->match( 'Add-on 3', 'Yes' ) );
	}

	// test that a mapping with a quantity of zero is floored to one
	public function test_qty_floors_at_one(): void {
		$map = $this->withMappings(
			[
				[ 'name' => 'Add-on 4', 'value' => '', 'ipn' => 'TEST-103', 'qty' => 0 ],
			]
		);
		$this->assertSame( 1, $map->match( 'Add-on 4', '' )['qty'] );
	}

	// test that a mapping with a quantity of less than zero is floored to one
	public function test_add_appends_a_normalised_mapping(): void {
		$stored = [];
		$map    = $this->withWritableMappings( $stored );

		$this->assertTrue( $map->add( [ 'name' => ' Add-on 1 ', 'value' => 'Yes', 'ipn' => ' TEST-101 ', 'qty' => 0 ] ) );

		$this->assertCount( 1, $stored );
		$this->assertSame( 'Add-on 1', $stored[0]['name'] );
		$this->assertSame( 'TEST-101', $stored[0]['ipn'] );
		$this->assertSame( 1, $stored[0]['qty'] );
	}

	// test that a mapping with a duplicate name and value is rejected
	public function test_add_rejects_a_duplicate_name_and_value(): void {
		$stored = [];
		$map    = $this->withWritableMappings( $stored );

		$this->assertTrue( $map->add( [ 'name' => 'Add-on 1', 'value' => 'Yes', 'ipn' => 'TEST-101', 'qty' => 1 ] ) );
		$this->assertFalse( $map->add( [ 'name' => 'ADD-ON 1', 'value' => 'yes', 'ipn' => 'TEST-102', 'qty' => 1 ] ) );

		$this->assertCount( 1, $stored );
	}
	// test that a mapping with the same name but a different value is allowed
	public function test_add_allows_the_same_name_with_a_different_value(): void {
		$stored = [];
		$map    = $this->withWritableMappings( $stored );

		$this->assertTrue( $map->add( [ 'name' => 'Add-on 1', 'value' => 'Yes', 'ipn' => 'TEST-101', 'qty' => 1 ] ) );
		$this->assertTrue( $map->add( [ 'name' => 'Add-on 1', 'value' => 'No', 'ipn' => 'TEST-102', 'qty' => 1 ] ) );

		$this->assertCount( 2, $stored );
	}

	// test that removing a mapping drops the row and reindexes the array
	public function test_remove_drops_the_row_and_reindexes(): void {
		$stored = [];
		$map    = $this->withWritableMappings( $stored );

		$map->add( [ 'name' => 'Add-on 1', 'value' => '', 'ipn' => 'TEST-101', 'qty' => 1 ] );
		$map->add( [ 'name' => 'Add-on 2', 'value' => '', 'ipn' => 'TEST-102', 'qty' => 1 ] );

		$this->assertTrue( $map->remove( 0 ) );

		$this->assertCount( 1, $stored );
		$this->assertArrayHasKey( 0, $stored );
		$this->assertSame( 'Add-on 2', $stored[0]['name'] );
	}

	// test that removing a mapping with an index that is not present does nothing
	public function test_remove_ignores_an_index_that_is_not_there(): void {
		$stored = [];
		$map    = $this->withWritableMappings( $stored );

		$map->add( [ 'name' => 'Add-on 1', 'value' => '', 'ipn' => 'TEST-101', 'qty' => 1 ] );

		$this->assertFalse( $map->remove( 7 ) );
		$this->assertFalse( $map->remove( -1 ) );
		$this->assertCount( 1, $stored );
	}
}

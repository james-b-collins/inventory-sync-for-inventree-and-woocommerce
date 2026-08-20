<?php
// This file tests the PartRepository class
declare(strict_types=1);

namespace InvenTreeSync\Tests\Unit;

use InvenTreeSync\InvenTree\Client;
use InvenTreeSync\InvenTree\PartRepository;
use PHPUnit\Framework\TestCase;

// this class runs through the InvenTree part repository functionality, including availability mode selection and available quantity calculation.
final class PartRepositoryTest extends TestCase {

	// create a new PartRepository with a test client
	private function repo(): PartRepository {
		return new PartRepository( new Client( 'http://example.test', 'token' ) );
	}

	// test that the availability mode is determined correctly based on the presence of required and allocated fields
	public function test_demand_mode_when_required_fields_present(): void {
		$this->assertSame(
			PartRepository::MODE_DEMAND,
			$this->repo()->availability_mode( [ 'required_for_sales_orders' => 0, 'allocated_to_sales_orders' => 0 ] )
		);
	}

	// test that the availability mode is determined correctly when only allocated fields are present
	public function test_allocation_mode_when_only_allocated_present(): void {
		$this->assertSame(
			PartRepository::MODE_ALLOCATION,
			$this->repo()->availability_mode( [ 'allocated_to_sales_orders' => 0 ] )
		);
	}

	// test that the availability mode defaults to stock when neither required nor allocated fields are present
	public function test_stock_mode_when_neither_present(): void {
		$this->assertSame(
			PartRepository::MODE_STOCK,
			$this->repo()->availability_mode( [ 'in_stock' => 5 ] )
		);
	}

	// test that the demand formula subtracts required quantities from in_stock
	public function test_demand_formula_subtracts_required(): void {
		$available = $this->repo()->available_for(
			[
				'in_stock'                  => 10,
				'required_for_sales_orders' => 3,
				'required_for_build_orders' => 2,
			]
		);
		$this->assertSame( 5, $available );
	}

	// test that the allocation formula subtracts allocated quantities from in_stock
	public function test_allocation_formula_subtracts_allocated(): void {
		$available = $this->repo()->available_for(
			[
				'in_stock'                  => 10,
				'allocated_to_sales_orders' => 4,
				'allocated_to_build_orders' => 1,
			]
		);
		$this->assertSame( 5, $available );
	}

	// test that the stock formula returns in_stock when no required or allocated fields are present
	public function test_stock_fallback_uses_in_stock(): void {
		$this->assertSame( 7, $this->repo()->available_for( [ 'in_stock' => 7 ] ) );
	}

	// test that a fractional in_stock value is floored to the nearest integer
	public function test_fractional_stock_is_floored(): void {
		// Under-report rather than oversell.
		$available = $this->repo()->available_for(
			[
				'in_stock'                  => 12.5,
				'required_for_sales_orders' => 0,
				'required_for_build_orders' => 0,
			]
		);
		$this->assertSame( 12, $available );
	}

	// test that available quantity can be negative when oversold
	public function test_available_can_be_negative_when_oversold(): void {
		$available = $this->repo()->available_for(
			[
				'in_stock'                  => 1,
				'required_for_sales_orders' => 3,
				'required_for_build_orders' => 0,
			]
		);
		$this->assertSame( -2, $available );
	}

	// test that demand is preferred over allocation when both are present
	public function test_demand_preferred_over_allocation_when_both_present(): void {
		$available = $this->repo()->available_for(
			[
				'in_stock'                  => 10,
				'required_for_sales_orders' => 6,
				'required_for_build_orders' => 0,
				'allocated_to_sales_orders' => 1,
			]
		);
		$this->assertSame( 4, $available );
	}
}

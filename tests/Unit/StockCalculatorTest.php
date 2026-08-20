<?php
// This file tests the StockCalculator class
declare(strict_types=1);

namespace InvenTreeSync\Tests\Unit;

use InvenTreeSync\Stock\StockCalculator;
use PHPUnit\Framework\TestCase;

// this class runs through the stock calculation functionality, including subtracting pending quantities from available stock and ensuring that stock never goes negative.
final class StockCalculatorTest extends TestCase {

	// test that the stock calculation subtracts pending quantities from available stock
	public function test_subtracts_pending_from_qty(): void {
		$this->assertSame( 22, StockCalculator::stock( 25, 3 ) );
	}

	// test that the stock calculation floors at zero when pending quantities exceed available stock
	public function test_zero_when_pending_equals_qty(): void {
		$this->assertSame( 0, StockCalculator::stock( 5, 5 ) );
	}

	// test that the stock calculation floors at zero when pending quantities exceed available stock
	public function test_never_negative_when_pending_exceeds_qty(): void {
		// The core under-report bias: pending can never drive stock below zero.
		$this->assertSame( 0, StockCalculator::stock( 3, 5 ) );
	}

	// test that the stock calculation floors at zero when available stock is negative
	public function test_never_negative_when_qty_is_negative(): void {
		// available can be negative (upstream oversold); stock still floors at 0.
		$this->assertSame( 0, StockCalculator::stock( -4, 0 ) );
	}

	// test that the stock calculation returns zero when both available stock and pending quantities are zero
	public function test_zero_inputs(): void {
		$this->assertSame( 0, StockCalculator::stock( 0, 0 ) );
	}
}

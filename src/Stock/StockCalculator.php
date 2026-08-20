<?php

declare(strict_types=1);

namespace InvenTreeSync\Stock;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to calculate stock levels
final class StockCalculator {

	// Calculate the stock level for a product given the available and pending quantities
	public static function stock( int $available, int $pending ): int {
		$stock = $available - $pending;
		if ( $stock < 0 ) {
			return 0;
		}
		return $stock;
	}
}

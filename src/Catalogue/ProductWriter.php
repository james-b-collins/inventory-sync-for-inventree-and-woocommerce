<?php

declare(strict_types=1);

namespace InvenTreeSync\Catalogue;

use InvenTreeSync\Stock\StockCalculator;
use InvenTreeSync\Support\Meta;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// This class writes stock and meta data to WooCommerce products, ensuring that the stock is consistent with inventree
final class ProductWriter {

	// insert or update the given product with the given InvenTree part, available quantity, and pending quantity. Returns an array with 'product_id' and 'changed' (true if the product was changed).
	public function upsert( int $product_id, array $part, int $available, int $pending ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return [ 'product_id' => $product_id, 'changed' => false ];
		}

		$changed = false;

		// If the product is not managing its own stock, take over management and mark it as changed
		$took_over_stock = false;
		if ( ! self::manages_own_stock( $product ) ) {
			$product->set_manage_stock( true );
			$changed         = true;
			$took_over_stock = true;
		}

		// If the stored _inventree_qty is different from the given available quantity, update it and mark it as changed
		$stored_qty = $product->get_meta( Meta::QTY );
		if ( '' === $stored_qty || (int) $stored_qty !== $available ) {
			$product->update_meta_data( Meta::QTY, $available );
			$changed = true;
		}

		// If the stored _inventree_pending is different from the given pending quantity, update it and mark it as changed
		$target_stock  = StockCalculator::stock( $available, $pending );
		$current_stock = $product->get_stock_quantity();
		if ( $took_over_stock || null === $current_stock || (int) $current_stock !== $target_stock ) {
			$product->set_stock_quantity( $target_stock );
			$changed = true;
		}

		if ( $changed ) {
			$product->save();
		}

		return [ 'product_id' => $product_id, 'changed' => $changed ];
	}

	// Rebuild _stock from stored _inventree_qty and _inventree_pending
	public function materialise( int $product_id ): bool {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		$available    = (int) get_post_meta( $product_id, Meta::QTY, true );
		$pending      = (int) get_post_meta( $product_id, Meta::PENDING, true );
		$target_stock = StockCalculator::stock( $available, $pending );

		$manages_own_stock = self::manages_own_stock( $product );

		$current_stock = $product->get_stock_quantity();
		
		// If the product manages its own stock and the current stock is equal to the target stock, no update is needed
		if ( $manages_own_stock && null !== $current_stock && (int) $current_stock === $target_stock ) {
			return false;
		}
		// If the product does not manage its own stock, or the current stock is different from the target stock, update the stock and save the product
		if ( ! $manages_own_stock ) {
			$product->set_manage_stock( true );
		}
		$product->set_stock_quantity( $target_stock );
		$product->save();

		return true;
	}

	// Determine if the given product manages its own stock, returning true if it does, or false if it does not
	private static function manages_own_stock( \WC_Product $product ): bool {
		return true === $product->get_manage_stock();
	}
}

<?php

declare(strict_types=1);

namespace InvenTreeSync\Orders;

use InvenTreeSync\Support\Meta;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to map WooCommerce order line items to InvenTree parts
final class LineItemMapper {

	// Return the InvenTree part id for a WooCommerce order line item, or null if not mapped.
	public function managed_lines( \WC_Order $order ): array {
		$lines = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product || $product->is_type( 'variable' ) ) {
				continue;
			}

			$part_id = (int) $product->get_meta( Meta::PART_ID );
			if ( $part_id <= 0 ) {
				continue; // Store-only line.
			}

			$lines[] = [
				'item'       => $item,
				'product_id' => $product->get_id(),
				'part_id'    => $part_id,
			];
		}

		return $lines;
	}
}

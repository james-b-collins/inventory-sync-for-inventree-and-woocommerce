<?php

declare(strict_types=1);

namespace InvenTreeSync\Woo;

use InvenTreeSync\Support\Meta;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to suppress WooCommerce stock reduction during sync
final class StockWriterSuppressor {

	// Register the filter
	public function register(): void {
		add_filter( 'woocommerce_order_item_quantity', [ $this, 'filter_item_quantity' ], 10, 3 );
	}

	// filter the order item quantity to prevent stock reduction for products managed by the plugin
	public function filter_item_quantity( $quantity, \WC_Order $order, $item ) {
		if ( $item instanceof \WC_Order_Item_Product ) {
			$product = $item->get_product();
			if ( $product && (int) $product->get_meta( Meta::PART_ID ) > 0 ) {
				return 0;
			}
		}
		return $quantity;
	}
}

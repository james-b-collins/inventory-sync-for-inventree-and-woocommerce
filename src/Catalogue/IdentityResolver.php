<?php

declare(strict_types=1);

namespace InvenTreeSync\Catalogue;

use InvenTreeSync\Support\Meta;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// This class resolves the identity of a WooCommerce product to an InvenTree part, and vice versa
final class IdentityResolver {

	public const MATCHED = 'matched';
	public const CREATE  = 'create';
	public const SKIP    = 'skip';

	// Resolve the given InvenTree part to a WooCommerce product, returning an array with 'action' and 'product_id'
	public function resolve( array $part ): array {
		$part_id = (int) ( $part['pk'] ?? 0 );

		// First, try to find a WooCommerce product that has the InvenTree part ID in its meta
		$linked_product_id = ProductLookup::find_by_part_id( $part_id );
		if ( $linked_product_id > 0 ) {
			return $this->classify( $linked_product_id );
		}

		// Next, try to find a WooCommerce product that has the InvenTree part's IPN as its SKU
		$ipn = trim( (string) ( $part['IPN'] ?? '' ) );
		if ( '' !== $ipn ) {
			$product_id_by_sku = (int) wc_get_product_id_by_sku( $ipn );
			if ( $product_id_by_sku > 0 ) {
				$result = $this->classify( $product_id_by_sku );
				if ( self::MATCHED === $result['action'] ) {
					update_post_meta( $product_id_by_sku, Meta::PART_ID, $part_id );
					$this->clear_pre_adoption_reduction( $product_id_by_sku );
				}
				return $result;
			}
		}

		// Nothing matched, so a new product should be created for this part
		return [ 'action' => self::CREATE, 'product_id' => 0 ];
	}

	// Classify the given WooCommerce product ID, returning an array with 'action' and 'product_id'
	private function classify( int $product_id ): array {
		$product = wc_get_product( $product_id );
		// If the product does not exist, return CREATE with product_id 0
		if ( ! $product ) {
			return [ 'action' => self::CREATE, 'product_id' => 0 ];
		}
		// If the product is a managed type, return MATCHED with the product ID
		if ( ProductLookup::is_managed_type( $product ) ) {
			return [ 'action' => self::MATCHED, 'product_id' => $product_id ];
		}
		// If the product is not a managed type, return SKIP with the product ID
		return [ 'action' => self::SKIP, 'product_id' => $product_id ];
	}

	// Clear the _reduced_stock meta for all order items that have the given product ID, to prevent double-reduction of stock when a product is adopted
	public function clear_pre_adoption_reduction( int $product_id ): void {
		$orders = wc_get_orders(
			[
				'status' => [ 'processing', 'on-hold', 'pending' ],
				'limit'  => -1,
			]
		);

		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$product = $item->get_product();
				if ( ! $product || $product->get_id() !== $product_id ) {
					continue;
				}
				if ( '' !== (string) $item->get_meta( '_reduced_stock' ) ) {
					$item->delete_meta_data( '_reduced_stock' );
					$item->save();
				}
			}
		}
	}

}

<?php

declare(strict_types=1);

namespace InvenTreeSync\Catalogue;

use InvenTreeSync\Support\Meta;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// This class provides methods to look up WooCommerce products by InvenTree part ID or SKU, and to determine if a product is of a managed type (simple or variation).
final class ProductLookup {

	private const MANAGED_TYPES = [ 'simple', 'variation' ];

	public static function is_managed_type( \WC_Product $product ): bool {
		return in_array( $product->get_type(), self::MANAGED_TYPES, true );
	}

	// Find the first WooCommerce product ID that has the given InvenTree part ID in its meta, or 0 if none found
	public static function find_by_part_id( int $part_id ): int {
		if ( $part_id <= 0 ) {
			return 0;
		}

		return self::first_id(
			[
				'post_status' => 'any',
				'meta_key'    => Meta::PART_ID,
				'meta_value'  => (string) $part_id,
			]
		);
	}

	// Find if there is a trashed product that matches the given part ID or IPN, returning the first matching product ID, or 0 if none found
	public static function find_trashed( int $part_id, string $ipn ): int {
		$meta_query = [ 'relation' => 'OR' ];

		if ( $part_id > 0 ) {
			$meta_query[] = [
				'key'   => Meta::PART_ID,
				'value' => (string) $part_id,
			];
		}
		if ( '' !== $ipn ) {
			$meta_query[] = [
				'key'   => '_sku',
				'value' => $ipn,
			];
		}

		if ( 1 === count( $meta_query ) ) {
			return 0;
		}

		return self::first_id(
			[
				'post_status' => 'trash',
				'meta_query'  => $meta_query,
			]
		);
	}

	// Find the first WooCommerce product ID that matches the given query arguments, or 0 if none found
	private static function first_id( array $args ): int {
		$product_ids = get_posts(
			array_merge(
				$args,
				[
					'post_type'        => [ 'product', 'product_variation' ],
					'fields'           => 'ids',
					'numberposts'      => 1,
					'suppress_filters' => true,
				]
			)
		);

		if ( empty( $product_ids ) ) {
			return 0;
		}
		return (int) $product_ids[0];
	}
}

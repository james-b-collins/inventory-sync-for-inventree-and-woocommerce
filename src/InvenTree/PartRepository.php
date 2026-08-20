<?php

declare(strict_types=1);

namespace InvenTreeSync\InvenTree;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Class to fetch parts from the InvenTree API.
final class PartRepository {

	public const MODE_DEMAND     = 'demand';
	public const MODE_ALLOCATION = 'allocation';
	public const MODE_STOCK      = 'stock';

	public function __construct(private Client $client) {}

	// Fetch one page of active, salable parts.
	public function fetch_salable_page( int $limit, int $offset ): array {
		$response = $this->client->get(
			'part/',
			[
				'salable' => true,
				'active'  => true,
				'limit'   => $limit,
				'offset'  => $offset,
			]
		);

		if ( array_key_exists( 'results', $response ) ) {
			$results = $response['results'];
			if ( ! is_array( $results ) ) {
				$results = [];
			}
			return [
				'count'   => (int) ( $response['count'] ?? 0 ),
				'results' => $results,
			];
		}

		return [
			'count'   => count( $response ),
			'results' => $response,
		];
	}

	// Fetch the total count of active parts from the InvenTree API.
	public function fetch_active_count(): int {
		$response = $this->client->get(
			'part/',
			[
				'active' => true,
				'limit'  => 1,
			]
		);

		if ( array_key_exists( 'count', $response ) ) {
			return (int) $response['count'];
		}
		return count( $response );
	}


	// Fetch a single part by its ID from the InvenTree API, returning null if not found.
	public function fetch_part( int $part_id ): ?array {
		try {
			return $this->client->get( sprintf( 'part/%d/', $part_id ) );
		} catch ( NotFoundException ) {
			return null;
		}
	}

	// Determine the available quantity of a part based on its availability mode and stock levels.
	public function available_for( array $part ): int {
		$in_stock = (float) ( $part['in_stock'] ?? 0 );

		switch ( $this->availability_mode( $part ) ) {
			case self::MODE_DEMAND:
				$available = $in_stock
					- (float) ( $part['required_for_sales_orders'] ?? 0 )
					- (float) ( $part['required_for_build_orders'] ?? 0 );
				break;

			case self::MODE_ALLOCATION:
				$available = $in_stock
					- (float) ( $part['allocated_to_sales_orders'] ?? 0 )
					- (float) ( $part['allocated_to_build_orders'] ?? 0 );
				break;

			default:
				$available = $in_stock;
				break;
		}

		return (int) floor( $available );
	}

	// Determine the availability mode of a part based on its properties.
	public function availability_mode( array $part ): string {
		if ( array_key_exists( 'required_for_sales_orders', $part ) ) {
			return self::MODE_DEMAND;
		}
		if ( array_key_exists( 'allocated_to_sales_orders', $part ) ) {
			return self::MODE_ALLOCATION;
		}
		return self::MODE_STOCK;
	}
}

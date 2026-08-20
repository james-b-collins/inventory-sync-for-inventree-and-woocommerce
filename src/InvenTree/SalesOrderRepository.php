<?php

declare(strict_types=1);

namespace InvenTreeSync\InvenTree;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Class to create and manage sales orders in the InvenTree API.
final class SalesOrderRepository {

	private const CUSTOMER_NAME = 'WooCommerce Web Orders';

	private ?int $customer_id = null;

	public function __construct(private Client $client) {}

	// Create a sales order or return an existing one with the same reference
	public function create( array $lines, array $context ): int {
		$reference = $context['reference'];

		$existing = $this->find_by_customer_reference( $reference );
		if ( $existing > 0 ) {
			return $existing;
		}

		$order = $this->client->post(
			'order/so/',
			[
				'customer'           => $this->ensure_customer(),
				'customer_reference' => $reference,
				'description'        => $context['description'] ?? '',
			]
		);
		$sales_order_id = (int) $order['pk'];

		foreach ( $lines as $line ) {
			$this->client->post(
				'order/so-line/',
				[
					'order'    => $sales_order_id,
					'part'     => (int) $line['part'],
					'quantity' => $line['quantity'],
				]
			);
		}

		return $sales_order_id;
	}

	// Read the lines of a sales order, returning an array of part IDs and quantities.
	public function read_lines( int $sales_order_id ): array {
		$response = $this->client->get( 'order/so-line/', [ 'order' => $sales_order_id, 'limit' => 1000 ] );
		$rows     = $response['results'] ?? $response;

		$lines = [];
		foreach ( $rows as $row ) {
			$lines[] = [
				'part'     => (int) ( $row['part'] ?? 0 ),
				'quantity' => (float) ( $row['quantity'] ?? 0 ),
			];
		}

		return $lines;
	}

	// Cancel a sales order in the InvenTree API. This will also cancel all lines.
	public function cancel( int $sales_order_id ): void {
		$this->client->post( sprintf( 'order/so/%d/cancel/', $sales_order_id ), [] );
	}

	// Reduce the upstream quantity for a part, after a line was edited downwards
	public function reduce_for_part( int $sales_order_id, int $part_id, float $reduce_by ): void {
		if ( $reduce_by <= 0 ) {
			return;
		}

		$response = $this->client->get( 'order/so-line/', [ 'order' => $sales_order_id, 'part' => $part_id, 'limit' => 50 ] );
		$rows     = $response['results'] ?? $response;

		foreach ( $rows as $row ) {
			if ( $reduce_by <= 0 ) {
				break;
			}
			if ( (int) ( $row['part'] ?? 0 ) !== $part_id ) {
				continue;
			}

			$line_id          = (int) $row['pk'];
			$quantity         = (float) $row['quantity'];
			$quantity_to_take = min( $quantity, $reduce_by );
			$new_quantity     = $quantity - $quantity_to_take;

			if ( $new_quantity <= 0 ) {
				$this->client->delete( sprintf( 'order/so-line/%d/', $line_id ) );
			} else {
				$this->client->patch( sprintf( 'order/so-line/%d/', $line_id ), [ 'quantity' => $new_quantity ] );
			}

			$reduce_by -= $quantity_to_take;
		}
	}

	// increase the upstream quantity for a part, after a line was edited upwards or added
	public function increase_for_part( int $sales_order_id, int $part_id, float $increase_by ): void {
		if ( $increase_by <= 0 ) {
			return;
		}

		$response = $this->client->get( 'order/so-line/', [ 'order' => $sales_order_id, 'part' => $part_id, 'limit' => 50 ] );
		$rows     = $response['results'] ?? $response;

		foreach ( $rows as $row ) {
			if ( (int) ( $row['part'] ?? 0 ) !== $part_id ) {
				continue;
			}

			$this->client->patch(
				sprintf( 'order/so-line/%d/', (int) $row['pk'] ),
				[ 'quantity' => (float) $row['quantity'] + $increase_by ]
			);
			return;
		}

		$this->client->post(
			'order/so-line/',
			[
				'order'    => $sales_order_id,
				'part'     => $part_id,
				'quantity' => $increase_by,
			]
		);
	}

	// Find a sales order by its customer reference, returning the PK or 0 if not found.
	private function find_by_customer_reference( string $reference ): int {
		$response = $this->client->get( 'order/so/', [ 'customer_reference' => $reference, 'limit' => 5 ] );
		$rows     = $response['results'] ?? $response;

		foreach ( $rows as $row ) {
			// Verify the match explicitly in case the filter is ignored server-side.
			if ( isset( $row['customer_reference'] ) && $row['customer_reference'] === $reference ) {
				return (int) $row['pk'];
			}
		}

		return 0;
	}

	// Ensure that the customer for WooCommerce web orders exists in InvenTree, creating it if necessary.
	private function ensure_customer(): int {
		if ( null !== $this->customer_id ) {
			return $this->customer_id;
		}

		$response = $this->client->get( 'company/', [ 'is_customer' => true, 'search' => self::CUSTOMER_NAME, 'limit' => 100 ] );
		$rows     = $response['results'] ?? $response;

		foreach ( $rows as $row ) {
			if ( isset( $row['name'] ) && $row['name'] === self::CUSTOMER_NAME ) {
				$this->customer_id = (int) $row['pk'];
				return $this->customer_id;
			}
		}

		$company           = $this->client->post(
			'company/',
			[
				'name'        => self::CUSTOMER_NAME,
				'description' => 'Auto-created for WooCommerce web orders.',
				'is_customer' => true,
			]
		);
		$this->customer_id = (int) $company['pk'];

		return $this->customer_id;
	}
}

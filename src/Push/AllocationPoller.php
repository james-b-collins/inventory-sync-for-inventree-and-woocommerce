<?php

declare(strict_types=1);

namespace InvenTreeSync\Push;

use Closure;
use InvenTreeSync\Admin\Settings;
use InvenTreeSync\InvenTree\SalesOrderRepository;
use InvenTreeSync\Orders\ReleaseService;
use InvenTreeSync\Stock\ReservationStore;
use InvenTreeSync\Support\Logger;
use InvenTreeSync\Support\Meta;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to poll for upstream releases of held stock, and release them locally.
final class AllocationPoller {

	public function __construct(
		private ReservationStore $store,
		private Closure $sales_order_repository_factory,
		private Settings $settings,
		private ReleaseService $releases,
		private Logger $logger,
	) {}

	// Poll for upstream releases of held stock, and release them locally.
	public function poll(): void {
		// Get the sales order repository from the factory.
		$sales_order_repository = ( $this->sales_order_repository_factory )();
		if ( null === $sales_order_repository ) {
			return;
		}

		$orders = wc_get_orders(
			[
				'status' => $this->settings->committing_statuses(),
				'limit'  => -1,
			]
		);

		// Iterate over all orders that are in a committing status.
		foreach ( $orders as $order ) {
			if ( 'yes' === (string) $order->get_meta( Meta::ORDER_RELEASED ) ) {
				continue;
			}

			$sales_order_id = (int) $order->get_meta( Meta::ORDER_SALES_ORDER_ID );
			if ( $sales_order_id <= 0 ) {
				continue; // Not pushed upstream yet.
			}

			try {
				$upstream_by_part = $this->upstream_quantities( $sales_order_repository, $sales_order_id );
			} catch ( \Throwable $exception ) {
				$this->logger->warning( 'Poll: could not read sales order.', [ 'so' => $sales_order_id, 'error' => $exception->getMessage() ] );
				continue;
			}

			$this->release_matched( $order, $upstream_by_part );
		}
	}

	// Release any reservations that are fully covered by upstream sales order lines.
	private function release_matched( \WC_Order $order, array $upstream_by_part ): void {
		$all_released = true;

		foreach ( $this->store->for_order( $order->get_id() ) as $reservation ) {
			$held_quantity = (int) $reservation->held_qty;
			if ( $held_quantity <= 0 ) {
				continue;
			}

			// Check if the upstream sales order has enough quantity to cover the held quantity.
			$upstream_quantity = $upstream_by_part[ (int) $reservation->part_id ] ?? 0;
			if ( $upstream_quantity >= $held_quantity ) {
				$this->releases->release_reservation( $reservation, $held_quantity );
			} else {
				$all_released = false;
			}
		}

		if ( $all_released ) {
			$order->update_meta_data( Meta::ORDER_RELEASED, 'yes' );
			$order->save();
		}
	}

	// Get the total quantities of each part in the upstream sales order.
	private function upstream_quantities( SalesOrderRepository $sales_order_repository, int $sales_order_id ): array {
		$totals_by_part = [];
		foreach ( $sales_order_repository->read_lines( $sales_order_id ) as $line ) {
			$part_id = $line['part'];
			if ( ! isset( $totals_by_part[ $part_id ] ) ) {
				$totals_by_part[ $part_id ] = 0;
			}
			$totals_by_part[ $part_id ] += $line['quantity'];
		}
		return $totals_by_part;
	}
}

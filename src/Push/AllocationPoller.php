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

		// Only orders that still hold stock are worth polling. Listing every order in a
		// committing status instead grows with the order history, and on a real store it
		// eventually cannot finish inside the time Action Scheduler allows.
		$order_ids = [];
		foreach ( $this->store->all_held() as $reservation ) {
			$order_ids[ (int) $reservation->order_id ] = true;
		}

		foreach ( array_keys( $order_ids ) as $order_id ) {
			// One bad order must not abort the run. A recurring action that fails is not
			// rescheduled, so throwing here would stop every future release.
			try {
				$this->poll_order( $sales_order_repository, (int) $order_id );
			} catch ( \Throwable $exception ) {
				$this->logger->error(
					'Poll failed for an order.',
					[ 'order' => $order_id, 'error' => $exception->getMessage() ]
				);
			}
		}
	}

	// Release whatever the upstream sales order now covers for one order.
	private function poll_order( SalesOrderRepository $sales_order_repository, int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( ! in_array( $order->get_status(), $this->settings->committing_statuses(), true ) ) {
			return;
		}

		$sales_order_id = (int) $order->get_meta( Meta::ORDER_SALES_ORDER_ID );
		if ( $sales_order_id <= 0 ) {
			return; // Not pushed upstream yet.
		}

		try {
			$upstream_by_part = $this->upstream_quantities( $sales_order_repository, $sales_order_id );
		} catch ( \Throwable $exception ) {
			$this->logger->warning( 'Poll: could not read sales order.', [ 'so' => $sales_order_id, 'error' => $exception->getMessage() ] );
			return;
		}

		$this->release_matched( $order, $upstream_by_part );
	}

	// Release held stock as far as the upstream sales order lines account for it.
	private function release_matched( \WC_Order $order, array $upstream_by_part ): void {
		$all_released = true;

		foreach ( $this->store->for_order( $order->get_id() ) as $reservation ) {
			$held_quantity = (int) $reservation->held_qty;
			if ( $held_quantity <= 0 ) {
				continue;
			}

			// Determine how much of this part is available upstream to release against.
			$part_id           = (int) $reservation->part_id;
			$upstream_quantity = $upstream_by_part[ $part_id ] ?? 0;
			if ( $upstream_quantity <= 0 ) {
				$all_released = false;
				continue;
			}

			// Release as much as the budget covers
			$released = $this->releases->release_reservation(
				$reservation,
				(int) min( $held_quantity, $upstream_quantity )
			);

			$upstream_by_part[ $part_id ] = $upstream_quantity - $released;

			if ( $released < $held_quantity ) {
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
			$part_id = (int) $line['part'];
			if ( ! isset( $totals_by_part[ $part_id ] ) ) {
				$totals_by_part[ $part_id ] = 0.0;
			}
			$totals_by_part[ $part_id ] += (float) $line['quantity'];
		}

		return array_map( 'intval', $totals_by_part );
	}
}

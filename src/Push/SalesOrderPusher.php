<?php

declare(strict_types=1);

namespace InvenTreeSync\Push;

use Closure;
use InvenTreeSync\InvenTree\ClientException;
use InvenTreeSync\InvenTree\SalesOrderRepository;
use InvenTreeSync\Stock\ReservationStore;
use InvenTreeSync\Support\Logger;
use InvenTreeSync\Support\Meta;

if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to push WooCommerce orders to InvenTree as sales orders.
final class SalesOrderPusher {

	public function __construct(
		private ReservationStore $store,
		private Closure $sales_order_repository_factory,
		private Logger $logger,
	) {}

	// Push a WooCommerce order to InvenTree as a sales order.
	public function push( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( (int) $order->get_meta( Meta::ORDER_SALES_ORDER_ID ) > 0 ) {
			return; // Already pushed.
		}

		// Get the total quantities of each part in the order.
		$quantity_per_part = [];
		foreach ( $this->store->for_order( $order_id ) as $reservation ) {
			$part_id = (int) $reservation->part_id;
			if ( $part_id <= 0 ) {
				continue;
			}
			if ( ! isset( $quantity_per_part[ $part_id ] ) ) {
				$quantity_per_part[ $part_id ] = 0;
			}
			$quantity_per_part[ $part_id ] += (int) $reservation->committed_qty;
		}

		if ( empty( $quantity_per_part ) ) {
			return; // Nothing managed to push.
		}

		$lines = [];
		foreach ( $quantity_per_part as $part_id => $quantity ) {
			$lines[] = [ 'part' => $part_id, 'quantity' => $quantity ];
		}

		$sales_order_repository = ( $this->sales_order_repository_factory )();
		if ( null === $sales_order_repository ) {
			$this->logger->warning( 'Push skipped: InvenTree not configured.', [ 'order' => $order_id ] );
			return;
		}

		try {
			$sales_order_id = $sales_order_repository->create(
				$lines,
				[
					'reference'   => 'WC-' . $order_id,
					'description' => 'WooCommerce order ' . $order->get_order_number(),
				]
			);
		}

		catch ( ClientException $exception ) {
			$this->logger->error( 'Sales order push failed; will retry.', [ 'order' => $order_id, 'error' => $exception->getMessage() ] );
			throw $exception;
		}

		$order->update_meta_data( Meta::ORDER_SALES_ORDER_ID, $sales_order_id );
		$order->save();

		$this->logger->info( 'Sales order created.', [ 'order' => $order_id, 'so' => $sales_order_id, 'lines' => count( $lines ) ] );
	}
}

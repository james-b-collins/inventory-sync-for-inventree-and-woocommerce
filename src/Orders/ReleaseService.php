<?php

declare(strict_types=1);

namespace InvenTreeSync\Orders;

use Closure;
use InvenTreeSync\Admin\Settings;
use InvenTreeSync\Catalogue\ProductWriter;
use InvenTreeSync\InvenTree\SalesOrderRepository;
use InvenTreeSync\Stock\PendingLedger;
use InvenTreeSync\Stock\ReservationStore;
use InvenTreeSync\Support\Logger;
use InvenTreeSync\Support\Meta;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

final class ReleaseService {

	public function __construct(
		private Settings $settings,
		private ReservationStore $store,
		private PendingLedger $pending,
		private ProductWriter $writer,
		private Closure $sales_order_repository_factory,
		private Logger $logger,
	) {}

	// Release a quantity of a stock reservation, and update the corresponding InvenTree sales order line
	public function release_reservation( object $reservation, int $quantity ): int {
		$held_quantity = (int) $reservation->held_qty;

		$quantity_to_release = $quantity;
		if ( $quantity_to_release > $held_quantity ) {
			$quantity_to_release = $held_quantity;
		}
		if ( $quantity_to_release <= 0 ) {
			return 0;
		}

		$remaining_held = $held_quantity - $quantity_to_release;
		$this->store->set_held( (int) $reservation->id, $remaining_held );

		// Only update the upstream sales order if the setting is enabled
		if ( $this->settings->reserves_stock() ) {
			$product_id = (int) $reservation->product_id;
			$this->pending->recompute( $product_id );
			$this->writer->materialise( $product_id );
		}

		return $quantity_to_release;
	}

	// Release all outstanding reservations and update the corresponding InvenTree sales order lines
	public function release_all_outstanding(): void {
		$held_reservations   = $this->store->all_held();
		$affected_product_ids = [];

		foreach ( $held_reservations as $reservation ) {
			$this->store->set_held( (int) $reservation->id, 0 );
			$affected_product_ids[ (int) $reservation->product_id ] = true;
		}

		if ( empty( $affected_product_ids ) ) {
			return;
		}

		foreach ( array_keys( $affected_product_ids ) as $product_id ) {
			$this->pending->recompute( $product_id );

			// Only materialise the product if the setting is enabled
			if ( $this->settings->mirror_inventory_setting() ) {
				$this->writer->materialise( $product_id );
			}
		}

		$this->logger->info(
			'Released all outstanding reservations.',
			[ 'reservations' => count( $held_reservations ), 'products' => count( $affected_product_ids ) ]
		);
	}

	// Release all reservations for a specific order and update the corresponding InvenTree sales order lines
	public function release_order_full( \WC_Order $order ): void {
		// Refresh the order object to ensure the latest data, in case it was modified during the status change
		$fresh_order = wc_get_order( $order->get_id() );
		if ( $fresh_order ) {
			$order = $fresh_order;
		}

		// Release all reservations for the order
		foreach ( $this->store->for_order( $order->get_id() ) as $reservation ) {
			$held_quantity = (int) $reservation->held_qty;
			if ( $held_quantity > 0 ) {
				$this->release_reservation( $reservation, $held_quantity );
			}
		}

		$this->cancel_upstream( $order );

		$order->update_meta_data( Meta::ORDER_RELEASED, 'yes' );
		$order->save();

		$this->logger->info( 'Released order.', [ 'order' => $order->get_id() ] );
	}

	// Cancel the upstream InvenTree sales order corresponding to a WooCommerce order
	private function cancel_upstream( \WC_Order $order ): void {
		$sales_order_id = (int) $order->get_meta( Meta::ORDER_SALES_ORDER_ID );
		if ( $sales_order_id <= 0 ) {
			return;
		}

		$sales_order_repository = ( $this->sales_order_repository_factory )();
		if ( null === $sales_order_repository ) {
			return;
		}

		try {
			$sales_order_repository->cancel( $sales_order_id );
		} catch ( \Throwable $exception ) {
			$this->logger->error(
				'Failed to cancel InvenTree sales order.',
				[ 'so' => $sales_order_id, 'error' => $exception->getMessage() ]
			);
		}
	}
}

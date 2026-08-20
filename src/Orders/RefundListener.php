<?php

declare(strict_types=1);

namespace InvenTreeSync\Orders;

use Closure;
use InvenTreeSync\InvenTree\SalesOrderRepository;
use InvenTreeSync\Stock\ReservationStore;
use InvenTreeSync\Support\Logger;
use InvenTreeSync\Support\Meta;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to listen for order refunds and release stock reservations
final class RefundListener {

	public function __construct(
		private ReservationStore $store,
		private ReleaseService $releases,
		private Closure $sales_order_repository_factory,
		private Logger $logger,
	) {}

	// Register the listener to the WooCommerce hook
	public function register(): void {
		add_action( 'woocommerce_order_refunded', [ $this, 'on_refund' ], 10, 2 );
	}

	// Callback for the WooCommerce hook when an order is refunded
	public function on_refund( int $order_id, int $refund_id ): void {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund instanceof \WC_Order_Refund ) {
			return;
		}

		$sales_order_id = (int) $order->get_meta( Meta::ORDER_SALES_ORDER_ID );

		// Release the reservations for each refunded item and reduce the corresponding sales order lines in InvenTree
		foreach ( $refund->get_items() as $refund_item ) {
			if ( ! $refund_item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$original_item_id  = (int) $refund_item->get_meta( '_refunded_item_id' );
			$refunded_quantity = (int) round( abs( (float) $refund_item->get_quantity() ) );
			if ( $original_item_id <= 0 || $refunded_quantity <= 0 ) {
				continue;
			}

			$reservations = $this->store->for_item( $original_item_id );

			// Determine the base committed quantity for the original item, to calculate the share of each reservation to release
			$base_committed = 0;
			foreach ( $reservations as $reservation ) {
				if ( ReservationStore::SOURCE_LINE === $reservation->source ) {
					$committed = (int) $reservation->committed_qty;
					if ( $committed > $base_committed ) {
						$base_committed = $committed;
					}
				}
			}
			if ( $base_committed <= 0 ) {
				continue;
			}

			// Release the share of each reservation corresponding to the refunded quantity, and reduce the upstream sales order line
			foreach ( $reservations as $reservation ) {
				$share_to_release = (int) round( (int) $reservation->committed_qty * $refunded_quantity / $base_committed );
				if ( $share_to_release <= 0 ) {
					continue;
				}

				$released = $this->releases->release_reservation( $reservation, $share_to_release );
				if ( $released > 0 ) {
					$this->reduce_upstream( $sales_order_id, (int) $reservation->part_id, $released );
				}
			}
		}
	}

	// Reduce the quantity of a part in the upstream InvenTree sales order
	private function reduce_upstream( int $sales_order_id, int $part_id, int $quantity ): void {
		if ( $sales_order_id <= 0 || $part_id <= 0 ) {
			return;
		}

		$sales_order_repository = ( $this->sales_order_repository_factory )();
		if ( null === $sales_order_repository ) {
			return;
		}

		try {
			$sales_order_repository->reduce_for_part( $sales_order_id, $part_id, $quantity );
		} catch ( \Throwable $exception ) {
			$this->logger->error(
				'Failed to reduce sales-order line on refund.',
				[ 'so' => $sales_order_id, 'part' => $part_id, 'error' => $exception->getMessage() ]
			);
		}
	}
}

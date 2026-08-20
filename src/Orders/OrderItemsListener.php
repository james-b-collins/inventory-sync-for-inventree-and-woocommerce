<?php

declare(strict_types=1);

namespace InvenTreeSync\Orders;

use Closure;
use InvenTreeSync\Admin\Settings;
use InvenTreeSync\InvenTree\SalesOrderRepository;
use InvenTreeSync\Support\Logger;
use InvenTreeSync\Support\Meta;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}


// Class to listen for order item changes and re-sync the reservations
final class OrderItemsListener {

	public function __construct(
		private Settings $settings,
		private CommitService $commits,
		private Closure $sales_order_repository_factory,
		private Logger $logger,
	) {}

	// Register the listener to the WooCommerce hook
	public function register(): void {
		add_action( 'woocommerce_saved_order_items', [ $this, 'on_items_saved' ], 10, 1 );
	}

	// Callback for the WooCommerce hook when order items are saved
	public function on_items_saved( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( ! in_array( $order->get_status(), $this->settings->committing_statuses(), true ) ) {
			return;
		}

		$part_deltas = $this->commits->resync_order( $order );
		if ( empty( $part_deltas ) ) {
			return;
		}

		$this->apply_upstream( $order, $part_deltas );
	}

	// Apply the part deltas to the upstream InvenTree sales order
	private function apply_upstream( \WC_Order $order, array $part_deltas ): void {
		$sales_order_id = (int) $order->get_meta( Meta::ORDER_SALES_ORDER_ID );
		if ( $sales_order_id <= 0 ) {
			return;
		}

		// Get the sales order repository from the factory
		// If the factory returns null, InvenTree is not configured, so nothing can be done.
		$sales_order_repository = ( $this->sales_order_repository_factory )();
		if ( null === $sales_order_repository ) {
			$this->logger->warning(
				'Order edited but InvenTree is not configured; the sales order still has the old quantities.',
				[ 'order' => $order->get_id() ]
			);
			return;
		}

		// Apply the part deltas to the sales order lines in InvenTree
		foreach ( $part_deltas as $part_id => $change ) {
			try {
				if ( $change > 0 ) {
					$sales_order_repository->increase_for_part( $sales_order_id, $part_id, $change );
				} else {
					$sales_order_repository->reduce_for_part( $sales_order_id, $part_id, abs( $change ) );
				}
			} 
			// Log an error if the sales order line could not be adjusted
			catch ( \Throwable $exception ) {
				$this->logger->error(
					'Order edited but the sales order line could not be adjusted. Correct it in InvenTree.',
					[
						'order' => $order->get_id(),
						'so'    => $sales_order_id,
						'part'  => $part_id,
						'delta' => $change,
						'error' => $exception->getMessage(),
					]
				);
			}
		}
	}
}

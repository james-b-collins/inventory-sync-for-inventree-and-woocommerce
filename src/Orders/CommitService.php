<?php

declare(strict_types=1);

namespace InvenTreeSync\Orders;

use InvenTreeSync\Addons\AddonReader;
use InvenTreeSync\Admin\Settings;
use InvenTreeSync\Catalogue\ProductWriter;
use InvenTreeSync\Scheduling\Scheduler;
use InvenTreeSync\Stock\PendingLedger;
use InvenTreeSync\Stock\ReservationStore;
use InvenTreeSync\Support\Logger;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to manage the commit of stock reservations for an order
final class CommitService {

	public function __construct(
		private Settings $settings,
		private ReservationStore $store,
		private PendingLedger $pending,
		private ProductWriter $writer,
		private AddonReader $addons,
		private Logger $logger,
		private ?LineItemMapper $mapper = null,
	) {
		if ( null === $this->mapper ) {
			$this->mapper = new LineItemMapper();
		}
	}

	// Re-sync the reservations for an order, based on its current line items.
	public function resync_order( \WC_Order $order ): array {
		$order_id = $order->get_id();

		$desired  = $this->desired_reservations( $order );
		$existing = [];
		foreach ( $this->store->for_order( $order_id ) as $reservation ) {
			$existing[ $this->reservation_key(
				(int) $reservation->order_item_id,
				(int) $reservation->product_id,
				(string) $reservation->source_key
			) ] = $reservation;
		}

		$part_deltas         = [];
		$touched_product_ids = [];

		foreach ( $existing as $key => $reservation ) {
			if ( isset( $desired[ $key ] ) ) {
				continue;
			}
			$this->store->remove( (int) $reservation->id );
			$this->record_delta( $part_deltas, (int) $reservation->part_id, -(int) $reservation->committed_qty );
			$touched_product_ids[ (int) $reservation->product_id ] = true;
		}

		// Add or update reservations for the desired lines
		foreach ( $desired as $key => $line ) {
			// If the reservation does not exist, add it
			if ( ! isset( $existing[ $key ] ) ) {
				$this->store->add(
					$order_id,
					$line['item_id'],
					$line['product_id'],
					$line['part_id'],
					$line['source'],
					$line['source_key'],
					$line['qty']
				);
				$this->record_delta( $part_deltas, $line['part_id'], $line['qty'] );
				$touched_product_ids[ $line['product_id'] ] = true;
				continue;
			}

			$reservation   = $existing[ $key ];
			$old_committed = (int) $reservation->committed_qty;
			$change        = $line['qty'] - $old_committed;
			if ( 0 === $change ) {
				continue;
			}

			// Update the reservation to the new quantity
			$this->store->update_quantities(
				(int) $reservation->id,
				$line['qty'],
				(int) $reservation->held_qty + $change
			);
			$this->record_delta( $part_deltas, $line['part_id'], $change );
			$touched_product_ids[ $line['product_id'] ] = true;
		}

		if ( empty( $touched_product_ids ) ) {
			return [];
		}

		if ( $this->settings->reserves_stock() ) {
			foreach ( array_keys( $touched_product_ids ) as $touched_product_id ) {
				$this->pending->recompute( $touched_product_id );
				$this->writer->materialise( $touched_product_id );
			}
		}

		$this->logger->info(
			'Order edited; reservations resynced.',
			[ 'order' => $order_id, 'parts_changed' => count( $part_deltas ) ]
		);

		return $part_deltas;
	}

	// Return the desired reservations for an order, based on its current line items.
	private function desired_reservations( \WC_Order $order ): array {
		$desired = [];

		foreach ( $this->mapper->managed_lines( $order ) as $line ) {
			$item     = $line['item'];
			$item_id  = (int) $item->get_id();
			$quantity = (int) $item->get_quantity();
			if ( $quantity <= 0 ) {
				continue;
			}

			$desired[ $this->reservation_key( $item_id, $line['product_id'], '' ) ] = [
				'item_id'    => $item_id,
				'product_id' => $line['product_id'],
				'part_id'    => $line['part_id'],
				'source'     => ReservationStore::SOURCE_LINE,
				'source_key' => '',
				'qty'        => $quantity,
			];

			foreach ( $this->addons->reservations_for( $item ) as $addon ) {
				$desired[ $this->reservation_key( $item_id, $addon['product_id'], $addon['source_key'] ) ] = [
					'item_id'    => $item_id,
					'product_id' => $addon['product_id'],
					'part_id'    => $addon['part_id'],
					'source'     => ReservationStore::SOURCE_ADDON,
					'source_key' => $addon['source_key'],
					'qty'        => $addon['qty'],
				];
			}
		}

		return $desired;
	}

	// Return a unique key for a reservation, based on the item id, product id, and source key.
	private function reservation_key( int $item_id, int $product_id, string $source_key ): string {
		return $item_id . '|' . $product_id . '|' . $source_key;
	}

	// Record the change in committed quantity for a part in the part_deltas array.
	private function record_delta( array &$part_deltas, int $part_id, int $change ): void {
		if ( $part_id <= 0 || 0 === $change ) {
			return;
		}
		if ( ! isset( $part_deltas[ $part_id ] ) ) {
			$part_deltas[ $part_id ] = 0;
		}
		$part_deltas[ $part_id ] += $change;
	}

	// Commit a single order line
	public function commit_line( \WC_Order $order, \WC_Order_Item_Product $item, int $product_id, int $part_id ): bool {
		$quantity = (int) $item->get_quantity();
		if ( $quantity <= 0 ) {
			return false;
		}

		$order_id = $order->get_id();
		$item_id  = $item->get_id();

		// Track which products have been touched so they can be recomputed
		$touched_product_ids = [];

		if ( $this->store->add( $order_id, $item_id, $product_id, $part_id, ReservationStore::SOURCE_LINE, '', $quantity ) ) {
			$touched_product_ids[ $product_id ] = true;
		}

		// Add any addon reservations for this line item
		foreach ( $this->addons->reservations_for( $item ) as $addon ) {
			$added = $this->store->add(
				$order_id,
				$item_id,
				$addon['product_id'],
				$addon['part_id'],
				ReservationStore::SOURCE_ADDON,
				$addon['source_key'],
				$addon['qty']
			);
			if ( $added ) {
				$touched_product_ids[ $addon['product_id'] ] = true;
			}
		}

		// Recompute the pending ledger and materialise the product if stock is reserved
		if ( $this->settings->reserves_stock() ) {
			foreach ( array_keys( $touched_product_ids ) as $touched_product_id ) {
				$this->pending->recompute( $touched_product_id );
				$this->writer->materialise( $touched_product_id );
			}
		}

		// Log the committed order line if any products were touched
		if ( ! empty( $touched_product_ids ) ) {
			$this->logger->info(
				'Committed order line.',
				[ 'order' => $order_id, 'product' => $product_id, 'part' => $part_id, 'qty' => $quantity ]
			);
		}

		// Schedule an async action to push the order if any products were touched
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				Scheduler::PUSH_ORDER,
				[ 'order_id' => $order_id ],
				Scheduler::GROUP,
				true
			);
		}

		return ! empty( $touched_product_ids );
	}
}

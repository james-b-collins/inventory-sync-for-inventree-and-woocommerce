<?php

declare(strict_types=1);

namespace InvenTreeSync\Push;

use InvenTreeSync\Catalogue\ProductWriter;
use InvenTreeSync\Stock\PendingLedger;
use InvenTreeSync\Stock\ReservationStore;
use InvenTreeSync\Support\Logger;
use InvenTreeSync\Support\Meta;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to reconcile pending stock for managed products.
final class PendingReconciler {

	public function __construct(
		private ReservationStore $store,
		private PendingLedger $pending,
		private ProductWriter $writer,
		private Logger $logger,
	) {}

	// Reconcile pending stock for all managed products, and materialise any changes.
	public function reconcile(): void {
		$checked   = 0;
		$corrected = 0;

		foreach ( $this->managed_product_ids() as $product_id ) {
			++$checked;
			$pending_before = $this->pending->current( $product_id );
			$pending_after  = $this->pending->recompute( $product_id );
			if ( $pending_before !== $pending_after ) {
				$this->writer->materialise( $product_id );
				++$corrected;
				$this->logger->info( 'Reconciled pending drift.', [ 'product' => $product_id, 'from' => $pending_before, 'to' => $pending_after ] );
			}
		}

		$this->logger->info( 'Pending reconcile complete.', [ 'checked' => $checked, 'corrected' => $corrected ] );
	}

	// Get the maximum age of pending reservations in seconds.
	public function max_pending_age_seconds(): int {
		return $this->store->max_pending_age_seconds();
	}

	// Get all managed product IDs.
	private function managed_product_ids(): array {
		$posts = get_posts(
			[
				'post_type'        => [ 'product', 'product_variation' ],
				'post_status'      => 'any',
				'meta_key'         => Meta::PART_ID,
				'fields'           => 'ids',
				'numberposts'      => -1,
				'suppress_filters' => true,
			]
		);

		$product_ids = [];
		foreach ( $posts as $post_id ) {
			$product_ids[] = (int) $post_id;
		}
		return $product_ids;
	}
}

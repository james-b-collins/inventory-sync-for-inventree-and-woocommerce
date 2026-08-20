<?php

declare(strict_types=1);

namespace InvenTreeSync\Stock;

use InvenTreeSync\Support\Meta;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to manage the pending ledger for products
final class PendingLedger {

	public function __construct(private ReservationStore $store) {}

	// return the current pending quantity for a product
	public function current( int $product_id ): int {
		return (int) get_post_meta( $product_id, Meta::PENDING, true );
	}

	// recompute the pending quantity for a product and update the post meta
	public function recompute( int $product_id ): int {
		$pending = $this->store->pending_for( $product_id );
		update_post_meta( $product_id, Meta::PENDING, $pending );
		return $pending;
	}
}

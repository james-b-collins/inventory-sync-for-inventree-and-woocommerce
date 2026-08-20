<?php

declare(strict_types=1);

namespace InvenTreeSync\Orders;

use InvenTreeSync\Admin\Settings;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to listen for order status changes and commit or release stock reservations
final class OrderStatusListener {

	private const RELEASING_STATUSES = [ 'cancelled', 'refunded', 'failed' ];

	public function __construct(
		private Settings $settings,
		private LineItemMapper $mapper,
		private CommitService $commits,
		private ReleaseService $releases,
	) {}

	// Register the listener to the WooCommerce hook
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 10, 4 );
	}

	// Callback for the WooCommerce hook when order status changes
	public function on_status_changed( int $order_id, string $from, string $to, \WC_Order $order ): void {
		if ( in_array( $to, $this->settings->committing_statuses(), true ) ) {
			foreach ( $this->mapper->managed_lines( $order ) as $line ) {
				$this->commits->commit_line( $order, $line['item'], $line['product_id'], $line['part_id'] );
			}
			return;
		}

		if ( in_array( $to, self::RELEASING_STATUSES, true ) ) {
			$this->releases->release_order_full( $order );
		}
	}
}

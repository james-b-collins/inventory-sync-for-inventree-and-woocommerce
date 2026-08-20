<?php

declare(strict_types=1);

namespace InvenTreeSync\Stock;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to manage the reservation store
final class ReservationStore {

	public const SOURCE_LINE  = 'line';
	public const SOURCE_ADDON = 'addon';

	private const DB_VERSION        = '1';
	private const DB_VERSION_OPTION = 'inventree_sync_db_version';

	// Return the name of the reservations table, with prefix
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'inventree_reservations';
	}

	// Check if the reservations table exists, and create it if not
	public function maybe_install(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			$this->install();
		}
	}

	// Create the reservations table
	public function install(): void {
		global $wpdb;

		$table   = $this->table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			order_item_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			part_id bigint(20) unsigned NOT NULL,
			source varchar(20) NOT NULL DEFAULT 'line',
			source_key varchar(191) NOT NULL DEFAULT '',
			committed_qty int(11) NOT NULL DEFAULT 0,
			held_qty int(11) NOT NULL DEFAULT 0,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_reservation (order_item_id, product_id, source_key),
			KEY product_id (product_id),
			KEY order_id (order_id),
			KEY held_qty (held_qty)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	// Add a reservation to the store. Returns true if a new row was inserted, false if it already existed.
	public function add( int $order_id, int $order_item_id, int $product_id, int $part_id, string $source, string $source_key, int $quantity ): bool {
		global $wpdb;

		$affected = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$this->table()}
					(order_id, order_item_id, product_id, part_id, source, source_key, committed_qty, held_qty, created_at)
				 VALUES (%d, %d, %d, %d, %s, %s, %d, %d, %s)",
				$order_id,
				$order_item_id,
				$product_id,
				$part_id,
				$source,
				$source_key,
				$quantity,
				$quantity,
				current_time( 'mysql', true )
			)
		);

		return $affected > 0;
	}

	// Return all reservations for a given order
	public function for_order( int $order_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE order_id = %d", $order_id )
		);
	}

	// Return all held reservations across all products
	public function all_held(): array {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM {$this->table()} WHERE held_qty > 0" );
	}

	// Return all reservations for a given order item
	public function for_item( int $order_item_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE order_item_id = %d", $order_item_id )
		);
	}

	// set the held quantity for a reservation. This is clamped to be non-negative.
	public function set_held( int $reservation_id, int $held ): void {
		global $wpdb;

		$clamped_held = $held;
		if ( $clamped_held < 0 ) {
			$clamped_held = 0;
		}

		$wpdb->update(
			$this->table(),
			[ 'held_qty' => $clamped_held ],
			[ 'id' => $reservation_id ],
			[ '%d' ],
			[ '%d' ]
		);
	}

	// update the committed and held quantities for a reservation
	public function update_quantities( int $reservation_id, int $committed, int $held ): void {
		global $wpdb;

		$clamped_committed = $committed;
		if ( $clamped_committed < 0 ) {
			$clamped_committed = 0;
		}

		$clamped_held = $held;
		if ( $clamped_held < 0 ) {
			$clamped_held = 0;
		}
		if ( $clamped_held > $clamped_committed ) {
			$clamped_held = $clamped_committed;
		}

		$wpdb->update(
			$this->table(),
			[ 'committed_qty' => $clamped_committed, 'held_qty' => $clamped_held ],
			[ 'id' => $reservation_id ],
			[ '%d', '%d' ],
			[ '%d' ]
		);
	}

	// Remove a reservation from the store
	public function remove( int $reservation_id ): void {
		global $wpdb;
		$wpdb->delete( $this->table(), [ 'id' => $reservation_id ], [ '%d' ] );
	}

	// Return the total pending quantity for a product across all reservations
	public function pending_for( int $product_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE( SUM( held_qty ), 0 ) FROM {$this->table()} WHERE product_id = %d", $product_id )
		);
	}

	// Return the age in seconds of the oldest held reservation. Returns 0 if there are no held reservations.
	public function max_pending_age_seconds(): int {
		global $wpdb;

		$oldest_created_at = $wpdb->get_var( "SELECT MIN( created_at ) FROM {$this->table()} WHERE held_qty > 0" );
		if ( ! $oldest_created_at ) {
			return 0;
		}

		$age = time() - (int) strtotime( $oldest_created_at . ' UTC' );
		if ( $age < 0 ) {
			return 0;
		}
		return $age;
	}
}

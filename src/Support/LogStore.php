<?php

declare(strict_types=1);

namespace InvenTreeSync\Support;

use InvenTreeSync\Admin\Settings;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to manage the log store in the database
final class LogStore {

	private const DB_VERSION        = '1';
	private const DB_VERSION_OPTION = 'inventree_sync_log_db_version';

	public function __construct(
		private Settings $settings,
	) {}

	// Return the name of the log table, with prefix
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'inventree_log';
	}

	// Check if the log table exists, and create it if not
	public function maybe_install(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			$this->install();
		}
	}

	// Create the log table
	public function install(): void {
		global $wpdb;

		$table   = $this->table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime DEFAULT NULL,
			level varchar(20) NOT NULL DEFAULT 'info',
			message text NOT NULL,
			context longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	// Write a log entry to the database table
	public function write( string $level, string $message, array $context ): void {
		global $wpdb;

		$encoded_context = null;
		if ( ! empty( $context ) ) {
			$encoded_context = (string) wp_json_encode( $context );
		}

		$wpdb->insert(
			$this->table(),
			[
				'created_at' => current_time( 'mysql', true ),
				'level'      => $level,
				'message'    => $message,
				'context'    => $encoded_context,
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		$this->trim();
	}

	// Return the most recent log entries, up to the specified limit
	public function recent( int $limit ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} ORDER BY id DESC LIMIT %d", $limit )
		);
	}
	// Return the total number of log entries
	public function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" );
	}

	// Clear all log entries
	public function clear(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$this->table()}" );
	}

	// Trim the log table to the configured retention limit
	private function trim(): void {
		global $wpdb;

		$keep = $this->settings->log_retention();
		if ( $keep <= 0 ) {
			return;
		}

		$table = $this->table();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE id NOT IN ( SELECT id FROM ( SELECT id FROM {$table} ORDER BY id DESC LIMIT %d ) keep_rows )",
				$keep
			)
		);
	}
}

<?php

declare(strict_types=1);

namespace InvenTreeSync\Admin;

use InvenTreeSync\Addons\AddonMap;

if ( ! defined( 'ABSPATH' ) ) {exit;}

final class Settings {

	public const OPTION = 'inventree_sync_settings';

	// The master switch. Off on a fresh install so nothing is written until switched on
	public const ENABLED_OPTION = 'inventree_sync_enabled';

	public const DEFAULT_COMMITTING_STATUSES = [ 'processing', 'completed' ];

	private array $data;

	public function __construct() {
		$stored = get_option( self::OPTION, [] );
		if ( is_array( $stored ) ) {
			$this->data = $stored;
		} else {
			$this->data = [];
		}
	}

	// Return the InvenTree URL, without a trailing slash. Empty if not set.
	public function inventree_url(): string {
		$url = trim( (string) ( $this->data['inventree_url'] ?? '' ) );
		if ( '' === $url ) {
			// If the URL is not set in the plugin settings, fall back to the environment variable
			$url = trim( (string) getenv( 'INVENTREE_URL' ) );
		}
		if ( '' === $url ) {
			return '';
		}
		return untrailingslashit( $url );
	}

	// Return the InvenTree API token. Empty if not set.
	public function inventree_token(): string {
		return trim( (string) ( $this->data['inventree_token'] ?? '' ) );
	}

	// Return the master switch state for the plugin
	public function is_enabled(): bool {
		return ! empty( get_option( self::ENABLED_OPTION, false ) );
	}

	// Defaults to on so an existing install keeps working after an upgrade.
	// The master switch will override this if it is off
	public function mirror_inventory(): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}
		return $this->mirror_inventory_setting();
	}
	// The raw mirror setting, ignoring the master switch
	public function mirror_inventory_setting(): bool {
		if ( ! array_key_exists( 'mirror_inventory', $this->data ) ) {
			return true;
		}
		return ! empty( $this->data['mirror_inventory'] );
	}

	// Defaults to on so an existing install keeps working after an upgrade.
	// The master switch will override this if it is off
	public function create_sales_orders(): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}
		return $this->create_sales_orders_setting();
	}

	// The raw create sales orders setting, ignoring the master switch
	public function create_sales_orders_setting(): bool {
		if ( ! array_key_exists( 'create_sales_orders', $this->data ) ) {
			return true;
		}
		return ! empty( $this->data['create_sales_orders'] );
	}

	// Optional integration with the third-party Product Add-Ons plugin. Off unless asked
	// for, except on an install already using it: mappings exist means it stays on.
	public function addons_enabled(): bool {
		$stored = get_option( self::OPTION, [] );
		if ( is_array( $stored ) && array_key_exists( 'addons_enabled', $stored ) ) {
			return ! empty( $stored['addons_enabled'] );
		}

		$stored_mappings = get_option( AddonMap::OPTION, [] );
		return is_array( $stored_mappings ) && ! empty( $stored_mappings );
	}

	// If both mirror inventory and create sales orders are on, then stock is reserved. If either is off, stock is not reserved.
	public function reserves_stock(): bool {
		return $this->mirror_inventory() && $this->create_sales_orders();
	}

	// Return the list of order statuses that trigger a stock reservation. Defaults to processing and completed.
	public function committing_statuses(): array {
		$value = $this->data['committing_statuses'] ?? self::DEFAULT_COMMITTING_STATUSES;
		if ( ! is_array( $value ) ) {
			return self::DEFAULT_COMMITTING_STATUSES;
		}
		return array_values( $value );
	}

	// Return the number of seconds after which a pending reservation is considered aged and can be released. Defaults to 1 day.
	public function aging_pending_threshold_seconds(): int {
		return (int) ( $this->data['aging_pending_threshold'] ?? DAY_IN_SECONDS );
	}

	// Return the number of seconds after which a committed reservation is considered aged and can be released. Defaults to 7 days.
	public function sync_interval_seconds(): int {
		return (int) ( $this->data['sync_interval'] ?? ( 15 * MINUTE_IN_SECONDS ) );
	}

	// Return the number of log entries to keep. Defaults to 500.
	public function log_retention(): int {
		return (int) ( $this->data['log_retention'] ?? 500 );
	}

	// Return whether the plugin is configured enough to run
	public function is_configured(): bool {
		if ( '' === $this->inventree_url() ) {
			return false;
		}
		if ( '' === $this->inventree_token() ) {
			return false;
		}
		return true;
	}
}

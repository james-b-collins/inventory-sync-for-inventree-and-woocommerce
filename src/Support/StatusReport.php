<?php

declare(strict_types=1);

namespace InvenTreeSync\Support;

use InvenTreeSync\Admin\Settings;
use InvenTreeSync\Scheduling\Scheduler;
use InvenTreeSync\Stock\ReservationStore;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to generate a status report for the plugin
final class StatusReport {

	public const LAST_SYNC_OPTION = 'inventree_sync_last_sync';

	public function __construct(
		private Settings $settings,
		private ReservationStore $store,
		private Scheduler $scheduler,
	) {}

	// Generate a status report as an array
	// takes a snapshot of the current state of the plugin, including sync status, pending reservations, and configuration
	public function snapshot(): array {
		$now        = time();
		$enabled    = $this->settings->is_enabled();
		$configured = $this->settings->is_configured();
		$scheduled  = $this->scheduler->is_scheduled();

		$last_sync  = (int) get_option( self::LAST_SYNC_OPTION, 0 );
		$has_synced = ( $last_sync > 0 );

		// Calculate the age of the last sync in seconds
		$sync_age = null;
		if ( $has_synced ) {
			$sync_age = $now - $last_sync;
		}

		// Determine the staleness threshold based on the sync interval
		$sync_interval       = $this->settings->sync_interval_seconds();
		$staleness_threshold = 3 * $sync_interval;
		if ( $staleness_threshold < 15 * MINUTE_IN_SECONDS ) {
			$staleness_threshold = 15 * MINUTE_IN_SECONDS;
		}

		// Determine if the sync is stale
		$sync_stale = false;
		if ( $scheduled ) {
			if ( ! $has_synced ) {
				$sync_stale = true;
			} elseif ( $sync_age > $staleness_threshold ) {
				$sync_stale = true;
			}
		}

		// Determine if the pending reservations are aging
		$max_pending_age   = $this->store->max_pending_age_seconds();
		$pending_threshold = $this->settings->aging_pending_threshold_seconds();
		$pending_aging     = ( $max_pending_age > $pending_threshold );

		// If the plugin is not enabled, it is considered healthy regardless of other factors.
		if ( ! $enabled ) {
			$healthy = true;
		} else {
			$healthy = ( $configured && ! $sync_stale && ! $pending_aging );
		}

		$last_success_at = null;
		if ( $has_synced ) {
			$last_success_at = gmdate( 'c', $last_sync );
		}

		// Return the status report as an associative array
		return [
			'healthy'      => $healthy,
			'enabled'      => $enabled,
			'configured'   => $configured,
			'scheduled'    => $scheduled,
			'sync'         => [
				'last_success_at'        => $last_success_at,
				'last_success_timestamp' => $has_synced ? $last_sync : null,
				'age_seconds'            => $sync_age,
				'staleness_threshold'    => $staleness_threshold,
				'stale'                  => $sync_stale,
			],
			'pending'      => [
				'max_age_seconds'   => $max_pending_age,
				'threshold_seconds' => $pending_threshold,
				'aging'             => $pending_aging,
			],
			'generated_at' => gmdate( 'c', $now ),
		];
	}
}

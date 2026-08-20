<?php

declare(strict_types=1);

namespace InvenTreeSync\Scheduling;

use InvenTreeSync\Push\AllocationPoller;
use InvenTreeSync\Push\PendingReconciler;
use InvenTreeSync\Push\SalesOrderPusher;
use InvenTreeSync\Sync\SyncRunner;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to schedule recurring actions for the plugin.
final class Scheduler {

	public const SYNC_START        = 'inventree_sync_start';
	public const SYNC_BATCH        = 'inventree_sync_batch';
	public const PUSH_ORDER        = 'inventree_push_order';
	public const POLL_ALLOCATIONS  = 'inventree_poll_allocations';
	public const RECONCILE_PENDING = 'inventree_reconcile_pending';

	public const GROUP = 'inventory-sync';

	private const POLL_INTERVAL = 5 * MINUTE_IN_SECONDS;	// Poll for upstream releases every 5 minutes.
	private const RECONCILE_INTERVAL = HOUR_IN_SECONDS;		// Reconcile pending stock every hour.
	private const INTERVAL_UNKNOWN = -1;					// Unknown interval means the action is scheduled but the recurrence cannot be read.

	public function __construct(
		private SyncRunner $sync,
		private SalesOrderPusher $pusher,
		private AllocationPoller $poller,
		private PendingReconciler $reconciler,
	) {}

	// Register the handlers for the scheduled actions.
	public function register_handlers( bool $mirror_inventory, bool $create_sales_orders ): void {
		if ( $mirror_inventory ) {
			add_action( self::SYNC_START, [ $this->sync, 'start' ] );
			add_action( self::SYNC_BATCH, [ $this->sync, 'run_batch' ], 10, 2 );
		}

		if ( $create_sales_orders ) {
			add_action( self::PUSH_ORDER, [ $this->pusher, 'push' ], 10, 1 );
			add_action( self::POLL_ALLOCATIONS, [ $this->poller, 'poll' ] );
			add_action( self::RECONCILE_PENDING, [ $this->reconciler, 'reconcile' ] );
		}
	}

	// Schedule recurring actions for the plugin.
	public function schedule_recurring( int $interval_seconds, bool $mirror_inventory, bool $create_sales_orders ): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		$interval = $interval_seconds;
		if ( $interval < 60 ) {
			$interval = 60;
		}

		if ( $mirror_inventory ) {
			$this->ensure_scheduled( self::SYNC_START, time() + 60, $interval );
		} else {
			$this->ensure_unscheduled( self::SYNC_START );
			$this->ensure_unscheduled( self::SYNC_BATCH );
		}

		if ( $create_sales_orders ) {
			$this->ensure_scheduled( self::POLL_ALLOCATIONS, time() + 60, self::POLL_INTERVAL );
			$this->ensure_scheduled( self::RECONCILE_PENDING, time() + 120, self::RECONCILE_INTERVAL );
		} else {
			$this->ensure_unscheduled( self::POLL_ALLOCATIONS );
			$this->ensure_unscheduled( self::RECONCILE_PENDING );
		}
	}

	// Ensure that a hook is scheduled at the given interval, unscheduling any existing schedule if necessary.
	private function ensure_scheduled( string $hook, int $first_run, int $interval ): void {
		$current_interval = $this->scheduled_interval( $hook );

		if ( $current_interval === $interval ) {
			return;
		}

		// Unknown means it is scheduled but the recurrence could not be read. Leave
		// it alone rather than rescheduling it on every request.
		if ( self::INTERVAL_UNKNOWN === $current_interval ) {
			return;
		}

		if ( null !== $current_interval ) {
			$this->ensure_unscheduled( $hook );
		}

		as_schedule_recurring_action( $first_run, $interval, $hook, [], self::GROUP );
	}

	// Get the scheduled interval for a hook, or null if not scheduled.
	private function scheduled_interval( string $hook ): ?int {
		if ( false === as_next_scheduled_action( $hook, [], self::GROUP ) ) {
			return null;
		}

		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( \ActionScheduler_Store::class ) ) {
			return self::INTERVAL_UNKNOWN;
		}

		$actions = as_get_scheduled_actions(
			[
				'hook'     => $hook,
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			]
		);

		foreach ( $actions as $action ) {
			$schedule = $action->get_schedule();
			if ( ! method_exists( $schedule, 'get_recurrence' ) ) {
				continue;
			}
			$recurrence = $schedule->get_recurrence();
			if ( is_numeric( $recurrence ) ) {
				return (int) $recurrence;
			}
		}

		return self::INTERVAL_UNKNOWN;
	}

	// Unschedule all actions for a hook.
	private function ensure_unscheduled( string $hook ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, [], self::GROUP );
		}
	}

	// Check if the sync start action is scheduled.
	public function is_scheduled(): bool {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}
		return false !== as_next_scheduled_action( self::SYNC_START, [], self::GROUP );
	}

	// Unschedule all actions for the plugin. Called on deactivation.
	public function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], self::GROUP );
		}
	}
}

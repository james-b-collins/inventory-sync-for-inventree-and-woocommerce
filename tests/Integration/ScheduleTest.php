<?php
// this file contains integration tests for scheduling the recurring sync and reconcile tasks
declare(strict_types=1);

namespace InvenTreeSync\Tests\Integration;

use InvenTreeSync\Scheduling\Scheduler;

// this class tests that scheduling the recurring sync and reconcile tasks correctly sets up the Action Scheduler hooks.
final class ScheduleTest extends IntegrationTestCase {

	// sets up the test environment, and unschedules any existing scheduled actions for the sync and reconcile tasks.
	protected function setUp(): void {
		parent::setUp();
		as_unschedule_all_actions( '', [], Scheduler::GROUP );
	}

	// tears down the test environment, and unschedules any existing scheduled actions for the sync and reconcile tasks.
	private function scheduler(): Scheduler {
		$plugin = \InvenTreeSync\Plugin::instance();
		$scheduler = $plugin->scheduler();
		if ( null === $scheduler ) {
			$this->fail( 'the plugin did not build a scheduler' );
		}
		return $scheduler;
	}

	// returns the interval of the next scheduled action for the given hook, or null if there is no scheduled action.
	private function interval_of( string $hook ): ?int {
		$actions = as_get_scheduled_actions(
			[
				'hook'     => $hook,
				'group'    => Scheduler::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			]
		);

		foreach ( $actions as $action ) {
			$schedule = $action->get_schedule();
			if ( method_exists( $schedule, 'get_recurrence' ) ) {
				return (int) $schedule->get_recurrence();
			}
		}
		return null;
	}

	// returns the count of scheduled actions for the given hook.
	private function count_of( string $hook ): int {
		return count(
			as_get_scheduled_actions(
				[
					'hook'     => $hook,
					'group'    => Scheduler::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				]
			)
		);
	}

	// tests that the sync is scheduled at the configured interval.
	public function test_the_sync_is_scheduled_at_the_configured_interval(): void {
		$this->scheduler()->schedule_recurring( 900, true, true );

		$this->assertSame( 900, $this->interval_of( Scheduler::SYNC_START ) );
	}

	// tests that changing the interval of the sync reschedules it to the new interval.
	public function test_changing_the_interval_reschedules_the_sync(): void {
		$this->scheduler()->schedule_recurring( 1234, true, true );
		$this->assertSame( 1234, $this->interval_of( Scheduler::SYNC_START ) );

		$this->scheduler()->schedule_recurring( 900, true, true );

		$this->assertSame( 900, $this->interval_of( Scheduler::SYNC_START ), 'the new interval must take effect' );
		$this->assertSame( 1, $this->count_of( Scheduler::SYNC_START ), 'the old schedule must be replaced, not duplicated' );
	}

	// tests that rescheduling the sync at the same interval does not change the next run time.
	public function test_rescheduling_at_the_same_interval_changes_nothing(): void {
		$this->scheduler()->schedule_recurring( 900, true, true );
		$first_run = as_next_scheduled_action( Scheduler::SYNC_START, [], Scheduler::GROUP );

		$this->scheduler()->schedule_recurring( 900, true, true );

		$this->assertSame(
			$first_run,
			as_next_scheduled_action( Scheduler::SYNC_START, [], Scheduler::GROUP ),
			'an unchanged interval must not push the next run back'
		);
		$this->assertSame( 1, $this->count_of( Scheduler::SYNC_START ) );
	}

	// tests that turning off the sync unschedules it, but does not affect the other scheduled tasks.
	public function test_turning_mirroring_off_unschedules_the_sync(): void {
		$this->scheduler()->schedule_recurring( 900, true, true );
		$this->assertSame( 1, $this->count_of( Scheduler::SYNC_START ) );

		$this->scheduler()->schedule_recurring( 900, false, true );

		$this->assertSame( 0, $this->count_of( Scheduler::SYNC_START ) );
		$this->assertSame( 1, $this->count_of( Scheduler::POLL_ALLOCATIONS ), 'the other half keeps running' );
	}

	// tests that turning off the reconcile unschedules it, but does not affect the other scheduled tasks.
	public function test_turning_sales_orders_off_unschedules_the_poll_and_reconcile(): void {
		$this->scheduler()->schedule_recurring( 900, true, true );

		$this->scheduler()->schedule_recurring( 900, true, false );

		$this->assertSame( 0, $this->count_of( Scheduler::POLL_ALLOCATIONS ) );
		$this->assertSame( 0, $this->count_of( Scheduler::RECONCILE_PENDING ) );
		$this->assertSame( 1, $this->count_of( Scheduler::SYNC_START ), 'the other half keeps running' );
	}

	// tests that the interval of the sync is floored at 60 seconds, even if a smaller interval is requested.
	public function test_the_interval_floors_at_a_minute(): void {
		$this->scheduler()->schedule_recurring( 5, true, true );

		$this->assertSame( 60, $this->interval_of( Scheduler::SYNC_START ) );
	}
}

<?php
// this file contains integration tests for how the status report decides the plugin is healthy
declare(strict_types=1);

namespace InvenTreeSync\Tests\Integration;

use InvenTreeSync\Admin\Settings;
use InvenTreeSync\Scheduling\Scheduler;
use InvenTreeSync\Stock\ReservationStore;
use InvenTreeSync\Support\StatusReport;

// Integration tests for the health report of the plugin.
final class HealthReportTest extends IntegrationTestCase {

	// Set up for the integration tests.
	protected function setUp(): void {
		parent::setUp();
		as_unschedule_all_actions( '', [], Scheduler::GROUP );
		delete_transient( 'inventree_sync_schedule_checked' );

		update_option(
			Settings::OPTION,
			[
				'inventree_url'      => 'http://fake',
				'inventree_token'    => 'token',
				'mirror_inventory'   => 1,
				'create_sales_orders' => 1,
				'sync_interval'      => 900,
			]
		);
		update_option( StatusReport::LAST_SYNC_OPTION, time() );
	}

	// Builds a status report over the live scheduler.
	private function report(): StatusReport {
		$scheduler = \InvenTreeSync\Plugin::instance()->scheduler();
		if ( null === $scheduler ) {
			$this->fail( 'the plugin did not build a scheduler' );
		}
		return new StatusReport( new Settings(), new ReservationStore(), $scheduler );
	}

	// Returns the live scheduler instance.
	private function scheduler(): Scheduler {
		$scheduler = \InvenTreeSync\Plugin::instance()->scheduler();
		if ( null === $scheduler ) {
			$this->fail( 'the plugin did not build a scheduler' );
		}
		return $scheduler;
	}

	// Tests that an intact schedule is reported as healthy.
	public function test_an_intact_schedule_is_healthy(): void {
		$this->scheduler()->schedule_recurring( 900, true, true );

		$snapshot = $this->report()->snapshot();

		$this->assertTrue( $snapshot['healthy'] );
		$this->assertTrue( $snapshot['scheduled'] );
		$this->assertFalse( $snapshot['schedule']['broken'] );
		$this->assertSame( [], $snapshot['schedule']['missing_hooks'] );
	}

	// Tests that a stopped release job is reported as unhealthy even if the sync continues to run.
	public function test_a_stopped_release_job_is_unhealthy_even_while_the_sync_runs(): void {
		$this->scheduler()->schedule_recurring( 900, true, true );
		as_unschedule_all_actions( Scheduler::POLL_ALLOCATIONS, [], Scheduler::GROUP );

		$snapshot = $this->report()->snapshot();

		$this->assertFalse( $snapshot['healthy'], 'a dead release job is a fault on its own' );
		$this->assertTrue( $snapshot['schedule']['broken'] );
		$this->assertSame( [ Scheduler::POLL_ALLOCATIONS ], $snapshot['schedule']['missing_hooks'] );
		$this->assertFalse( $snapshot['pending']['aging'], 'nothing has aged yet; the schedule alone is the fault' );
	}

	// Tests that a completely stopped schedule still reports the sync as stale.
	public function test_a_stopped_schedule_does_not_suppress_sync_staleness(): void {
		update_option( StatusReport::LAST_SYNC_OPTION, time() - ( 7 * DAY_IN_SECONDS ) );

		$snapshot = $this->report()->snapshot();

		$this->assertFalse( $snapshot['healthy'] );
		$this->assertTrue( $snapshot['schedule']['broken'] );
		$this->assertTrue( $snapshot['sync']['stale'], 'a week old sync is stale whether or not it is scheduled' );
	}

	// Tests that a hook the settings have turned off is not reported as missing.
	public function test_a_disabled_feature_is_not_reported_as_missing(): void {
		update_option(
			Settings::OPTION,
			[
				'inventree_url'       => 'http://fake',
				'inventree_token'     => 'token',
				'mirror_inventory'    => 1,
				'create_sales_orders' => 0,
				'sync_interval'       => 900,
			]
		);
		$this->scheduler()->schedule_recurring( 900, true, false );

		$snapshot = $this->report()->snapshot();

		$this->assertSame( [], $snapshot['schedule']['missing_hooks'] );
		$this->assertTrue( $snapshot['healthy'] );
	}

	// Tests that a disabled plugin is reported as healthy even if no schedule exists.
	public function test_a_disabled_plugin_is_healthy_with_no_schedule(): void {
		$this->set_enabled( false );

		$snapshot = $this->report()->snapshot();

		$this->assertTrue( $snapshot['healthy'] );
		$this->assertSame( [], $snapshot['schedule']['missing_hooks'] );
	}
}

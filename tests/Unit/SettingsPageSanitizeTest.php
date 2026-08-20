<?php
// This file tests the Sanitize method of the SettingsPage class
declare(strict_types=1);

namespace InvenTreeSync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use InvenTreeSync\Addons\AddonMap;
use InvenTreeSync\Admin\AddonMappingPage;
use InvenTreeSync\Admin\ImportPage;
use InvenTreeSync\Admin\LogPage;
use InvenTreeSync\Admin\Settings;
use InvenTreeSync\Admin\SettingsPage;
use InvenTreeSync\Support\LogStore;
use PHPUnit\Framework\TestCase;

// this class runs through the SettingsPage sanitize method, including token handling, status list parsing, and interval clamping.
// uses Brain Monkey to mock WordPress functions
final class SettingsPageSanitizeTest extends TestCase {

	// set up brain monkey environment
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static fn( $value ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) )
		);
	}

	// tear down brain monkey environment
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// create a new SettingsPage with a test AddonMap and LogStore
	private function page(): SettingsPage {
		return new SettingsPage(
			new Settings(),
			new AddonMappingPage( new AddonMap() ),
			new ImportPage( new Settings(), static fn () => null, static fn () => null ),
			new LogPage( new LogStore( new Settings() ) )
		);
	}

	// test that a blank token keeps the stored one
	public function test_a_blank_token_keeps_the_stored_one(): void {
		Functions\when( 'get_option' )->justReturn( [ 'inventree_token' => 'stored-token' ] );

		$out = $this->page()->sanitize( [ 'inventree_token' => '' ] );

		$this->assertSame( 'stored-token', $out['inventree_token'] );
	}

	// test that a submitted token replaces the stored one
	public function test_a_submitted_token_replaces_the_stored_one(): void {
		Functions\when( 'get_option' )->justReturn( [ 'inventree_token' => 'stored-token' ] );

		$out = $this->page()->sanitize( [ 'inventree_token' => 'new-token' ] );

		$this->assertSame( 'new-token', $out['inventree_token'] );
	}

	// test that a whitespace-only token counts as blank
	public function test_a_whitespace_only_token_counts_as_blank(): void {
		Functions\when( 'get_option' )->justReturn( [ 'inventree_token' => 'stored-token' ] );

		$out = $this->page()->sanitize( [ 'inventree_token' => '   ' ] );

		$this->assertSame( 'stored-token', $out['inventree_token'] );
	}

	// test that a comma-separated string of statuses is parsed into an array
	public function test_parses_comma_separated_string(): void {
		$out = $this->page()->sanitize( [ 'committing_statuses' => 'processing, completed' ] );
		$this->assertSame( [ 'processing', 'completed' ], $out['committing_statuses'] );
	}

	// test that a whitespace-separated string of statuses is parsed into an array
	public function test_sanitize_is_idempotent_on_array_input(): void {
		$page   = $this->page();
		$first  = $page->sanitize( [ 'committing_statuses' => 'processing, completed' ] );
		$second = $page->sanitize( $first ); // Second pass receives the array.

		$this->assertSame( [ 'processing', 'completed' ], $first['committing_statuses'] );
		$this->assertSame( [ 'processing', 'completed' ], $second['committing_statuses'], 'sanitize must not corrupt an array-valued status list' );
	}

	// test that an empty status list falls back to the default
	public function test_empty_statuses_fall_back_to_default(): void {
		$out = $this->page()->sanitize( [ 'committing_statuses' => '' ] );
		$this->assertSame( Settings::DEFAULT_COMMITTING_STATUSES, $out['committing_statuses'] );
	}

	// test that a whitespace-only status list falls back to the default
	public function test_intervals_are_clamped_to_a_floor(): void {
		$out = $this->page()->sanitize(
			[
				'sync_interval'           => 5,
				'aging_pending_threshold' => 10,
			]
		);
		$this->assertSame( 60, $out['sync_interval'] );
		$this->assertSame( 60, $out['aging_pending_threshold'] );
	}
}

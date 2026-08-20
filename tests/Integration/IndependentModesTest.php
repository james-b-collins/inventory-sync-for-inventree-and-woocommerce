<?php
// This file tests the plugin behaves correctly in it's independent modes
declare(strict_types=1);

namespace InvenTreeSync\Tests\Integration;

use InvenTreeSync\Addons\AddonMap;
use InvenTreeSync\Addons\AddonReader;
use InvenTreeSync\Admin\Settings;
use InvenTreeSync\Catalogue\ProductWriter;
use InvenTreeSync\Orders\CommitService;
use InvenTreeSync\Orders\ReleaseService;
use InvenTreeSync\Stock\PendingLedger;
use InvenTreeSync\Stock\ReservationStore;
use InvenTreeSync\Support\Logger;

// This class tests that the plugin behaves correctly when the two halves of its functionality are toggled independently. The two halves are:
// 1. Mirroring inventory from InvenTree to WooCommerce (mirror_inventory)
// 2. Creating sales orders in InvenTree for WooCommerce orders (create_sales_orders)
final class IndependentModesTest extends IntegrationTestCase {

	// creates a Settings object with the given overrides, and ensures the plugin is enabled.
	private function settings_with( array $overrides ): Settings {
		$this->set_enabled( true );
		update_option( Settings::OPTION, $overrides );
		return new Settings();
	}

	// creates a CommitService with a fresh ReservationStore, so that each test can run independently.
	private function commit_service( Settings $settings ): CommitService {
		$store = new ReservationStore();
		return new CommitService(
			$settings,
			$store,
			new PendingLedger( $store ),
			new ProductWriter(),
			new AddonReader( new AddonMap(), new Logger() ),
			new Logger()
		);
	}

	// creates a ReleaseService with a fresh ReservationStore, so that each test can run independently.
	private function release_service( Settings $settings ): ReleaseService {
		$store = new ReservationStore();
		return new ReleaseService(
			$settings,
			$store,
			new PendingLedger( $store ),
			new ProductWriter(),
			static fn () => null,
			new Logger()
		);
	}

	// returns the first product line in an order, or fails the test if there is none.
	private function first_item( \WC_Order $order ): \WC_Order_Item_Product {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				return $item;
			}
		}
		$this->fail( 'order has no product line' );
	}

	// tests that when sales orders are created but inventory is not mirrored, the plugin does not hold back any stock in WooCommerce.
	public function test_sales_orders_without_mirroring_reserve_but_do_not_touch_stock(): void {
		$settings = $this->settings_with(
			[
				'mirror_inventory'    => 0,
				'create_sales_orders' => 1,
			]
		);

		$product = $this->make_managed_product( 'MODE-1', 301, 25 );
		$order   = $this->make_order( [ [ $product, 4 ] ] );
		$item    = $this->first_item( $order );

		$this->commit_service( $settings )->commit_line( $order, $item, $product->get_id(), 301 );

		// Still the record of what the order consumes, for the push to build lines from.
		$reservations = $this->reservations_for_order( $order->get_id() );
		$this->assertCount( 1, $reservations );
		$this->assertSame( 4, (int) $reservations[0]->committed_qty );

		// But WooCommerce keeps its own stock: the plugin has not held anything back.
		$this->assertSame( 0, $this->pending( $product->get_id() ) );
		$this->assertSame( 25, $this->stock( $product->get_id() ) );
	}

	// tests that when both halves are on, the plugin holds back stock in WooCommerce for the InvenTree order.
	public function test_mirroring_and_sales_orders_together_do_hold_stock(): void {
		$settings = $this->settings_with(
			[
				'mirror_inventory'    => 1,
				'create_sales_orders' => 1,
			]
		);

		$product = $this->make_managed_product( 'MODE-2', 302, 25 );
		$order   = $this->make_order( [ [ $product, 4 ] ] );
		$item    = $this->first_item( $order );

		$this->commit_service( $settings )->commit_line( $order, $item, $product->get_id(), 302 );

		$this->assertSame( 4, $this->pending( $product->get_id() ) );
		$this->assertSame( 21, $this->stock( $product->get_id() ) );
	}

	// tests that turning sales orders off releases held stock.
	public function test_turning_sales_orders_off_releases_held_stock(): void {
		$settings = $this->settings_with(
			[
				'mirror_inventory'    => 1,
				'create_sales_orders' => 1,
			]
		);

		$product = $this->make_managed_product( 'MODE-3', 303, 25 );
		$order   = $this->make_order( [ [ $product, 6 ] ] );
		$item    = $this->first_item( $order );

		$this->commit_service( $settings )->commit_line( $order, $item, $product->get_id(), 303 );
		$this->assertSame( 19, $this->stock( $product->get_id() ) );

		$after = $this->settings_with(
			[
				'mirror_inventory'    => 1,
				'create_sales_orders' => 0,
			]
		);
		$this->release_service( $after )->release_all_outstanding();

		$this->assertSame( 0, $this->pending( $product->get_id() ) );
		$this->assertSame( 25, $this->stock( $product->get_id() ), 'stock should not stay held after the setting is turned off' );

		foreach ( $this->reservations_for_order( $order->get_id() ) as $reservation ) {
			$this->assertSame( 0, (int) $reservation->held_qty );
		}
	}

	// tests that a fresh install of the plugin is deactivated and does nothing.
	public function test_a_fresh_install_is_deactivated_and_does_nothing(): void {
		delete_option( Settings::OPTION );
		delete_option( Settings::ENABLED_OPTION );
		$settings = new Settings();

		$this->assertFalse( $settings->is_enabled(), 'the master switch defaults to off' );
		$this->assertFalse( $settings->mirror_inventory() );
		$this->assertFalse( $settings->create_sales_orders() );
		$this->assertFalse( $settings->reserves_stock() );
	}

	// tests that when the plugin is activated, both halves default to on.
	public function test_both_halves_default_on_once_activated(): void {
		$settings = $this->settings_with( [] );

		$this->assertTrue( $settings->is_enabled() );
		$this->assertTrue( $settings->mirror_inventory() );
		$this->assertTrue( $settings->create_sales_orders() );
		$this->assertTrue( $settings->reserves_stock() );
	}

	// tests that deactivating the plugin overrides both halves.
	public function test_deactivating_overrides_both_halves(): void {
		$settings = $this->settings_with(
			[
				'mirror_inventory'    => 1,
				'create_sales_orders' => 1,
			]
		);
		$this->set_enabled( false );

		$this->assertTrue( $settings->create_sales_orders_setting(), 'the half is still on underneath' );
		$this->assertFalse( $settings->mirror_inventory() );
		$this->assertFalse( $settings->create_sales_orders() );
		$this->assertFalse( $settings->reserves_stock() );
	}

	// tests that toggling the plugin on and off does not disturb the underlying settings.
	public function test_toggling_does_not_disturb_the_settings(): void {
		$this->settings_with(
			[
				'inventree_url'       => 'http://inventree.test',
				'inventree_token'     => 'token-1',
				'mirror_inventory'    => 1,
				'create_sales_orders' => 1,
				'sync_interval'       => 600,
			]
		);
		$before = get_option( Settings::OPTION );

		$this->set_enabled( false );
		$this->set_enabled( true );

		$this->assertSame( $before, get_option( Settings::OPTION ), 'the settings array must be untouched' );

		$settings = new Settings();
		$this->assertTrue( $settings->is_enabled(), 'the switch must actually take' );
		$this->assertTrue( $settings->mirror_inventory() );
		$this->assertTrue( $settings->create_sales_orders() );
		$this->assertSame( 600, $settings->sync_interval_seconds() );
	}

	// tests that deactivating the plugin releases stock held against orders.
	public function test_deactivating_releases_stock_held_against_orders(): void {
		$settings = $this->settings_with( [] );
		$product  = $this->make_managed_product( 'MODE-OFF-1', 401, 20 );
		$order    = $this->make_order( [ [ $product, 5 ] ] );

		$item = $this->first_item( $order );
		$this->commit_service( $settings )->commit_line( $order, $item, $product->get_id(), 401 );
		$this->assertSame( 5, $this->pending( $product->get_id() ) );
		$this->assertSame( 15, $this->stock( $product->get_id() ) );

		// Same release path as turning the sales-order half off.
		$this->set_enabled( false );

		$this->assertSame( 0, $this->pending( $product->get_id() ) );
		$this->assertSame( 20, $this->stock( $product->get_id() ), 'stock must go back to the InvenTree figure' );
	}
}

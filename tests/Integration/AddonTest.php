<?php
// This file tests the Addon integration functionality
declare(strict_types=1);

namespace InvenTreeSync\Tests\Integration;

use InvenTreeSync\Addons\AddonMap;
use InvenTreeSync\Admin\Settings;

// this class runs through the add-on integration functionality, including enabling/disabling the integration and reserving stock for selected add-ons.
final class AddonTest extends IntegrationTestCase {

	// create a new order with a base product and a selected add-on, and return the order
	private function order_with_selected_addon(): \WC_Order {
		$base = $this->make_managed_product( 'ADDON-OFF-BASE', 411, 25 );
		$this->make_managed_product( 'ADDON-OFF-CASE', 412, 100 );

		( new AddonMap() )->save(
			[
				[ 'name' => 'Case', 'value' => 'Yes', 'ipn' => 'ADDON-OFF-CASE', 'qty' => 1 ],
			]
		);

		$order = $this->make_order( [ [ $base, 2 ] ] );
		$item  = $order->get_items()[ array_key_first( $order->get_items() ) ];
		$item->update_meta_data( '_pao_ids', [ [ 'key' => 'Case', 'value' => 'Yes', 'id' => 'sel-1' ] ] );
		$item->save();

		return $order;
	}

	// return the stock level for the given product ID
	public function test_the_integration_is_off_on_a_fresh_install(): void {
		delete_option( AddonMap::OPTION );
		delete_option( Settings::OPTION );

		$this->assertFalse( ( new Settings() )->addons_enabled() );
	}

	// test that the integration is on when there are existing mappings
	public function test_existing_mappings_keep_the_integration_on(): void {
		delete_option( Settings::OPTION );
		( new AddonMap() )->save(
			[
				[ 'name' => 'Case', 'value' => 'Yes', 'ipn' => 'ANY-IPN', 'qty' => 1 ],
			]
		);

		$this->assertTrue( ( new Settings() )->addons_enabled() );
	}

	// test that the integration is off when there are no existing mappings
	public function test_an_explicit_setting_beats_the_mappings_fallback(): void {
		( new AddonMap() )->save(
			[
				[ 'name' => 'Case', 'value' => 'Yes', 'ipn' => 'ANY-IPN', 'qty' => 1 ],
			]
		);
		update_option( Settings::OPTION, [ 'addons_enabled' => 0 ] );

		$this->assertFalse( ( new Settings() )->addons_enabled() );
	}

	// test that the integration is on when there are no existing mappings but the setting is explicitly enabled
	public function test_add_ons_are_ignored_when_the_integration_is_off(): void {
		$order = $this->order_with_selected_addon();

		// Mappings are still stored; the setting is what stops them applying.
		update_option( Settings::OPTION, [ 'addons_enabled' => 0 ] );

		wc_get_order( $order->get_id() )->update_status( 'processing' );

		$part_ids = [];
		foreach ( $this->reservations_for_order( $order->get_id() ) as $reservation ) {
			$part_ids[] = (int) $reservation->part_id;
		}

		$this->assertSame( [ 411 ], $part_ids, 'only the base product should be reserved' );
		$this->assertSame( 100, $this->stock( (int) wc_get_product_id_by_sku( 'ADDON-OFF-CASE' ) ), 'the add-on part keeps its stock' );
	}

	// test that the integration is on when there are no existing mappings but the setting is explicitly enabled
	public function test_add_ons_apply_when_the_integration_is_on(): void {
		$order = $this->order_with_selected_addon();
		update_option( Settings::OPTION, [ 'addons_enabled' => 1 ] );

		wc_get_order( $order->get_id() )->update_status( 'processing' );

		$part_ids = [];
		foreach ( $this->reservations_for_order( $order->get_id() ) as $reservation ) {
			$part_ids[] = (int) $reservation->part_id;
		}
		sort( $part_ids );

		$this->assertSame( [ 411, 412 ], $part_ids );
	}

	// test theat a selected add-on reserves the correct quantity of its part
	public function test_selected_addon_reserves_its_part(): void {
		$base  = $this->make_managed_product( 'ADDON-BASE', 401, 25 );
		$extra = $this->make_managed_product( 'ADDON-CASE', 402, 100 );

		( new AddonMap() )->save(
			[
				[ 'name' => 'Case', 'value' => 'Yes', 'ipn' => 'ADDON-CASE', 'qty' => 1 ],
			]
		);

		$order = $this->make_order( [ [ $base, 2 ] ] );
		$item  = $order->get_items()[ array_key_first( $order->get_items() ) ];
		$item->update_meta_data(
			'_pao_ids',
			[
				[ 'key' => 'Case', 'value' => 'Yes', 'id' => 'sel-1' ],
				[ 'key' => 'Colour', 'value' => 'Red', 'id' => 'sel-2' ], // unmapped, ignored
			]
		);
		$item->save();

		wc_get_order( $order->get_id() )->update_status( 'processing' );

		// Two reservations on the line: the base part and the add-on part.
		$by_part = [];
		foreach ( $this->reservations_for_order( $order->get_id() ) as $reservation ) {
			$by_part[ (int) $reservation->part_id ] = (int) $reservation->held_qty;
		}
		$this->assertSame( [ 401 => 2, 402 => 2 ], $by_part );

		$this->assertSame( 23, $this->stock( $base->get_id() ) );  // 25 - 2
		$this->assertSame( 98, $this->stock( $extra->get_id() ) ); // 100 - 2
	}
}

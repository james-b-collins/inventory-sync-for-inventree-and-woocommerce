<?php

declare(strict_types=1);

namespace InvenTreeSync\Addons;

use InvenTreeSync\Admin\Settings;
use InvenTreeSync\Support\Logger;
use InvenTreeSync\Support\Meta;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// This class reads the add-ons selected for a WooCommerce order item, and resolves them to InvenTree parts
final class AddonReader {
	public function __construct(
		private AddonMap $map,
		private Logger $logger,
		private ?Settings $settings = null,
	) {
		if ( null === $this->settings ) {
			$this->settings = new Settings();
		}
	}

	// Return an array of reservations for the given order item, each with product_id, part_id, source_key, and qty
	public function reservations_for( \WC_Order_Item_Product $item ): array {
		// If add-ons are not enabled, there are no reservations
		if ( ! $this->settings->addons_enabled() ) {
			return [];
		}

		// If the add-on plugin is not active, there are no reservations
		$selections = $item->get_meta( '_pao_ids' );
		if ( ! is_array( $selections ) ) {
			return [];
		}

		// If the line quantity is zero or negative, there are no reservations
		$line_quantity = (int) $item->get_quantity();
		if ( $line_quantity <= 0 ) {
			return [];
		}

		// For each selected add-on, find the mapping to an InvenTree part, and resolve it to a WooCommerce product and part ID
		$reservations = [];
		foreach ( $selections as $selection ) {
			if ( ! is_array( $selection ) ) {
				continue;
			}

			$name  = trim( (string) ( $selection['key'] ?? '' ) );
			$value = trim( (string) ( $selection['value'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}

			$mapping = $this->map->match( $name, $value );
			if ( null === $mapping ) {
				continue; // Not a stockable add-on, or not selected.
			}

			$resolved = $this->resolve_part( $mapping['ipn'], $name );
			if ( null === $resolved ) {
				continue;
			}

			$reservations[] = [
				'product_id' => $resolved['product_id'],
				'part_id'    => $resolved['part_id'],
				'source_key' => (string) ( $selection['id'] ?? $name ),
				'qty'        => $line_quantity * $mapping['qty'],
			];
		}

		return $reservations;
	}

	// Resolve a WooCommerce product and InvenTree part from an IPN, returning null if not found or not managed
	private function resolve_part( string $ipn, string $addon_name ): ?array {
		if ( '' === $ipn ) {
			return null;
		}

		$product_id = (int) wc_get_product_id_by_sku( $ipn );
		if ( $product_id <= 0 ) {
			$this->logger->warning( 'Add-on maps to an IPN with no WooCommerce product.', [ 'ipn' => $ipn, 'addon' => $addon_name ] );
			return null;
		}

		$product = wc_get_product( $product_id );
		$part_id = 0;
		if ( $product ) {
			$part_id = (int) $product->get_meta( Meta::PART_ID );
		}
		if ( $part_id <= 0 ) {
			$this->logger->warning( 'Add-on product is not managed (no InvenTree part).', [ 'ipn' => $ipn, 'addon' => $addon_name ] );
			return null;
		}

		return [ 'product_id' => $product_id, 'part_id' => $part_id ];
	}
}

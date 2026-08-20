<?php
declare(strict_types=1);

namespace InvenTreeSync\Addons;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// This class manages the mapping of WooCommerce add-ons to InvenTree parts
final class AddonMap {

	public const OPTION = 'inventree_sync_addon_map';

	// Return all mappings, normalised
	public function all(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$mappings = [];
		foreach ( $stored as $row ) {
			if ( is_array( $row ) ) {
				$mappings[] = $this->normalise( $row );
			}
		}
		return $mappings;
	}

	// Save the given mappings to the WordPress options table, normalising them first
	public function save( array $mappings ): void {
		$normalised = [];
		foreach ( $mappings as $row ) {
			if ( is_array( $row ) ) {
				$normalised[] = $this->normalise( $row );
			}
		}
		update_option( self::OPTION, $normalised );
	}

	// Add a new mapping, returning true if it was added, or false if it already exists
	public function add( array $mapping ): bool {
		$mapping  = $this->normalise( $mapping );
		$mappings = $this->all();

		foreach ( $mappings as $existing ) {
			if ( 0 === strcasecmp( $existing['name'], $mapping['name'] )
				&& 0 === strcasecmp( $existing['value'], $mapping['value'] ) ) {
				return false;
			}
		}

		$mappings[] = $mapping;
		$this->save( $mappings );

		return true;
	}

	// Remove a mapping by its index, returning true if it was removed, or false if it did not exist
	public function remove( int $index ): bool {
		$mappings = $this->all();
		if ( ! array_key_exists( $index, $mappings ) ) {
			return false;
		}

		unset( $mappings[ $index ] );
		$this->save( array_values( $mappings ) );

		return true;
	}

	// Find a mapping by its name and value, returning the mapping or null if not found
	public function match( string $name, string $value ): ?array {
		foreach ( $this->all() as $mapping ) {
			if ( 0 !== strcasecmp( $mapping['name'], $name ) ) {
				continue;
			}
			if ( '' !== $mapping['value'] && 0 !== strcasecmp( $mapping['value'], $value ) ) {
				continue;
			}
			return $mapping;
		}
		return null;
	}

	// Normalise a mapping row, ensuring it has the correct keys and types
	private function normalise( array $row ): array {
		$qty = (int) ( $row['qty'] ?? 1 );
		if ( $qty < 1 ) {
			$qty = 1;
		}

		return [
			'name'  => trim( (string) ( $row['name'] ?? '' ) ),
			'value' => trim( (string) ( $row['value'] ?? '' ) ),
			'ipn'   => trim( (string) ( $row['ipn'] ?? '' ) ),
			'qty'   => $qty,
		];
	}
}

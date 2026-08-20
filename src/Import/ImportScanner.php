<?php

declare(strict_types=1);

namespace InvenTreeSync\Import;

use InvenTreeSync\Catalogue\ProductLookup;
use InvenTreeSync\InvenTree\PartRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to scan the InvenTree parts and classify them for import into WooCommerce.
final class ImportScanner {

	public const STATUS_LINKED = 'linked';		// indicates if the part is already linked to a WooCommerce product
	public const STATUS_ADOPT = 'adopt';		// indicates if the part can be adopted by an existing WooCommerce product
	public const STATUS_CREATE = 'create';		// indicates if a new WooCommerce product should be created
	public const STATUS_NO_IPN = 'no_ipn';		// indicates if the part has no IPN, so there is no SKU to match on or create with
	public const STATUS_CONFLICT = 'conflict';	// indicates if the IPN matches a product this plugin cannot manage, such as a variable parent
	public const STATUS_TEMPLATE = 'template';	// indicates if the part is a template, which is not a sellable product
	public const STATUS_TRASHED = 'trashed';	// indicates if the part is linked to a product that is in the trash
	private const PAGE_SIZE = 100;				// The InvenTree API has a default page size of 100
	private const MAX_PARTS = 1000;				// The maximum number of parts to scan in one go

	public function __construct(private PartRepository $parts) {}

	// Scan the InvenTree parts and classify them for import into WooCommerce.
	public function scan(): array {
		$rows      = [];
		$offset    = 0;
		$total     = 0;
		$truncated = false;

		do {
			$page  = $this->parts->fetch_salable_page( self::PAGE_SIZE, $offset );
			$total = $page['count'];

			foreach ( $page['results'] as $part ) {
				$rows[] = $this->describe( $part );
			}

			if ( count( $rows ) >= self::MAX_PARTS ) {
				$truncated = true;
				break;
			}

			$offset += self::PAGE_SIZE;
		} while ( $offset < $total );

		return [
			'rows'         => $rows,
			'total'        => $total,
			'truncated'    => $truncated,
			'active_total' => $this->parts->fetch_active_count(),
		];
	}

	// builds a description of the part, including its classification and product name if linked
	public function describe( array $part ): array {
		$classification = $this->classify( $part );
		$product_name   = '';

		if ( $classification['product_id'] > 0 ) {
			$product = wc_get_product( $classification['product_id'] );
			if ( $product ) {
				$product_name = $product->get_name();
			}
		}

		return [
			'part_id'      => (int) ( $part['pk'] ?? 0 ),
			'ipn'          => trim( (string) ( $part['IPN'] ?? '' ) ),
			'name'         => (string) ( $part['name'] ?? '' ),
			'description'  => (string) ( $part['description'] ?? '' ),
			'available'    => $this->parts->available_for( $part ),
			'status'       => $classification['status'],
			'product_id'   => $classification['product_id'],
			'product_name' => $product_name,
		];
	}

	// Classifies the part for import into WooCommerce.
	public function classify( array $part ): array {
		if ( ! empty( $part['is_template'] ) ) {
			return [ 'status' => self::STATUS_TEMPLATE, 'product_id' => 0 ];
		}

		// If the part is already linked to a WooCommerce product skip it
		$part_id           = (int) ( $part['pk'] ?? 0 );
		$linked_product_id = ProductLookup::find_by_part_id( $part_id );
		if ( $linked_product_id > 0 ) {
			return [ 'status' => self::STATUS_LINKED, 'product_id' => $linked_product_id ];
		}

		// If the part has no IPN, it cannot be imported so skip it
		$ipn = trim( (string) ( $part['IPN'] ?? '' ) );
		if ( '' === $ipn ) {
			return [ 'status' => self::STATUS_NO_IPN, 'product_id' => 0 ];
		}

		// if the part sku matches a WooCommerce product, adopt it
		$product_id_by_sku = (int) wc_get_product_id_by_sku( $ipn );
		if ( $product_id_by_sku > 0 ) {
			$product = wc_get_product( $product_id_by_sku );
			if ( ! $product ) {
				return [ 'status' => self::STATUS_CREATE, 'product_id' => 0 ];
			}
			if ( ProductLookup::is_managed_type( $product ) ) {
				return [ 'status' => self::STATUS_ADOPT, 'product_id' => $product_id_by_sku ];
			}
			return [ 'status' => self::STATUS_CONFLICT, 'product_id' => $product_id_by_sku ];
		}

		// if the part sku matches a WooCommerce product in the trash, mark it as trashed
		$trashed_product_id = ProductLookup::find_trashed( $part_id, $ipn );
		if ( $trashed_product_id > 0 ) {
			return [ 'status' => self::STATUS_TRASHED, 'product_id' => $trashed_product_id ];
		}

		return [ 'status' => self::STATUS_CREATE, 'product_id' => 0 ];
	}

	// Returns true if the part is importable, false otherwise.
	public static function is_importable( string $status ): bool {
		if ( self::STATUS_ADOPT === $status ) {
			return true;
		}
		if ( self::STATUS_CREATE === $status ) {
			return true;
		}
		return false;
	}
}

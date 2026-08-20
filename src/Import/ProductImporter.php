<?php

declare(strict_types=1);

namespace InvenTreeSync\Import;

use InvenTreeSync\Catalogue\IdentityResolver;
use InvenTreeSync\Catalogue\ProductWriter;
use InvenTreeSync\InvenTree\PartRepository;
use InvenTreeSync\Stock\PendingLedger;
use InvenTreeSync\Support\Logger;
use InvenTreeSync\Support\Meta;
use InvenTreeSync\Woo\NotificationSuppressor;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to import InvenTree parts into WooCommerce.
final class ProductImporter {

	public function __construct(
		private PartRepository $parts,
		private ImportScanner $scanner,
		private IdentityResolver $resolver,
		private ProductWriter $writer,
		private PendingLedger $pending,
		private NotificationSuppressor $notifications,
		private Logger $logger,
	) {}

	// Import the selected InvenTree parts into WooCommerce.
	public function import( array $part_ids ): array {
		$result = [
			'adopted'  => 0,
			'created'  => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'messages' => [],
		];

		$this->notifications->suppress();
		try {
			foreach ( $part_ids as $part_id ) {
				try {
					$this->import_one( (int) $part_id, $result );
				} catch ( \Throwable $e ) {
					// Log the error and continue with the next part, rather than aborting the whole import.
					++$result['failed'];
					$result['messages'][] = sprintf(
						__( 'Part %1$d could not be imported: %2$s', 'inventory-sync-for-inventree-and-woocommerce' ),
						$part_id,
						$e->getMessage()
					);
					$this->logger->error(
						'Import failed for a part.',
						[ 'pk' => $part_id, 'error' => $e->getMessage() ]
					);
				}
			}
		} finally {
			$this->notifications->restore();
		}

		$this->logger->info(
			'Product import finished.',
			[
				'adopted' => $result['adopted'],
				'created' => $result['created'],
				'skipped' => $result['skipped'],
				'failed'  => $result['failed'],
			]
		);

		return $result;
	}

	// Import one part into WooCommerce, either by adopting an existing product or creating a new one.
	private function import_one( int $part_id, array &$result ): void {
		$part = $this->parts->fetch_part( $part_id );
		if ( null === $part ) {
			++$result['skipped'];
			$result['messages'][] = sprintf(
				__( 'Part %d no longer exists in InvenTree.', 'inventory-sync-for-inventree-and-woocommerce' ),
				$part_id
			);
			return;
		}

		// confirm the part is still salable and active, as the scan may have been done some time ago
		if ( empty( $part['salable'] ) || empty( $part['active'] ) ) {
			++$result['skipped'];
			$result['messages'][] = sprintf(
				__( '%s is no longer an active salable part, so it was not imported.', 'inventory-sync-for-inventree-and-woocommerce' ),
				(string) ( $part['name'] ?? $part_id )
			);
			return;
		}

		// check the part's classification again, as it may have changed since the scan
		$classification = $this->scanner->classify( $part );

		if ( ImportScanner::STATUS_ADOPT === $classification['status'] ) {
			$this->adopt( $part, $classification['product_id'] );
			++$result['adopted'];
			return;
		}

		if ( ImportScanner::STATUS_CREATE === $classification['status'] ) {
			$this->create( $part );
			++$result['created'];
			return;
		}

		++$result['skipped'];
		$result['messages'][] = sprintf(
			__( '%1$s was skipped; it is no longer importable (%2$s).', 'inventory-sync-for-inventree-and-woocommerce' ),
			(string) ( $part['name'] ?? $part_id ),
			$classification['status']
		);
	}

	// Adopt an existing WooCommerce product for the part, then link and stock it.
	private function adopt( array $part, int $product_id ): void {
		$part_id = (int) $part['pk'];

		update_post_meta( $product_id, Meta::PART_ID, $part_id );

		// Clear any pre-adoption stock reduction to avoid double-counting the stock when the product is adopted.
		$this->resolver->clear_pre_adoption_reduction( $product_id );

		$this->write_stock( $part, $product_id );

		$this->logger->info(
			'Import adopted an existing product.',
			[ 'pk' => $part_id, 'product_id' => $product_id, 'ipn' => $part['IPN'] ?? null ]
		);
	}

	// Create a new WooCommerce product for the part, then link and stock it.
	private function create( array $part ): void {
		$part_id     = (int) $part['pk'];
		$ipn         = trim( (string) ( $part['IPN'] ?? '' ) );
		$name        = trim( (string) ( $part['name'] ?? '' ) );
		$description = trim( (string) ( $part['description'] ?? '' ) );

		if ( '' === $name ) {
			$name = $ipn;
		}

		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_sku( $ipn );

		// Set the product to draft and manage stock, with zero quantity, until the stock is written.
		$product->set_status( 'draft' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );

		if ( '' !== $description ) {
			$product->set_description( $description );
		}

		$product_id = (int) $product->save();
		if ( $product_id <= 0 ) {
			throw new \RuntimeException( 'WooCommerce did not return a product id.' );
		}

		update_post_meta( $product_id, Meta::PART_ID, $part_id );

		$this->write_stock( $part, $product_id );

		$this->logger->info(
			'Import created a draft product.',
			[ 'pk' => $part_id, 'product_id' => $product_id, 'ipn' => $ipn ]
		);
	}

	// Write the stock for the part into WooCommerce, using the ProductWriter to handle the details.
	private function write_stock( array $part, int $product_id ): void {
		$available = $this->parts->available_for( $part );
		$pending   = $this->pending->current( $product_id );
		$this->writer->upsert( $product_id, $part, $available, $pending );
	}
}

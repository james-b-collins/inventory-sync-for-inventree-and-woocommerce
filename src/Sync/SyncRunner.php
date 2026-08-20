<?php

declare(strict_types=1);

namespace InvenTreeSync\Sync;

use Closure;
use InvenTreeSync\Catalogue\IdentityResolver;
use InvenTreeSync\Catalogue\ProductWriter;
use InvenTreeSync\InvenTree\PartRepository;
use InvenTreeSync\Scheduling\Scheduler;
use InvenTreeSync\Stock\PendingLedger;
use InvenTreeSync\Support\Logger;
use InvenTreeSync\Support\StatusReport;
use InvenTreeSync\Woo\NotificationSuppressor;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to run the sync process in batches, either asynchronously or synchronously
final class SyncRunner {

	private const DEFAULT_LIMIT = 50;

	public function __construct(
		private Closure $part_repository_factory,
		private IdentityResolver $resolver,
		private ProductWriter $writer,
		private PendingLedger $pending,
		private NotificationSuppressor $notifications,
		private Logger $logger,
	) {}

	// Start the sync process asynchronously by enqueuing the first batch
	public function start(): void {
		as_enqueue_async_action(
			Scheduler::SYNC_BATCH,
			[ 'limit' => self::DEFAULT_LIMIT, 'offset' => 0 ],
			Scheduler::GROUP
		);
	}

	// Run a single batch of the sync process, processing a page of parts from InvenTree
	public function run_batch( int $limit = self::DEFAULT_LIMIT, int $offset = 0 ): void {
		$repo = ( $this->part_repository_factory )();
		if ( null === $repo ) {
			$this->logger->warning( 'Sync batch skipped: InvenTree not configured.' );
			return;
		}

		// Process a page of parts and log the results
		$result = $this->process_page( $repo, $limit, $offset );
		update_option( StatusReport::LAST_SYNC_OPTION, time() );
		$this->logger->info( 'Sync batch complete.', array_merge( [ 'offset' => $offset ], $result['stats'] ) );

		// If there are more parts to process, enqueue the next batch
		$next = $offset + $limit;
		if ( $next < $result['count'] ) {
			as_enqueue_async_action(
				Scheduler::SYNC_BATCH,
				[ 'limit' => $limit, 'offset' => $next ],
				Scheduler::GROUP
			);
		}
	}

	// Run the entire sync process synchronously, use with the cli
	public function run_all( int $limit = self::DEFAULT_LIMIT ): array {
		$repo = ( $this->part_repository_factory )();
		if ( null === $repo ) {
			throw new \RuntimeException( 'InvenTree is not configured (missing URL or token).' );
		}

		$totals = [ 'updated' => 0, 'unchanged' => 0, 'unmatched' => 0, 'skipped' => 0 ];
		$offset = 0;
		$count  = 0;

		do {
			$result = $this->process_page( $repo, $limit, $offset );
			$count  = $result['count'];
			foreach ( $totals as $key => $_ ) {
				$totals[ $key ] += $result['stats'][ $key ];
			}
			$offset += $limit;
		} while ( $offset < $count );

		update_option( StatusReport::LAST_SYNC_OPTION, time() );

		return [ 'count' => $count, 'stats' => $totals ];
	}

	// Process a single page of parts from InvenTree
	private function process_page( PartRepository $repo, int $limit, int $offset ): array {
		$page  = $repo->fetch_salable_page( $limit, $offset );
		$stats = [ 'updated' => 0, 'unchanged' => 0, 'unmatched' => 0, 'skipped' => 0 ];

		$this->notifications->suppress();
		try {
			foreach ( $page['results'] as $part ) {
				try {
					++$stats[ $this->process_part( $repo, $part ) ];
				} catch ( \Throwable $e ) {
					// One bad part must not abort the batch.
					++$stats['skipped'];
					$this->logger->error(
						'Sync failed for a part.',
						[ 'pk' => $part['pk'] ?? null, 'error' => $e->getMessage() ]
					);
				}
			}
		} finally {
			$this->notifications->restore();
		}

		return [ 'count' => $page['count'], 'stats' => $stats ];
	}

	// Process a single part from InvenTree
	private function process_part( PartRepository $repo, array $part ): string {
		if ( ! empty( $part['is_template'] ) ) {
			return 'skipped';
		}

		$resolution = $this->resolver->resolve( $part );

		// Skip parts that are not managed by the plugin
		if ( IdentityResolver::SKIP === $resolution['action'] ) {
			$this->logger->warning(
				'Sync skipped part; SKU or id resolves to a non-managed product (e.g. a variable parent).',
				[ 'pk' => $part['pk'] ?? null, 'ipn' => $part['IPN'] ?? null ]
			);
			return 'skipped';
		}

		// Log unmatched parts for visibility, but do not create a product for them
		if ( IdentityResolver::CREATE === $resolution['action'] ) {
			$this->logger->info(
				'Unmatched part: no WooCommerce product carries this IPN as its SKU.',
				[ 'pk' => $part['pk'] ?? null, 'ipn' => $part['IPN'] ?? null ]
			);
			return 'unmatched';
		}

		$product_id = (int) $resolution['product_id'];
		$available  = $repo->available_for( $part );
		$pending    = $this->pending->current( $product_id );

		$result = $this->writer->upsert( $product_id, $part, $available, $pending );

		if ( $result['changed'] ) {
			return 'updated';
		}
		return 'unchanged';
	}
}

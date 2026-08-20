<?php

declare(strict_types=1);

namespace InvenTreeSync\Cli;

use InvenTreeSync\InvenTree\ClientException;
use InvenTreeSync\InvenTree\PartRepository;
use InvenTreeSync\Plugin;
use WP_CLI;

use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\get_flag_value;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// This class registers WP-CLI commands for the plugin, and implements the command handlers
final class Commands {

	// Register the WP-CLI commands for this plugin
	public static function register(): void {
		WP_CLI::add_command( 'inventree', self::class );
	}

	// Ping the InvenTree server and report the connection status, server version, and number of salable parts
	public function ping( array $args, array $assoc_args ): void {
		$client = Plugin::instance()->make_client();
		if ( null === $client ) {
			$this->not_configured();
		}

		try {
			$root = $client->get( '' );
			$page = ( new PartRepository( $client ) )->fetch_salable_page( 1, 0 );
		} catch ( ClientException $exception ) {
			WP_CLI::error( 'InvenTree request failed: ' . $exception->getMessage() );
		}

		WP_CLI::log( sprintf( 'URL         : %s', Plugin::instance()->settings()->inventree_url() ) );
		WP_CLI::log( sprintf( 'Server      : %s %s (API %s)', (string) ( $root['server'] ?? 'InvenTree' ), (string) ( $root['version'] ?? '?' ), (string) ( $root['apiVersion'] ?? '?' ) ) );
		WP_CLI::success( sprintf( 'Connected. %d salable, active part(s) visible.', $page['count'] ) );
	}

	// Dump the raw field names (and one sample part) from the part endpoint
	public function fields( array $args, array $assoc_args ): void {
		$client = Plugin::instance()->make_client();
		if ( null === $client ) {
			$this->not_configured();
		}
		$repo = new PartRepository( $client );

		try {
			$pk = get_flag_value( $assoc_args, 'pk' );
			if ( null !== $pk ) {
				$part = $repo->fetch_part( (int) $pk );
				if ( null === $part ) {
					WP_CLI::error( sprintf( 'Part %d not found (404).', (int) $pk ) );
				}
			} else {
				$page = $repo->fetch_salable_page( 1, 0 );
				$part = $page['results'][0] ?? null;
				if ( null === $part ) {
					WP_CLI::error( 'No salable, active parts to inspect.' );
				}
			}
		} catch ( ClientException $exception ) {
			WP_CLI::error( 'InvenTree request failed: ' . $exception->getMessage() );
		}

		$keys = array_keys( $part );
		sort( $keys );
		WP_CLI::log( 'Fields on this part:' );
		WP_CLI::log( '  ' . implode( ', ', $keys ) );
		WP_CLI::log( '' );

		$checks = [
			'in_stock',
			'required_for_sales_orders',
			'required_for_build_orders',
			'allocated_to_sales_orders',
			'allocated_to_build_orders',
			'IPN',
			'salable',
			'active',
			'full_name',
			'is_template',
			'variant_of',
		];
		$rows = [];
		foreach ( $checks as $field ) {
			$present = array_key_exists( $field, $part );
			if ( $present ) {
				$value = $this->stringify( $part[ $field ] );
			} else {
				$value = '-';
			}
			$rows[] = [
				'field'   => $field,
				'present' => $present ? 'yes' : 'no',
				'value'   => $value,
			];
		}
		format_items( 'table', $rows, [ 'field', 'present', 'value' ] );

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Availability mode in force: %s', $repo->availability_mode( $part ) ) );
		WP_CLI::log( '' );
		WP_CLI::log( 'Full sample part JSON:' );
		WP_CLI::log( (string) wp_json_encode( $part, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	// synchronize the salable parts from InvenTree into WooCommerce, creating or updating products as needed
	// --dry-run: report what would be done, but do not write anything
	// --limit=<n>: limit the number of parts to process (default 50)
	public function sync( array $args, array $assoc_args ): void {
		if ( (bool) get_flag_value( $assoc_args, 'dry-run', false ) ) {
			$this->dry_run( $assoc_args );
			return;
		}

		if ( ! Plugin::instance()->settings()->is_enabled() ) {
			WP_CLI::error( 'Inventory Sync is deactivated. Activate it at Settings > Inventory Sync, or use --dry-run to inspect without writing.' );
		}

		if ( ! Plugin::instance()->settings()->mirror_inventory() ) {
			WP_CLI::error( 'Inventory mirroring is off. Enable it at Settings > Inventory Sync, or use --dry-run to inspect without writing.' );
		}

		$runner = Plugin::instance()->sync_runner();
		if ( null === $runner ) {
			WP_CLI::error( 'Sync unavailable (WooCommerce inactive?).' );
		}

		$limit = (int) get_flag_value( $assoc_args, 'limit', 50 );
		if ( $limit < 1 ) {
			$limit = 1;
		}
		WP_CLI::log( 'Syncing salable parts from InvenTree into WooCommerce...' );

		try {
			$result = $runner->run_all( $limit );
		} catch ( ClientException $exception ) {
			WP_CLI::error( 'InvenTree request failed: ' . $exception->getMessage() );
		} catch ( \RuntimeException $exception ) {
			WP_CLI::error( $exception->getMessage() );
		}

		$stats = $result['stats'];
		format_items(
			'table',
			[
				[
					'total'     => $result['count'],
					'updated'   => $stats['updated'],
					'unchanged' => $stats['unchanged'],
					'unmatched' => $stats['unmatched'],
					'skipped'   => $stats['skipped'],
				],
			],
			[ 'total', 'updated', 'unchanged', 'unmatched', 'skipped' ]
		);
		WP_CLI::success( 'Sync complete.' );
	}

	// schedule a recurring background sync, using the interval and settings from the plugin settings page
	public function schedule( array $args, array $assoc_args ): void {
		$plugin    = Plugin::instance();
		$scheduler = $plugin->scheduler();
		if ( null === $scheduler ) {
			WP_CLI::error( 'Scheduler unavailable (WooCommerce inactive?).' );
		}
		$settings = $plugin->settings();
		$interval = $settings->sync_interval_seconds();
		$scheduler->schedule_recurring( $interval, $settings->mirror_inventory(), $settings->create_sales_orders() );

		if ( ! $settings->is_enabled() ) {
			WP_CLI::success( 'Schedule updated. Inventory Sync is deactivated, so nothing was scheduled.' );
			return;
		}

		if ( ! $settings->mirror_inventory() ) {
			WP_CLI::success( 'Schedule updated. Inventory mirroring is off, so no catalogue sync was scheduled.' );
			return;
		}
		WP_CLI::success( sprintf( 'Recurring sync scheduled every %d second(s).', $interval ) );
	}

	// unschedule any recurring background sync
	public function unschedule( array $args, array $assoc_args ): void {
		$scheduler = Plugin::instance()->scheduler();
		if ( null === $scheduler ) {
			WP_CLI::error( 'Scheduler unavailable (WooCommerce inactive?).' );
		}
		$scheduler->unschedule_all();
		WP_CLI::success( 'Recurring sync unscheduled.' );
	}

	// Perform a dry run of the sync, reporting what would be done without writing anything
	private function dry_run( array $assoc_args ): void {
		$repo = Plugin::instance()->make_part_repository();
		if ( null === $repo ) {
			$this->not_configured();
		}

		$limit = (int) get_flag_value( $assoc_args, 'limit', 20 );
		if ( $limit < 1 ) {
			$limit = 1;
		}

		if ( (bool) get_flag_value( $assoc_args, 'all', false ) ) {
			$pages = PHP_INT_MAX;
		} else {
			$pages = (int) get_flag_value( $assoc_args, 'pages', 1 );
			if ( $pages < 1 ) {
				$pages = 1;
			}
		}

		$format = (string) get_flag_value( $assoc_args, 'format', 'table' );

		$rows        = [];
		$modes       = [];
		$offset      = 0;
		$total       = 0;
		$page_number = 0;

		try {
			do {
				$page  = $repo->fetch_salable_page( $limit, $offset );
				$total = $page['count'];

				foreach ( $page['results'] as $part ) {
					$mode = $repo->availability_mode( $part );
					if ( ! isset( $modes[ $mode ] ) ) {
						$modes[ $mode ] = 0;
					}
					++$modes[ $mode ];

					$rows[] = [
						'pk'        => (int) ( $part['pk'] ?? 0 ),
						'ipn'       => (string) ( $part['IPN'] ?? '' ),
						'name'      => (string) ( $part['full_name'] ?? $part['name'] ?? '' ),
						'in_stock'  => $this->stringify( $part['in_stock'] ?? 0 ),
						'mode'      => $mode,
						'available' => $repo->available_for( $part ),
					];
				}

				++$page_number;
				$offset += $limit;
			} while ( $offset < $total && $page_number < $pages );
		} catch ( ClientException $exception ) {
			WP_CLI::error( 'InvenTree request failed: ' . $exception->getMessage() );
		}

		if ( empty( $rows ) ) {
			WP_CLI::warning( 'No salable, active parts returned.' );
			return;
		}

		format_items( $format, $rows, [ 'pk', 'ipn', 'name', 'in_stock', 'mode', 'available' ] );

		if ( 'table' === $format ) {
			$summary = [];
			foreach ( $modes as $mode => $count ) {
				$summary[] = sprintf( '%s=%d', $mode, $count );
			}
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Shown %d of %d part(s). Availability modes: %s', count( $rows ), $total, implode( ', ', $summary ) ) );
			WP_CLI::success( 'Dry run complete. Nothing was written.' );
		}
	}

	// Convert a value to a string for display, handling booleans, arrays, and nulls
	private function stringify( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			return (string) wp_json_encode( $value );
		}
		if ( null === $value ) {
			return 'null';
		}
		return (string) $value;
	}

	// Report that the plugin is not configured, and exit with an error
	private function not_configured(): void {
		WP_CLI::error( 'Not configured. Set the InvenTree URL and token at Settings > Inventory Sync (or the INVENTREE_URL env var + a saved token).' );
	}
}

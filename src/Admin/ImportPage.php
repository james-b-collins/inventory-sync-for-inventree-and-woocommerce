<?php

declare(strict_types=1);

namespace InvenTreeSync\Admin;

use Closure;
use InvenTreeSync\Import\ImportScanner;
use InvenTreeSync\Import\ProductImporter;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// This class renders the admin page for importing InvenTree parts into WooCommerce
final class ImportPage {

	private const IMPORT_NONCE = 'inventree_sync_import';

	public function __construct(
		private Settings $settings,
		private Closure $scanner_factory,
		private Closure $importer_factory,
	) {}

	// Register the page with WordPress
	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_import' ] );
	}

	// Handle a form submission to import selected parts
	public function maybe_import(): void {
		if ( ! isset( $_POST['inventree_sync_import'] ) ) {
			return;
		}
		if ( ! Capabilities::can_use_catalogue_tools() ) {
			return;
		}
		check_admin_referer( self::IMPORT_NONCE );

		$part_ids = $this->posted_part_ids();
		if ( empty( $part_ids ) ) {
			add_settings_error(
				'inventree_sync_import',
				'nothing_selected',
				__( 'No parts were selected, so nothing was imported.', 'inventory-sync-for-inventree-and-woocommerce' ),
				'error'
			);
			return;
		}

		$importer = ( $this->importer_factory )();
		if ( null === $importer ) {
			add_settings_error(
				'inventree_sync_import',
				'not_configured',
				__( 'InvenTree is not configured, so nothing was imported.', 'inventory-sync-for-inventree-and-woocommerce' ),
				'error'
			);
			return;
		}

		$result = $importer->import( $part_ids );

		add_settings_error(
			'inventree_sync_import',
			'imported',
			sprintf(
				__( 'Import finished. Created %1$d, linked %2$d, skipped %3$d, failed %4$d.', 'inventory-sync-for-inventree-and-woocommerce' ),
				$result['created'],
				$result['adopted'],
				$result['skipped'],
				$result['failed']
			),
			'updated'
		);

		foreach ( $result['messages'] as $index => $message ) {
			add_settings_error( 'inventree_sync_import', 'detail_' . $index, $message, 'warning' );
		}
	}

	// Return the part IDs posted in the form, as an array of positive integers
	private function posted_part_ids(): array {
		if ( ! isset( $_POST['part_ids'] ) || ! is_array( $_POST['part_ids'] ) ) {
			return [];
		}

		$part_ids = [];
		foreach ( wp_unslash( $_POST['part_ids'] ) as $raw_part_id ) {
			if ( ! is_scalar( $raw_part_id ) ) {
				continue;
			}
			$part_id = (int) $raw_part_id;
			if ( $part_id > 0 ) {
				$part_ids[] = $part_id;
			}
		}

		return array_values( array_unique( $part_ids ) );
	}

	// Render the page content
	public function render_content( string $page_url ): void {
		?>
		<p><?php echo esc_html__( 'Bring InvenTree parts into WooCommerce. Every part that InvenTree marks as active and salable is listed below, with what an import would do to it. Nothing is imported until you tick it and press the button, and the scheduled sync never creates products on its own.', 'inventory-sync-for-inventree-and-woocommerce' ); ?></p>
		<p class="description"><?php echo esc_html__( 'New products are created as drafts with no price, because InvenTree owns inventory and not pricing. Set a price and publish each one yourself. The name and description are copied once as a starting point and are never overwritten afterwards.', 'inventory-sync-for-inventree-and-woocommerce' ); ?></p>
		<?php

		if ( ! $this->settings->is_configured() ) {
			echo '<div class="notice notice-error inline"><p>';
			echo esc_html__( 'Set the InvenTree URL and API token on the Settings tab before importing.', 'inventory-sync-for-inventree-and-woocommerce' );
			echo '</p></div>';
			return;
		}

		$scanner = ( $this->scanner_factory )();
		if ( null === $scanner ) {
			echo '<div class="notice notice-error inline"><p>';
			echo esc_html__( 'InvenTree is not configured.', 'inventory-sync-for-inventree-and-woocommerce' );
			echo '</p></div>';
			return;
		}

		try {
			$scan = $scanner->scan();
		} catch ( \Throwable $e ) {
			echo '<div class="notice notice-error inline"><p>';
			printf(
				esc_html__( 'Could not read parts from InvenTree: %s', 'inventory-sync-for-inventree-and-woocommerce' ),
				esc_html( $e->getMessage() )
			);
			echo '</p></div>';
			return;
		}

		$this->render_salable_hint( $scan['total'], $scan['active_total'] );

		if ( $scan['truncated'] ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'There are more salable parts than this page will show at once. Import the ones listed here, then reload the tab for the rest.', 'inventory-sync-for-inventree-and-woocommerce' );
			echo '</p></div>';
		}

		if ( empty( $scan['rows'] ) ) {
			echo '<p>' . esc_html__( 'InvenTree returned no active salable parts.', 'inventory-sync-for-inventree-and-woocommerce' ) . '</p>';
			return;
		}

		$this->render_table( $page_url, $scan['rows'] );
	}

	// Render a notice if there are more active parts than salable parts, to explain why some are not listed
	private function render_salable_hint( int $salable_total, int $active_total ): void {
		if ( $active_total <= $salable_total ) {
			return;
		}

		echo '<div class="notice notice-info inline"><p>';
		printf(
			esc_html__( 'InvenTree reports %1$d salable parts out of %2$d active parts. Only salable parts can be sold through a sales order, so this plugin ignores the rest. Tick "Salable" on a part in InvenTree to make it available here.', 'inventory-sync-for-inventree-and-woocommerce' ),
			(int) $salable_total,
			(int) $active_total
		);
		echo '</p></div>';
	}

	// Render the table of parts with checkboxes to select which ones to import
	private function render_table( string $page_url, array $rows ): void {
		?>
		<form action="<?php echo esc_url( $page_url ); ?>" method="post">
			<?php wp_nonce_field( self::IMPORT_NONCE ); ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" id="inventory-sync-check-all" /></td>
						<th><?php echo esc_html__( 'Part', 'inventory-sync-for-inventree-and-woocommerce' ); ?></th>
						<th><?php echo esc_html__( 'IPN', 'inventory-sync-for-inventree-and-woocommerce' ); ?></th>
						<th><?php echo esc_html__( 'Available', 'inventory-sync-for-inventree-and-woocommerce' ); ?></th>
						<th><?php echo esc_html__( 'What an import would do', 'inventory-sync-for-inventree-and-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td class="check-column">
								<?php if ( ImportScanner::is_importable( $row['status'] ) ) : ?>
									<input type="checkbox" name="part_ids[]" value="<?php echo esc_attr( (string) $row['part_id'] ); ?>" aria-label="<?php echo esc_attr( sprintf(__( 'Import %s', 'inventory-sync-for-inventree-and-woocommerce' ), $row['name'] ) ); ?>" />
								<?php endif; ?>
							</td>
							<td><strong><?php echo esc_html( $row['name'] ); ?></strong></td>
							<td>
								<?php if ( '' === $row['ipn'] ) : ?>
									<em><?php echo esc_html__( 'none', 'inventory-sync-for-inventree-and-woocommerce' ); ?></em>
								<?php else : ?>
									<code><?php echo esc_html( $row['ipn'] ); ?></code>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) $row['available'] ); ?></td>
							<td><?php $this->render_status( $row ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Import selected', 'inventory-sync-for-inventree-and-woocommerce' ), 'primary', 'inventree_sync_import' ); ?>
		</form>
		<script>
		document.getElementById( 'inventory-sync-check-all' ).addEventListener( 'change', function ( event ) {
			var boxes = document.querySelectorAll( 'input[name="part_ids[]"]' );
			for ( var index = 0; index < boxes.length; index++ ) {
				boxes[ index ].checked = event.target.checked;
			}
		} );
		</script>
		<?php
	}

	// Render the status message for a part row, explaining what an import would do
	private function render_status( array $row ): void {
		switch ( $row['status'] ) {
			case ImportScanner::STATUS_CREATE:
				echo esc_html__( 'Create a new draft product.', 'inventory-sync-for-inventree-and-woocommerce' );
				break;

			case ImportScanner::STATUS_ADOPT:
				printf(
					esc_html__( 'Link to the existing product "%s", which already has this IPN as its SKU.', 'inventory-sync-for-inventree-and-woocommerce' ),
					esc_html( (string) $row['product_name'] )
				);
				break;

			case ImportScanner::STATUS_LINKED:
				printf(
					esc_html__( 'Nothing. Already linked to "%s".', 'inventory-sync-for-inventree-and-woocommerce' ),
					esc_html( (string) $row['product_name'] )
				);
				break;

			case ImportScanner::STATUS_NO_IPN:
				echo esc_html__( 'Nothing. The part has no IPN, so there is no SKU to match or create with. Give it an IPN in InvenTree.', 'inventory-sync-for-inventree-and-woocommerce' );
				break;

			case ImportScanner::STATUS_CONFLICT:
				printf(
					esc_html__( 'Nothing. The IPN is already the SKU of "%s", which this plugin cannot manage (a variable parent holds no stock of its own). Put the IPN on the variation instead.', 'inventory-sync-for-inventree-and-woocommerce' ),
					esc_html( (string) $row['product_name'] )
				);
				break;

			case ImportScanner::STATUS_TRASHED:
				printf(
					esc_html__( 'Nothing. The product "%s" is in the trash and still holds this SKU. Restore it, then reload this tab to link it; or delete it permanently to create a fresh one.', 'inventory-sync-for-inventree-and-woocommerce' ),
					esc_html( (string) $row['product_name'] )
				);
				break;

			case ImportScanner::STATUS_TEMPLATE:
				echo esc_html__( 'Nothing. Template parts are containers for variants, not sellable stock.', 'inventory-sync-for-inventree-and-woocommerce' );
				break;
		}
	}
}

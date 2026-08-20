<?php

declare(strict_types=1);

namespace InvenTreeSync\Admin;

use InvenTreeSync\Addons\AddonMap;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// This class renders the admin page for managing add-on mappings
final class AddonMappingPage {

	private const ADD_MAPPING_NONCE    = 'inventree_sync_addon_add';
	private const DELETE_MAPPING_NONCE = 'inventree_sync_addon_delete';

	public function __construct(private AddonMap $map, private ?Settings $settings = null) {
		if ( null === $this->settings ) {
			$this->settings = new Settings();
		}
	}

	// Register the page with WordPress
	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_add' ] );
		add_action( 'admin_init', [ $this, 'maybe_delete' ] );
	}

	// called by admin_init to handle a form submission to add a mapping
	public function maybe_add(): void {
		if ( ! isset( $_POST['inventree_sync_addon_add'] ) ) {
			return;
		}
		if ( ! Capabilities::can_use_catalogue_tools() ) {
			return;
		}
		if ( ! $this->settings->addons_enabled() ) {
			return;
		}
		check_admin_referer( self::ADD_MAPPING_NONCE );

		$name = $this->posted_text( 'addon_name' );
		$ipn  = $this->posted_text( 'addon_ipn' );

		if ( '' === $name || '' === $ipn ) {
			add_settings_error(
				'inventree_sync_addons',
				'incomplete',
				__( 'A mapping needs both an add-on name and a part IPN.', 'inventory-sync-for-inventree-and-woocommerce' ),
				'error'
			);
			return;
		}

		$qty = (int) ( $_POST['addon_qty'] ?? 1 );
		if ( $qty < 1 ) {
			$qty = 1;
		}

		$added = $this->map->add(
			[
				'name'  => $name,
				'value' => $this->posted_text( 'addon_value' ),
				'ipn'   => $ipn,
				'qty'   => $qty,
			]
		);

		if ( $added ) {
			add_settings_error(
				'inventree_sync_addons',
				'added',
				__( 'Mapping added.', 'inventory-sync-for-inventree-and-woocommerce' ),
				'updated'
			);
			return;
		}

		add_settings_error(
			'inventree_sync_addons',
			'duplicate',
			__( 'A mapping for that add-on and value already exists.', 'inventory-sync-for-inventree-and-woocommerce' ),
			'error'
		);
	}

	// called by admin_init to handle a form submission to delete a mapping
	public function maybe_delete(): void {
		if ( ! isset( $_POST['inventree_sync_addon_delete'] ) ) {
			return;
		}
		if ( ! Capabilities::can_use_catalogue_tools() ) {
			return;
		}
		if ( ! $this->settings->addons_enabled() ) {
			return;
		}
		check_admin_referer( self::DELETE_MAPPING_NONCE );

		$index = (int) ( $_POST['mapping_index'] ?? -1 );
		if ( $this->map->remove( $index ) ) {
			add_settings_error(
				'inventree_sync_addons',
				'deleted',
				__( 'Mapping deleted.', 'inventory-sync-for-inventree-and-woocommerce' ),
				'updated'
			);
		}
	}

	// Return a posted text field, or an empty string if not present
	private function posted_text( string $key ): string {
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
	}

	// Render the page content
	public function render_content( string $page_url ): void {
		?>
		<p><?php echo esc_html__( 'Map options from the separate WooCommerce Product Add-Ons plugin to InvenTree parts. When a mapped add-on is selected on an order line, the mapped part is reserved as well, at line quantity times the quantity per unit.', 'inventory-sync-for-inventree-and-woocommerce' ); ?></p>
		<p class="description"><?php echo esc_html__( 'Product Add-Ons is a third-party plugin that this one neither includes nor requires. This tab only appears because you switched the integration on in the settings; turning it off hides it again and add-ons are ignored.', 'inventory-sync-for-inventree-and-woocommerce' ); ?></p>

		<h2><?php echo esc_html__( 'Add a mapping', 'inventory-sync-for-inventree-and-woocommerce' ); ?></h2>
		<form action="<?php echo esc_url( $page_url ); ?>" method="post">
			<?php wp_nonce_field( self::ADD_MAPPING_NONCE ); ?>
			<table class="widefat">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Add-on name', 'inventory-sync-for-inventree-and-woocommerce' ); ?></th>
						<th><?php echo esc_html__( 'Selected value', 'inventory-sync-for-inventree-and-woocommerce' ); ?></th>
						<th><?php echo esc_html__( 'Part IPN', 'inventory-sync-for-inventree-and-woocommerce' ); ?></th>
						<th><?php echo esc_html__( 'Qty per unit', 'inventory-sync-for-inventree-and-woocommerce' ); ?></th>
						<th style="width:1%;"></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><input type="text" name="addon_name" class="regular-text" required /></td>
						<td><input type="text" name="addon_value" class="regular-text" /></td>
						<td><input type="text" name="addon_ipn" required /></td>
						<td><input type="number" name="addon_qty" value="1" min="1" step="1" class="small-text" /></td>
						<td><button type="submit" name="inventree_sync_addon_add" value="1" class="button button-primary"><?php echo esc_html__( 'Add mapping', 'inventory-sync-for-inventree-and-woocommerce' ); ?></button></td>
					</tr>
				</tbody>
			</table>
			<p class="description">
				<?php echo esc_html__( 'The add-on name must match the Product Add-Ons field name. For a yes/no add-on set the value to the label that means yes, so it only reserves when chosen; leave the value blank to match any selection. The part IPN must be the SKU of a product this plugin manages.', 'inventory-sync-for-inventree-and-woocommerce' ); ?>
			</p>
		</form>

		<h2><?php echo esc_html__( 'Current mappings', 'inventory-sync-for-inventree-and-woocommerce' ); ?></h2>
		<?php $mappings = $this->map->all(); ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Add-on name', 'inventory-sync-for-inventree-and-woocommerce' ); ?></th>
					<th><?php echo esc_html__( 'Selected value', 'inventory-sync-for-inventree-and-woocommerce' ); ?></th>
					<th><?php echo esc_html__( 'Part IPN', 'inventory-sync-for-inventree-and-woocommerce' ); ?></th>
					<th><?php echo esc_html__( 'Qty per unit', 'inventory-sync-for-inventree-and-woocommerce' ); ?></th>
					<th style="width:1%;"></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $mappings ) ) : ?>
					<tr><td colspan="5"><?php echo esc_html__( 'No mappings yet. Add-ons are ignored until one is added.', 'inventory-sync-for-inventree-and-woocommerce' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $mappings as $index => $mapping ) : ?>
					<tr>
						<td><?php echo esc_html( $mapping['name'] ); ?></td>
						<td>
							<?php if ( '' === $mapping['value'] ) : ?>
								<em><?php echo esc_html__( 'any value', 'inventory-sync-for-inventree-and-woocommerce' ); ?></em>
							<?php else : ?>
								<?php echo esc_html( $mapping['value'] ); ?>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $mapping['ipn'] ); ?></code></td>
						<td><?php echo esc_html( (string) $mapping['qty'] ); ?></td>
						<td>
							<form action="<?php echo esc_url( $page_url ); ?>" method="post">
								<?php wp_nonce_field( self::DELETE_MAPPING_NONCE ); ?>
								<input type="hidden" name="mapping_index" value="<?php echo esc_attr( (string) $index ); ?>" />
								<button type="submit" name="inventree_sync_addon_delete" value="1" class="button-link delete" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: add-on name. */ __( 'Delete the mapping for %s', 'inventory-sync-for-inventree-and-woocommerce' ), $mapping['name'] ) ); ?>">
									<span class="dashicons dashicons-trash"></span>
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}

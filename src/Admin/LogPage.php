<?php

declare(strict_types=1);

namespace InvenTreeSync\Admin;

use InvenTreeSync\Support\LogStore;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// This class renders the admin page for viewing and clearing the plugin log
// This is only visible to administrators
final class LogPage {

	private const NONCE         = 'inventree_sync_log_clear';
	private const DISPLAY_LIMIT = 200;

	public function __construct(private LogStore $store,) {}

	// Register the page with WordPress
	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_clear' ] );
	}

	// Handle a form submission to clear the log
	public function maybe_clear(): void {
		if ( ! isset( $_POST['inventree_sync_log_clear'] ) ) {
			return;
		}
		if ( ! Capabilities::can_manage_plugin() ) {
			return;
		}
		check_admin_referer( self::NONCE );

		$this->store->clear();
		add_settings_error( 'inventree_sync_log', 'cleared', __( 'Log cleared.', 'inventory-sync-for-inventree-and-woocommerce' ), 'updated' );
	}

	// Render the log page content
	public function render_content( string $page_url ): void {
		$entries = $this->store->recent( self::DISPLAY_LIMIT );
		$total   = $this->store->count();

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					__( 'Showing the %1$d most recent of %2$d log entries.', 'inventory-sync-for-inventree-and-woocommerce' ),
					count( $entries ),
					$total
				)
			)
		);
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:160px;"><?php echo esc_html__( 'Time', 'inventory-sync-for-inventree-and-woocommerce' ); ?></th>
					<th style="width:80px;"><?php echo esc_html__( 'Level', 'inventory-sync-for-inventree-and-woocommerce' ); ?></th>
					<th><?php echo esc_html__( 'Message', 'inventory-sync-for-inventree-and-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $entries ) ) : ?>
					<tr><td colspan="3"><?php echo esc_html__( 'No log entries yet.', 'inventory-sync-for-inventree-and-woocommerce' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( (string) $entry->created_at ) ); ?></td>
						<td><?php echo esc_html( (string) $entry->level ); ?></td>
						<td>
							<?php echo esc_html( (string) $entry->message ); ?>
							<?php if ( ! empty( $entry->context ) ) : ?>
								<br /><code style="font-size:11px;"><?php echo esc_html( (string) $entry->context ); ?></code>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form action="<?php echo esc_url( $page_url ); ?>" method="post" style="margin-top:1em;">
			<?php wp_nonce_field( self::NONCE ); ?>
			<?php submit_button( __( 'Clear log', 'inventory-sync-for-inventree-and-woocommerce' ), 'delete', 'inventree_sync_log_clear', false ); ?>
		</form>
		<?php
	}
}

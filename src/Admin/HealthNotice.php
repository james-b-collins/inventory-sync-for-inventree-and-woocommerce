<?php

declare(strict_types=1);

namespace InvenTreeSync\Admin;

use InvenTreeSync\Support\StatusReport;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// This class renders a health notice in the admin area if the plugin is not healthy
final class HealthNotice {

	public function __construct(private StatusReport $report) {}

	// Register the notice with WordPress
	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
	}

	// Render the notice if the plugin is not healthy
	public function render(): void {
		// Only show the notice to users who can manage the plugin
		if ( ! Capabilities::can_manage_plugin() ) {
			return;
		}

		$snapshot = $this->report->snapshot();

		// Prevent the notice from showing if the plugin has never been configured, or if it is healthy
		if ( ! $snapshot['configured'] || $snapshot['healthy'] ) {
			return;
		}

		$problems = [];
		if ( $snapshot['sync']['stale'] ) {
			$problems[] = __( 'the catalogue sync has not run recently (check that a system cron is hitting wp-cron.php)', 'inventory-sync-for-inventree-and-woocommerce' );
		}
		if ( $snapshot['pending']['aging'] ) {
			$problems[] = __( 'stock reservations are aging without being released upstream', 'inventory-sync-for-inventree-and-woocommerce' );
		}

		if ( empty( $problems ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>';
		echo esc_html__( 'Inventory Sync:', 'inventory-sync-for-inventree-and-woocommerce' );
		echo '</strong> ';
		echo esc_html( implode( '; ', $problems ) );
		echo '.</p></div>';
	}
}

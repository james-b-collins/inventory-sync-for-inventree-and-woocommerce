<?php

declare(strict_types=1);

namespace InvenTreeSync\Rest;

use InvenTreeSync\Admin\Capabilities;
use InvenTreeSync\Support\StatusReport;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to provide a REST endpoint for the plugin status.
final class StatusEndpoint {

	public const NAMESPACE = 'inventory-sync/v1';
	public const ROUTE     = '/status';

	public function __construct(private StatusReport $report) {}

	// Register the REST endpoint.
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
	}

	// Handle the REST endpoint request.
	public function register_route(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);
	}

	// Check if the current user is authorized to access the endpoint.
	public function authorize( \WP_REST_Request $request ): bool {
		return Capabilities::can_manage_plugin();
	}

	// Handle the REST endpoint request and return the status report.
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$snapshot = $this->report->snapshot();

		if ( $snapshot['healthy'] ) {
			$status_code = 200;
		} else {
			$status_code = 503;
		}

		return new \WP_REST_Response( $snapshot, $status_code );
	}
}

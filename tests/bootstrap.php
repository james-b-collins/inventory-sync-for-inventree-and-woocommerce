<?php

declare(strict_types=1);

// Plugin files abort unless ABSPATH is defined
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Define constants that WordPress normally defines
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// Define a minimal WP_Error class if it doesn't exist, to avoid fatal errors during testing
if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:ignore
	class WP_Error {
		public function __construct( private string $message = '' ) {}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

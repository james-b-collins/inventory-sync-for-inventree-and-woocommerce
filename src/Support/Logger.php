<?php

declare(strict_types=1);

namespace InvenTreeSync\Support;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to log messages to the WooCommerce logger and optionally to a database table
final class Logger {

	public const SOURCE = 'inventory-sync';

	public function __construct(private ?LogStore $store = null) {}

	// Log an info message
	public function info( string $message, array $context = [] ): void {
		$this->log( 'info', $message, $context );
	}

	// Log a warning message
	public function warning( string $message, array $context = [] ): void {
		$this->log( 'warning', $message, $context );
	}

	// Log an error message
	public function error( string $message, array $context = [] ): void {
		$this->log( 'error', $message, $context );
	}

	// Log a debug message
	private function log( string $level, string $message, array $context ): void {
		if ( null !== $this->store ) {
			$this->store->write( $level, $message, $context );
		}

		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$wc_message = $message;
		if ( ! empty( $context ) ) {
			$wc_message .= ' ' . (string) wp_json_encode( $context );
		}
		wc_get_logger()->log( $level, $wc_message, [ 'source' => self::SOURCE ] );
	}
}

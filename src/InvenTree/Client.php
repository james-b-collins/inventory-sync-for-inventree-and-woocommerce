<?php

declare(strict_types=1);

namespace InvenTreeSync\InvenTree;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Class to handle HTTP requests to the InvenTree API.
final class Client {

	private const API_PREFIX = '/api/';
	private const TIMEOUT    = 20;

	public function __construct(
		private string $base_url,
		private string $token,
	) {
	}

	// Perform a GET request to the InvenTree API.
	public function get( string $path, array $query = [] ): array {
		return $this->request( 'GET', $path, $query, null );
	}

	// Perform a POST request to the InvenTree API.
	public function post( string $path, array $body ): array {
		return $this->request( 'POST', $path, [], $body );
	}

	// Perform a PATCH request to the InvenTree API.
	public function patch( string $path, array $body ): array {
		return $this->request( 'PATCH', $path, [], $body );
	}

	// Perform a DELETE request to the InvenTree API.
	public function delete( string $path ): void {
		$this->request( 'DELETE', $path, [], null );
	}

	// Perform an HTTP request to the InvenTree API.
	private function request( string $method, string $path, array $query, ?array $body ): array {
		$url = $this->url( $path, $query );
		// Use WordPress's HTTP API to perform the request, with the token in the Authorization header.
		$args = [
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => [
				'Authorization' => 'Token ' . $this->token,
				'Accept'        => 'application/json',
			],
		];

		// If there is a body, encode it as JSON and set the Content-Type header.
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			if ( empty( $body ) ) {
				$args['body'] = '{}';
			} else {
				$args['body'] = (string) wp_json_encode( $body );
			}
		}

		$response = wp_remote_request( $url, $args );
		// Check for errors in the response and throw exceptions as needed.
		if ( is_wp_error( $response ) ) {
			throw new ClientException(
				sprintf( '%s %s failed: %s', $method, $path, $response->get_error_message() )
			);
		}

		$status_code   = (int) wp_remote_retrieve_response_code( $response );
		$response_body = (string) wp_remote_retrieve_body( $response );

		if ( 404 === $status_code ) {
			throw new NotFoundException( sprintf( '%s %s: not found', $method, $path ) );
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			throw new ClientException(
				sprintf( '%s %s: HTTP %d %s', $method, $path, $status_code, $this->truncate( $response_body ) )
			);
		}

		if ( '' === $response_body ) {
			return [];
		}
		$decoded_body = json_decode( $response_body, true );
		if ( ! is_array( $decoded_body ) ) {
			throw new ClientException( sprintf( '%s %s: response was not JSON', $method, $path ) );
		}

		return $decoded_body;
	}
	// Build the full URL for the request, including the base URL, API prefix, path, and query parameters.
	private function url( string $path, array $query ): string {
		$url = $this->base_url . self::API_PREFIX . ltrim( $path, '/' );

		if ( empty( $query ) ) {
			return $url;
		}

		// InvenTree expects string booleans (true/false), not 1/0.
		$query_args = [];
		foreach ( $query as $key => $value ) {
			if ( is_bool( $value ) ) {
				$query_args[ $key ] = $value ? 'true' : 'false';
			} else {
				$query_args[ $key ] = $value;
			}
		}

		return add_query_arg( $query_args, $url );
	}

	// Truncate a string to a maximum length, adding ellipsis if it was truncated.
	private function truncate( string $text, int $limit = 300 ): string {
		$text = trim( $text );
		if ( strlen( $text ) > $limit ) {
			return substr( $text, 0, $limit ) . '...';
		}
		return $text;
	}
}

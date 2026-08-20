<?php
// This file tests the Client class
declare(strict_types=1);

namespace InvenTreeSync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use InvenTreeSync\InvenTree\Client;
use InvenTreeSync\InvenTree\ClientException;
use InvenTreeSync\InvenTree\NotFoundException;
use PHPUnit\Framework\TestCase;
use WP_Error;

// this class runs through the InvenTree API client functionality, including GET and POST requests, error handling, and JSON decoding.
// uses Brain Monkey to mock WordPress functions
final class ClientTest extends TestCase {

	// set up brain monkey environment
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $response ) => $response['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $response ) => $response['body'] );
		Functions\when( 'add_query_arg' )->alias(
			static fn( $args, $url ) => $url . '?' . http_build_query( $args )
		);
	}

	// tear down brain monkey environment
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// create a new InvenTree API client with a test URL and token
	private function client(): Client {
		return new Client( 'http://api.test', 'token' );
	}

	// test that a GET request is made to the correct URL with the correct headers
	public function test_get_decodes_json_body(): void {
		Functions\when( 'wp_remote_request' )->justReturn( [ 'code' => 200, 'body' => '{"pk":7,"name":"Item"}' ] );
		$this->assertSame( [ 'pk' => 7, 'name' => 'Item' ], $this->client()->get( 'part/7/' ) );
	}

	// test that a POST request is made to the correct URL with the correct headers and body
	public function test_404_raises_not_found(): void {
		Functions\when( 'wp_remote_request' )->justReturn( [ 'code' => 404, 'body' => '' ] );
		$this->expectException( NotFoundException::class );
		$this->client()->get( 'part/999/' );
	}

	// test that a 500 response raises a ClientException
	public function test_500_raises_client_exception(): void {
		Functions\when( 'wp_remote_request' )->justReturn( [ 'code' => 500, 'body' => 'boom' ] );
		$this->expectException( ClientException::class );
		$this->client()->get( 'part/' );
	}

	// test that a transport failure raises a ClientException
	public function test_transport_failure_raises_client_exception(): void {
		Functions\when( 'wp_remote_request' )->justReturn( new WP_Error( 'no route to host' ) );
		$this->expectException( ClientException::class );
		$this->client()->get( 'part/' );
	}

	// test that a POST request with an empty body is sent as an empty JSON object
	public function test_empty_post_body_serialises_to_object(): void {
		$captured = null;
		Functions\when( 'wp_remote_request' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = $args;
				return [ 'code' => 200, 'body' => '{}' ];
			}
		);

		$this->client()->post( 'order/so/5/cancel/', [] );

		$this->assertSame( '{}', $captured['body'], 'empty body must be {} not []' );
	}

	// test that a POST request with a populated body is sent as a JSON object
	public function test_populated_post_body_is_json_object(): void {
		$captured = null;
		Functions\when( 'wp_remote_request' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = $args;
				return [ 'code' => 201, 'body' => '{"pk":1}' ];
			}
		);

		$this->client()->post( 'order/so/', [ 'customer' => 3 ] );

		$this->assertSame( '{"customer":3}', $captured['body'] );
		$this->assertSame( 'Token token', $captured['headers']['Authorization'] );
	}
}

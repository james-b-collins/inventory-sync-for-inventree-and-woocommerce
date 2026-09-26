<?php
// this file contains integration tests for the push and poll functionality of the plugin
declare(strict_types=1);

namespace InvenTreeSync\Tests\Integration;

use InvenTreeSync\InvenTree\Client;
use InvenTreeSync\InvenTree\SalesOrderRepository;
use InvenTreeSync\Admin\Settings;
use InvenTreeSync\Orders\ReleaseService;
use InvenTreeSync\Push\AllocationPoller;
use InvenTreeSync\Push\SalesOrderPusher;
use InvenTreeSync\Stock\PendingLedger;
use InvenTreeSync\Catalogue\ProductWriter;
use InvenTreeSync\Stock\ReservationStore;
use InvenTreeSync\Support\Logger;
use InvenTreeSync\Support\Meta;

// this class tests that pushing an order to InvenTree and then polling for releases correctly updates the WooCommerce stock and reservations.
final class PushPollTest extends IntegrationTestCase {

	private array $pushed_lines = [];	// lines that have been pushed to the fake InvenTree API

	// sets up the test environment, and adds a filter to fake the InvenTree API responses for testing.
	protected function setUp(): void {
		parent::setUp();
		$this->pushed_lines = [];
		add_filter( 'pre_http_request', [ $this, 'fake_inventree' ], 10, 3 );
	}

	// tears down the test environment, and removes the filter that fakes the InvenTree API responses.
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'fake_inventree' ], 10 );
		parent::tearDown();
	}

	// fakes the InvenTree API responses for testing, returning appropriate JSON data based on the request URL and method.
	public function fake_inventree( $pre, array $args, string $url ): array {
		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );

		$body = [];
		if ( ! empty( $args['body'] ) ) {
			$decoded = json_decode( (string) $args['body'], true );
			if ( is_array( $decoded ) ) {
				$body = $decoded;
			}
		}

		if ( false !== strpos( $url, '/api/company/' ) ) {
			if ( 'POST' === $method ) {
				return $this->json( [ 'pk' => 1 ] );
			}
			return $this->json( [ 'count' => 0, 'results' => [] ] );
		}

		if ( false !== strpos( $url, '/api/order/so-line/' ) ) {
			if ( 'POST' === $method ) {
				$this->pushed_lines[] = $body;
				return $this->json( [ 'pk' => count( $this->pushed_lines ) ] );
			}
			return $this->json( [ 'count' => count( $this->pushed_lines ), 'results' => $this->pushed_lines ] );
		}

		if ( false !== strpos( $url, '/api/order/so/' ) ) {
			if ( 'POST' === $method ) {
				return $this->json( [ 'pk' => 500 ] );
			}
			return $this->json( [ 'count' => 0, 'results' => [] ] );
		}

		return $this->json( [], 404 );
	}

	// helper function to create a JSON response for the fake InvenTree API, with the given data and HTTP status code.
	private function json( array $data, int $code = 200 ): array {
		return [
			'headers'  => [],
			'body'     => (string) wp_json_encode( $data ),
			'response' => [ 'code' => $code, 'message' => '' ],
			'cookies'  => [],
			'filename' => null,
		];
	}

	// test that pushing an order to InvenTree and then polling for releases correctly updates the WooCommerce stock and reservations.
	public function test_push_creates_sales_order_then_poll_releases(): void {
		$product = $this->make_managed_product( 'PP-1', 601, 25 );
		$order   = $this->make_order( [ [ $product, 3 ] ] );
		$order->update_status( 'processing' ); // commit reserves 3

		$store        = new ReservationStore();
		$logger       = new Logger();
		$client       = new Client( 'http://fake', 'token' );
		$repo         = new SalesOrderRepository( $client );
		$repo_factory = static fn (): SalesOrderRepository => $repo;

		// push
		( new SalesOrderPusher( $store, $repo_factory, $logger ) )->push( $order->get_id() );

		$this->assertSame( 500, (int) wc_get_order( $order->get_id() )->get_meta( Meta::ORDER_SALES_ORDER_ID ) );
		$this->assertCount( 1, $this->pushed_lines );
		$this->assertSame( 601, (int) $this->pushed_lines[0]['part'] );
		$this->assertSame( 3, (int) $this->pushed_lines[0]['quantity'] );

		// poll
		$releases = new ReleaseService( new Settings(), $store, new PendingLedger( $store ), new ProductWriter(), $repo_factory, $logger );
		( new AllocationPoller( $store, $repo_factory, new Settings(), $releases, $logger ) )->poll();

		$this->assertSame( 0, $this->pending( $product->get_id() ) );
		$this->assertSame( 25, $this->stock( $product->get_id() ) );
		$this->assertSame( 'yes', (string) wc_get_order( $order->get_id() )->get_meta( Meta::ORDER_RELEASED ) );
	}

	// test that the poll only reaches orders that still hold stock.
	public function test_poll_ignores_orders_that_hold_nothing(): void {
		$product = $this->make_managed_product( 'PP-2', 602, 25 );
		$order   = $this->make_order( [ [ $product, 2 ] ] );

		$order->update_status( 'processing' );

		$store        = new ReservationStore();
		$logger       = new Logger();
		$repo         = new SalesOrderRepository( new Client( 'http://fake', 'token' ) );
		$repo_factory = static fn (): SalesOrderRepository => $repo;
		$releases     = new ReleaseService( new Settings(), $store, new PendingLedger( $store ), new ProductWriter(), $repo_factory, $logger );

		// Remove all reservations for this order to simulate it holding no stock.
		foreach ( $store->for_order( $order->get_id() ) as $reservation ) {
			$store->remove( (int) $reservation->id );
		}
		$order->update_meta_data( Meta::ORDER_SALES_ORDER_ID, 500 );
		$order->save();

		// Ensure that the order initially has no reservations.
		$this->assertSame( [], $store->for_order( $order->get_id() ), 'the order must hold nothing for this test to mean anything' );

		( new AllocationPoller( $store, $repo_factory, new Settings(), $releases, $logger ) )->poll();

		// Assert that the order holding no stock is not visited.
		$this->assertSame(
			'',
			(string) wc_get_order( $order->get_id() )->get_meta( Meta::ORDER_RELEASED ),
			'an order holding nothing must not be visited at all'
		);
	}

	// test that a reservation left behind by a deleted order cannot stop the rest of the run.
	public function test_poll_survives_a_reservation_for_a_missing_order(): void {
		$store = new ReservationStore();
		$store->add( 999999, 0, 0, 603, 'test', 'missing', 4 );

		$product = $this->make_managed_product( 'PP-3', 604, 25 );
		$order   = $this->make_order( [ [ $product, 3 ] ] );
		$order->update_status( 'processing' );

		$logger       = new Logger();
		$repo         = new SalesOrderRepository( new Client( 'http://fake', 'token' ) );
		$repo_factory = static fn (): SalesOrderRepository => $repo;

		( new SalesOrderPusher( $store, $repo_factory, $logger ) )->push( $order->get_id() );

		$releases = new ReleaseService( new Settings(), $store, new PendingLedger( $store ), new ProductWriter(), $repo_factory, $logger );
		( new AllocationPoller( $store, $repo_factory, new Settings(), $releases, $logger ) )->poll();

		$this->assertSame( 'yes', (string) wc_get_order( $order->get_id() )->get_meta( Meta::ORDER_RELEASED ), 'the good order must still release' );
	}
}

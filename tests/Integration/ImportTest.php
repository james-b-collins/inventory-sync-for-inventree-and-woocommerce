<?php
// This file is part of InvenTreeSync, a WooCommerce plugin to synchronize products with InvenTree.
declare(strict_types=1);

namespace InvenTreeSync\Tests\Integration;

use InvenTreeSync\Catalogue\IdentityResolver;
use InvenTreeSync\Catalogue\ProductWriter;
use InvenTreeSync\Import\ImportScanner;
use InvenTreeSync\Import\ProductImporter;
use InvenTreeSync\InvenTree\Client;
use InvenTreeSync\InvenTree\PartRepository;
use InvenTreeSync\Stock\PendingLedger;
use InvenTreeSync\Stock\ReservationStore;
use InvenTreeSync\Support\Logger;
use InvenTreeSync\Support\LogStore;
use InvenTreeSync\Support\Meta;
use InvenTreeSync\Woo\NotificationSuppressor;

// This test class is a full integration test of the import path, including the scanner and importer.
final class ImportTest extends IntegrationTestCase {

	private array $parts = [];	//	Mocked InvenTree parts, keyed by pk.

	// set up the pre_http_request filter to mock InvenTree API responses.
	protected function setUp(): void {
		parent::setUp();
		add_filter( 'pre_http_request', [ $this, 'serve_inventree' ], 10, 3 );
	}

	// tear down the pre_http_request filter to avoid affecting other tests.
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'serve_inventree' ], 10 );
		parent::tearDown();
	}

	// serve_inventree is a callback for the pre_http_request filter that returns mocked InvenTree API responses based on the requested URL.
	public function serve_inventree( $preempt, $args, $url ) {
		$body = [];

		if ( preg_match( '#/api/part/(\d+)/#', (string) $url, $matches ) ) {
			$pk = $matches[1];
			if ( ! isset( $this->parts[ $pk ] ) ) {
				return [
					'response' => [ 'code' => 404 ],
					'body'     => '{}',
				];
			}
			$body = $this->parts[ $pk ];
		} else {
			$rows = array_values( $this->parts );
			$body = [
				'count'   => count( $rows ),
				'results' => $rows,
			];
		}

		return [
			'response' => [ 'code' => 200 ],
			'body'     => (string) wp_json_encode( $body ),
		];
	}

	// add_part adds a mocked InvenTree part to the $parts array, with optional overrides for its properties.
	private function add_part( int $pk, string $ipn, array $overrides = [] ): array {
		$part = array_merge(
			[
				'pk'                        => $pk,
				'IPN'                       => $ipn,
				'name'                      => 'Part ' . $pk,
				'description'               => 'Description for part ' . $pk,
				'active'                    => true,
				'salable'                   => true,
				'is_template'               => false,
				'in_stock'                  => 10,
				'required_for_sales_orders' => 0,
				'required_for_build_orders' => 0,
			],
			$overrides
		);

		$this->parts[ (string) $pk ] = $part;
		return $part;
	}

	// creates a PartRepository instance with a mocked InvenTree client.
	private function make_parts_repository(): PartRepository {
		return new PartRepository( new Client( 'http://inventree.test', 'token' ) );
	}

	// creates an ImportScanner instance with a mocked PartRepository.
	private function make_scanner(): ImportScanner {
		return new ImportScanner( $this->make_parts_repository() );
	}
	// creates a ProductImporter instance with mocked dependencies.
	private function make_importer(): ProductImporter {
		$parts = $this->make_parts_repository();
		$store = new ReservationStore();
		$store->maybe_install();

		return new ProductImporter(
			$parts,
			new ImportScanner( $parts ),
			new IdentityResolver(),
			new ProductWriter(),
			new PendingLedger( $store ),
			new NotificationSuppressor(),
			new Logger( new LogStore( new \InvenTreeSync\Admin\Settings() ) )
		);
	}

	// tests that a part with no matching product is classified as "create".
	public function test_classifies_a_part_with_no_matching_product_as_create(): void {
		$part = $this->add_part( 801, 'IMPORT-1' );

		$classification = $this->make_scanner()->classify( $part );

		$this->assertSame( ImportScanner::STATUS_CREATE, $classification['status'] );
		$this->assertSame( 0, $classification['product_id'] );
	}

	// tests that a part with a matching product SKU is classified as "adopt".
	public function test_classifies_a_matching_sku_as_adopt(): void {
		$product = $this->make_plain_product( 'IMPORT-2', 5 );
		$part    = $this->add_part( 802, 'IMPORT-2' );

		$classification = $this->make_scanner()->classify( $part );

		$this->assertSame( ImportScanner::STATUS_ADOPT, $classification['status'] );
		$this->assertSame( $product->get_id(), $classification['product_id'] );
	}

	// tests that a part already linked to a product is classified as "linked".
	public function test_classifies_an_already_linked_part_as_linked(): void {
		$product = $this->make_managed_product( 'IMPORT-3', 803, 7 );
		$part    = $this->add_part( 803, 'IMPORT-3' );

		$classification = $this->make_scanner()->classify( $part );

		$this->assertSame( ImportScanner::STATUS_LINKED, $classification['status'] );
		$this->assertSame( $product->get_id(), $classification['product_id'] );
	}

	// tests that a part without an IPN is classified as "no_ipn" and is not importable.
	public function test_classifies_a_part_without_an_ipn_as_not_importable(): void {
		$part = $this->add_part( 804, '' );

		$classification = $this->make_scanner()->classify( $part );

		$this->assertSame( ImportScanner::STATUS_NO_IPN, $classification['status'] );
		$this->assertFalse( ImportScanner::is_importable( $classification['status'] ) );
	}

	// tests that a part marked as a template is classified as "template" and is not importable.
	public function test_classifies_a_template_part_as_not_importable(): void {
		$part = $this->add_part( 805, 'IMPORT-5', [ 'is_template' => true ] );

		$classification = $this->make_scanner()->classify( $part );

		$this->assertSame( ImportScanner::STATUS_TEMPLATE, $classification['status'] );
		$this->assertFalse( ImportScanner::is_importable( $classification['status'] ) );
	}

	// tests that a part with a SKU that matches a variable product's parent SKU is classified as "conflict" and is not importable.
	public function test_classifies_a_variable_parent_sku_as_a_conflict(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variable product' );
		$parent->set_sku( 'IMPORT-6' );
		$parent->set_status( 'publish' );
		$parent->save();

		$part = $this->add_part( 806, 'IMPORT-6' );

		$classification = $this->make_scanner()->classify( $part );

		$this->assertSame( ImportScanner::STATUS_CONFLICT, $classification['status'] );
		$this->assertFalse( ImportScanner::is_importable( $classification['status'] ) );
	}

	// tests that the scanner lists every salable part with its availability.
	public function test_scan_lists_every_salable_part_with_its_availability(): void {
		$this->add_part( 807, 'IMPORT-7', [ 'in_stock' => 12, 'required_for_sales_orders' => 4 ] );

		$scan = $this->make_scanner()->scan();

		$this->assertCount( 1, $scan['rows'] );
		$this->assertSame( 807, $scan['rows'][0]['part_id'] );
		$this->assertSame( 8, $scan['rows'][0]['available'] );
		$this->assertFalse( $scan['truncated'] );
	}

	// tests that importing a part with no matching product creates a draft product with the IPN as the SKU.
	public function test_import_creates_a_draft_product_with_the_ipn_as_sku(): void {
		$this->add_part( 808, 'IMPORT-8', [ 'in_stock' => 9 ] );

		$result = $this->make_importer()->import( [ 808 ] );

		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 0, $result['adopted'] );

		$product_id = (int) wc_get_product_id_by_sku( 'IMPORT-8' );
		$this->assertGreaterThan( 0, $product_id );

		$product = wc_get_product( $product_id );
		$this->assertSame( 'draft', $product->get_status(), 'a new product must not be published at no price' );
		$this->assertSame( '', $product->get_price(), 'InvenTree must not set a price' );
		$this->assertTrue( $product->get_manage_stock() );
		$this->assertSame( 9, (int) $product->get_stock_quantity() );
		$this->assertSame( 808, (int) get_post_meta( $product_id, Meta::PART_ID, true ) );
	}

	// tests that importing a part with a matching product SKU adopts the existing product without changing its content.
	public function test_import_adopts_an_existing_product_without_touching_its_content(): void {
		$product = $this->make_plain_product( 'IMPORT-9', 3 );
		$product->set_regular_price( '19.99' );
		$product->save();

		$this->add_part( 809, 'IMPORT-9', [ 'in_stock' => 15 ] );

		$result = $this->make_importer()->import( [ 809 ] );

		$this->assertSame( 1, $result['adopted'] );
		$this->assertSame( 0, $result['created'] );

		$reloaded = wc_get_product( $product->get_id() );
		$this->assertSame( 'Plain IMPORT-9', $reloaded->get_name(), 'the name is WooCommerce content' );
		$this->assertSame( '19.99', $reloaded->get_regular_price(), 'the price is WooCommerce content' );
		$this->assertSame( 'publish', $reloaded->get_status() );
		$this->assertSame( 15, (int) $reloaded->get_stock_quantity(), 'stock now comes from InvenTree' );
		$this->assertSame( 809, (int) get_post_meta( $product->get_id(), Meta::PART_ID, true ) );
	}

	// tests that importing a part that is no longer salable skips the import and does not create a product.
	public function test_import_skips_a_part_that_is_no_longer_salable(): void {
		$this->add_part( 810, 'IMPORT-10', [ 'salable' => false ] );

		$result = $this->make_importer()->import( [ 810 ] );

		$this->assertSame( 0, $result['created'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 0, (int) wc_get_product_id_by_sku( 'IMPORT-10' ) );
	}

	// tests that importing a part that no longer exists in InvenTree skips the import and does not create a product.
	public function test_import_skips_a_part_that_no_longer_exists(): void {
		$result = $this->make_importer()->import( [ 999 ] );

		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 0, $result['created'] );
	}

	// tests that a trashed product holding the SKU blocks a duplicate create.
	public function test_a_trashed_product_holding_the_sku_blocks_a_duplicate_create(): void {
		$this->add_part( 812, 'IMPORT-12' );

		$importer = $this->make_importer();
		$importer->import( [ 812 ] );

		$product_id = (int) wc_get_product_id_by_sku( 'IMPORT-12' );
		wp_trash_post( $product_id );

		// Nothing else sees the trash, so without the check this creates a duplicate SKU.
		$scanner        = $this->make_scanner();
		$classification = $scanner->classify( $this->parts['812'] );

		$this->assertSame( ImportScanner::STATUS_TRASHED, $classification['status'] );
		$this->assertSame( $product_id, $classification['product_id'] );
		$this->assertFalse( ImportScanner::is_importable( $classification['status'] ) );

		$result = $importer->import( [ 812 ] );

		$this->assertSame( 0, $result['created'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	// tests that a restored product is adopted rather than duplicated.
	public function test_a_restored_product_is_adopted_rather_than_duplicated(): void {
		$this->add_part( 813, 'IMPORT-13' );

		$importer = $this->make_importer();
		$importer->import( [ 813 ] );

		$product_id = (int) wc_get_product_id_by_sku( 'IMPORT-13' );
		wp_trash_post( $product_id );
		wp_untrash_post( $product_id );

		$classification = $this->make_scanner()->classify( $this->parts['813'] );
		$this->assertSame( ImportScanner::STATUS_LINKED, $classification['status'] );
		$this->assertSame( $product_id, $classification['product_id'] );

		$products = wc_get_products( [ 'sku' => 'IMPORT-13', 'status' => 'any', 'limit' => -1 ] );
		$this->assertCount( 1, $products );
	}

	// tests that importing the same part twice does not duplicate the product.
	public function test_importing_the_same_part_twice_does_not_duplicate_the_product(): void {
		$this->add_part( 811, 'IMPORT-11' );

		$importer = $this->make_importer();
		$importer->import( [ 811 ] );
		$second = $importer->import( [ 811 ] );

		$this->assertSame( 0, $second['created'] );
		$this->assertSame( 1, $second['skipped'] );

		$products = wc_get_products( [ 'sku' => 'IMPORT-11', 'status' => 'any', 'limit' => -1 ] );
		$this->assertCount( 1, $products );
	}
}

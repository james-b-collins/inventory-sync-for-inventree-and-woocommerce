<?php
// Create a small set of test parts in InvenTree, so the read path has something to sync against.

use InvenTreeSync\InvenTree\ClientException;
use InvenTreeSync\Plugin;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {return;}

// Make sure the plugin is configured and connected to InvenTree
$client = Plugin::instance()->make_client();
if ( null === $client ) {
	WP_CLI::error( 'Not configured. Save the InvenTree URL and token first.' );
}

// 5 test parts, with a mix of stock levels
$parts = [
	[ 'TEST-001', 'Item 1', 25 ],
	[ 'TEST-002', 'Item 2', 0 ],
	[ 'TEST-003', 'Item 3', 100 ],
	[ 'TEST-004', 'Item 4', 7 ],
	[ 'TEST-005', 'Item 5', 12.5 ],
];

/**
 * Find an existing category by exact name, or create it. Returns its PK.
 */

//Create a category called "WooCommerce Test" if it doesn't exist, and return its primary key
$ensure_category = static function ( $client, string $name ): int {
	$existing = $client->get( 'part/category/', [ 'search' => $name, 'limit' => 100 ] );
	$rows     = $existing['results'] ?? $existing;
	foreach ( $rows as $row ) {
		if ( isset( $row['name'] ) && $row['name'] === $name ) {
			return (int) $row['pk'];
		}
	}
	$created = $client->post(
		'part/category/',
		[
			'name'        => $name,
			'description' => 'Test parts for the WooCommerce sync plugin. Safe to delete.',
		]
	);
	return (int) $created['pk'];
};

//Find a part by its IPN. Returns the part row or null if not found
$find_part_by_ipn = static function ( $client, string $ipn ): ?array {
	$res  = $client->get( 'part/', [ 'IPN' => $ipn, 'limit' => 1 ] );
	$rows = $res['results'] ?? $res;
	return $rows[0] ?? null;
};

// Create the test category and parts, skipping any that already exist
try {
	$category_id = $ensure_category( $client, 'WooCommerce Test' );
	WP_CLI::log( sprintf( 'Category "WooCommerce Test" = pk %d', $category_id ) );

	$created = 0;
	$skipped = 0;

	foreach ( $parts as [ $ipn, $name, $quantity ] ) {
		if ( null !== $find_part_by_ipn( $client, $ipn ) ) {
			WP_CLI::log( sprintf( '  skip  %-10s %s (already exists)', $ipn, $name ) );
			++$skipped;
			continue;
		}

		$part = $client->post(
			'part/',
			[
				'name'         => $name,
				'IPN'          => $ipn,
				'description'  => 'WooCommerce sync test part.',
				'category'     => $category_id,
				'salable'      => true,
				'active'       => true,
				'purchaseable' => false,
				'component'    => false,
			]
		);
		$part_id = (int) $part['pk'];

		if ( $quantity > 0 ) {
			$client->post(
				'stock/',
				[
					'part'     => $part_id,
					'quantity' => $quantity,
				]
			);
		}

		WP_CLI::log( sprintf( '  create %-10s %-32s pk=%-4d stock=%s', $ipn, $name, $part_id, $quantity ) );
		++$created;
	}

	WP_CLI::success( sprintf( 'Seed complete: %d created, %d skipped.', $created, $skipped ) );
} 
catch ( ClientException $e ) {
	WP_CLI::error( 'InvenTree request failed: ' . $e->getMessage() );
}

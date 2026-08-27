<?php
// Seed InvenTree with parts and stock from a CSV file

use InvenTreeSync\InvenTree\ClientException;
use InvenTreeSync\Plugin;

// Ensure this script is run within the WP-CLI context
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {return;}

$file  = $args[0] ?? '';
$limit = isset( $args[1] ) ? (int) $args[1] : 0;

if ( '' === $file ) {
	WP_CLI::error( 'Usage: wp eval-file scripts/seed-inventree-from-file.php <file.csv> [limit]' );
}
if ( ! is_readable( $file ) ) {
	WP_CLI::error( sprintf( 'Cannot read file: %s', $file ) );
}

// Category used for any row that has no category of its own
$default_category = 'WooCommerce Test';

// Column aliases for mapping CSV headers to actual field names
$column_aliases = [
	'ipn'         => [ 'ipn', 'internal part number' ],
	'name'        => [ 'name', 'part', 'title' ],
	'description' => [ 'description', 'desc' ],
	'quantity'    => [ 'in stock', 'quantity', 'qty', 'stock', 'in_stock', 'total stock' ],
	'category'    => [ 'category name', 'category' ],
	'active'      => [ 'active' ],
	'salable'     => [ 'salable', 'saleable' ],
];

// Helper function to interpret a CSV cell value as a boolean
$as_bool = static function ( ?string $value ): bool {
	if ( null === $value ) {
		return true;
	}
	$value = strtolower( trim( $value ) );
	if ( '' === $value ) {
		return true;
	}
	return in_array( $value, [ '1', 'true', 'yes', 'y', 't' ], true );
};

// Open the CSV file for reading
$handle = fopen( $file, 'r' );
if ( false === $handle ) {
	WP_CLI::error( sprintf( 'Could not open file: %s', $file ) );
}

// Read the header row from the CSV file
$header = fgetcsv( $handle );
if ( ! is_array( $header ) ) {
	WP_CLI::error( 'The file has no header row.' );
}

// Remove the byte order mark from the first header cell, if present (inserted by Excel)
$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );

// Convert the header titles to lowercase and trim whitespace
$titles = [];
foreach ( $header as $index => $title ) {
	$titles[ $index ] = strtolower( trim( (string) $title ) );
}

// Determine the column indices for each field based on the header row and the defined aliases
$columns = [];
foreach ( $column_aliases as $canonical => $aliases ) {
	foreach ( $aliases as $alias ) {
		$index = array_search( $alias, $titles, true );
		if ( false !== $index ) {
			$columns[ $canonical ] = $index;
			continue 2;
		}
	}
}

// Ensure that at least one of the 'name' or 'ipn' columns is present
if ( ! isset( $columns['name'] ) && ! isset( $columns['ipn'] ) ) {
	WP_CLI::error( sprintf( 'Need at least a name or an IPN column. Header was: %s', implode( ', ', $header ) ) );
}

// Helper function to retrieve a cell value from a row based on the column mapping
$cell = static function ( array $row, array $columns, string $name ): ?string {
	if ( ! isset( $columns[ $name ] ) ) {
		return null;
	}
	$value = $row[ $columns[ $name ] ] ?? null;
	if ( null === $value ) {
		return null;
	}
	return trim( (string) $value );
};

// Read the rows from the CSV file into an array, keeping track of rows with no IPN or name
$rows    = [];
$no_ipn  = 0;
while ( false !== ( $row = fgetcsv( $handle ) ) ) {
	if ( ! is_array( $row ) || [ null ] === $row ) {
		continue;
	}
	$ipn  = (string) ( $cell( $row, $columns, 'ipn' ) ?? '' );
	$name = (string) ( $cell( $row, $columns, 'name' ) ?? '' );

	// Skip rows that have neither an IPN nor a name
	if ( '' === $ipn && '' === $name ) {
		++$no_ipn;
		continue;
	}
	// Add the row to the array of valid rows
	$rows[] = [
		'ipn'         => $ipn,
		'name'        => $name ?: $ipn,
		'description' => $cell( $row, $columns, 'description' ) ?: 'Imported from ' . basename( $file ),
		'quantity'    => (float) ( $cell( $row, $columns, 'quantity' ) ?? 0 ),
		'category'    => $cell( $row, $columns, 'category' ) ?: $default_category,
		'active'      => $as_bool( $cell( $row, $columns, 'active' ) ),
		'salable'     => $as_bool( $cell( $row, $columns, 'salable' ) ),
	];
}
// Close the CSV file after reading all rows
fclose( $handle );

// Report the number of rows read and any skipped rows due to missing IPN and name
WP_CLI::log( sprintf( 'Read %d row(s) from %s', count( $rows ), $file ) );
if ( $no_ipn > 0 ) {
	WP_CLI::warning( sprintf( '%d row(s) skipped: no name and no IPN.', $no_ipn ) );
}

// Apply the row limit if specified
if ( $limit > 0 && count( $rows ) > $limit ) {
	$rows = array_slice( $rows, 0, $limit );
	WP_CLI::log( sprintf( 'Limited to the first %d row(s).', $limit ) );
}

// Ensure there are rows to import
if ( empty( $rows ) ) {
	WP_CLI::error( 'Nothing to import.' );
}

// Initialize the InvenTree client for API communication
$client = Plugin::instance()->make_client();
if ( null === $client ) {
	WP_CLI::error( 'Not configured. Save the InvenTree URL and token first.' );
}

// Check if a category exists by name, or create it if it does not
$category_cache  = [];
$ensure_category = static function ( $client, string $name ) use ( &$category_cache ): int {
	if ( isset( $category_cache[ $name ] ) ) {
		return $category_cache[ $name ];
	}
	$existing = $client->get( 'part/category/', [ 'search' => $name, 'limit' => 100 ] );
	foreach ( ( $existing['results'] ?? $existing ) as $row ) {
		if ( isset( $row['name'] ) && $row['name'] === $name ) {
			$category_cache[ $name ] = (int) $row['pk'];
			return $category_cache[ $name ];
		}
	}
	$created                 = $client->post( 'part/category/', [ 'name' => $name, 'description' => 'Created by seed-inventree-from-file.' ] );
	$category_cache[ $name ] = (int) $created['pk'];
	return $category_cache[ $name ];
};

// Check if a part already exists by IPN or name to avoid creating duplicates
$find_existing = static function ( $client, string $ipn, string $name ): ?array {
	if ( '' !== $ipn ) {
		$res = $client->get( 'part/', [ 'IPN' => $ipn, 'limit' => 1 ] );
		return ( $res['results'] ?? $res )[0] ?? null;
	}

	$res = $client->get( 'part/', [ 'search' => $name, 'limit' => 50 ] );
	foreach ( ( $res['results'] ?? $res ) as $row ) {
		if ( isset( $row['name'] ) && $row['name'] === $name ) {
			return $row;
		}
	}
	return null;
};

$created = 0;
$skipped = 0;
$failed  = 0;

// Iterate over each row and attempt to create the part in InvenTree
foreach ( $rows as $row ) {
	try {

		// Skip this row if the part already exists
		if ( null !== $find_existing( $client, $row['ipn'], $row['name'] ) ) {
			WP_CLI::log( sprintf( '  skip   %-16s %s (already exists)', $row['ipn'] ?: '-', $row['name'] ) );
			++$skipped;
			continue;
		}

		$payload = [
			'name'         => $row['name'],
			'description'  => $row['description'],
			'category'     => $ensure_category( $client, $row['category'] ),
			'salable'      => $row['salable'],
			'active'       => $row['active'],
			'purchaseable' => false,
			'component'    => false,
		];

		// Only include the IPN in the payload if it is not an empty string
		if ( '' !== $row['ipn'] ) {
			$payload['IPN'] = $row['ipn'];
		}

		$part    = $client->post( 'part/', $payload );
		$part_id = (int) $part['pk'];

		// If the row specifies a quantity, create a stock item for this part
		if ( $row['quantity'] > 0 ) {
			$client->post( 'stock/', [ 'part' => $part_id, 'quantity' => $row['quantity'] ] );
		}

		WP_CLI::log( sprintf( '  create %-16s %-32s pk=%-5d stock=%s', $row['ipn'], substr( $row['name'], 0, 32 ), $part_id, $row['quantity'] ) );
		++$created;
	}
	
	// Catch any client exceptions and log a warning, then continue with the next row
	catch ( ClientException $e ) {
		WP_CLI::warning( sprintf( '%s failed: %s', $row['ipn'], $e->getMessage() ) );
		++$failed;
	}
}

WP_CLI::success( sprintf( 'Done: %d created, %d skipped, %d failed.', $created, $skipped, $failed ) );

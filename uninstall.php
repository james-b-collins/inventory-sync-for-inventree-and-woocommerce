<?php

declare(strict_types=1);

// Exit if accessed directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// All plugin options
$inventree_sync_options = [
	'inventree_sync_settings',
	'inventree_sync_enabled',
	'inventree_sync_addon_map',
	'inventree_sync_db_version',
	'inventree_sync_log_db_version',
	'inventree_sync_last_sync',
];

// loop through the options and delete them
foreach ( $inventree_sync_options as $inventree_sync_option ) {
	delete_option( $inventree_sync_option );
}

// all product/variation meta keys used by the plugin
$inventree_sync_meta_keys = [
	'_inventree_part_id',
	'_inventree_qty',
	'_inventree_pending',
];

// loop through the meta keys and delete them
foreach ( $inventree_sync_meta_keys as $inventree_sync_meta_key ) {
	delete_post_meta_by_key( $inventree_sync_meta_key );
}

// Unschedule the background actions
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', [], 'inventory-sync' );
}

// all plugin tables
$inventree_sync_tables = [
	$wpdb->prefix . 'inventree_reservations',
	$wpdb->prefix . 'inventree_log',
];

// loop through the tables and drop them
foreach ( $inventree_sync_tables as $inventree_sync_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$inventree_sync_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

<?php

declare(strict_types=1);

namespace InvenTreeSync\Admin;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// This class defines the capabilities required to use the plugin's features
final class Capabilities {

	// Settings, the master switch and the log. Administrators only.
	public const MANAGE_PLUGIN = 'manage_options';

	// Importing products and mapping add-ons. Shop managers and administrators.
	public const USE_CATALOGUE_TOOLS = 'manage_woocommerce';

	public static function can_manage_plugin(): bool {
		return current_user_can( self::MANAGE_PLUGIN );
	}

	public static function can_use_catalogue_tools(): bool {
		return current_user_can( self::USE_CATALOGUE_TOOLS );
	}
}

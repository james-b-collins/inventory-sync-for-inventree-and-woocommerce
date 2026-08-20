<?php

declare(strict_types=1);

namespace InvenTreeSync\Admin;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// This class renders the admin page for managing the plugin's settings
final class SettingsPage {

	private const GROUP   = 'inventree_sync';
	private const PAGE    = 'inventory-sync-settings';
	private const SECTION = 'inventree_sync_main';

	private const TOGGLE_NONCE = 'inventree_sync_toggle_enabled';

	private const TOGGLE_NONCE_FIELD = 'inventree_sync_toggle_nonce';

	public function __construct(
		private Settings $settings,
		private AddonMappingPage $addons,
		private ImportPage $import,
		private LogPage $log,
	) {}

	// Register the page with WordPress
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'maybe_toggle_enabled' ] );
	}

	// Handle a form submission to toggle the plugin's master switch on or off
	// Saved in its own option, so the settings sanitiser won't overwrite it
	public function maybe_toggle_enabled(): void {
		if ( ! isset( $_POST['inventree_sync_toggle'] ) ) {
			return;
		}
		if ( ! Capabilities::can_manage_plugin() ) {
			return;
		}
		check_admin_referer( self::TOGGLE_NONCE, self::TOGGLE_NONCE_FIELD );

		if ( $this->settings->is_enabled() ) {
			$now_enabled = 0;
		} else {
			$now_enabled = 1;
		}

		update_option( Settings::ENABLED_OPTION, $now_enabled );

		if ( $now_enabled ) {
			$message = __( 'Inventory Sync is now active. The background jobs have been scheduled.', 'inventory-sync-for-inventree-and-woocommerce' );
		} else {
			$message = __( 'Inventory Sync is now inactive. Background jobs are unscheduled and any stock it was holding has been released.', 'inventory-sync-for-inventree-and-woocommerce' );
		}

		add_settings_error( 'inventree_sync_toggle', 'toggled', $message, 'updated' );
	}

	// Add the settings page to the WordPress admin menu
	public function add_menu(): void {
		add_options_page(
			__( 'Inventory Sync', 'inventory-sync-for-inventree-and-woocommerce' ),
			__( 'Inventory Sync', 'inventory-sync-for-inventree-and-woocommerce' ),

			Capabilities::USE_CATALOGUE_TOOLS,
			self::PAGE,
			[ $this, 'render' ]
		);
	}

	// Register the settings, section and fields with WordPress
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			[ 'sanitize_callback' => [ $this, 'sanitize' ] ]
		);

		add_settings_section( self::SECTION, '', '__return_false', self::PAGE );

		$fields = [
			'inventree_url'           => __( 'InvenTree URL', 'inventory-sync-for-inventree-and-woocommerce' ),
			'inventree_token'         => __( 'API token', 'inventory-sync-for-inventree-and-woocommerce' ),
			'mirror_inventory'        => __( 'Mirror inventory', 'inventory-sync-for-inventree-and-woocommerce' ),
			'create_sales_orders'     => __( 'Create sales orders', 'inventory-sync-for-inventree-and-woocommerce' ),
			'addons_enabled'          => __( 'Product Add-Ons support', 'inventory-sync-for-inventree-and-woocommerce' ),
			'committing_statuses'     => __( 'Committing statuses', 'inventory-sync-for-inventree-and-woocommerce' ),
			'sync_interval'           => __( 'Sync interval (seconds)', 'inventory-sync-for-inventree-and-woocommerce' ),
			'aging_pending_threshold' => __( 'Stuck reservation warning (seconds)', 'inventory-sync-for-inventree-and-woocommerce' ),
			'log_retention'           => __( 'Log records to keep', 'inventory-sync-for-inventree-and-woocommerce' ),
		];

		foreach ( $fields as $key => $label ) {
			add_settings_field(
				$key,
				$label,
				[ $this, 'render_field' ],
				self::PAGE,
				self::SECTION,
				[ 'key' => $key, 'label_for' => $key ]
			);
		}
	}

	// Sanitize the settings before saving them to the database. Called by WordPress when the settings form is submitted.
	public function sanitize( $raw ): array {
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}
		$raw_statuses = $raw['committing_statuses'] ?? '';
		if ( is_array( $raw_statuses ) ) {
			$status_list = $raw_statuses;
		} else {
			$status_list = explode( ',', (string) $raw_statuses );
		}

		$statuses = [];
		foreach ( $status_list as $status ) {
			$status = sanitize_key( trim( (string) $status ) );
			if ( '' !== $status ) {
				$statuses[] = $status;
			}
		}
		if ( empty( $statuses ) ) {
			$statuses = Settings::DEFAULT_COMMITTING_STATUSES;
		}

		$sync_interval = (int) ( $raw['sync_interval'] ?? 900 );
		if ( $sync_interval < 60 ) {
			$sync_interval = 60;
		}

		$aging_threshold = (int) ( $raw['aging_pending_threshold'] ?? DAY_IN_SECONDS );
		if ( $aging_threshold < 60 ) {
			$aging_threshold = 60;
		}

		$log_retention = (int) ( $raw['log_retention'] ?? 500 );
		if ( $log_retention < 50 ) {
			$log_retention = 50;
		}

		// return the sanitized settings array. Note that the master switch is not included here
		return [
			'inventree_url'           => esc_url_raw( trim( (string) ( $raw['inventree_url'] ?? '' ) ) ),
			'inventree_token'         => $this->sanitize_token( $raw['inventree_token'] ?? '' ),
			'mirror_inventory'        => empty( $raw['mirror_inventory'] ) ? 0 : 1,
			'create_sales_orders'     => empty( $raw['create_sales_orders'] ) ? 0 : 1,
			'addons_enabled'          => empty( $raw['addons_enabled'] ) ? 0 : 1,
			'committing_statuses'     => $statuses,
			'sync_interval'           => $sync_interval,
			'aging_pending_threshold' => $aging_threshold,
			'log_retention'           => $log_retention,
		];
	}

	// Sanitize the token field. If the user left it blank, keep the stored token instead of overwriting it with an empty string.
	private function sanitize_token( $raw_token ): string {
		if ( ! is_scalar( $raw_token ) ) {
			$raw_token = '';
		}
		$submitted_token = sanitize_text_field( trim( (string) $raw_token ) );

		if ( '' !== $submitted_token ) {
			return $submitted_token;
		}

		$stored = get_option( Settings::OPTION, [] );
		if ( is_array( $stored ) && isset( $stored['inventree_token'] ) ) {
			return (string) $stored['inventree_token'];
		}
		return '';
	}

	// Detect whether the Product Add-Ons plugin is installed and active
	private static function product_addons_detected(): bool {
		if ( class_exists( 'WC_Product_Addons' ) ) {
			return true;
		}
		if ( defined( 'WC_PRODUCT_ADDONS_VERSION' ) ) {
			return true;
		}
		return false;
	}

	// Mask a token for display, showing only the last few characters.
	// prevents the real token from being in the page source
	private static function mask_token( string $token ): string {
		$visible_characters = 4;
		if ( strlen( $token ) <= $visible_characters ) {
			return str_repeat( "\u{2022}", 12 );
		}
		return str_repeat( "\u{2022}", 12 ) . substr( $token, -$visible_characters );
	}

	// Renders the settings field
	public function render_field( array $args ): void {
		$key    = $args['key'];
		$name   = Settings::OPTION . '[' . $key . ']';
		$stored = get_option( Settings::OPTION, [] );
		$value  = $stored[ $key ] ?? '';

		switch ( $key ) {
			case 'mirror_inventory':
			case 'create_sales_orders':
				if ( array_key_exists( $key, $stored ) ) {
					$checked = ! empty( $value );
				} else {
					$checked = true;
				}
				printf(
					'<input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s />',
					esc_attr( $key ),
					esc_attr( $name ),
					checked( $checked, true, false )
				);
				if ( 'mirror_inventory' === $key ) {
					echo '<p class="description">' . esc_html__( 'Sync stock from InvenTree onto matched WooCommerce products. Turn this off if WooCommerce should own its own stock levels.', 'inventory-sync-for-inventree-and-woocommerce' ) . '</p>';
				}
				if ( 'create_sales_orders' === $key ) {
					echo '<p class="description">' . esc_html__( 'Record WooCommerce orders in InvenTree as sales orders. Turn this off if orders are recorded some other way. Any stock still reserved is released when you turn this off.', 'inventory-sync-for-inventree-and-woocommerce' ) . '</p>';
				}
				break;

			case 'addons_enabled':
				printf(
					'<input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s />',
					esc_attr( $key ),
					esc_attr( $name ),
					checked( $this->settings->addons_enabled(), true, false )
				);
				echo '<p class="description">' . esc_html__( 'Optional integration with the separate WooCommerce Product Add-Ons plugin, which this plugin does not include and is not affiliated with. Turn it on only if you use that plugin and some of its options consume stock. It adds an "Add-on mapping" tab where you say which add-on options map to which InvenTree parts; until you map one, nothing changes. Leave it off and add-ons are ignored entirely.', 'inventory-sync-for-inventree-and-woocommerce' ) . '</p>';

				if ( $this->settings->addons_enabled() && ! self::product_addons_detected() ) {
					echo '<p class="description" style="color:#b32d2e;">' . esc_html__( 'The Product Add-Ons plugin was not detected on this site, so no add-on selections will be found on orders.', 'inventory-sync-for-inventree-and-woocommerce' ) . '</p>';
				}
				break;
			case 'inventree_url':
				printf(
					'<input type="url" id="%1$s" name="%2$s" value="%3$s" class="regular-text" placeholder="http://host.docker.internal:8080" />',
					esc_attr( $key ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				echo '<p class="description">' . esc_html__( 'InvenTree root URL (no trailing /api). Falls back to the INVENTREE_URL env var if blank.', 'inventory-sync-for-inventree-and-woocommerce' ) . '</p>';
				break;

			case 'inventree_token':
				$stored_token = trim( (string) $value );
				if ( '' === $stored_token ) {
					$placeholder = __( 'Paste the InvenTree API token', 'inventory-sync-for-inventree-and-woocommerce' );
				} else {
					$placeholder = self::mask_token( $stored_token );
				}

				printf(
					'<input type="password" id="%1$s" name="%2$s" value="" class="regular-text" autocomplete="off" placeholder="%3$s" />',
					esc_attr( $key ),
					esc_attr( $name ),
					esc_attr( $placeholder )
				);

				echo '<p class="description">' . esc_html__( 'API token for an InvenTree user who can read parts and create sales orders. Generate one from that user\'s account settings in InvenTree.', 'inventory-sync-for-inventree-and-woocommerce' ) . '</p>';
				if ( '' !== $stored_token ) {
					echo '<p class="description">' . esc_html__( 'A token is saved. Leave this blank to keep it, or paste a new one to replace it.', 'inventory-sync-for-inventree-and-woocommerce' ) . '</p>';
				}
				break;

			case 'committing_statuses':
				if ( is_array( $value ) ) {
					$current = implode( ', ', $value );
				} else {
					$current = implode( ', ', Settings::DEFAULT_COMMITTING_STATUSES );
				}
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text" />',
					esc_attr( $key ),
					esc_attr( $name ),
					esc_attr( $current )
				);
				echo '<p class="description">' . esc_html__( 'Comma-separated WooCommerce order status slugs. An order entering one of these is what reserves its stock and sends it to InvenTree. Default: processing, completed.', 'inventory-sync-for-inventree-and-woocommerce' ) . '</p>';
				break;

			case 'sync_interval':
				if ( '' === $value ) {
					$field_value = 900;
				} else {
					$field_value = $value;
				}
				printf(
					'<input type="number" min="60" step="1" id="%1$s" name="%2$s" value="%3$s" class="small-text" />',
					esc_attr( $key ),
					esc_attr( $name ),
					esc_attr( (string) $field_value )
				);
				echo '<p class="description">' . esc_html__( 'How often stock is pulled from InvenTree. Default 900 (15 minutes). Only used when inventory mirroring is on.', 'inventory-sync-for-inventree-and-woocommerce' ) . '</p>';
				break;

			case 'aging_pending_threshold':
				if ( '' === $value ) {
					$field_value = DAY_IN_SECONDS;
				} else {
					$field_value = $value;
				}
				printf(
					'<input type="number" min="60" step="1" id="%1$s" name="%2$s" value="%3$s" class="small-text" />',
					esc_attr( $key ),
					esc_attr( $name ),
					esc_attr( (string) $field_value )
				);
				echo '<p class="description">' . esc_html__( 'How long stock may stay reserved against an order before the plugin reports a problem. A reservation normally clears within minutes, once InvenTree confirms the sales order; one that lingers usually means the order never reached InvenTree, so the stock is being held back for nothing. Default 86400 (24 hours).', 'inventory-sync-for-inventree-and-woocommerce' ) . '</p>';
				break;

			case 'log_retention':
				if ( '' === $value ) {
					$field_value = 500;
				} else {
					$field_value = $value;
				}
				printf(
					'<input type="number" min="50" step="1" id="%1$s" name="%2$s" value="%3$s" class="small-text" />',
					esc_attr( $key ),
					esc_attr( $name ),
					esc_attr( (string) $field_value )
				);
				echo '<p class="description">' . esc_html__( 'How many log entries to keep on the Log tab. Older entries are dropped.', 'inventory-sync-for-inventree-and-woocommerce' ) . '</p>';
				break;
		}
	}

	// Render the toggle button for activating or deactivating the plugin
	private function render_toggle_button( string $page_url ): void {
		if ( $this->settings->is_enabled() ) {
			$label = __( 'Deactivate', 'inventory-sync-for-inventree-and-woocommerce' );
			$class = 'button button-secondary';
			$hint  = __( 'Stops all syncing and releases any stock being held.', 'inventory-sync-for-inventree-and-woocommerce' );
		} else {
			$label = __( 'Activate', 'inventory-sync-for-inventree-and-woocommerce' );
			$class = 'button button-secondary';
			$hint  = __( 'Starts the background jobs for whichever halves are enabled above.', 'inventory-sync-for-inventree-and-woocommerce' );
		}

		wp_nonce_field( self::TOGGLE_NONCE, self::TOGGLE_NONCE_FIELD, false );
		printf(
			'<button type="submit" name="inventree_sync_toggle" value="1" formaction="%1$s" formnovalidate="formnovalidate" class="%2$s" style="margin-left:8px;" title="%3$s">%4$s</button>',
			esc_url( $page_url ),
			esc_attr( $class ),
			esc_attr( $hint ),
			esc_html( $label )
		);
	}

	// handles which tabs are visible to the current user, based on their role
	private function visible_tabs(): array {
		$tabs = [];

		if ( Capabilities::can_manage_plugin() ) {
			$tabs['settings'] = __( 'Settings', 'inventory-sync-for-inventree-and-woocommerce' );
		}
		if ( Capabilities::can_use_catalogue_tools() ) {
			$tabs['import'] = __( 'Import products', 'inventory-sync-for-inventree-and-woocommerce' );

			// Only show the add-ons tab if the user can manage the plugin and add-ons are enabled
			if ( $this->settings->addons_enabled() ) {
				$tabs['addons'] = __( 'Add-on mapping', 'inventory-sync-for-inventree-and-woocommerce' );
			}
		}
		if ( Capabilities::can_manage_plugin() ) {
			$tabs['log'] = __( 'Log', 'inventory-sync-for-inventree-and-woocommerce' );
		}

		return $tabs;
	}

	// Render the settings page, including the tabs and the form for the current tab
	public function render(): void {
		$tabs = $this->visible_tabs();
		if ( empty( $tabs ) ) {
			return;
		}
		if ( isset( $_GET['tab'] ) ) {
			$active = sanitize_key( wp_unslash( $_GET['tab'] ) );
		} else {
			$active = '';
		}
		// If the active tab is not in the list of visible tabs, default to the first tab
		if ( ! array_key_exists( $active, $tabs ) ) {
			$active = (string) array_key_first( $tabs );
		}

		$base = admin_url( 'options-general.php?page=' . self::PAGE );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Inventory Sync for InvenTree and WooCommerce', 'inventory-sync-for-inventree-and-woocommerce' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab_slug => $tab_label ) : ?>
					<?php
					$tab_class = 'nav-tab';
					if ( $tab_slug === $active ) {
						$tab_class .= ' nav-tab-active';
					}
					?>
					<a href="<?php echo esc_url( $base . '&tab=' . $tab_slug ); ?>" class="<?php echo esc_attr( $tab_class ); ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php if ( 'import' === $active ) : ?>
				<?php $this->import->render_content( $base . '&tab=import' ); ?>
			<?php elseif ( 'addons' === $active ) : ?>
				<?php $this->addons->render_content( $base . '&tab=addons' ); ?>
			<?php elseif ( 'log' === $active ) : ?>
				<?php $this->log->render_content( $base . '&tab=log' ); ?>
			<?php else : ?>
				<p>
					<strong><?php echo esc_html__( 'Status:', 'inventory-sync-for-inventree-and-woocommerce' ); ?></strong>
					<?php if ( $this->settings->is_enabled() ) : ?>
						<span style="color:#00794a;"><?php echo esc_html__( 'Active', 'inventory-sync-for-inventree-and-woocommerce' ); ?></span>
					<?php else : ?>
						<span style="color:#b32d2e;"><?php echo esc_html__( 'Inactive', 'inventory-sync-for-inventree-and-woocommerce' ); ?></span>
						<?php echo esc_html__( ' - nothing is being synced. Use the button at the bottom to activate.', 'inventory-sync-for-inventree-and-woocommerce' ); ?>
					<?php endif; ?>
					<br />
					<?php if ( $this->settings->is_configured() ) : ?>
						<span style="color:#2271b1;"><?php echo esc_html__( 'Configured', 'inventory-sync-for-inventree-and-woocommerce' ); ?></span>
						<?php echo esc_html( ' - ' . $this->settings->inventree_url() ); ?>
					<?php else : ?>
						<span style="color:#b32d2e;"><?php echo esc_html__( 'Not configured. Set the URL and token below.', 'inventory-sync-for-inventree-and-woocommerce' ); ?></span>
					<?php endif; ?>
				</p>

				<form action="options.php" method="post">
					<?php
					settings_fields( self::GROUP );
					do_settings_sections( self::PAGE );
					?>
					<p class="submit">
						<?php submit_button( __( 'Save settings', 'inventory-sync-for-inventree-and-woocommerce' ), 'primary', 'submit', false ); ?>
						<?php $this->render_toggle_button( $base ); ?>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}

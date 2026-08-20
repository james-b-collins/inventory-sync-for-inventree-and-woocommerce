<?php

declare(strict_types=1);

namespace InvenTreeSync;

use InvenTreeSync\Addons\AddonMap;
use InvenTreeSync\Addons\AddonReader;
use InvenTreeSync\Admin\AddonMappingPage;
use InvenTreeSync\Admin\HealthNotice;
use InvenTreeSync\Admin\ImportPage;
use InvenTreeSync\Admin\LogPage;
use InvenTreeSync\Admin\Settings;
use InvenTreeSync\Admin\SettingsPage;
use InvenTreeSync\Catalogue\IdentityResolver;
use InvenTreeSync\Catalogue\ProductWriter;
use InvenTreeSync\Cli\Commands;
use InvenTreeSync\Import\ImportScanner;
use InvenTreeSync\Import\ProductImporter;
use InvenTreeSync\InvenTree\Client;
use InvenTreeSync\InvenTree\PartRepository;
use InvenTreeSync\InvenTree\SalesOrderRepository;
use InvenTreeSync\Orders\CommitService;
use InvenTreeSync\Orders\LineItemMapper;
use InvenTreeSync\Orders\OrderItemsListener;
use InvenTreeSync\Orders\OrderStatusListener;
use InvenTreeSync\Orders\RefundListener;
use InvenTreeSync\Orders\ReleaseService;
use InvenTreeSync\Push\AllocationPoller;
use InvenTreeSync\Push\PendingReconciler;
use InvenTreeSync\Push\SalesOrderPusher;
use InvenTreeSync\Rest\StatusEndpoint;
use InvenTreeSync\Scheduling\Scheduler;
use InvenTreeSync\Stock\PendingLedger;
use InvenTreeSync\Stock\ReservationStore;
use InvenTreeSync\Support\Logger;
use InvenTreeSync\Support\LogStore;
use InvenTreeSync\Support\StatusReport;
use InvenTreeSync\Sync\SyncRunner;
use InvenTreeSync\Woo\NotificationSuppressor;
use InvenTreeSync\Woo\StockWriterSuppressor;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Main plugin class to manage the plugin's lifecycle, settings, and dependencies
final class Plugin {

	private static ?Plugin $instance = null;

	private Settings $settings;
	private Logger $logger;
	private ?SyncRunner $sync = null;
	private ?Scheduler $scheduler = null;
	private bool $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// Boot the plugin, initializing settings, logger, and other components
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->settings = new Settings();

		$log_store = new LogStore( $this->settings );
		$log_store->maybe_install();
		$this->logger = new Logger( $log_store );

		// Diagnostics don't need WooCommerce, so register them before the guard.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Commands::register();
		}

		// Guard against running the plugin when WooCommerce is not active
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', [ $this, 'render_missing_woocommerce_notice' ] );
			return;
		}

		// initialize the plugin components
		$store = new ReservationStore();
		$store->maybe_install();

		$writer          = new ProductWriter();
		$pending         = new PendingLedger( $store );
		$so_repo_factory = fn (): ?SalesOrderRepository => $this->make_sales_order_repository();

		$addon_map = new AddonMap();
		$addons    = new AddonMappingPage( $addon_map, $this->settings );
		$addons->register();
		$log_page = new LogPage( $log_store );
		$log_page->register();
		$import_page = new ImportPage(
			$this->settings,
			fn (): ?ImportScanner => $this->make_import_scanner(),
			fn (): ?ProductImporter => $this->make_product_importer( $writer, $pending ),
		);
		$import_page->register();
		( new SettingsPage( $this->settings, $addons, $import_page, $log_page ) )->register();

		$mirror_inventory    = $this->settings->mirror_inventory();
		$create_sales_orders = $this->settings->create_sales_orders();

		$this->sync = new SyncRunner(
			fn (): ?PartRepository => $this->make_part_repository(),
			new IdentityResolver(),
			$writer,
			$pending,
			new NotificationSuppressor(),
			$this->logger
		);

		$addon_reader = new AddonReader( $addon_map, $this->logger, $this->settings );
		$commits      = new CommitService( $this->settings, $store, $pending, $writer, $addon_reader, $this->logger );
		$releases     = new ReleaseService( $this->settings, $store, $pending, $writer, $so_repo_factory, $this->logger );

		if ( $create_sales_orders ) {
			( new OrderStatusListener( $this->settings, new LineItemMapper(), $commits, $releases ) )->register();
			( new RefundListener( $store, $releases, $so_repo_factory, $this->logger ) )->register();
			( new OrderItemsListener( $this->settings, $commits, $so_repo_factory, $this->logger ) )->register();
		}

		if ( $this->settings->reserves_stock() ) {
			( new StockWriterSuppressor() )->register();
		}

		$pusher     = new SalesOrderPusher( $store, $so_repo_factory, $this->logger );
		$poller     = new AllocationPoller( $store, $so_repo_factory, $this->settings, $releases, $this->logger );
		$reconciler = new PendingReconciler( $store, $pending, $writer, $this->logger );

		$this->scheduler = new Scheduler( $this->sync, $pusher, $poller, $reconciler );
		$this->scheduler->register_handlers( $mirror_inventory, $create_sales_orders );

		// Register hooks to respond to settings changes, ensuring that the plugin's runtime state matches the current configuration
		add_action(
			'update_option_' . Settings::OPTION,
			function ( $old_value, $new_value ) use ( $releases ): void {
				$this->on_settings_saved( $old_value, $new_value, $releases );
			},
			10,
			2
		);

		// Register hooks to respond to changes in the enabled state, ensuring that the plugin's runtime state matches the current configuration
		add_action(
			'update_option_' . Settings::ENABLED_OPTION,
			function ( $old_value, $new_value ) use ( $releases ): void {
				$this->on_enabled_changed( ! empty( $old_value ), ! empty( $new_value ), $releases );
			},
			10,
			2
		);

		// Register hooks to respond to changes in the enabled state, ensuring that the plugin's runtime state matches the current configuration
		add_action(
			'add_option_' . Settings::ENABLED_OPTION,
			function ( $option, $value ) use ( $releases ): void {
				$this->on_enabled_changed( false, ! empty( $value ), $releases );
			},
			10,
			2
		);

		// Register the status report endpoint and health notice
		$status_report = new StatusReport( $this->settings, $store, $this->scheduler );
		( new StatusEndpoint( $status_report ) )->register();
		( new HealthNotice( $status_report ) )->register();
	}

	// Return the plugin settings
	public function settings(): Settings {
		return $this->settings;
	}

	// Return the sync runner, or null if the plugin is not fully booted
	public function sync_runner(): ?SyncRunner {
		return $this->sync;
	}

	// Return the scheduler, or null if the plugin is not fully booted
	public function scheduler(): ?Scheduler {
		return $this->scheduler;
	}

	// Make a new InvenTree client if the plugin is configured, or return null if not
	public function make_client(): ?Client {
		if ( ! $this->settings->is_configured() ) {
			return null;
		}
		return new Client( $this->settings->inventree_url(), $this->settings->inventree_token() );
	}

	// Make a new InvenTree part repository if the plugin is configured, or return null if not
	public function make_part_repository(): ?PartRepository {
		$client = $this->make_client();
		if ( null === $client ) {
			return null;
		}
		return new PartRepository( $client );
	}

	// Make a new import scanner if the plugin is configured, or return null if not
	public function make_import_scanner(): ?ImportScanner {
		$parts = $this->make_part_repository();
		if ( null === $parts ) {
			return null;
		}
		return new ImportScanner( $parts );
	}

	// Make a new product importer if the plugin is configured, or return null if not
	public function make_product_importer( ProductWriter $writer, PendingLedger $pending ): ?ProductImporter {
		$parts = $this->make_part_repository();
		if ( null === $parts ) {
			return null;
		}
		return new ProductImporter(
			$parts,
			new ImportScanner( $parts ),
			new IdentityResolver(),
			$writer,
			$pending,
			new NotificationSuppressor(),
			$this->logger
		);
	}

	// Make a new sales order repository if the plugin is configured, or return null if not
	public function make_sales_order_repository(): ?SalesOrderRepository {
		$client = $this->make_client();
		if ( null === $client ) {
			return null;
		}
		return new SalesOrderRepository( $client );
	}

	// Handle the settings being saved
	private function on_settings_saved( $old_value, $new_value, ReleaseService $releases ): void {
		$settings = new Settings();

		$enabled                   = $settings->is_enabled();
		$was_creating_sales_orders = $enabled && $this->option_flag_was_on( $old_value, 'create_sales_orders', true );
		$now_creating_sales_orders = $enabled && $this->option_flag_was_on( $new_value, 'create_sales_orders', true );

		$this->apply_runtime_state( $was_creating_sales_orders, $now_creating_sales_orders, $releases );
	}

	// Handle the enabled state changing
	private function on_enabled_changed( bool $was_enabled, bool $now_enabled, ReleaseService $releases ): void {
		// The raw flag, because create_sales_orders() is already false by now.
		$creates_sales_orders = ( new Settings() )->create_sales_orders_setting();

		$this->apply_runtime_state(
			$was_enabled && $creates_sales_orders,
			$now_enabled && $creates_sales_orders,
			$releases
		);
	}

	// Apply the runtime state based on the previous and current settings
	private function apply_runtime_state( bool $was_creating_sales_orders, bool $now_creating_sales_orders, ReleaseService $releases ): void {
		if ( $was_creating_sales_orders && ! $now_creating_sales_orders ) {
			$releases->release_all_outstanding();
		}

		if ( null !== $this->scheduler ) {
			$settings = new Settings();
			$this->scheduler->schedule_recurring(
				$settings->sync_interval_seconds(),
				$settings->mirror_inventory(),
				$settings->create_sales_orders()
			);
		}
	}

	// Determine if a given option value indicates that a specific flag was on, with a default fallback
	private function option_flag_was_on( $option_value, string $key, bool $default_on ): bool {
		if ( ! is_array( $option_value ) ) {
			return $default_on;
		}
		if ( ! array_key_exists( $key, $option_value ) ) {
			return $default_on;
		}
		return ! empty( $option_value[ $key ] );
	}

	// Render an admin notice if WooCommerce is not installed or active
	public function render_missing_woocommerce_notice(): void {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Inventory Sync for InvenTree and WooCommerce requires WooCommerce to be installed and active.', 'inventory-sync-for-inventree-and-woocommerce' );
		echo '</p></div>';
	}

	// On activation: boot the plugin and schedule recurring actions
	public static function activate(): void {
		$self = self::instance();
		$self->boot();
		if ( $self->scheduler ) {
			$self->scheduler->schedule_recurring(
				$self->settings->sync_interval_seconds(),
				$self->settings->mirror_inventory(),
				$self->settings->create_sales_orders()
			);
		}
	}

	// On deactivation: unschedule all actions
	public static function deactivate(): void {
		$self = self::instance();
		if ( $self->scheduler ) {
			$self->scheduler->unschedule_all();
		}
	}
}

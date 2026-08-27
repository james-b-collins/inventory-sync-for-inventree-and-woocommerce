<?php

declare(strict_types=1);

namespace InvenTreeSync\Admin;

use Closure;
use InvenTreeSync\Catalogue\IdentityResolver;
use InvenTreeSync\Catalogue\ProductLookup;
use InvenTreeSync\InvenTree\PartRepository;
use InvenTreeSync\Support\Meta;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) { exit;}

// This class handles the manual mapping of WooCommerce products to InvenTree parts.
final class MappingPage {

	private const NONCE      = 'inventree_sync_mapping';// Nonce for AJAX requests
	private const SEARCH_MAX = 20;						// Max number of search results
	private const PER_PAGE   = [ 20, 50, 100, 200 ];	// Max number of products per page 

	public function __construct( private Settings $settings, private Closure $part_repository_factory) {}

	// Register the AJAX endpoints for the mapping page
	public function register(): void {
		add_action( 'wp_ajax_inventree_sync_search_parts', [ $this, 'ajax_search_parts' ] );
		add_action( 'wp_ajax_inventree_sync_link_part', [ $this, 'ajax_link_part' ] );
		add_action( 'wp_ajax_inventree_sync_unlink_part', [ $this, 'ajax_unlink_part' ] );
	}

	// Check the AJAX request for proper permissions and nonce
	private function check_request(): void {
		if ( ! Capabilities::can_use_catalogue_tools() ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to do that.', 'inventory-sync-for-inventree-and-woocommerce' ) ], 403 );
		}
		check_ajax_referer( self::NONCE );
	}

	// Search Inventree parts repository
	public function ajax_search_parts(): void {
		$this->check_request();

		// Get the search query from the POST request
		$query = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['q'] ) ) : '';
		if ( strlen( $query ) < 2 ) {
			wp_send_json_success( [ 'parts' => [] ] );
		}

		// Get the InvenTree parts repository
		$repository = ( $this->part_repository_factory )();
		if ( null === $repository ) {
			wp_send_json_error( [ 'message' => __( 'InvenTree is not configured.', 'inventory-sync-for-inventree-and-woocommerce' ) ] );
		}

		try {
			$parts = $repository->search_parts( $query, self::SEARCH_MAX );
		} catch ( \Throwable $exception ) {
			wp_send_json_error( [ 'message' => $exception->getMessage() ] );
		}

		wp_send_json_success( [ 'parts' => $parts ] );
	}

	// Link a WooCommerce product to an InvenTree part
	public function ajax_link_part(): void {
		$this->check_request();

		$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		$part_id    = isset( $_POST['part_id'] ) ? (int) $_POST['part_id'] : 0;

		// Get the WooCommerce product
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( [ 'message' => __( 'That product no longer exists.', 'inventory-sync-for-inventree-and-woocommerce' ) ] );
		}

		// If the product is not a managed type, it cannot be linked
		if ( ! ProductLookup::is_managed_type( $product ) ) {
			wp_send_json_error( [ 'message' => __( 'Only simple products and variations can be linked.', 'inventory-sync-for-inventree-and-woocommerce' ) ] );
		}

		// Get the InvenTree parts repository
		$repository = ( $this->part_repository_factory )();
		if ( null === $repository ) {
			wp_send_json_error( [ 'message' => __( 'InvenTree is not configured.', 'inventory-sync-for-inventree-and-woocommerce' ) ] );
		}

		// Check if part exists.
		try {
			$part = $repository->fetch_part( $part_id );
		} catch ( \Throwable $exception ) {
			wp_send_json_error( [ 'message' => $exception->getMessage() ] );
		}
		if ( null === $part ) {
			wp_send_json_error( [ 'message' => __( 'That InvenTree part no longer exists.', 'inventory-sync-for-inventree-and-woocommerce' ) ] );
		}

		// Make sure no other product has already claimed this part
		$claimed_by = ProductLookup::find_by_part_id( $part_id );
		if ( $claimed_by > 0 && $claimed_by !== $product_id ) {
			$other = wc_get_product( $claimed_by );
			wp_send_json_error(
				[
					'message' => sprintf(
						__( 'That part is already linked to "%s".', 'inventory-sync-for-inventree-and-woocommerce' ),
						$other ? $other->get_name() : '#' . $claimed_by
					),
				]
			);
		}

		$label = $this->part_label( $part );

		update_post_meta( $product_id, Meta::PART_ID, $part_id );
		update_post_meta( $product_id, Meta::PART_LABEL, $label );

		// Clear any pre-adoption reduction for this product
		( new IdentityResolver() )->clear_pre_adoption_reduction( $product_id );

		// Warn if the linked part is not both active and salable
		$warning = '';
		if ( empty( $part['active'] ) || empty( $part['salable'] ) ) {
			$warning = __( 'Linked, but this part is not both active and salable, so it will not sync yet.', 'inventory-sync-for-inventree-and-woocommerce' );
		}

		wp_send_json_success(
			[
				'part_id' => $part_id,
				'label'   => $label,
				'warning' => $warning,
			]
		);
	}

	// Unlink an InvenTree part from a WooCommerce product
	public function ajax_unlink_part(): void {
		$this->check_request();

		$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		if ( $product_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Missing product.', 'inventory-sync-for-inventree-and-woocommerce' ) ] );
		}

		delete_post_meta( $product_id, Meta::PART_ID );
		delete_post_meta( $product_id, Meta::PART_LABEL );

		wp_send_json_success( [] );
	}

	// Generate a readable label for a part based on its name and IPN.
	private function part_label( array $part ): string {
		$name = trim( (string) ( $part['full_name'] ?? $part['name'] ?? '' ) );
		$ipn  = trim( (string) ( $part['IPN'] ?? '' ) );

		if ( '' === $name ) {
			$name = sprintf( '#%d', (int) ( $part['pk'] ?? 0 ) );
		}
		if ( '' !== $ipn ) {
			return sprintf( '%s (%s)', $name, $ipn );
		}
		return $name;
	}

	// Query WooCommerce products with optional filters
	private function query_products( bool $unlinked_only, string $search, int $per_page, int $paged ): array {
		global $wpdb;

		// Base WHERE clause for products with a SKU.
		$where  = "p.post_type IN ( 'product', 'product_variation' )
			AND p.post_status IN ( 'publish', 'draft', 'private' )
			AND sku.meta_value IS NOT NULL AND sku.meta_value <> ''";
		$params = [];

		// Apply the "unlinked only" filter if requested.
		if ( $unlinked_only ) {
			$where .= ' AND link.post_id IS NULL';
		}

		// Apply the search filter if a search term is provided.
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND ( p.post_title LIKE %s OR sku.meta_value LIKE %s )';
			$params[] = $like;
			$params[] = $like;
		}

		// Construct the SQL joins for the query.
		$joins = "FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
			LEFT JOIN {$wpdb->postmeta} link ON link.post_id = p.ID AND link.meta_key = '" . Meta::PART_ID . "'";

		// Count the total number of matching products.
		$count_sql = "SELECT COUNT(*) {$joins} WHERE {$where}";
		if ( ! empty( $params ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Calculate the offset and prepare the SQL query for fetching the product IDs.
		$offset   = ( $paged - 1 ) * $per_page;
		$rows_sql = "SELECT p.ID {$joins} WHERE {$where} ORDER BY p.post_title ASC LIMIT %d OFFSET %d";
		$rows_sql = $wpdb->prepare( $rows_sql, array_merge( $params, [ $per_page, $offset ] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return [
			'ids'   => array_map( 'intval', (array) $wpdb->get_col( $rows_sql ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'total' => $total,
			'pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
		];
	}

	// Read a query parameter from the URL, with an optional default value.
	private function param( string $key, string $default = '' ): string {
		if ( ! isset( $_GET[ $key ] ) ) {
			return $default;
		}
		return sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
	}

	// Render the main content of the mapping page.
	public function render_content( string $page_url ): void {
		// Check if the plugin is configured before rendering the content.
		if ( ! $this->settings->is_configured() ) {
			echo '<div class="notice notice-error inline"><p>';
			echo esc_html__( 'Set the InvenTree URL and API token on the Settings tab before mapping.', 'inventory-sync-for-inventree-and-woocommerce' );
			echo '</p></div>';
			return;
		}

		$unlinked_only = 'all' !== $this->param( 'show', 'unlinked' );
		$search        = $this->param( 's' );
		$paged         = max( 1, (int) $this->param( 'paged', '1' ) );

		// Ensure the number of items per page is within the allowed options.
		$per_page = (int) $this->param( 'per_page', '20' );
		if ( ! in_array( $per_page, self::PER_PAGE, true ) ) {
			$per_page = 20;
		}

		// Query the products based on the current filters and page
		$result = $this->query_products( $unlinked_only, $search, $per_page, $paged );

		// Construct the URL for the current view with the applied filters.
		$view_url = add_query_arg(
			[
				'show'     => $unlinked_only ? 'unlinked' : 'all',
				'per_page' => $per_page,
				's'        => $search,
			],
			$page_url
		);
		?>
		<p><?php echo esc_html__( 'Link a WooCommerce product to an InvenTree part by hand. Use this when the part has no IPN to match on, or when the IPN does not equal the SKU. A link made here always wins over IPN matching and is never overwritten by it.', 'inventory-sync-for-inventree-and-woocommerce' ); ?></p>

		<ul class="subsubsub">
			<li>
				<a href="<?php echo esc_url( add_query_arg( [ 'show' => 'unlinked', 'per_page' => $per_page, 's' => $search ], $page_url ) ); ?>" class="<?php echo $unlinked_only ? 'current' : ''; ?>">
					<?php echo esc_html__( 'Not linked', 'inventory-sync-for-inventree-and-woocommerce' ); ?>
				</a> |
			</li>
			<li>
				<a href="<?php echo esc_url( add_query_arg( [ 'show' => 'all', 'per_page' => $per_page, 's' => $search ], $page_url ) ); ?>" class="<?php echo $unlinked_only ? '' : 'current'; ?>">
					<?php echo esc_html__( 'All products with a SKU', 'inventory-sync-for-inventree-and-woocommerce' ); ?>
				</a>
			</li>
		</ul>

		<form method="get" class="is-toolbar">
			<?php
			foreach ( [ 'page' => $this->param( 'page' ), 'tab' => 'mapping', 'show' => $unlinked_only ? 'unlinked' : 'all' ] as $name => $value ) {
				printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $name ), esc_attr( $value ) );
			}
			?>
			<p class="search-box">
				<label class="screen-reader-text" for="inventory-sync-product-search"><?php echo esc_html__( 'Search products', 'inventory-sync-for-inventree-and-woocommerce' ); ?></label>
				<input type="search" id="inventory-sync-product-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Product name or SKU', 'inventory-sync-for-inventree-and-woocommerce' ); ?>" />
				<?php submit_button( __( 'Search products', 'inventory-sync-for-inventree-and-woocommerce' ), '', '', false ); ?>
			</p>

			<p class="is-per-page">
				<label for="inventory-sync-per-page"><?php echo esc_html__( 'Show', 'inventory-sync-for-inventree-and-woocommerce' ); ?></label>
				<select name="per_page" id="inventory-sync-per-page" onchange="this.form.submit()">
					<?php foreach ( self::PER_PAGE as $option ) : ?>
						<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $per_page, $option ); ?>><?php echo esc_html( (string) $option ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="displaying-num">
					<?php
					printf(
						esc_html__( '%s product(s)', 'inventory-sync-for-inventree-and-woocommerce' ),
						esc_html( number_format_i18n( $result['total'] ) )
					);
					?>
				</span>
			</p>
		</form>

		<table class="widefat striped" id="inventory-sync-mapping">
			<thead>
				<tr>
					<th style="width:34%;"><?php echo esc_html__( 'Product', 'inventory-sync-for-inventree-and-woocommerce' ); ?></th>
					<th style="width:16%;"><?php echo esc_html__( 'SKU', 'inventory-sync-for-inventree-and-woocommerce' ); ?></th>
					<th><?php echo esc_html__( 'InvenTree part', 'inventory-sync-for-inventree-and-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $result['ids'] ) ) : ?>
					<tr><td colspan="3">
						<?php
						if ( '' !== $search ) {
							echo esc_html__( 'No products match that search.', 'inventory-sync-for-inventree-and-woocommerce' );
						} else {
							echo esc_html__( 'Nothing to show. Products need a SKU before they can be linked.', 'inventory-sync-for-inventree-and-woocommerce' );
						}
						?>
					</td></tr>
				<?php endif; ?>

				<?php
				foreach ( $result['ids'] as $product_id ) :
					$product = wc_get_product( $product_id );
					if ( ! $product ) {
						continue;
					}
					$linked_part  = (int) get_post_meta( $product_id, Meta::PART_ID, true );
					$linked_label = (string) get_post_meta( $product_id, Meta::PART_LABEL, true );
					if ( '' === $linked_label && $linked_part > 0 ) {
						$linked_label = sprintf( '#%d', $linked_part );
					}
					?>
					<tr data-product="<?php echo esc_attr( (string) $product_id ); ?>">
						<td>
							<strong><?php echo esc_html( $product->get_name() ); ?></strong>
							<?php if ( 'variation' === $product->get_type() ) : ?>
								<span class="is-muted"><?php echo esc_html__( 'variation', 'inventory-sync-for-inventree-and-woocommerce' ); ?></span>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $product->get_sku() ); ?></code></td>
						<td class="is-link-cell">
							<?php if ( $linked_part > 0 ) : ?>
								<span class="is-linked"><span class="dashicons dashicons-yes"></span> <?php echo esc_html( $linked_label ); ?></span>
								<button type="button" class="button-link is-unlink"><?php echo esc_html__( 'Unlink', 'inventory-sync-for-inventree-and-woocommerce' ); ?></button>
							<?php else : ?>
								<input type="search" class="regular-text is-search" placeholder="<?php echo esc_attr__( 'Search InvenTree parts…', 'inventory-sync-for-inventree-and-woocommerce' ); ?>" autocomplete="off" />
								<div class="is-results"></div>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $result['pages'] > 1 ) : ?>
			<div class="tablenav bottom"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						[
							'base'      => add_query_arg( 'paged', '%#%', $view_url ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $result['pages'],
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						]
					)
				);
				?>
			</div></div>
		<?php endif; ?>

		<style>
			.is-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 8px 0 12px; }
			.is-toolbar .search-box { float: none; margin: 0; }
			.is-per-page { margin: 0; display: flex; align-items: center; gap: 6px; }
			.is-per-page .displaying-num { margin-left: 8px; color: #646970; }
			#inventory-sync-mapping .is-muted { color: #646970; font-size: 12px; margin-left: 4px; }
			#inventory-sync-mapping .is-link-cell { position: relative; }
			#inventory-sync-mapping .is-results { display: none; position: absolute; z-index: 20; left: 0; right: 0; margin-top: 2px; background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 2px 6px rgba(0,0,0,.12); max-height: 260px; overflow-y: auto; }
			#inventory-sync-mapping .is-results.open { display: block; }
			#inventory-sync-mapping .is-results button { display: block; width: 100%; text-align: left; padding: 6px 10px; border: 0; background: none; cursor: pointer; line-height: 1.4; }
			#inventory-sync-mapping .is-results button:hover,
			#inventory-sync-mapping .is-results button.is-active { background: #f0f6fc; }
			#inventory-sync-mapping .is-results .is-meta { color: #646970; font-size: 12px; }
			#inventory-sync-mapping .is-results .is-warn { color: #b32d2e; font-size: 12px; }
			#inventory-sync-mapping .is-empty { padding: 6px 10px; color: #646970; }
			#inventory-sync-mapping .is-note { color: #b32d2e; display: block; margin-top: 4px; }
			#inventory-sync-mapping .is-linked .dashicons-yes { color: #00794a; }
			#inventory-sync-mapping tr.is-just-linked { background: #f0f6fc; }
			#inventory-sync-mapping .is-busy { color: #646970; font-size: 12px; margin-left: 6px; }
		</style>

		<script>
		( 
			// Initialize the inventory sync mapping table functionality
			function () {
				var table = document.getElementById( 'inventory-sync-mapping' );
				if ( ! table ) { return; }

				var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				var nonce   = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
				var strings = <?php echo wp_json_encode(
					[
						'searching' => __( 'Searching…', 'inventory-sync-for-inventree-and-woocommerce' ),
						'none'      => __( 'No parts match.', 'inventory-sync-for-inventree-and-woocommerce' ),
						'failed'    => __( 'Search failed.', 'inventory-sync-for-inventree-and-woocommerce' ),
						'noLink'    => __( 'Could not link.', 'inventory-sync-for-inventree-and-woocommerce' ),
						'unlink'    => __( 'Unlink', 'inventory-sync-for-inventree-and-woocommerce' ),
						'notSalable' => __( 'not salable', 'inventory-sync-for-inventree-and-woocommerce' ),
						'stock'     => __( 'stock', 'inventory-sync-for-inventree-and-woocommerce' ),
					]
				); ?>;

			// Function to perform AJAX POST requests
			function post( action, data ) {
				var body = new URLSearchParams();
				body.append( 'action', action );
				body.append( '_wpnonce', nonce );
				for ( var key in data ) { body.append( key, data[ key ] ); }
				return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( response ) { return response.json(); } );
			}

			// Function to display a note message within a table cell
			function note( cell, message ) {
				var existing = cell.querySelector( '.is-note' );
				if ( existing ) { existing.remove(); }
				if ( ! message ) { return; }
				var span = document.createElement( 'span' );
				span.className = 'is-note';
				span.textContent = message;
				cell.appendChild( span );
			}

			// Function to close all open result boxes within the table
			function closeAll() {
				Array.prototype.forEach.call( table.querySelectorAll( '.is-results.open' ), function ( box ) {
					box.classList.remove( 'open' );
				} );
			}

			// Function to display a linked item within a table cell
			function showLinked( cell, label, warning ) {
				cell.innerHTML = '';
				var span = document.createElement( 'span' );
				span.className = 'is-linked';
				span.innerHTML = '<span class="dashicons dashicons-yes"></span> ';
				span.appendChild( document.createTextNode( label ) );
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'button-link is-unlink';
				button.textContent = strings.unlink;
				cell.appendChild( span );
				cell.appendChild( button );
				note( cell, warning );

				var row = cell.closest( 'tr' );
				row.classList.add( 'is-just-linked' );
				window.setTimeout( function () { row.classList.remove( 'is-just-linked' ); }, 1500 );
			}

			// Function to create a search box within a table cell
			function searchBox( cell ) {
				cell.innerHTML = '<input type="search" class="regular-text is-search" autocomplete="off" /><div class="is-results"></div>';
			}

			var timer = null;	// Timer for debouncing search input

			// Event listener for handling search input within the table
			table.addEventListener( 'input', function ( event ) {
				var input = event.target;
				if ( ! input.classList.contains( 'is-search' ) ) { return; }

				var cell    = input.closest( '.is-link-cell' );
				var results = cell.querySelector( '.is-results' );
				var term    = input.value.trim();

				// Debounce the search input to avoid excessive AJAX requests
				window.clearTimeout( timer );

				// If the search term is too short, close the results box and clear its contents
				if ( term.length < 2 ) {
					results.classList.remove( 'open' );
					results.innerHTML = '';
					return;
				}

				// Display a "searching" message while waiting for the AJAX response
				results.innerHTML = '<div class="is-empty">' + strings.searching + '</div>';
				results.classList.add( 'open' );

				// Perform the AJAX search after a short delay
				timer = window.setTimeout( function () {
					post( 'inventree_sync_search_parts', { q: term } ).then( function ( body ) {
						results.innerHTML = '';
						if ( ! body.success ) {
							results.classList.remove( 'open' );
							note( cell, body.data && body.data.message ? body.data.message : strings.failed );
							return;
						}
						note( cell, '' );

						if ( ! body.data.parts.length ) {
							results.innerHTML = '<div class="is-empty">' + strings.none + '</div>';
							return;
						}

						// Iterate over the search results and create buttons for each part
						body.data.parts.forEach( function ( part ) {
							var button = document.createElement( 'button' );
							button.type = 'button';

							var title = document.createElement( 'div' );
							title.textContent = part.name;
							button.appendChild( title );

							var meta = document.createElement( 'div' );
							meta.className = 'is-meta';
							var bits = [];
							if ( part.ipn ) { bits.push( part.ipn ); }
							bits.push( strings.stock + ' ' + part.in_stock );
							meta.textContent = bits.join( ' · ' );
							button.appendChild( meta );

							if ( ! part.active || ! part.salable ) {
								var warn = document.createElement( 'div' );
								warn.className = 'is-warn';
								warn.textContent = strings.notSalable;
								button.appendChild( warn );
							}

							// Event listener for handling the click on a search result button
							button.addEventListener( 'click', function () {
								var row = cell.closest( 'tr' );
								post( 'inventree_sync_link_part', {
									product_id: row.getAttribute( 'data-product' ),
									part_id: part.pk
								} ).then( function ( linked ) {
									if ( ! linked.success ) {
										note( cell, linked.data && linked.data.message ? linked.data.message : strings.noLink );
										return;
									}
									showLinked( cell, linked.data.label, linked.data.warning );
								} );
							} );
							results.appendChild( button );
						} );
					} );
				}, 300 );
			} );

			// Event listener for handling the unlink action within the table
			table.addEventListener( 'click', function ( event ) {
				if ( ! event.target.classList.contains( 'is-unlink' ) ) { return; }
				var cell = event.target.closest( '.is-link-cell' );
				var row  = event.target.closest( 'tr' );
				post( 'inventree_sync_unlink_part', { product_id: row.getAttribute( 'data-product' ) } ).then( function ( body ) {
					if ( ! body.success ) { return; }
					searchBox( cell );
				} );
			} );

			// Event listeners for handling global keyboard and mouse actions
			document.addEventListener( 'keydown', function ( event ) {
				if ( 'Escape' === event.key ) { closeAll(); }
			} );
			document.addEventListener( 'click', function ( event ) {
				if ( ! event.target.closest( '.is-link-cell' ) ) { closeAll(); }
			} );
		}() );
		</script>
		<?php
	}
}

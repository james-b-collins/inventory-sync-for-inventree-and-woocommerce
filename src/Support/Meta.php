<?php

declare(strict_types=1);

namespace InvenTreeSync\Support;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {exit;}

// Class to manage the meta keys
final class Meta {
	public const PART_ID = '_inventree_part_id';	// InvenTree part PK for this WooCommerce product
	public const QTY = '_inventree_qty';			// Quantity of the product in the order item
	public const PENDING = '_inventree_pending';	// Pending quantity for this product, derived from reservations
	public const ORDER_SALES_ORDER_ID = '_inventree_sales_order_id';	// InvenTree sales order PK created for this WooCommerce order.
	public const ORDER_RELEASED = '_inventree_released';				// Set to 'yes' once every reservation on the order has been released.
}

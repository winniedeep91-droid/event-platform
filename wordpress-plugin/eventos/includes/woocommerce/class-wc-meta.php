<?php
/**
 * Mapping between WooCommerce objects and EventOS entities.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Woocommerce;

use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EventOS never duplicates WooCommerce's own storage. Every link back to an
 * event, ticket type or campaign is a small piece of post/user meta on the
 * WooCommerce object itself, resolved on read.
 */
final class Wc_Meta {

	/**
	 * Product/coupon/order meta key holding the linked EventOS event ID.
	 */
	public const EVENT_META = '_eventos_event_id';

	/**
	 * Product meta key holding the linked EventOS ticket type ID.
	 */
	public const TICKET_TYPE_META = '_eventos_ticket_type_id';

	/**
	 * Coupon meta key holding the linked EventOS marketing campaign ID.
	 */
	public const CAMPAIGN_META = '_eventos_campaign_id';

	/**
	 * Meta key (post or user) recording the last time the object was synced.
	 */
	public const SYNCED_META = '_eventos_synced_at';

	/**
	 * Resolve the EventOS event an order belongs to.
	 *
	 * Prefers an explicitly cached value, otherwise falls back to the first
	 * line item whose product is mapped to an event.
	 *
	 * @param WC_Order $order Order.
	 * @return int Event ID, 0 when unresolved.
	 */
	public static function resolve_order_event_id( WC_Order $order ): int {
		$cached = (int) $order->get_meta( self::EVENT_META, true );

		if ( $cached > 0 ) {
			return $cached;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}

			$product_id = (int) $item->get_product_id();

			if ( $product_id <= 0 ) {
				continue;
			}

			$event_id = (int) get_post_meta( $product_id, self::EVENT_META, true );

			if ( $event_id > 0 ) {
				return $event_id;
			}
		}

		return 0;
	}

	/**
	 * Ticket type an order line item resolves to, via its product mapping.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return int Ticket type ID, 0 when unmapped.
	 */
	public static function resolve_line_item_ticket_type( int $product_id ): int {
		if ( $product_id <= 0 ) {
			return 0;
		}

		return (int) get_post_meta( $product_id, self::TICKET_TYPE_META, true );
	}
}

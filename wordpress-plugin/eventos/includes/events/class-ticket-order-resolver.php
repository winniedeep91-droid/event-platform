<?php
/**
 * Resolves which WooCommerce orders belong to an event.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

use EventOS\WooCommerce;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce owns orders; EventOS never mirrors them. An "event's orders"
 * is simply every WooCommerce order that contains at least one line item for
 * one of the event's ticket-type products, resolved live on each request.
 */
final class Ticket_Order_Resolver {

	/**
	 * Ticket type repository.
	 *
	 * @var Ticket_Type_Repository
	 */
	private Ticket_Type_Repository $ticket_types;

	/**
	 * Constructor.
	 *
	 * @param Ticket_Type_Repository $ticket_types Ticket type repository.
	 */
	public function __construct( Ticket_Type_Repository $ticket_types ) {
		$this->ticket_types = $ticket_types;
	}

	/**
	 * Orders containing at least one ticket for an event, with search,
	 * filters and pagination applied over the matching set.
	 *
	 * Accepted args: search, status, page, per_page, orderby, order.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function orders_for_event( int $event_id, array $args = array() ): array {
		$args     = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'status'   => '',
				'page'     => 1,
				'per_page' => 20,
				'order'    => 'desc',
			)
		);
		$page     = max( 1, (int) $args['page'] );
		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );

		$empty = array(
			'items'    => array(),
			'total'    => 0,
			'page'     => $page,
			'per_page' => $per_page,
		);

		if ( ! WooCommerce::is_active() ) {
			return $empty;
		}

		$product_map = $this->ticket_types->product_map_for_event( $event_id );

		if ( empty( $product_map ) ) {
			return $empty;
		}

		$matching = $this->matching_orders( array_keys( $product_map ), (string) $args['search'], (string) $args['status'] );

		usort(
			$matching,
			static function ( WC_Order $a, WC_Order $b ) use ( $args ): int {
				$ta   = $a->get_date_created() ? $a->get_date_created()->getTimestamp() : 0;
				$tb   = $b->get_date_created() ? $b->get_date_created()->getTimestamp() : 0;
				$sign = 'asc' === strtolower( (string) $args['order'] ) ? 1 : -1;

				return $sign * ( $ta <=> $tb );
			}
		);

		$types_by_id = array();

		foreach ( $this->ticket_types->for_event( $event_id ) as $type ) {
			$types_by_id[ (int) $type['id'] ] = $type;
		}

		$total = count( $matching );
		$slice = array_slice( $matching, ( $page - 1 ) * $per_page, $per_page );

		$items = array_map(
			function ( WC_Order $order ) use ( $event_id, $product_map, $types_by_id ): array {
				return $this->order_payload( $order, $event_id, $product_map, $types_by_id );
			},
			$slice
		);

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Every live WooCommerce order containing at least one of the given products.
	 *
	 * @param int[]  $product_ids Product IDs to match.
	 * @param string $search      Search term.
	 * @param string $status      Order status filter.
	 * @return WC_Order[]
	 */
	private function matching_orders( array $product_ids, string $search, string $status ): array {
		$wc_args = array(
			'limit'  => -1,
			'return' => 'objects',
		);

		if ( '' !== $status ) {
			$wc_args['status'] = array( $status );
		}

		if ( '' !== $search ) {
			$wc_args['s'] = $search;
		}

		$orders   = wc_get_orders( $wc_args );
		$matching = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			foreach ( $order->get_items() as $item ) {
				if ( method_exists( $item, 'get_product_id' ) && in_array( (int) $item->get_product_id(), $product_ids, true ) ) {
					$matching[] = $order;
					break;
				}
			}
		}

		return $matching;
	}

	/**
	 * Shape a WooCommerce order into the OrderRecord contract, scoped to one event.
	 *
	 * @param WC_Order                          $order       Order.
	 * @param int                                $event_id   Event ID.
	 * @param array<int, int>                    $product_map WC product ID => ticket type ID.
	 * @param array<int, array<string, mixed>>   $types_by_id Ticket type ID => ticket type record.
	 * @return array<string, mixed>
	 */
	public function order_payload( WC_Order $order, int $event_id, array $product_map, array $types_by_id ): array {
		$tickets      = array();
		$ticket_count = 0;

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}

			$product_id = (int) $item->get_product_id();

			if ( ! isset( $product_map[ $product_id ] ) ) {
				continue;
			}

			$ticket_type_id = $product_map[ $product_id ];
			$ticket_type    = $types_by_id[ $ticket_type_id ] ?? null;
			$quantity       = max( 1, method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 1 );
			$total          = (float) $item->get_total();

			$tickets[] = array(
				'id'               => (int) $item_id,
				'ticket_type_id'   => $ticket_type_id,
				'ticket_type_name' => null !== $ticket_type ? (string) $ticket_type['name'] : $item->get_name(),
				'quantity'         => $quantity,
				'price'            => $quantity > 0 ? $total / $quantity : $total,
				'total'            => $total,
			);

			$ticket_count += $quantity;
		}

		$refunds = array_map(
			static function ( $refund ): array {
				$created = $refund->get_date_created();

				return array(
					'id'         => $refund->get_id(),
					'amount'     => (float) $refund->get_amount(),
					'reason'     => (string) $refund->get_reason(),
					'created_at' => $created ? $created->date( 'c' ) : '',
				);
			},
			$order->get_refunds()
		);

		$created  = $order->get_date_created();
		$modified = $order->get_date_modified();

		return array(
			'id'               => $order->get_id(),
			'wc_order_id'      => $order->get_id(),
			'event_id'         => $event_id,
			'customer_id'      => (int) $order->get_customer_id(),
			'customer_name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'customer_email'   => (string) $order->get_billing_email(),
			'status'           => $order->get_status(),
			'payment_method'   => (string) $order->get_payment_method_title() ?: (string) $order->get_payment_method(),
			'total'            => (float) $order->get_total(),
			'subtotal'         => (float) $order->get_subtotal(),
			'tax'              => (float) $order->get_total_tax(),
			'currency'         => $order->get_currency(),
			'ticket_count'     => $ticket_count,
			'tickets'          => $tickets,
			'refunds'          => $refunds,
			'notes'            => (string) $order->get_customer_note(),
			'billing_address'  => trim( wp_strip_all_tags( str_replace( array( '<br/>', '<br>', '<br />' ), ', ', (string) $order->get_formatted_billing_address( '' ) ) ), ', ' ),
			'created_at'       => $created ? $created->date( 'c' ) : '',
			'updated_at'       => $modified ? $modified->date( 'c' ) : '',
		);
	}
}

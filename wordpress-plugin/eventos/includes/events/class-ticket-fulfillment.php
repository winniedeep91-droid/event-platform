<?php
/**
 * Issues EventOS tickets from paid WooCommerce orders.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

use WC_Order;
use WC_Order_Refund;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens to WooCommerce's own order hooks — the same ones WooCommerce fires
 * for every store, no separate payment flow — and turns a paid order line
 * item into EventOS ticket and guest records when the product is a ticket
 * type's linked product. Never writes to any WooCommerce table.
 */
final class Ticket_Fulfillment {

	/**
	 * Ticket type repository.
	 *
	 * @var Ticket_Type_Repository
	 */
	private Ticket_Type_Repository $ticket_types;

	/**
	 * Ticket repository.
	 *
	 * @var Ticket_Repository
	 */
	private Ticket_Repository $tickets;

	/**
	 * Guest repository.
	 *
	 * @var Guest_Repository
	 */
	private Guest_Repository $guests;

	/**
	 * Constructor.
	 *
	 * @param Ticket_Type_Repository $ticket_types Ticket type repository.
	 * @param Ticket_Repository      $tickets      Ticket repository.
	 * @param Guest_Repository       $guests       Guest repository.
	 */
	public function __construct( Ticket_Type_Repository $ticket_types, Ticket_Repository $tickets, Guest_Repository $guests ) {
		$this->ticket_types = $ticket_types;
		$this->tickets      = $tickets;
		$this->guests       = $guests;
	}

	/**
	 * Attach the WooCommerce order hooks.
	 *
	 * @return void
	 */
	public function bootstrap(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_status_changed' ), 20, 3 );
		add_action( 'woocommerce_order_refunded', array( $this, 'handle_refunded' ), 20, 2 );
	}

	/**
	 * React to an order status transition.
	 *
	 * WooCommerce only moves an order's overall status to "refunded" once the
	 * full order total has been refunded — partial refunds leave the status
	 * unchanged and fire {@see self::handle_refunded()} instead, which is
	 * always fired for every refund (partial or full) and is the one place
	 * that reads what was actually refunded. So "refunded" is deliberately
	 * not handled here — only "cancelled"/"failed", which void the whole
	 * order with no partial concept.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Previous status.
	 * @param string $to       New status.
	 * @return void
	 */
	public function handle_status_changed( $order_id, $from, $to ): void {
		unset( $from );

		if ( in_array( $to, array( 'processing', 'completed' ), true ) ) {
			$this->fulfil_order( (int) $order_id );
		} elseif ( in_array( $to, array( 'cancelled', 'failed' ), true ) ) {
			$this->tickets->cancel_for_order( (int) $order_id );
			$this->refresh_stock_for_order( (int) $order_id );
		}
	}

	/**
	 * React to a refund (partial or full) by cancelling exactly the tickets
	 * it covers.
	 *
	 * WooCommerce's own refund line items (product ID + quantity, negative
	 * for refunds) are stable, documented public API — used here rather
	 * than any undocumented internal linkage back to the original order
	 * item, so this stays correct across WooCommerce versions. A refund
	 * with no line items (a manual, non-itemized amount refund) can't be
	 * matched to specific tickets, so it conservatively falls back to
	 * cancelling the whole order rather than leaving refunded tickets active.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 * @return void
	 */
	public function handle_refunded( $order_id, $refund_id ): void {
		$order_id  = (int) $order_id;
		$refund    = $refund_id ? wc_get_order( (int) $refund_id ) : false;
		$cancelled = false;

		if ( $refund instanceof WC_Order_Refund ) {
			$items = $refund->get_items();

			if ( ! empty( $items ) ) {
				foreach ( $items as $refund_item ) {
					if ( ! method_exists( $refund_item, 'get_product_id' ) ) {
						continue;
					}

					$product_id = (int) $refund_item->get_product_id();
					$quantity   = abs( method_exists( $refund_item, 'get_quantity' ) ? (int) $refund_item->get_quantity() : 0 );

					if ( $product_id <= 0 || $quantity <= 0 ) {
						continue;
					}

					$ticket_type_id = $this->find_ticket_type_by_product( $product_id );

					if ( null === $ticket_type_id ) {
						continue;
					}

					$this->tickets->cancel_n_for_order_type( $order_id, $ticket_type_id, $quantity );
				}

				$cancelled = true;
			}
		}

		if ( ! $cancelled ) {
			$this->tickets->cancel_for_order( $order_id );
		}

		$this->refresh_stock_for_order( $order_id );
	}

	/**
	 * Issue tickets and guests for every ticket-type line item on an order
	 * that has not already been fulfilled.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	private function fulfil_order( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$email = (string) $order->get_billing_email();
		$phone = (string) $order->get_billing_phone();

		$customer_id     = (int) $order->get_customer_id();
		$affected_types  = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}

			$product_id = (int) $item->get_product_id();

			if ( $product_id <= 0 ) {
				continue;
			}

			$ticket_type_id = $this->find_ticket_type_by_product( $product_id );

			if ( null === $ticket_type_id ) {
				continue;
			}

			if ( $this->tickets->exists_for_order_item( (int) $item_id ) ) {
				// Tickets already exist for this item. Reactivate any that a
				// previous cancellation/refund cancelled — e.g. a failed order
				// later reinstated to processing — instead of silently doing
				// nothing and leaving them stuck cancelled.
				if ( $this->tickets->reactivate_for_order_item( (int) $item_id ) > 0 ) {
					$affected_types[ $ticket_type_id ] = true;
				}

				continue;
			}

			$ticket_type = $this->ticket_types->find( $ticket_type_id );

			if ( null === $ticket_type ) {
				continue;
			}

			$quantity = max( 1, method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 1 );

			for ( $i = 0; $i < $quantity; $i++ ) {
				$ticket = $this->tickets->issue(
					array(
						'event_id'         => (int) $ticket_type['event_id'],
						'ticket_type_id'   => $ticket_type_id,
						'wc_order_id'      => $order_id,
						'wc_order_item_id' => (int) $item_id,
						'wc_customer_id'   => $customer_id,
						'is_complimentary' => false,
					)
				);

				$guest = $this->guests->create(
					array(
						'event_id'       => (int) $ticket_type['event_id'],
						'ticket_id'      => $ticket['id'],
						'wc_customer_id' => $customer_id,
						'name'           => $name,
						'email'          => $email,
						'phone'          => $phone,
					)
				);

				$this->tickets->set_guest( (int) $ticket['id'], (int) $guest['id'] );
			}

			$affected_types[ $ticket_type_id ] = true;
		}

		foreach ( array_keys( $affected_types ) as $ticket_type_id ) {
			$this->ticket_types->refresh_stock( (int) $ticket_type_id );
		}
	}

	/**
	 * Refresh WooCommerce stock for every ticket type an order touched.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	private function refresh_stock_for_order( int $order_id ): void {
		global $wpdb;

		$table = Event_Schema::tickets();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$type_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ticket_type_id FROM {$table} WHERE wc_order_id = %d", $order_id ) );

		foreach ( (array) $type_ids as $type_id ) {
			$this->ticket_types->refresh_stock( (int) $type_id );
		}
	}

	/**
	 * Ticket type owning a WooCommerce product, if any.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return int|null
	 */
	private function find_ticket_type_by_product( int $product_id ): ?int {
		global $wpdb;

		$table = Event_Schema::ticket_types();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE wc_product_id = %d", $product_id ) );

		return $id ? (int) $id : null;
	}
}

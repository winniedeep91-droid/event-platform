<?php
/**
 * Issues EventOS tickets from paid WooCommerce orders.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

use EventOS\Woocommerce\Wc_Meta;
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
	 * Waitlist service.
	 *
	 * @var Waitlist_Service
	 */
	private Waitlist_Service $waitlist;

	/**
	 * Constructor.
	 *
	 * @param Ticket_Type_Repository $ticket_types Ticket type repository.
	 * @param Ticket_Repository      $tickets      Ticket repository.
	 * @param Guest_Repository       $guests       Guest repository.
	 * @param Waitlist_Service       $waitlist     Waitlist service.
	 */
	public function __construct( Ticket_Type_Repository $ticket_types, Ticket_Repository $tickets, Guest_Repository $guests, Waitlist_Service $waitlist ) {
		$this->ticket_types = $ticket_types;
		$this->tickets      = $tickets;
		$this->guests       = $guests;
		$this->waitlist     = $waitlist;
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
			$cancelled = $this->tickets->cancel_tickets_for_order( (int) $order_id );
			$this->propagate_cancellation( (int) $order_id, $cancelled );
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
		$cancelled = array();
		$itemized  = false;

		if ( $refund instanceof WC_Order_Refund ) {
			$items = $refund->get_items();

			if ( ! empty( $items ) ) {
				foreach ( $items as $refund_item ) {
					if ( ! method_exists( $refund_item, 'get_product_id' ) ) {
						continue;
					}

					$product_id = Wc_Meta::resolve_purchased_product_id( $refund_item );
					$quantity   = abs( method_exists( $refund_item, 'get_quantity' ) ? (int) $refund_item->get_quantity() : 0 );

					if ( $product_id <= 0 || $quantity <= 0 ) {
						continue;
					}

					$ticket_type_id = $this->find_ticket_type_by_product( $product_id );

					if ( null === $ticket_type_id ) {
						continue;
					}

					$cancelled = array_merge(
						$cancelled,
						$this->tickets->cancel_tickets_for_order_type( $order_id, $ticket_type_id, $quantity )
					);
				}

				$itemized = true;
			}
		}

		if ( ! $itemized ) {
			$cancelled = $this->tickets->cancel_tickets_for_order( $order_id );
		}

		$this->propagate_cancellation( $order_id, $cancelled );
		$this->refresh_stock_for_order( $order_id );
	}

	/**
	 * Propagate a set of newly-cancelled tickets to their linked Guest
	 * records and the purchaser's CRM Person metrics.
	 *
	 * A no-op when nothing was actually cancelled — which is exactly what
	 * makes this safe to run more than once for the same refund/order
	 * transition: {@see Ticket_Repository::cancel_by_ids()} only ever
	 * cancels tickets it finds still `active`, so a repeat webhook for an
	 * already-cancelled order reaches here with an empty `$cancelled` and
	 * returns immediately — no duplicate Guest update, no repeat CRM
	 * recompute.
	 *
	 * @param int                               $order_id  WooCommerce order ID.
	 * @param array<int, array<string, mixed>>  $cancelled Newly-cancelled ticket rows (each carrying `guest_id`).
	 * @return void
	 */
	private function propagate_cancellation( int $order_id, array $cancelled ): void {
		if ( empty( $cancelled ) ) {
			return;
		}

		foreach ( $cancelled as $ticket ) {
			$guest_id = (int) ( $ticket['guest_id'] ?? 0 );

			if ( $guest_id > 0 ) {
				$this->guests->set_status( $guest_id, 'cancelled' );
			}
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		/**
		 * Fires after one or more EventOS tickets on an order were
		 * cancelled — by a refund (partial or full) or by the order
		 * moving to cancelled/failed. The counterpart to
		 * eventos_ticket_order_fulfilled: CRM listens here to recompute
		 * the purchaser's Person metrics so cached spend/ticket counts
		 * stop counting a refunded/cancelled purchase as active.
		 *
		 * @param int    $order_id    WooCommerce order ID.
		 * @param int    $customer_id WooCommerce customer ID, 0 for a guest checkout.
		 * @param string $email       Billing email.
		 */
		do_action( 'eventos_ticket_order_cancelled', $order_id, (int) $order->get_customer_id(), (string) $order->get_billing_email() );
	}

	/**
	 * Restore the Guest records linked to a set of newly-reactivated
	 * tickets — the counterpart to {@see propagate_cancellation()}'s Guest
	 * update, for the reverse transition (an order that was
	 * cancelled/failed and refunded is later reinstated to
	 * processing/completed). Only ever touches the guest belonging to a
	 * ticket that was actually reactivated, never every guest on the order.
	 *
	 * A no-op when nothing was actually reactivated — the caller only ever
	 * passes rows from {@see Ticket_Repository::reactivate_tickets_for_order_item()},
	 * which already guards on `status = 'cancelled'`, so an already-active
	 * ticket (or a repeat hook firing) reaches here with an empty array.
	 * No separate CRM action needs firing here: the caller already adds
	 * this ticket type to `$affected_types`, which fires
	 * `eventos_ticket_order_fulfilled` at the end of `fulfil_order()` —
	 * the same hook a first-time purchase fires — so the purchaser's
	 * cached spend/ticket counts are recomputed from live WooCommerce data
	 * exactly as they would be for a new purchase.
	 *
	 * @param array<int, array<string, mixed>> $reactivated Newly-reactivated ticket rows (each carrying `guest_id`).
	 * @return void
	 */
	private function restore_guests( array $reactivated ): void {
		foreach ( $reactivated as $ticket ) {
			$guest_id = (int) ( $ticket['guest_id'] ?? 0 );

			if ( $guest_id > 0 ) {
				$this->guests->set_status( $guest_id, 'confirmed' );
			}
		}
	}

	/**
	 * Publicly re-run fulfilment for one order — the same logic
	 * {@see self::handle_status_changed()} triggers live on a status
	 * transition, exposed for a one-time backfill of orders whose
	 * "processing"/"completed" transition happened before this order's
	 * product had a linked ticket type (e.g. a WooCommerce product that
	 * pre-dates {@see \EventOS\Woocommerce\Wc_Event_Provisioning::sync()}
	 * ever running against it — a live status *change* never fires again
	 * for an order that already reached its current status). Safe to call
	 * on an already-fulfilled order: {@see self::fulfil_order_locked()}
	 * skips any line item {@see Ticket_Repository::exists_for_order_item()}
	 * already covers, so re-running this over every paid order is a no-op
	 * for anything already ticketed and only fills the actual gap.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function backfill_order( int $order_id ): void {
		$this->fulfil_order( $order_id );
	}

	/**
	 * Issue tickets and guests for every ticket-type line item on an order
	 * that has not already been fulfilled.
	 *
	 * Wrapped in a MySQL named lock scoped to this exact order: two
	 * genuinely concurrent requests processing the same order (e.g. a
	 * payment gateway firing two near-simultaneous webhook deliveries)
	 * could otherwise both pass {@see Ticket_Repository::exists_for_order_item()}
	 * before either has inserted, issuing duplicate tickets/guests for the
	 * same order item. A per-row unique constraint on `wc_order_item_id`
	 * is not a safe fix here — the schema and live data both confirm a
	 * single order item legitimately backs more than one ticket
	 * (quantity > 1 line items loop `issue()` once per unit, all sharing
	 * one `wc_order_item_id`), and every complimentary ticket shares
	 * `wc_order_item_id = 0` by design. A lock scoped to the order,
	 * covering the whole check-then-issue sequence, is the smallest
	 * correct fix: the second concurrent call simply waits, then finds
	 * every item already fulfilled via the same `exists_for_order_item()`
	 * check that already makes sequential re-fires safe.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	private function fulfil_order( int $order_id ): void {
		if ( ! $this->acquire_fulfilment_lock( $order_id ) ) {
			// Another request already holds the lock for this exact order
			// and will complete the work itself; proceeding unprotected
			// here is exactly the race this lock exists to prevent, so the
			// safest action is to do nothing and let that request finish.
			return;
		}

		try {
			$this->fulfil_order_locked( $order_id );
		} finally {
			$this->release_fulfilment_lock( $order_id );
		}
	}

	/**
	 * The actual fulfilment logic, only ever run while
	 * {@see fulfil_order()} holds this order's lock.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	private function fulfil_order_locked( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$email = (string) $order->get_billing_email();
		$phone = (string) $order->get_billing_phone();

		$customer_id     = (int) $order->get_customer_id();
		$affected_types  = array();

		// A cancelled ticket is only ever eligible for reactivation when
		// the order has never had any money refunded on it — i.e. it was
		// cancelled purely by a status transition (cancelled/failed),
		// never by wc_create_refund(). get_total_refunded() is
		// WooCommerce's own live, storage-mode-agnostic refund ledger for
		// this order (works identically under HPOS and legacy post-based
		// storage), so this needs no new EventOS column: a refund of any
		// kind — itemized against a specific ticket, or a manual/
		// non-itemized amount that falls back to cancelling every ticket
		// on the order (see handle_refunded()) — permanently disqualifies
		// every ticket on this order from ever being silently reactivated
		// by an unrelated later status transition (e.g. a fraud-review
		// hold-and-release that has nothing to do with the refund). Only
		// a genuine whole-order cancelled/failed state, with zero money
		// ever refunded, is eligible for reactivation on reinstatement.
		$has_been_refunded = (float) $order->get_total_refunded() > 0.0;

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}

			$product_id = Wc_Meta::resolve_purchased_product_id( $item );

			if ( $product_id <= 0 ) {
				continue;
			}

			$ticket_type_id = $this->find_ticket_type_by_product( $product_id );

			if ( null === $ticket_type_id ) {
				continue;
			}

			if ( $this->tickets->exists_for_order_item( (int) $item_id ) ) {
				// Tickets already exist for this item. Reactivate any that a
				// previous whole-order cancellation/failure cancelled — e.g.
				// a failed order later reinstated to processing — instead of
				// silently doing nothing and leaving them stuck cancelled.
				// Never attempted once the order has any refund history; see
				// $has_been_refunded above.
				if ( ! $has_been_refunded ) {
					$reactivated = $this->tickets->reactivate_tickets_for_order_item( (int) $item_id );

					if ( ! empty( $reactivated ) ) {
						$affected_types[ $ticket_type_id ] = true;
						$this->restore_guests( $reactivated );
					}
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

				// A no-op for the overwhelming majority of purchases, which
				// never touched the waitlist — only marks an entry
				// converted when this exact ticket type/email was actually
				// promoted.
				$this->waitlist->mark_converted_if_promoted( $ticket_type_id, $email, (int) $ticket['id'] );
			}

			$affected_types[ $ticket_type_id ] = true;
		}

		foreach ( array_keys( $affected_types ) as $ticket_type_id ) {
			$this->ticket_types->refresh_stock( (int) $ticket_type_id );
		}

		if ( ! empty( $affected_types ) ) {
			/**
			 * Fires once per order after it fulfilled at least one EventOS
			 * ticket — the single point in the ticket/order lifecycle where
			 * the purchaser's identity signals (customer ID, billing email,
			 * name, phone) are known and this order is confirmed EventOS-
			 * relevant. The CRM module listens here to resolve/update the
			 * permanent Person via Person_Resolver, the same path
			 * Person_Backfill_Service uses for historical data — see that
			 * class's docblock. Deliberately not fired for orders with no
			 * ticket-type line items, so CRM only ever resolves purchasers
			 * who actually did EventOS business.
			 *
			 * @param int    $order_id    WooCommerce order ID.
			 * @param int    $customer_id WooCommerce customer ID, 0 for a guest checkout.
			 * @param string $email       Billing email.
			 * @param string $name        Billing first + last name.
			 * @param string $phone       Billing phone.
			 */
			do_action( 'eventos_ticket_order_fulfilled', $order_id, $customer_id, $email, $name, $phone );
		}
	}

	/**
	 * Acquire a MySQL named lock scoped to one order's fulfilment.
	 *
	 * `GET_LOCK()` is session-scoped: the same PHP request's `$wpdb`
	 * connection can safely re-enter it, while a genuinely concurrent
	 * request on a separate connection blocks until the first releases it
	 * (or the timeout elapses). A 10 second timeout is generous relative to
	 * how fast fulfilment actually runs; a timeout is treated as "another
	 * request is still working on it", not as permission to proceed
	 * unprotected.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return bool Whether the lock was acquired.
	 */
	private function acquire_fulfilment_lock( int $order_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::fulfilment_lock_name( $order_id ), 10 ) );
	}

	/**
	 * Release the lock {@see acquire_fulfilment_lock()} took.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	private function release_fulfilment_lock( int $order_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::fulfilment_lock_name( $order_id ) ) );
	}

	/**
	 * The `GET_LOCK()` name for one order — well under MySQL's 64
	 * character limit for any realistic order ID.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return string
	 */
	private static function fulfilment_lock_name( int $order_id ): string {
		return 'eventos_fulfil_order_' . $order_id;
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

			// A cancellation or refund is the only path that reaches here,
			// and both are real capacity becoming available again — queue a
			// waitlist pass rather than processing synchronously inside this
			// WooCommerce hook.
			$this->waitlist->queue_processing( (int) $type_id );
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

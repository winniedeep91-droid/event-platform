<?php
/**
 * Cached lifetime-metric recomputation boundary for the permanent Person.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Crm;

use EventOS\Events\Event_Schema;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `eventos_persons` carries cached lifetime-metric columns (Phase 1 schema)
 * so a CRM list view never has to aggregate tickets/orders live. This class
 * is the one place that computes them, so a later phase never has to
 * scatter that arithmetic across the UI or REST layer.
 *
 * Two data sources, reached through whichever identities are currently
 * attached to the Person (which, unlike the single wc_customer_id + single
 * email that {@see \EventOS\Events\Guest_Repository::attendance_history()}
 * takes, can be several of each over a Person's lifetime):
 *
 * - `eventos_tickets`/`eventos_ticket_types` for total_tickets_purchased,
 *   total_events_attended, first_event_id, last_event_id,
 *   last_attendance_at, vip_purchase_count (ticket_types.tier = 'vip', a
 *   real structured column — see Ticket_Type_Repository::tiers()) and
 *   complimentary_count (tickets.is_complimentary, the per-ticket flag —
 *   deliberately not tier = 'complimentary', which classifies the ticket
 *   *type*, not whether this specific ticket was actually issued free).
 *
 * - Live WooCommerce orders (`wc_get_orders()`) for total_spend,
 *   avg_order_value, avg_ticket_value and last_purchase_at. total_spend is
 *   the sum, for every order in a paid status ('completed', 'processing',
 *   'on-hold') across every wc_customer_id AND every email identity the
 *   Person has (de-duplicated by order ID), of `$order->get_total()` minus
 *   whatever that order's own `get_refunds()` records — a *partial* refund
 *   leaves WooCommerce's order status unchanged (see
 *   {@see \EventOS\Events\Ticket_Fulfillment::handle_refunded()}'s
 *   docblock), so without netting out the refund a still-"completed" order
 *   would keep counting its pre-refund total as active spend forever. The
 *   paid-status set itself matches
 *   {@see \EventOS\Rest\Woocommerce_Controller::customer_order_stats()}'s,
 *   generalized across every identity rather than one signal.
 *   avg_ticket_value = total_spend ÷ total_tickets_purchased is a
 *   documented APPROXIMATION, not an exact per-ticket price: no field
 *   anywhere in the schema records what was actually paid for one specific
 *   ticket (ticket_types.price is the list price, not the paid price after
 *   discounts/fees/tax), so this is order revenue spread evenly across
 *   tickets issued, not each ticket's individual sale price.
 *
 * refund_count and cancellation_count are deliberately NOT populated here.
 * Both {@see \EventOS\Events\Ticket_Fulfillment::handle_refunded()} and the
 * cancelled/failed order path in the same class collapse to the same
 * `tickets.status = 'cancelled'` — there is no independent field
 * distinguishing "this ticket was refunded" from "this order was
 * cancelled/failed". Guessing a split here risks double-counting or
 * misclassifying, so both columns are left at their Phase 1 default (0)
 * until the underlying ticket lifecycle can actually support the
 * distinction — a future data-model phase, not this one.
 */
final class Person_Metrics_Service {

	/**
	 * Order statuses counted as revenue — identical to
	 * Woocommerce_Controller::customer_order_stats()'s own list.
	 */
	private const PAID_STATUSES = array( 'completed', 'processing', 'on-hold' );

	/**
	 * Person repository.
	 *
	 * @var Person_Repository
	 */
	private Person_Repository $persons;

	/**
	 * Person identity repository.
	 *
	 * @var Person_Identity_Repository
	 */
	private Person_Identity_Repository $identities;

	/**
	 * Constructor.
	 *
	 * @param Person_Repository          $persons    Person repository.
	 * @param Person_Identity_Repository $identities Person identity repository.
	 */
	public function __construct( Person_Repository $persons, Person_Identity_Repository $identities ) {
		$this->persons    = $persons;
		$this->identities = $identities;
	}

	/**
	 * Recompute and persist the cached metrics for one Person.
	 *
	 * @param int $person_id Person ID.
	 * @return array<string, mixed>|null Updated Person row, null if not found.
	 */
	public function recompute( int $person_id ): ?array {
		$person = $this->persons->find_by_id( $person_id );

		if ( null === $person ) {
			return null;
		}

		$wc_customer_ids = $this->identity_values( $person_id, 'wc_customer_id' );
		$emails          = $this->identity_values( $person_id, 'email' );

		if ( ! $wc_customer_ids && ! $emails ) {
			return $person;
		}

		$ticket_metrics   = $this->ticket_metrics( $wc_customer_ids, $emails );
		$financial_metrics = $this->financial_metrics( $wc_customer_ids, $emails, $ticket_metrics['total_tickets_purchased'] );

		return $this->persons->update( $person_id, array_merge( $ticket_metrics, $financial_metrics ) );
	}

	/**
	 * Ticket/attendance-derived metrics.
	 *
	 * @param int[]    $wc_customer_ids WooCommerce customer IDs attached to the Person.
	 * @param string[] $emails          Normalized emails attached to the Person.
	 * @return array<string, mixed>
	 */
	private function ticket_metrics( array $wc_customer_ids, array $emails ): array {
		global $wpdb;

		$tickets      = Event_Schema::tickets();
		$guests       = Event_Schema::guests();
		$ticket_types = Event_Schema::ticket_types();

		$where  = array();
		$params = array();

		if ( $wc_customer_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $wc_customer_ids ), '%d' ) );
			$where[]      = "t.wc_customer_id IN ({$placeholders})";
			$params       = array_merge( $params, array_map( 'intval', $wc_customer_ids ) );
		}

		if ( $emails ) {
			$placeholders = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
			$where[]      = "g.email IN ({$placeholders})";
			$params       = array_merge( $params, $emails );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.event_id, t.checked_in, t.checked_in_at, t.created_at, t.is_complimentary, tt.tier
				FROM {$tickets} t
				LEFT JOIN {$guests} g ON g.id = t.guest_id
				LEFT JOIN {$ticket_types} tt ON tt.id = t.ticket_type_id
				WHERE t.status != 'cancelled' AND (" . implode( ' OR ', $where ) . ')',
				$params
			),
			ARRAY_A
		);

		$attended_events      = array();
		$events_by_first_seen = array();
		$last_attendance_at   = null;
		$vip_count            = 0;
		$complimentary_count  = 0;

		foreach ( (array) $rows as $row ) {
			$event_id = (int) $row['event_id'];

			if ( ! isset( $events_by_first_seen[ $event_id ] ) ) {
				$events_by_first_seen[ $event_id ] = (string) $row['created_at'];
			}

			if ( 1 === (int) $row['checked_in'] ) {
				$attended_events[ $event_id ] = true;

				if ( null === $last_attendance_at || strtotime( (string) $row['checked_in_at'] ) > strtotime( (string) $last_attendance_at ) ) {
					$last_attendance_at = $row['checked_in_at'];
				}
			}

			if ( 'vip' === (string) $row['tier'] ) {
				++$vip_count;
			}

			if ( 1 === (int) $row['is_complimentary'] ) {
				++$complimentary_count;
			}
		}

		asort( $events_by_first_seen );
		$ordered_event_ids = array_keys( $events_by_first_seen );

		return array(
			'total_tickets_purchased' => count( $rows ),
			'total_events_attended'   => count( $attended_events ),
			'first_event_id'          => $ordered_event_ids ? (int) $ordered_event_ids[0] : 0,
			'last_event_id'           => $ordered_event_ids ? (int) $ordered_event_ids[ count( $ordered_event_ids ) - 1 ] : 0,
			'last_attendance_at'      => $last_attendance_at,
			'vip_purchase_count'      => $vip_count,
			'complimentary_count'     => $complimentary_count,
		);
	}

	/**
	 * WooCommerce-order-derived financial metrics. See the class docblock
	 * for the source-of-truth and de-duplication rules.
	 *
	 * @param int[]    $wc_customer_ids     WooCommerce customer IDs attached to the Person.
	 * @param string[] $emails              Normalized emails attached to the Person.
	 * @param int      $total_tickets       Already-computed total_tickets_purchased, for avg_ticket_value.
	 * @return array<string, mixed>
	 */
	private function financial_metrics( array $wc_customer_ids, array $emails, int $total_tickets ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'total_spend'      => 0.0,
				'avg_order_value'  => 0.0,
				'avg_ticket_value' => 0.0,
				'last_purchase_at' => null,
			);
		}

		$orders_by_id = array();

		foreach ( $wc_customer_ids as $customer_id ) {
			$customer_id = (int) $customer_id;

			if ( $customer_id <= 0 ) {
				continue;
			}

			$orders = wc_get_orders(
				array(
					'customer_id' => $customer_id,
					'limit'       => -1,
					'return'      => 'objects',
				)
			);

			foreach ( $orders as $order ) {
				if ( $order instanceof WC_Order ) {
					$orders_by_id[ $order->get_id() ] = $order;
				}
			}
		}

		foreach ( $emails as $email ) {
			if ( '' === $email ) {
				continue;
			}

			$orders = wc_get_orders(
				array(
					'billing_email' => $email,
					'limit'         => -1,
					'return'        => 'objects',
				)
			);

			foreach ( $orders as $order ) {
				if ( $order instanceof WC_Order ) {
					$orders_by_id[ $order->get_id() ] = $order;
				}
			}
		}

		$total_spend      = 0.0;
		$order_count      = 0;
		$last_purchase_at = null;

		foreach ( $orders_by_id as $order ) {
			if ( ! in_array( $order->get_status(), self::PAID_STATUSES, true ) ) {
				continue;
			}

			$refunded = 0.0;

			foreach ( $order->get_refunds() as $refund ) {
				$refunded += abs( (float) $refund->get_amount() );
			}

			$total_spend += max( 0.0, (float) $order->get_total() - $refunded );
			++$order_count;

			$created = $order->get_date_created();

			if ( $created && ( null === $last_purchase_at || $created->getTimestamp() > strtotime( (string) $last_purchase_at ) ) ) {
				$last_purchase_at = $created->date( 'Y-m-d H:i:s' );
			}
		}

		return array(
			'total_spend'      => $total_spend,
			'avg_order_value'  => $order_count > 0 ? round( $total_spend / $order_count, 2 ) : 0.0,
			'avg_ticket_value' => $total_tickets > 0 ? round( $total_spend / $total_tickets, 2 ) : 0.0,
			'last_purchase_at' => $last_purchase_at,
		);
	}

	/**
	 * Values currently attached to a Person for one identity type.
	 *
	 * @param int    $person_id Person ID.
	 * @param string $type      Identity type.
	 * @return string[]
	 */
	private function identity_values( int $person_id, string $type ): array {
		$values = array();

		foreach ( $this->identities->for_person( $person_id ) as $identity ) {
			if ( $type === $identity['type'] ) {
				$values[] = $identity['value'];
			}
		}

		return $values;
	}
}

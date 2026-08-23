<?php
/**
 * Brand-wide (cross-event) commercial performance reporting for the
 * EventOS dashboard.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

use EventOS\WooCommerce;
use EventOS\Woocommerce\Wc_Meta;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where Event_Report_Builder answers "how did this one event perform?",
 * this answers "how is the brand performing across every event?" — the
 * same WooCommerce-is-source-of-truth-for-money, EventOS-is-source-of-truth-
 * for-tickets split, just resolved across every event's ticket products
 * instead of one event's. Nothing here is cached or duplicated storage;
 * every figure is computed live from Ticket_Repository and WooCommerce.
 */
final class Brand_Report_Builder {

	/**
	 * Supported chart periods.
	 *
	 * @var string[]
	 */
	public const PERIODS = array( '7d', '30d', '90d', 'year' );

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
	 * Order resolver.
	 *
	 * @var Ticket_Order_Resolver
	 */
	private Ticket_Order_Resolver $orders;

	/**
	 * Event repository.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $events;

	/**
	 * Constructor.
	 *
	 * @param Ticket_Type_Repository $ticket_types Ticket type repository.
	 * @param Ticket_Repository      $tickets      Ticket repository.
	 * @param Ticket_Order_Resolver  $orders       Order resolver.
	 * @param Event_Repository       $events       Event repository.
	 */
	public function __construct(
		Ticket_Type_Repository $ticket_types,
		Ticket_Repository $tickets,
		Ticket_Order_Resolver $orders,
		Event_Repository $events
	) {
		$this->ticket_types = $ticket_types;
		$this->tickets      = $tickets;
		$this->orders       = $orders;
		$this->events       = $events;
	}

	/**
	 * All-time, brand-wide performance totals — the dashboard's Performance
	 * Overview cards. Ticket figures come straight from the tickets table;
	 * revenue and order count are resolved from WooCommerce with no upper
	 * bound on history, since these are meant to read as cumulative totals,
	 * not a period-scoped figure (the charts below cover the period view).
	 *
	 * @return array<string, mixed>
	 */
	public function summary(): array {
		$ticket_totals = $this->tickets->totals();
		$orders        = $this->orders->paid_orders();

		$revenue = 0.0;

		foreach ( $orders as $order ) {
			$revenue += (float) $order->get_total();
		}

		return array(
			'currency'      => WooCommerce::currency(),
			'total_revenue' => $revenue,
			'tickets_sold'  => $ticket_totals['total'],
			'attendance'    => $ticket_totals['checked_in'],
			'complimentary' => $ticket_totals['complimentary'],
			'orders'        => count( $orders ),
		);
	}

	/**
	 * Day-bucketed revenue and ticket sales for the given period — the
	 * dashboard's Revenue Over Time / Tickets Sold Over Time charts. Bounded
	 * to the requested window so switching periods never triggers an
	 * unbounded full-history WooCommerce scan.
	 *
	 * @param string $period One of self::PERIODS; unrecognised values fall back to '30d'.
	 * @return array<string, mixed>
	 */
	public function series( string $period ): array {
		[$from, $to] = self::period_bounds( $period );

		$orders         = $this->orders->paid_orders( array(), $from, $to );
		$revenue_by_day = array();

		foreach ( $orders as $order ) {
			$created = $order->get_date_created();
			$day     = $created ? $created->date( 'Y-m-d' ) : gmdate( 'Y-m-d' );

			if ( ! isset( $revenue_by_day[ $day ] ) ) {
				$revenue_by_day[ $day ] = array(
					'date'    => $day,
					'revenue' => 0.0,
					'orders'  => 0,
				);
			}

			$revenue_by_day[ $day ]['revenue'] += (float) $order->get_total();
			$revenue_by_day[ $day ]['orders']  += 1;
		}

		ksort( $revenue_by_day );

		return array(
			'period'         => in_array( $period, self::PERIODS, true ) ? $period : '30d',
			'from'           => $from,
			'to'             => $to,
			'currency'       => WooCommerce::currency(),
			'revenue_by_day' => array_values( $revenue_by_day ),
			'tickets_by_day' => $this->tickets->counts_by_day( $from, $to ),
		);
	}

	/**
	 * Batched tickets-sold / attendance / revenue summary for a set of
	 * events, resolved in a bounded number of queries regardless of how
	 * many events are requested — one ticket-table query and one
	 * WooCommerce order pass, never one query per event. Used by the
	 * dashboard's My Events table.
	 *
	 * @param int[] $event_ids Event IDs.
	 * @return array<int, array{tickets_sold: int, checked_in: int, revenue: float}> Event ID => summary.
	 */
	public function events_summary( array $event_ids ): array {
		$event_ids = array_values( array_unique( array_map( 'intval', $event_ids ) ) );

		if ( empty( $event_ids ) ) {
			return array();
		}

		$summary = array();

		foreach ( $event_ids as $id ) {
			$summary[ $id ] = array(
				'tickets_sold' => 0,
				'checked_in'   => 0,
				'revenue'      => 0.0,
			);
		}

		foreach ( $this->tickets->counts_by_event( $event_ids ) as $id => $counts ) {
			$summary[ $id ]['tickets_sold'] = $counts['total'];
			$summary[ $id ]['checked_in']   = $counts['checked_in'];
		}

		$product_map = $this->ticket_types->product_event_map( $event_ids );
		$orders      = $this->orders->paid_orders( $event_ids );

		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				if ( ! method_exists( $item, 'get_product_id' ) ) {
					continue;
				}

				$event_id = $product_map[ Wc_Meta::resolve_purchased_product_id( $item ) ] ?? null;

				if ( null !== $event_id && isset( $summary[ $event_id ] ) ) {
					$summary[ $event_id ]['revenue'] += (float) $item->get_total();
				}
			}
		}

		return $summary;
	}

	/**
	 * Inclusive [from, to] datetime bounds for a period keyword, anchored to
	 * the current moment.
	 *
	 * @param string $period One of self::PERIODS.
	 * @return array{0: string, 1: string}
	 */
	private static function period_bounds( string $period ): array {
		$now = current_time( 'mysql', true );

		$offset = match ( $period ) {
			'7d'   => '-7 days',
			'90d'  => '-90 days',
			'year' => '-1 year',
			default => '-30 days',
		};

		$from = gmdate( 'Y-m-d H:i:s', strtotime( $offset, (int) strtotime( $now ) ) );

		return array( $from, $now );
	}
}

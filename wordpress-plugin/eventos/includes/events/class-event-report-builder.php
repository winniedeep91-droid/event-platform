<?php
/**
 * Combines WooCommerce financial data with EventOS ticket/attendance data.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

use EventOS\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce is the source of truth for revenue and refunds; EventOS is the
 * source of truth for capacity, sales and check-ins. Every figure here is
 * computed live from one or the other — nothing is duplicated or cached.
 */
final class Event_Report_Builder {

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
	 * Constructor.
	 *
	 * @param Ticket_Type_Repository $ticket_types Ticket type repository.
	 * @param Ticket_Repository      $tickets      Ticket repository.
	 * @param Ticket_Order_Resolver  $orders       Order resolver.
	 */
	public function __construct( Ticket_Type_Repository $ticket_types, Ticket_Repository $tickets, Ticket_Order_Resolver $orders ) {
		$this->ticket_types = $ticket_types;
		$this->tickets      = $tickets;
		$this->orders       = $orders;
	}

	/**
	 * Build the full report payload for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return array<string, mixed>
	 */
	public function build( int $event_id ): array {
		$types      = $this->ticket_types->for_event( $event_id );
		$counts     = $this->tickets->counts_for_event( $event_id );
		$order_rows = $this->orders->orders_for_event( $event_id, array( 'per_page' => 1000 ) )['items'];

		$gross           = 0.0;
		$refund_total    = 0.0;
		$paid_orders     = 0;
		$by_day          = array();
		$by_type         = array();
		$by_customer     = array();
		$refunds_by_day  = array();
		$sales_by_day    = array();

		foreach ( $types as $type ) {
			$by_type[ (int) $type['id'] ] = array(
				'ticket_type_id' => (int) $type['id'],
				'name'           => (string) $type['name'],
				'tier'           => (string) $type['tier'],
				'sold'           => (int) $type['sold'],
				'capacity'       => $type['capacity'],
				'gross'          => 0.0,
				'net'            => 0.0,
			);
		}

		foreach ( $order_rows as $order ) {
			$order_refund_amount = array_sum( array_column( $order['refunds'], 'amount' ) );

			if ( in_array( $order['status'], Ticket_Order_Resolver::PAID_STATUSES, true ) ) {
				++$paid_orders;
				$gross += (float) $order['total'];

				$day = substr( (string) $order['created_at'], 0, 10 );

				if ( ! isset( $by_day[ $day ] ) ) {
					$by_day[ $day ] = array(
						'date'   => $day,
						'gross'  => 0.0,
						'net'    => 0.0,
						'orders' => 0,
					);
				}

				$by_day[ $day ]['gross']  += (float) $order['total'];
				$by_day[ $day ]['net']    += (float) $order['total'] - $order_refund_amount;
				$by_day[ $day ]['orders'] += 1;

				$tickets_this_order = 0;

				foreach ( (array) $order['tickets'] as $ticket ) {
					$ticket_type_id = (int) $ticket['ticket_type_id'];

					if ( isset( $by_type[ $ticket_type_id ] ) ) {
						$by_type[ $ticket_type_id ]['gross'] += (float) $ticket['total'];
						$by_type[ $ticket_type_id ]['net']   += (float) $ticket['total'];
					}

					$tickets_this_order += (int) $ticket['quantity'];
				}

				$sales_by_day[ $day ] = (int) ( $sales_by_day[ $day ] ?? 0 ) + $tickets_this_order;

				$customer_key = $order['customer_id'] > 0 ? 'c' . $order['customer_id'] : 'e' . $order['customer_email'];

				if ( ! isset( $by_customer[ $customer_key ] ) ) {
					$by_customer[ $customer_key ] = array(
						'customer_id' => (int) $order['customer_id'],
						'name'        => (string) $order['customer_name'],
						'email'       => (string) $order['customer_email'],
						'orders'      => 0,
						'spend'       => 0.0,
					);
				}

				$by_customer[ $customer_key ]['orders'] += 1;
				$by_customer[ $customer_key ]['spend']  += (float) $order['total'];
			}

			if ( $order_refund_amount > 0 ) {
				$refund_total += $order_refund_amount;

				foreach ( (array) $order['refunds'] as $refund ) {
					$refund_day = substr( (string) ( $refund['created_at'] ?: $order['created_at'] ), 0, 10 );

					if ( ! isset( $refunds_by_day[ $refund_day ] ) ) {
						$refunds_by_day[ $refund_day ] = array(
							'date'   => $refund_day,
							'amount' => 0.0,
							'count'  => 0,
						);
					}

					$refunds_by_day[ $refund_day ]['amount'] += (float) $refund['amount'];
					$refunds_by_day[ $refund_day ]['count']  += 1;
				}
			}
		}

		$capacity      = null;
		$has_unlimited = false;

		foreach ( $types as $type ) {
			if ( null === $type['capacity'] ) {
				$has_unlimited = true;
			} else {
				$capacity = (int) $capacity + (int) $type['capacity'];
			}
		}

		if ( $has_unlimited ) {
			$capacity = null;
		}

		$tickets_sold      = (int) $counts['total'];
		$checked_in        = (int) $counts['checked_in'];
		$tickets_available = null !== $capacity ? max( 0, $capacity - $tickets_sold ) : null;
		$complimentary     = $this->tickets->complimentary_count_for_event( $event_id );

		uasort(
			$by_day,
			static function ( array $a, array $b ): int {
				return strcmp( (string) $a['date'], (string) $b['date'] );
			}
		);

		ksort( $sales_by_day );

		uasort(
			$refunds_by_day,
			static function ( array $a, array $b ): int {
				return strcmp( (string) $a['date'], (string) $b['date'] );
			}
		);

		$top_customers = array_values( $by_customer );

		usort(
			$top_customers,
			static function ( array $a, array $b ): int {
				return $b['spend'] <=> $a['spend'];
			}
		);

		return array(
			// Same store currency WooCommerce prices every order in — see
			// EventOS\WooCommerce::currency(). Report figures are aggregated
			// from those orders, so they share its currency.
			'currency'               => WooCommerce::currency(),
			'summary'                => array(
				'gross_revenue'        => $gross,
				'net_revenue'          => $gross - $refund_total,
				'refunds'              => $refund_total,
				'tickets_sold'         => $tickets_sold,
				'tickets_available'    => $tickets_available,
				'capacity'             => $capacity,
				'attendance_rate'      => $tickets_sold > 0 ? ( $checked_in / $tickets_sold ) * 100 : null,
				'checked_in'           => $checked_in,
				'complimentary'        => $complimentary,
				'average_order_value'  => $paid_orders > 0 ? $gross / $paid_orders : 0.0,
				'orders'               => $paid_orders,
			),
			'revenue_by_day'         => array_values( $by_day ),
			'revenue_by_ticket_type' => array_values( $by_type ),
			'sales_velocity'         => array_map(
				static function ( string $date, int $tickets ): array {
					return array(
						'date'    => $date,
						'tickets' => $tickets,
					);
				},
				array_keys( $sales_by_day ),
				array_values( $sales_by_day )
			),
			'top_customers'          => array_slice( $top_customers, 0, 10 ),
			'refund_breakdown'       => array_values( $refunds_by_day ),
		);
	}
}

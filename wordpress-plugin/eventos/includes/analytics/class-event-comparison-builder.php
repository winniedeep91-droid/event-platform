<?php
/**
 * Organisation-wide event performance comparison.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Analytics;

use EventOS\Events\Brand_Report_Builder;
use EventOS\Events\Event_Repository;
use EventOS\Events\Ticket_Order_Resolver;
use EventOS\Finance\Finance_Report_Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers "how is this promoter performing across all events?" by placing
 * events side by side, ranked — the one thing neither the Dashboard's "My
 * Events" table (no profit/margin, not sortable) nor Finance's
 * organisation summary (a single aggregate total, not broken out per
 * event) already provides. Ticket/attendance/revenue figures come straight
 * from {@see Brand_Report_Builder::events_summary()}, batched across every
 * compared event in one pass exactly as the Dashboard already relies on it
 * for; nothing here recomputes them.
 *
 * Financial figures (profit, margin, expenses) are deliberately a separate,
 * opt-in step ({@see self::attach_financials()}) rather than always
 * included: they come from {@see Finance_Report_Builder::build()}, one call
 * per compared event, and the caller only invokes it after confirming the
 * requesting user actually holds `eventos_view_finance` — see
 * Analytics_Controller. A capability that can list/compare events must
 * never imply financial visibility.
 *
 * Bounded to {@see self::MAX_EVENTS} events per call. Larger than that and
 * the per-event Finance_Report_Builder::build() pass (one WooCommerce query
 * per event — the same cost Finance's own event Finance tab already pays
 * for a single event) stops being a reasonable trade-off; see this class's
 * own docblock note in the Analytics module's final report for why no
 * further batching was built for this pass.
 */
final class Event_Comparison_Builder {

	/**
	 * Maximum events compared in a single call.
	 */
	public const MAX_EVENTS = 25;

	/**
	 * Event repository.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $events;

	/**
	 * Brand-wide report builder.
	 *
	 * @var Brand_Report_Builder
	 */
	private Brand_Report_Builder $brand;

	/**
	 * Order resolver.
	 *
	 * @var Ticket_Order_Resolver
	 */
	private Ticket_Order_Resolver $orders;

	/**
	 * Finance report builder.
	 *
	 * @var Finance_Report_Builder
	 */
	private Finance_Report_Builder $finance;

	/**
	 * Audience insights builder.
	 *
	 * @var Event_Insights_Builder
	 */
	private Event_Insights_Builder $insights;

	/**
	 * Constructor.
	 *
	 * @param Event_Repository       $events   Event repository.
	 * @param Brand_Report_Builder   $brand    Brand-wide report builder.
	 * @param Ticket_Order_Resolver  $orders   Order resolver.
	 * @param Finance_Report_Builder $finance  Finance report builder.
	 * @param Event_Insights_Builder $insights Audience insights builder.
	 */
	public function __construct(
		Event_Repository $events,
		Brand_Report_Builder $brand,
		Ticket_Order_Resolver $orders,
		Finance_Report_Builder $finance,
		Event_Insights_Builder $insights
	) {
		$this->events   = $events;
		$this->brand    = $brand;
		$this->orders   = $orders;
		$this->finance  = $finance;
		$this->insights = $insights;
	}

	/**
	 * Ticket, attendance, revenue and audience figures for a page of events.
	 *
	 * Accepted args: any {@see Event_Repository::query()} filter (search,
	 * status, from, to, orderby, order), plus page/per_page (capped at
	 * {@see self::MAX_EVENTS}).
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function compare( array $args = array() ): array {
		$args['per_page'] = min( self::MAX_EVENTS, max( 1, (int) ( $args['per_page'] ?? self::MAX_EVENTS ) ) );
		$args['page']     = max( 1, (int) ( $args['page'] ?? 1 ) );

		$result    = $this->events->query( $args );
		$event_ids = array_map(
			static function ( array $event ): int {
				return (int) $event['id'];
			},
			$result['items']
		);

		$summaries = $this->brand->events_summary( $event_ids );
		$rows      = array();

		foreach ( $result['items'] as $event ) {
			$id      = (int) $event['id'];
			$summary = $summaries[ $id ] ?? array(
				'tickets_sold' => 0,
				'checked_in'   => 0,
				'revenue'      => 0.0,
			);

			$order_rows  = $this->orders->orders_for_event( $id, array( 'per_page' => 1000 ) )['items'];
			$paid_orders = array_values(
				array_filter(
					$order_rows,
					static function ( array $order ): bool {
						return in_array( $order['status'], Ticket_Order_Resolver::PAID_STATUSES, true );
					}
				)
			);
			$order_count = count( $paid_orders );
			$audience    = $this->insights->summarise( $id, $order_rows );
			$capacity    = $event['capacity'];

			$rows[] = array(
				'event_id'             => $id,
				'title'                => (string) $event['title'],
				'starts_at'            => $event['starts_at'],
				'status'               => (string) $event['status'],
				'capacity'             => $capacity,
				'tickets_sold'         => (int) $summary['tickets_sold'],
				'checked_in'           => (int) $summary['checked_in'],
				'sell_through'         => ( null !== $capacity && (int) $capacity > 0 )
					? ( (int) $summary['tickets_sold'] / (int) $capacity ) * 100
					: null,
				'revenue'              => (float) $summary['revenue'],
				'orders'               => $order_count,
				'average_order_value'  => $order_count > 0 ? (float) $summary['revenue'] / $order_count : 0.0,
				'new_customers'        => $audience['new_customers'],
				'returning_customers'  => $audience['returning_customers'],
			);
		}

		return array(
			'items' => $rows,
			'total' => $result['total'],
		);
	}

	/**
	 * Add profit, margin and expenses to each row — a separate step so a
	 * caller only ever invokes it once the requesting user's
	 * `eventos_view_finance` capability has been confirmed. See class
	 * docblock.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows from {@see self::compare()}.
	 * @return array<int, array<string, mixed>>
	 */
	public function attach_financials( array $rows ): array {
		foreach ( $rows as &$row ) {
			$pnl = $this->finance->build( (int) $row['event_id'] );

			$row['currency']       = $pnl['currency'];
			$row['net_profit']     = $pnl['result']['net_profit'];
			$row['profit_margin']  = $pnl['result']['profit_margin'];
			$row['total_expenses'] = $pnl['result']['total_expenses'];
			$row['total_fees']     = $pnl['result']['total_fees'];
		}
		unset( $row );

		return $rows;
	}
}

<?php
/**
 * Event and organisation-level Profit & Loss.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Finance;

use EventOS\Events\Ticket_Order_Resolver;
use EventOS\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Combines the same WooCommerce-sourced revenue/refund/discount/fee figures
 * {@see \EventOS\Events\Event_Report_Builder} already computes with the
 * expenses this module persists, into a single Revenue → Adjustments →
 * Fees → Expenses → Result P&L. WooCommerce stays the only source of truth
 * for money that moved through checkout; nothing it exposes is duplicated
 * into storage here, and nothing it does *not* expose (gateway-specific
 * processing fees, for example) is ever guessed — see the `fee_status`
 * field below.
 */
final class Finance_Report_Builder {

	/**
	 * Order resolver.
	 *
	 * @var Ticket_Order_Resolver
	 */
	private Ticket_Order_Resolver $orders;

	/**
	 * Expense repository.
	 *
	 * @var Expense_Repository
	 */
	private Expense_Repository $expenses;

	/**
	 * Constructor.
	 *
	 * @param Ticket_Order_Resolver $orders   Order resolver.
	 * @param Expense_Repository    $expenses Expense repository.
	 */
	public function __construct( Ticket_Order_Resolver $orders, Expense_Repository $expenses ) {
		$this->orders   = $orders;
		$this->expenses = $expenses;
	}

	/**
	 * Full P&L for one event.
	 *
	 * @param int $event_id Event ID.
	 * @return array<string, mixed>
	 */
	public function build( int $event_id ): array {
		$order_rows = $this->orders->orders_for_event( $event_id, array( 'per_page' => 1000 ) )['items'];

		$ticket_revenue = 0.0;
		$discounts      = 0.0;
		$refunds        = 0.0;
		$payment_fees   = 0.0;
		$fee_seen       = false;
		$paid_orders    = 0;

		foreach ( $order_rows as $order ) {
			if ( ! in_array( $order['status'], Ticket_Order_Resolver::PAID_STATUSES, true ) ) {
				continue;
			}

			++$paid_orders;
			// Pre-discount, ticket-line-items-only — see org_summary()'s
			// matching use of get_subtotal() and this class's docblock.
			$ticket_revenue += (float) $order['subtotal'];
			$discounts      += (float) ( $order['discount_total'] ?? 0.0 );
			$refunds        += array_sum( array_column( (array) $order['refunds'], 'amount' ) );

			$order_fees = (array) ( $order['fees'] ?? array() );

			if ( ! empty( $order_fees ) ) {
				$fee_seen      = true;
				$payment_fees += array_sum( array_column( $order_fees, 'total' ) );
			}
		}

		return $this->assemble(
			$event_id,
			$ticket_revenue,
			$discounts,
			$refunds,
			$payment_fees,
			$fee_seen,
			$paid_orders,
			$this->expenses->totals_by_category( $event_id ),
			$this->expenses->total_for_event( $event_id )
		);
	}

	/**
	 * Organisation-wide P&L totals across a set of events (an empty array
	 * scopes to every event), resolved in a bounded number of queries the
	 * same way {@see \EventOS\Events\Brand_Report_Builder} batches revenue —
	 * never one WooCommerce query per event.
	 *
	 * @param int[] $event_ids Event IDs; empty scopes to every event.
	 * @return array<string, mixed>
	 */
	public function org_summary( array $event_ids = array() ): array {
		$orders = $this->orders->paid_orders( $event_ids );

		$ticket_revenue = 0.0;
		$discounts      = 0.0;
		$refunds        = 0.0;
		$payment_fees   = 0.0;
		$fee_seen       = false;

		foreach ( $orders as $order ) {
			// Pre-discount, ticket-line-items-only — get_total() bakes in
			// both the discount and any fee line items, which would make
			// "ticket revenue" overstate fees and double-subtract discounts
			// once "Discounts" and "Fees" are also deducted below. See
			// build()'s matching use of the order_payload() 'subtotal' key.
			$ticket_revenue += (float) $order->get_subtotal();
			$discounts      += (float) $order->get_discount_total();

			foreach ( $order->get_refunds() as $refund ) {
				$refunds += (float) $refund->get_amount();
			}

			$fees = $order->get_fees();

			if ( ! empty( $fees ) ) {
				$fee_seen = true;

				foreach ( $fees as $fee ) {
					$payment_fees += (float) $fee->get_total();
				}
			}
		}

		$expense_total = empty( $event_ids )
			? $this->expenses->total_all()
			: $this->expenses->total_for_events( $event_ids );

		return $this->assemble(
			0,
			$ticket_revenue,
			$discounts,
			$refunds,
			$payment_fees,
			$fee_seen,
			count( $orders ),
			array(),
			$expense_total
		);
	}

	/**
	 * Shape the Revenue → Adjustments → Fees → Expenses → Result P&L
	 * payload shared by both {@see build()} and {@see org_summary()}.
	 *
	 * @param int                                                   $event_id       Event ID (0 for an organisation-wide summary).
	 * @param float                                                 $ticket_revenue Gross ticket revenue.
	 * @param float                                                 $discounts      Coupon/discount total.
	 * @param float                                                 $refunds        Refund total.
	 * @param float                                                 $payment_fees   Recorded WooCommerce fee line items.
	 * @param bool                                                  $fee_seen       Whether any fee line item was found.
	 * @param int                                                   $orders         Paid order count.
	 * @param array<int, array{category: string, total: float, count: int}> $expenses_by_category Expense breakdown.
	 * @param float                                                 $total_expenses Total recorded expenses.
	 * @return array<string, mixed>
	 */
	private function assemble(
		int $event_id,
		float $ticket_revenue,
		float $discounts,
		float $refunds,
		float $payment_fees,
		bool $fee_seen,
		int $orders,
		array $expenses_by_category,
		float $total_expenses
	): array {
		$other_revenue      = 0.0;
		$total_revenue       = $ticket_revenue + $other_revenue;
		$other_adjustments   = 0.0;
		$total_adjustments   = $discounts + $refunds + $other_adjustments;

		$platform_fees = 0.0; // EventOS charges no platform/ticketing commission today — see class docblock.
		$other_fees    = 0.0;
		$total_fees    = $payment_fees + $platform_fees + $other_fees;

		$gross_revenue = $total_revenue;
		$net_revenue   = $gross_revenue - $total_adjustments;
		$net_profit    = $net_revenue - $total_fees - $total_expenses;
		$profit_margin = $net_revenue > 0 ? ( $net_profit / $net_revenue ) * 100 : null;

		return array(
			'event_id' => $event_id,
			'currency' => WooCommerce::currency(),
			'revenue'  => array(
				'ticket_revenue' => $ticket_revenue,
				'other_revenue'  => $other_revenue,
				'total_revenue'  => $total_revenue,
			),
			'adjustments' => array(
				'discounts'         => $discounts,
				'refunds'           => $refunds,
				'other_adjustments' => $other_adjustments,
				'total_adjustments' => $total_adjustments,
			),
			'fees' => array(
				'payment_fees'  => $payment_fees,
				// 'recorded' when WooCommerce fee line items were found on
				// at least one order, 'unknown' when none were — this is
				// never presented as "no fees were charged", only as "this
				// install has no fee data to show". See class docblock.
				'fee_status'    => $fee_seen ? 'recorded' : 'unknown',
				'platform_fees' => $platform_fees,
				'other_fees'    => $other_fees,
				'total_fees'    => $total_fees,
			),
			'expenses' => array(
				'by_category'    => $expenses_by_category,
				'total_expenses' => $total_expenses,
			),
			'result' => array(
				'gross_revenue'  => $gross_revenue,
				'net_revenue'    => $net_revenue,
				'total_fees'     => $total_fees,
				'total_expenses' => $total_expenses,
				'net_profit'     => $net_profit,
				'profit_margin'  => $profit_margin,
			),
			'orders' => $orders,
		);
	}
}

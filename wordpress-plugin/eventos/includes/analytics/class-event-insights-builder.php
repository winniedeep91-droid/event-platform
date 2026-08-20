<?php
/**
 * Per-event audience composition: new vs. returning customers.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Analytics;

use EventOS\Crm\Person_Repository;
use EventOS\Events\Ticket_Order_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one audience metric nothing in EventOS computes yet: among the people
 * who bought a ticket to *this* event, how many were buying from the brand
 * for the first time versus already had a history with it? Every other
 * audience figure (unique/top customers, average order value) already
 * exists on the event's Reports tab and is deliberately not repeated here —
 * see this module's docblock.
 *
 * Reuses the exact same "paid order" resolution every other financial
 * aggregation in EventOS uses ({@see Ticket_Order_Resolver}) and the
 * permanent Person record CRM already maintains
 * ({@see Person_Repository::find_by_primary_email()}), rather than
 * introducing a second customer-matching mechanism. Read-only: never writes
 * to the Person table, that stays {@see \EventOS\Crm\Person_Metrics_Service}'s
 * job.
 */
final class Event_Insights_Builder {

	/**
	 * Order resolver.
	 *
	 * @var Ticket_Order_Resolver
	 */
	private Ticket_Order_Resolver $orders;

	/**
	 * Person repository.
	 *
	 * @var Person_Repository
	 */
	private Person_Repository $persons;

	/**
	 * Constructor.
	 *
	 * @param Ticket_Order_Resolver $orders  Order resolver.
	 * @param Person_Repository     $persons Person repository.
	 */
	public function __construct( Ticket_Order_Resolver $orders, Person_Repository $persons ) {
		$this->orders  = $orders;
		$this->persons = $persons;
	}

	/**
	 * Audience composition for one event.
	 *
	 * @param int $event_id Event ID.
	 * @return array<string, mixed>
	 */
	public function build( int $event_id ): array {
		$order_rows = $this->orders->orders_for_event( $event_id, array( 'per_page' => 1000 ) )['items'];

		return $this->summarise( $event_id, $order_rows );
	}

	/**
	 * Fold a set of already-fetched order rows (one event's, or a slice of
	 * a batched multi-event fetch) into an audience summary. Split out from
	 * {@see build()} so {@see Event_Comparison_Builder} can reuse it against
	 * orders it already pulled in bulk, instead of this class re-querying
	 * WooCommerce once per compared event.
	 *
	 * @param int                                $event_id   Event ID the rows belong to.
	 * @param array<int, array<string, mixed>>    $order_rows Order rows shaped like {@see Ticket_Order_Resolver::order_payload()}.
	 * @return array<string, mixed>
	 */
	public function summarise( int $event_id, array $order_rows ): array {
		$seen      = array();
		$new       = 0;
		$returning = 0;
		$unmatched = 0;

		foreach ( $order_rows as $order ) {
			if ( ! in_array( $order['status'], Ticket_Order_Resolver::PAID_STATUSES, true ) ) {
				continue;
			}

			$email = strtolower( trim( (string) $order['customer_email'] ) );

			if ( '' === $email || isset( $seen[ $email ] ) ) {
				continue;
			}

			$seen[ $email ] = true;
			$person         = $this->persons->find_by_primary_email( $email );

			if ( null === $person ) {
				// A guest checkout the CRM resolver hasn't matched to a
				// Person yet — real, just not yet identity-resolved. Not
				// counted as new or returning so the two never silently
				// misrepresent a gap in identity resolution as growth.
				++$unmatched;
				continue;
			}

			if ( (int) $person['first_event_id'] === $event_id ) {
				++$new;
			} else {
				++$returning;
			}
		}

		return array(
			'unique_customers'       => count( $seen ),
			'new_customers'          => $new,
			'returning_customers'    => $returning,
			'unmatched_customers'    => $unmatched,
			'new_customer_definition' => __( 'This event was the first one this person ever bought a ticket for.', 'eventos' ),
		);
	}
}

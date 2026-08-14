<?php
/**
 * Cached lifetime-metric recomputation boundary for the permanent Person.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Crm;

use EventOS\Events\Event_Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `eventos_persons` carries cached lifetime-metric columns (Phase 1 schema)
 * so a CRM list view never has to aggregate tickets/orders live. This class
 * is the one place that computes them, so a later phase never has to
 * scatter that arithmetic across the UI or REST layer.
 *
 * Deliberately limited this phase to what `eventos_tickets`/
 * `eventos_checkins` can answer directly — total_tickets_purchased,
 * total_events_attended, first_event_id, last_event_id,
 * last_attendance_at — reached through whichever identities are currently
 * attached to the Person (which, unlike the single wc_customer_id + single
 * email that {@see \EventOS\Events\Guest_Repository::attendance_history()}
 * takes, can be several of each over a Person's lifetime).
 *
 * total_spend, avg_order_value/avg_ticket_value, vip_purchase_count,
 * complimentary_count, refund_count, cancellation_count and
 * last_purchase_at all need a live WooCommerce order lookup per ticket
 * (price, refund status, ticket-type VIP flag) that Phase 2 does not build.
 * This establishes the boundary those calculations plug into later — it
 * does not guess at them with a placeholder now.
 *
 * Not invoked anywhere in Phase 2 yet. Your Section 6 scope list is Person
 * identity/resolution/backfill only; recomputing metrics as a byproduct of
 * backfill isn't in it, so this is available for a later phase to call
 * (e.g. from {@see \EventOS\Job_Queue} once metric recompute is actually
 * wired to a trigger) rather than run automatically today.
 */
final class Person_Metrics_Service {

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
		global $wpdb;

		$person = $this->persons->find_by_id( $person_id );

		if ( null === $person ) {
			return null;
		}

		$wc_customer_ids = $this->identity_values( $person_id, 'wc_customer_id' );
		$emails          = $this->identity_values( $person_id, 'email' );

		if ( ! $wc_customer_ids && ! $emails ) {
			return $person;
		}

		$tickets = Event_Schema::tickets();
		$guests  = Event_Schema::guests();

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
				"SELECT t.event_id, t.checked_in, t.checked_in_at, t.created_at
				FROM {$tickets} t
				LEFT JOIN {$guests} g ON g.id = t.guest_id
				WHERE t.status != 'cancelled' AND (" . implode( ' OR ', $where ) . ')',
				$params
			),
			ARRAY_A
		);

		$attended_events    = array();
		$events_by_first_seen = array();
		$last_attendance_at  = null;

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
		}

		asort( $events_by_first_seen );
		$ordered_event_ids = array_keys( $events_by_first_seen );

		return $this->persons->update(
			$person_id,
			array(
				'total_tickets_purchased' => count( $rows ),
				'total_events_attended'   => count( $attended_events ),
				'first_event_id'          => $ordered_event_ids ? (int) $ordered_event_ids[0] : 0,
				'last_event_id'           => $ordered_event_ids ? (int) $ordered_event_ids[ count( $ordered_event_ids ) - 1 ] : 0,
				'last_attendance_at'      => $last_attendance_at,
			)
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

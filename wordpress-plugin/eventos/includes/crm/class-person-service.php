<?php
/**
 * CRM read-model service for the permanent Person.
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
 * The one place that assembles a Person into the shape the CRM REST API
 * (and eventually the CRM UI) actually consumes. Every section of
 * {@see self::get_profile()} is real, source-backed data — this class never
 * fabricates a value it cannot derive; it composes repositories that
 * already exist rather than duplicating what they know.
 *
 * Lifetime metrics are read from the cached columns on `eventos_persons`
 * (kept current by {@see Person_Metrics_Service::recompute()}), never
 * recomputed live on every profile request — the same "cache, don't
 * recalculate on read" boundary Phase 1/2 already established.
 */
final class Person_Service {

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
	 * Person tag repository.
	 *
	 * @var Person_Tag_Repository
	 */
	private Person_Tag_Repository $tags;

	/**
	 * Person note repository.
	 *
	 * @var Person_Note_Repository
	 */
	private Person_Note_Repository $notes;

	/**
	 * Person consent repository.
	 *
	 * @var Person_Consent_Repository
	 */
	private Person_Consent_Repository $consents;

	/**
	 * Segment repository.
	 *
	 * @var Segment_Repository
	 */
	private Segment_Repository $segments;

	/**
	 * Timeline service.
	 *
	 * @var Person_Timeline_Service
	 */
	private Person_Timeline_Service $timeline;

	/**
	 * Constructor.
	 *
	 * @param Person_Repository          $persons    Person repository.
	 * @param Person_Identity_Repository $identities Person identity repository.
	 * @param Person_Tag_Repository      $tags       Person tag repository.
	 * @param Person_Note_Repository     $notes      Person note repository.
	 * @param Person_Consent_Repository  $consents   Person consent repository.
	 * @param Segment_Repository         $segments   Segment repository.
	 * @param Person_Timeline_Service    $timeline   Timeline service.
	 */
	public function __construct(
		Person_Repository $persons,
		Person_Identity_Repository $identities,
		Person_Tag_Repository $tags,
		Person_Note_Repository $notes,
		Person_Consent_Repository $consents,
		Segment_Repository $segments,
		Person_Timeline_Service $timeline
	) {
		$this->persons    = $persons;
		$this->identities = $identities;
		$this->tags       = $tags;
		$this->notes      = $notes;
		$this->consents   = $consents;
		$this->segments   = $segments;
		$this->timeline   = $timeline;
	}

	/**
	 * The full CRM profile for one Person. Collection fields are always
	 * present as arrays (possibly empty), never omitted or null, so a
	 * consumer never has to guard against a missing key.
	 *
	 * @param int $person_id Person ID.
	 * @return array<string, mixed>|null Null if the Person does not exist.
	 */
	public function get_profile( int $person_id ): ?array {
		$person = $this->persons->find_by_id( $person_id );

		if ( null === $person ) {
			return null;
		}

		$wc_customer_ids = $this->identity_values( $person_id, 'wc_customer_id' );
		$emails          = $this->identity_values( $person_id, 'email' );
		$event_history   = $this->event_history( $wc_customer_ids, $emails );

		$touched_events  = count( $event_history );
		$attended_events = count( array_filter( $event_history, static fn( array $e ): bool => $e['attended'] ) );

		return array(
			'identity'              => array(
				'person_id'     => $person['id'],
				'display_name'  => $person['display_name'],
				'first_name'    => $person['first_name'],
				'last_name'     => $person['last_name'],
				'primary_email' => $person['primary_email'],
				'primary_phone' => $person['primary_phone'],
				'avatar_url'    => $person['avatar_url'],
				'location'      => $person['location'],
				'date_of_birth' => $person['date_of_birth'],
			),
			'relationship_metrics'  => array(
				'first_interaction'       => $person['created_at'],
				'first_event_id'          => $person['first_event_id'],
				'last_event_id'           => $person['last_event_id'],
				'total_events_attended'   => $person['total_events_attended'],
				'total_tickets_purchased' => $person['total_tickets_purchased'],
				'total_spend'             => $person['total_spend'],
				'avg_order_value'         => $person['avg_order_value'],
				'avg_ticket_value'        => $person['avg_ticket_value'],
				'vip_purchase_count'      => $person['vip_purchase_count'],
				'complimentary_count'     => $person['complimentary_count'],
				'refund_count'            => $person['refund_count'],
				'cancellation_count'      => $person['cancellation_count'],
				'last_purchase_at'        => $person['last_purchase_at'],
				'last_attendance_at'      => $person['last_attendance_at'],
				'attendance_rate'         => $touched_events > 0 ? round( $attended_events / $touched_events, 4 ) : null,
			),
			'identity_signals'      => $this->identities->for_person( $person_id ),
			'tags'                  => $this->tags->for_person( $person_id ),
			'notes'                 => $this->notes->for_person( $person_id ),
			'consents'              => $this->consents->for_person( $person_id ),
			'segments'              => $this->segments->for_person( $person_id ),
			'event_history'         => $event_history,
			'relationship_timeline' => $this->timeline->relationship_history( $person_id, $wc_customer_ids, $emails ),
		);
	}

	/**
	 * Search Persons by name, email, phone, WooCommerce customer ID or
	 * Person ID. Search is a display/lookup convenience, never an identity
	 * signal — matching by name here never merges or resolves anything, it
	 * only finds already-resolved Persons for staff to look at.
	 *
	 * Accepted keys: q (free text against name/email/phone), wc_customer_id,
	 * person_id, page, per_page.
	 *
	 * @param array<string, mixed> $args Search arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function search( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'q'              => '',
				'wc_customer_id' => 0,
				'person_id'      => 0,
				'page'           => 1,
				'per_page'       => 20,
			)
		);

		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );

		if ( (int) $args['person_id'] > 0 ) {
			$person = $this->persons->find_by_id( (int) $args['person_id'] );

			return $this->single_result( $person, $page, $per_page );
		}

		if ( (int) $args['wc_customer_id'] > 0 ) {
			$identity = $this->identities->find_by_type_value( 'wc_customer_id', (string) (int) $args['wc_customer_id'] );
			$person   = $identity ? $this->persons->find_by_id( (int) $identity['person_id'] ) : null;

			return $this->single_result( $person, $page, $per_page );
		}

		$table  = Person_Schema::persons();
		$q      = trim( (string) $args['q'] );
		$where  = '1=1';
		$params = array();

		// The table collation (utf8mb4_unicode_520_ci) is already
		// case-insensitive, so a plain LIKE needs no extra normalization.
		if ( '' !== $q ) {
			$like   = '%' . $wpdb->esc_like( $q ) . '%';
			$where  = '(display_name LIKE %s OR primary_email LIKE %s OR primary_phone LIKE %s)';
			$params = array( $like, $like, $like );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY display_name ASC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);

		$hydrate = function ( array $row ): array {
			return $this->summarize( $this->hydrate_person_row( $row ) );
		};

		return array(
			'items'    => array_map( $hydrate, (array) $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Persons associated with one event — one row per ticket/guest
	 * occurrence, each carrying the resolved global Person ID plus that
	 * ticket's event-specific context. A Person holding two tickets to the
	 * same event appears twice, once per ticket, by design: ticket-specific
	 * fields (status, check-in) cannot be collapsed into one row without
	 * losing information. Never creates an event-specific Person record.
	 *
	 * @param int $event_id Event ID.
	 * @param int $page     Page, 1 based.
	 * @param int $per_page Page size.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function list_for_event( int $event_id, int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$guests       = Event_Schema::guests();
		$tickets      = Event_Schema::tickets();
		$ticket_types = Event_Schema::ticket_types();
		$per_page     = max( 1, min( 100, $per_page ) );
		$page         = max( 1, $page );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$guests} WHERE event_id = %d", $event_id ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.id AS guest_id, g.name, g.email, g.phone, g.wc_customer_id,
					t.id AS ticket_id, t.ticket_number, t.status AS ticket_status, t.wc_order_id,
					t.checked_in, t.checked_in_at, tt.name AS ticket_type_name, tt.tier
				FROM {$guests} g
				INNER JOIN {$tickets} t ON t.id = g.ticket_id
				LEFT JOIN {$ticket_types} tt ON tt.id = t.ticket_type_id
				WHERE g.event_id = %d
				ORDER BY g.created_at DESC
				LIMIT %d OFFSET %d",
				$event_id,
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);

		$items = array();

		foreach ( (array) $rows as $row ) {
			$person = $this->resolve_for_row( (int) $row['wc_customer_id'], (string) $row['email'] );

			$items[] = array(
				'person_id'        => $person ? $person['id'] : null,
				'display_name'     => $person ? $person['display_name'] : (string) $row['name'],
				'guest_id'         => (int) $row['guest_id'],
				'ticket_id'        => (int) $row['ticket_id'],
				'ticket_number'    => (string) $row['ticket_number'],
				'ticket_type_name' => (string) $row['ticket_type_name'],
				'tier'             => (string) $row['tier'],
				'ticket_status'    => (string) $row['ticket_status'],
				'wc_order_id'      => (int) $row['wc_order_id'],
				'checked_in'       => (bool) $row['checked_in'],
				'checked_in_at'    => $row['checked_in_at'],
				// wc_customer_id/email on the guest row are, today, the same
				// signal as the order's purchaser (see Person_Timeline_Service's
				// docblock on Ticket_Fulfillment's current limitation) — not a
				// separately-fetched "true purchaser" value.
				'purchaser_context' => array(
					'wc_customer_id' => (int) $row['wc_customer_id'],
					'email'          => (string) $row['email'],
				),
			);
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Brand-wide relationship insights — aggregate counts across every
	 * known Person. Every figure is a direct SQL aggregate over
	 * `eventos_persons`; nothing here is estimated or inferred.
	 *
	 * "Lapsed customers" is deliberately absent as a number. No lapsed
	 * threshold (e.g. "no purchase in N days") has been defined or approved
	 * anywhere in this codebase — inventing one here would present an
	 * editorial choice as a calculated fact. The response says so
	 * explicitly rather than silently omitting the question or guessing a
	 * cutoff.
	 *
	 * @return array<string, mixed>
	 */
	public function insights(): array {
		global $wpdb;

		$table = Person_Schema::persons();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$totals = $wpdb->get_row(
			"SELECT
				COUNT(*) AS total_known,
				SUM(CASE WHEN total_tickets_purchased > 0 THEN 1 ELSE 0 END) AS purchased,
				SUM(CASE WHEN total_events_attended > 0 THEN 1 ELSE 0 END) AS attended,
				SUM(CASE WHEN total_tickets_purchased >= 2 THEN 1 ELSE 0 END) AS repeat_customers,
				SUM(total_spend) AS known_revenue
			FROM {$table}",
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$top = $wpdb->get_results(
			"SELECT id, display_name, primary_email, total_spend, total_events_attended
			FROM {$table}
			WHERE total_spend > 0
			ORDER BY total_spend DESC
			LIMIT 10",
			ARRAY_A
		);

		return array(
			'total_known_people'         => (int) ( $totals['total_known'] ?? 0 ),
			'purchased_count'            => (int) ( $totals['purchased'] ?? 0 ),
			'attended_count'             => (int) ( $totals['attended'] ?? 0 ),
			'repeat_customer_count'      => (int) ( $totals['repeat_customers'] ?? 0 ),
			'repeat_customer_definition' => __( '2 or more tickets purchased.', 'eventos' ),
			'known_revenue'              => (float) ( $totals['known_revenue'] ?? 0 ),
			'top_relationships'          => array_map(
				static function ( array $row ): array {
					return array(
						'person_id'             => (int) $row['id'],
						'display_name'          => (string) $row['display_name'],
						'primary_email'         => (string) $row['primary_email'],
						'total_spend'           => (float) $row['total_spend'],
						'total_events_attended' => (int) $row['total_events_attended'],
					);
				},
				(array) $top
			),
			'lapsed_customers'           => array(
				'available' => false,
				'reason'    => __( 'Not available yet — no lapsed threshold has been defined.', 'eventos' ),
			),
		);
	}

	/**
	 * Distinct events reachable through a Person's identities, each with
	 * that Person's ticket count and whether they attended.
	 *
	 * @param int[]    $wc_customer_ids WooCommerce customer IDs.
	 * @param string[] $emails          Normalized emails.
	 * @return array<int, array<string, mixed>>
	 */
	private function event_history( array $wc_customer_ids, array $emails ): array {
		global $wpdb;

		if ( ! $wc_customer_ids && ! $emails ) {
			return array();
		}

		$tickets = Event_Schema::tickets();
		$guests  = Event_Schema::guests();
		$events  = Event_Schema::events();

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
				"SELECT t.event_id, e.title AS event_title, e.starts_at, t.checked_in
				FROM {$tickets} t
				LEFT JOIN {$guests} g ON g.id = t.guest_id
				LEFT JOIN {$events} e ON e.id = t.event_id
				WHERE t.status != 'cancelled' AND (" . implode( ' OR ', $where ) . ')',
				$params
			),
			ARRAY_A
		);

		$by_event = array();

		foreach ( (array) $rows as $row ) {
			$event_id = (int) $row['event_id'];

			if ( ! isset( $by_event[ $event_id ] ) ) {
				$by_event[ $event_id ] = array(
					'event_id'    => $event_id,
					'event_title' => (string) $row['event_title'],
					'starts_at'   => $row['starts_at'],
					'tickets'     => 0,
					'attended'    => false,
				);
			}

			++$by_event[ $event_id ]['tickets'];

			if ( 1 === (int) $row['checked_in'] ) {
				$by_event[ $event_id ]['attended'] = true;
			}
		}

		$history = array_values( $by_event );

		usort(
			$history,
			static function ( array $a, array $b ): int {
				return strtotime( (string) $b['starts_at'] ) <=> strtotime( (string) $a['starts_at'] );
			}
		);

		return $history;
	}

	/**
	 * Resolve a guest row's signals to an existing Person without creating
	 * one — a read model must never mutate identity state as a side effect
	 * of a GET request. An unresolved guest (e.g. predating Phase 2's
	 * backfill) is reported as such rather than hidden.
	 *
	 * @param int    $wc_customer_id WooCommerce customer ID, 0 when absent.
	 * @param string $email          Raw email.
	 * @return array<string, mixed>|null
	 */
	private function resolve_for_row( int $wc_customer_id, string $email ): ?array {
		if ( $wc_customer_id > 0 ) {
			$identity = $this->identities->find_by_type_value( 'wc_customer_id', (string) $wc_customer_id );

			if ( null !== $identity ) {
				return $this->persons->find_by_id( (int) $identity['person_id'] );
			}
		}

		$normalized = Identity_Normalizer::normalize_email( $email );

		if ( '' === $normalized ) {
			return null;
		}

		$identity = $this->identities->find_by_type_value( 'email', $normalized );

		return $identity ? $this->persons->find_by_id( (int) $identity['person_id'] ) : null;
	}

	/**
	 * A single-Person search result, shaped like a paginated collection for
	 * a consistent response envelope.
	 *
	 * @param array<string, mixed>|null $person   Person row, or null if not found.
	 * @param int                        $page     Page.
	 * @param int                        $per_page Page size.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	private function single_result( ?array $person, int $page, int $per_page ): array {
		return array(
			'items'    => $person ? array( $this->summarize( $person ) ) : array(),
			'total'    => $person ? 1 : 0,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Compact Person shape for list/search results — the columns your brief
	 * names for the future CRM list view. "Status" is deliberately absent:
	 * no lifecycle/status field exists anywhere in the Phase 1 Person
	 * schema, so this does not invent one.
	 *
	 * @param array<string, mixed> $person Person row.
	 * @return array<string, mixed>
	 */
	private function summarize( array $person ): array {
		return array(
			'person_id'               => $person['id'],
			'display_name'            => $person['display_name'],
			'primary_email'           => $person['primary_email'],
			'primary_phone'           => $person['primary_phone'],
			'first_event_id'          => $person['first_event_id'],
			'last_event_id'           => $person['last_event_id'],
			'total_events_attended'   => $person['total_events_attended'],
			'total_tickets_purchased' => $person['total_tickets_purchased'],
			'total_spend'             => $person['total_spend'],
			'vip_purchase_count'      => $person['vip_purchase_count'],
			'last_attendance_at'      => $person['last_attendance_at'],
			'last_purchase_at'        => $person['last_purchase_at'],
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

	/**
	 * Person_Repository::hydrate() is private, so re-hydrate a raw
	 * `eventos_persons` row the same way here for the one place (search's
	 * direct SQL) that reads rows outside that repository's own methods.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed>
	 */
	private function hydrate_person_row( array $row ): array {
		return array(
			'id'                      => (int) $row['id'],
			'display_name'            => (string) $row['display_name'],
			'primary_email'           => (string) $row['primary_email'],
			'primary_phone'           => (string) $row['primary_phone'],
			'first_event_id'          => (int) $row['first_event_id'],
			'last_event_id'           => (int) $row['last_event_id'],
			'total_events_attended'   => (int) $row['total_events_attended'],
			'total_tickets_purchased' => (int) $row['total_tickets_purchased'],
			'total_spend'             => (float) $row['total_spend'],
			'vip_purchase_count'      => (int) $row['vip_purchase_count'],
			'last_attendance_at'      => $row['last_attendance_at'],
			'last_purchase_at'        => $row['last_purchase_at'],
		);
	}
}

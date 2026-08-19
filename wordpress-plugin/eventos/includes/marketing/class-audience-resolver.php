<?php
/**
 * Resolves a Marketing audience definition against real Audience CRM /
 * Events data.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Marketing;

use EventOS\Crm\Person_Identity_Repository;
use EventOS\Crm\Person_Repository;
use EventOS\Crm\Person_Schema;
use EventOS\Crm\Segment_Repository;
use EventOS\Events\Event_Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only. Never creates, merges or mutates a Person, an identity, a
 * ticket, an order or a guest — it only ever reads the tables those
 * existing services already own ({@see Person_Repository},
 * {@see Person_Identity_Repository}, {@see Segment_Repository}, and the
 * Events module's `tickets`/`guests` tables) and joins them the same way
 * {@see \EventOS\Crm\Person_Service::event_history()} already does for a
 * single Person, generalized here to a whole audience at once.
 *
 * There is deliberately no per-audience caching in this class — see
 * {@see \EventOS\Rest\Marketing_Controller} for why counts/previews are
 * computed on demand rather than stored.
 */
final class Audience_Resolver {

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
	 * Segment repository.
	 *
	 * @var Segment_Repository
	 */
	private Segment_Repository $segments;

	/**
	 * Constructor.
	 *
	 * @param Person_Repository          $persons    Person repository.
	 * @param Person_Identity_Repository $identities Person identity repository.
	 * @param Segment_Repository         $segments   Segment repository.
	 */
	public function __construct( Person_Repository $persons, Person_Identity_Repository $identities, Segment_Repository $segments ) {
		$this->persons    = $persons;
		$this->identities = $identities;
		$this->segments   = $segments;
	}

	/**
	 * Every Person ID currently matching an audience definition, deduplicated
	 * regardless of how many signals/paths matched them.
	 *
	 * @param array<string, mixed> $audience Audience definition (event_id, type, criteria).
	 * @return int[]
	 */
	public function resolve( array $audience ): array {
		$type     = (string) $audience['type'];
		$event_id = null !== ( $audience['event_id'] ?? null ) ? (int) $audience['event_id'] : 0;
		$criteria = (array) ( $audience['criteria'] ?? array() );

		switch ( $type ) {
			case 'all':
				return $this->all_person_ids();

			case 'event_purchasers':
				return $this->event_ticket_holders( $event_id, 0, null );

			case 'event_ticket_type':
				return $this->event_ticket_holders( $event_id, (int) ( $criteria['ticket_type_id'] ?? 0 ), null );

			case 'event_attendees':
				return $this->event_ticket_holders( $event_id, 0, true );

			case 'event_non_attendees':
				return $this->event_non_attendees( $event_id );

			case 'repeat_customers':
				return $this->persons_where( 'total_tickets_purchased >= 2' );

			case 'high_value':
				return $this->persons_where( $this->prepare( 'total_spend >= %f', (float) ( $criteria['min_spend'] ?? 0 ) ) );

			case 'recent_purchasers':
				return $this->persons_where(
					$this->prepare( 'last_purchase_at IS NOT NULL AND last_purchase_at >= %s', $this->days_ago( (int) ( $criteria['days'] ?? 0 ) ) )
				);

			case 'lapsed_customers':
				return $this->persons_where(
					$this->prepare( 'last_purchase_at IS NOT NULL AND last_purchase_at < %s', $this->days_ago( (int) ( $criteria['days'] ?? 0 ) ) )
				);

			case 'segment':
				return $this->segment_member_ids( (int) ( $criteria['segment_id'] ?? 0 ) );

			default:
				return array();
		}
	}

	/**
	 * How many Persons currently match — same resolution, just a count.
	 *
	 * @param array<string, mixed> $audience Audience definition.
	 * @return int
	 */
	public function count( array $audience ): int {
		return count( $this->resolve( $audience ) );
	}

	/**
	 * A small, human-readable sample of matching Persons for the UI to show
	 * "who this actually is" before the audience is used.
	 *
	 * @param array<string, mixed> $audience Audience definition.
	 * @param int                  $limit    Maximum rows to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function preview( array $audience, int $limit = 5 ): array {
		$ids = array_slice( $this->resolve( $audience ), 0, max( 1, $limit ) );

		return array_values(
			array_filter(
				array_map(
					function ( int $id ): ?array {
						$person = $this->persons->find_by_id( $id );

						if ( null === $person ) {
							return null;
						}

						return array(
							'person_id'     => $person['id'],
							'display_name'  => $person['display_name'],
							'primary_email' => $person['primary_email'],
						);
					},
					$ids
				)
			)
		);
	}

	/**
	 * Every known Person.
	 *
	 * @return int[]
	 */
	private function all_person_ids(): array {
		global $wpdb;

		$table = Person_Schema::persons();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( "SELECT id FROM {$table}" );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Persons resolved from `eventos_persons` cached metrics matching a raw
	 * (already-prepared) WHERE fragment — one row per Person already, so no
	 * dedup pass is needed beyond the query itself.
	 *
	 * @param string $where_sql Prepared WHERE fragment.
	 * @return int[]
	 */
	private function persons_where( string $where_sql ): array {
		global $wpdb;

		$table = Person_Schema::persons();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( "SELECT id FROM {$table} WHERE {$where_sql}" );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Persons holding at least one non-cancelled ticket for an event,
	 * optionally narrowed to one ticket type and/or to checked-in tickets
	 * only. Mirrors the join {@see \EventOS\Crm\Person_Service::event_history()}
	 * already uses for a single Person's view, generalized to every guest
	 * row for the event so it can resolve a whole audience in one query.
	 *
	 * @param int       $event_id       Event ID.
	 * @param int       $ticket_type_id Ticket type ID, 0 for any.
	 * @param bool|null $checked_in     true = attendees only, null = any status.
	 * @return int[]
	 */
	private function event_ticket_holders( int $event_id, int $ticket_type_id, ?bool $checked_in ): array {
		global $wpdb;

		if ( $event_id <= 0 ) {
			return array();
		}

		$tickets = Event_Schema::tickets();
		$guests  = Event_Schema::guests();

		$where  = array( "t.event_id = %d", "t.status != 'cancelled'" );
		$params = array( $event_id );

		if ( $ticket_type_id > 0 ) {
			$where[]  = 't.ticket_type_id = %d';
			$params[] = $ticket_type_id;
		}

		if ( null !== $checked_in ) {
			$where[]  = 't.checked_in = %d';
			$params[] = $checked_in ? 1 : 0;
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT t.wc_customer_id, g.email
				FROM {$tickets} t
				LEFT JOIN {$guests} g ON g.id = t.guest_id
				WHERE {$where_sql}",
				$params
			),
			ARRAY_A
		);

		return $this->resolve_signal_rows( (array) $rows );
	}

	/**
	 * Persons who hold at least one non-cancelled ticket for an event, but
	 * whose tickets for that event were never checked in — a genuine
	 * relative complement (holds a ticket AND is absent from the attendee
	 * set), not merely "not in the attendees list", so someone with two
	 * tickets who checked in on only one is correctly excluded.
	 *
	 * @param int $event_id Event ID.
	 * @return int[]
	 */
	private function event_non_attendees( int $event_id ): array {
		$purchasers = $this->event_ticket_holders( $event_id, 0, null );
		$attendees  = $this->event_ticket_holders( $event_id, 0, true );

		return array_values( array_diff( $purchasers, $attendees ) );
	}

	/**
	 * Resolve a set of (wc_customer_id, email) signal rows to deduplicated
	 * Person IDs, exactly the trust order {@see \EventOS\Crm\Person_Resolver}
	 * documents (wc_customer_id first, email fallback) — read-only here,
	 * never creating a Person for a signal that doesn't resolve.
	 *
	 * @param array<int, array<string, mixed>> $rows Raw signal rows.
	 * @return int[]
	 */
	private function resolve_signal_rows( array $rows ): array {
		$person_ids = array();

		foreach ( $rows as $row ) {
			$wc_customer_id = (int) ( $row['wc_customer_id'] ?? 0 );
			$email          = (string) ( $row['email'] ?? '' );
			$person_id      = null;

			if ( $wc_customer_id > 0 ) {
				$identity = $this->identities->find_by_type_value( 'wc_customer_id', (string) $wc_customer_id );
				$person_id = $identity ? (int) $identity['person_id'] : null;
			}

			if ( null === $person_id && '' !== $email ) {
				$identity = $this->identities->find_by_type_value( 'email', $email );
				$person_id = $identity ? (int) $identity['person_id'] : null;
			}

			if ( null !== $person_id ) {
				$person_ids[ $person_id ] = true;
			}
		}

		return array_map( 'intval', array_keys( $person_ids ) );
	}

	/**
	 * Every Person currently in a CRM segment, paging through
	 * {@see Segment_Repository::members()} (capped at 100 rows per call) so
	 * an audience referencing a large segment still resolves completely.
	 *
	 * @param int $segment_id Segment ID.
	 * @return int[]
	 */
	private function segment_member_ids( int $segment_id ): array {
		if ( $segment_id <= 0 ) {
			return array();
		}

		$ids  = array();
		$page = 1;

		do {
			$result = $this->segments->members( $segment_id, $page, 100 );

			foreach ( $result['items'] as $item ) {
				$ids[] = (int) $item['person_id'];
			}

			$fetched = count( $result['items'] );
			++$page;
		} while ( $fetched === 100 && count( $ids ) < (int) $result['total'] );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * A MySQL datetime string N days before now, for recency comparisons.
	 *
	 * @param int $days Days.
	 * @return string
	 */
	private function days_ago( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() - ( max( 0, $days ) * DAY_IN_SECONDS ) );
	}

	/**
	 * Thin wrapper around $wpdb->prepare() so persons_where() callers stay
	 * one-line — every caller passes a literal format string, never
	 * interpolated user input, so this is exactly as safe as calling
	 * $wpdb->prepare() directly at each call site.
	 *
	 * @param string $format Format string.
	 * @param mixed  ...$args Values.
	 * @return string
	 */
	private function prepare( string $format, ...$args ): string {
		global $wpdb;

		return $wpdb->prepare( $format, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}
}

<?php
/**
 * Data access for waitlist entries.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A waitlist entry represents a CRM Person's place in line for a sold-out
 * ticket type — never a ticket, never an order, never a Guest. Those are
 * only ever created through the existing WooCommerce/Ticket_Fulfillment
 * path once a promoted entry actually converts to a real purchase.
 *
 * State machine: waiting -> promoted -> converted
 *                        -> promoted -> expired (re-eligible for the next
 *                           person; a late purchase can still convert an
 *                           expired entry — see {@see mark_converted()})
 *                waiting -> cancelled
 *                promoted -> cancelled
 *
 * `active_slot` mirrors `status` (1 for waiting/promoted, NULL otherwise)
 * purely so the `active_entry` unique key can enforce "at most one active
 * entry per person/ticket-type" at the database level — every method here
 * that changes `status` must keep it in sync.
 */
final class Waitlist_Repository {

	/**
	 * Statuses that count as "currently on the list" for duplicate protection.
	 */
	public const ACTIVE_STATUSES = array( 'waiting', 'promoted' );

	/**
	 * Every valid status.
	 */
	public const STATUSES = array( 'waiting', 'promoted', 'converted', 'expired', 'cancelled' );

	/**
	 * Join the waitlist. Race-safe: a concurrent duplicate join hits the
	 * `active_entry` unique key and is resolved to the existing active row
	 * rather than erroring.
	 *
	 * Accepted keys: event_id, ticket_type_id, person_id, name, email, phone.
	 *
	 * @param array<string, mixed> $data Entry data.
	 * @return array<string, mixed> The entry — newly created, or the
	 *                              pre-existing active one on a race.
	 */
	public function join( array $data ): array {
		global $wpdb;

		$event_id       = (int) $data['event_id'];
		$ticket_type_id = (int) $data['ticket_type_id'];
		$person_id      = (int) $data['person_id'];
		$now            = current_time( 'mysql', true );

		$row = array(
			'event_id'       => $event_id,
			'ticket_type_id' => $ticket_type_id,
			'person_id'      => $person_id,
			'name'           => (string) ( $data['name'] ?? '' ),
			'email'          => (string) ( $data['email'] ?? '' ),
			'phone'          => (string) ( $data['phone'] ?? '' ),
			'status'         => 'waiting',
			'active_slot'    => 1,
			'position'       => $this->next_position( $ticket_type_id ),
			'metadata'       => wp_json_encode( (array) ( $data['metadata'] ?? array() ) ),
			'created_at'     => $now,
			'updated_at'     => $now,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			Event_Schema::waitlist_entries(),
			$row,
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( $inserted ) {
			return $this->find( (int) $wpdb->insert_id );
		}

		// Duplicate key on `active_entry` — another request won the race.
		// Not an error from the caller's point of view: they are on the
		// list either way.
		$existing = $this->find_active( $event_id, $ticket_type_id, $person_id );

		return $existing ?? $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Read a single entry.
	 *
	 * @param int $id Entry ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Event_Schema::waitlist_entries();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * The active (waiting or promoted) entry for a person/ticket-type, if any.
	 *
	 * @param int $event_id       Event ID.
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $person_id      CRM Person ID.
	 * @return array<string, mixed>|null
	 */
	public function find_active( int $event_id, int $ticket_type_id, int $person_id ): ?array {
		global $wpdb;

		$table = Event_Schema::waitlist_entries();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_id = %d AND ticket_type_id = %d AND person_id = %d AND active_slot = 1",
				$event_id,
				$ticket_type_id,
				$person_id
			),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * The next `$limit` waiting entries for a ticket type, in FIFO join order.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $limit          Maximum entries to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function next_waiting( int $ticket_type_id, int $limit ): array {
		global $wpdb;

		if ( $limit <= 0 ) {
			return array();
		}

		$table = Event_Schema::waitlist_entries();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE ticket_type_id = %d AND status = 'waiting' ORDER BY position ASC, id ASC LIMIT %d",
				$ticket_type_id,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Atomically claim one waiting entry for promotion. Two concurrent
	 * callers racing on the same row: only one `UPDATE` matches
	 * `status = 'waiting'`, so only one ever returns non-null — the same
	 * compare-and-swap pattern {@see \EventOS\Marketing\Campaign_Recipient_Repository::claim_for_sending()}
	 * uses for send jobs.
	 *
	 * @param int    $id         Entry ID.
	 * @param string $expires_at Promotion window end (MySQL datetime, UTC).
	 * @return int|null The entry ID on success, null if it was no longer waiting.
	 */
	public function claim_for_promotion( int $id, string $expires_at ): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . Event_Schema::waitlist_entries() . "
				SET status = 'promoted', promoted_at = %s, expires_at = %s, updated_at = %s
				WHERE id = %d AND status = 'waiting'",
				current_time( 'mysql', true ),
				$expires_at,
				current_time( 'mysql', true ),
				$id
			)
		);

		return $updated > 0 ? $id : null;
	}

	/**
	 * Atomically expire one promoted entry whose window has passed. Same
	 * compare-and-swap shape as {@see claim_for_promotion()}.
	 *
	 * @param int $id Entry ID.
	 * @return int|null The entry ID on success, null if it was no longer promoted.
	 */
	public function claim_for_expiry( int $id ): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . Event_Schema::waitlist_entries() . "
				SET status = 'expired', active_slot = NULL, updated_at = %s
				WHERE id = %d AND status = 'promoted'",
				current_time( 'mysql', true ),
				$id
			)
		);

		return $updated > 0 ? $id : null;
	}

	/**
	 * Entries promoted (and still active) whose window has passed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function due_for_expiry(): array {
		global $wpdb;

		$table = Event_Schema::waitlist_entries();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'promoted' AND expires_at <= %s",
				current_time( 'mysql', true )
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Number of entries currently promoted (their window hasn't expired) for
	 * a ticket type — the number of units already spoken for by a pending
	 * promotion, so processing never over-promotes beyond real availability.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int
	 */
	public function count_actively_promoted( int $ticket_type_id ): int {
		global $wpdb;

		$table = Event_Schema::waitlist_entries();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE ticket_type_id = %d AND status = 'promoted' AND expires_at > %s",
				$ticket_type_id,
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Mark an entry converted — a real ticket was issued for it. Allowed
	 * from `promoted` (the normal case) or `expired` (a late purchase using
	 * a link that outlived its window still legitimately counts; the
	 * person is not double-charged or denied a ticket they actually paid
	 * for merely because a background sweep ran first).
	 *
	 * @param int $id        Entry ID.
	 * @param int $ticket_id Ticket ID the entry converted to.
	 * @return bool Whether a row was updated.
	 */
	public function mark_converted( int $id, int $ticket_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . Event_Schema::waitlist_entries() . "
				SET status = 'converted', active_slot = NULL, converted_ticket_id = %d, updated_at = %s
				WHERE id = %d AND status IN ('promoted', 'expired')",
				$ticket_id,
				current_time( 'mysql', true ),
				$id
			)
		);

		return $updated > 0;
	}

	/**
	 * The active promoted entry for a ticket type matching an email, if any
	 * — used to correlate a real purchase back to the promotion that made
	 * it possible.
	 *
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param string $email          Purchaser email.
	 * @return array<string, mixed>|null
	 */
	public function find_promoted_by_email( int $ticket_type_id, string $email ): ?array {
		global $wpdb;

		if ( '' === $email ) {
			return null;
		}

		$table = Event_Schema::waitlist_entries();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE ticket_type_id = %d AND email = %s AND status IN ('promoted', 'expired') ORDER BY promoted_at DESC LIMIT 1",
				$ticket_type_id,
				$email
			),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Cancel a waiting or promoted entry.
	 *
	 * @param int $id Entry ID.
	 * @return bool Whether a row was updated.
	 */
	public function cancel( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . Event_Schema::waitlist_entries() . "
				SET status = 'cancelled', active_slot = NULL, updated_at = %s
				WHERE id = %d AND status IN ('waiting', 'promoted')",
				current_time( 'mysql', true ),
				$id
			)
		);

		return $updated > 0;
	}

	/**
	 * Live 1-based rank among still-waiting entries — computed at read time
	 * rather than by renumbering `position` on every departure, so leaving
	 * the list never rewrites other people's historical position values.
	 *
	 * @param array<string, mixed> $entry A waiting entry (from {@see hydrate()}).
	 * @return int|null Null when the entry is not currently waiting.
	 */
	public function live_rank( array $entry ): ?int {
		global $wpdb;

		if ( 'waiting' !== $entry['status'] ) {
			return null;
		}

		$table = Event_Schema::waitlist_entries();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ahead = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE ticket_type_id = %d AND status = 'waiting' AND (position < %d OR (position = %d AND id < %d))",
				$entry['ticket_type_id'],
				$entry['position'],
				$entry['position'],
				$entry['id']
			)
		);

		return $ahead + 1;
	}

	/**
	 * Paginated, filterable listing for the admin UI.
	 *
	 * Accepted args: ticket_type_id, status, search, page, per_page.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function query( int $event_id, array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'ticket_type_id' => 0,
				'status'         => '',
				'search'         => '',
				'page'           => 1,
				'per_page'       => 20,
			)
		);

		$where  = array( 'event_id = %d' );
		$params = array( $event_id );

		if ( (int) $args['ticket_type_id'] > 0 ) {
			$where[]  = 'ticket_type_id = %d';
			$params[] = (int) $args['ticket_type_id'];
		}

		if ( '' !== (string) $args['status'] && in_array( (string) $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}

		if ( '' !== (string) $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(name LIKE %s OR email LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$clause   = implode( ' AND ', $where );
		$table    = Event_Schema::waitlist_entries();
		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$clause}", $params ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$clause} ORDER BY status = 'waiting' DESC, position ASC, id ASC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);

		return array(
			'items'    => array_map( array( $this, 'hydrate' ), (array) $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Counts per status for a ticket type — the basis for a real
	 * `waitlist_count` instead of the previous hard-coded zero.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return array<string, int>
	 */
	public function counts_for_ticket_type( int $ticket_type_id ): array {
		global $wpdb;

		$table = Event_Schema::waitlist_entries();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS total FROM {$table} WHERE ticket_type_id = %d GROUP BY status", $ticket_type_id ),
			ARRAY_A
		);

		$counts = array_fill_keys( self::STATUSES, 0 );

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Every entry across every event for a CRM Person — the lookup
	 * {@see \EventOS\Crm\Person_Privacy} needs to export or erase a
	 * data-subject's waitlist history. `person_id` is the entry's real
	 * identity anchor (unlike the denormalized `name`/`email`/`phone`
	 * captured at join time), so this is an exact FK match, not a search.
	 *
	 * @param int $person_id CRM Person ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_by_person( int $person_id ): array {
		global $wpdb;

		if ( $person_id <= 0 ) {
			return array();
		}

		$table = Event_Schema::waitlist_entries();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE person_id = %d ORDER BY id DESC", $person_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Redact the identifying contact fields captured on a Person's waitlist
	 * entries, leaving the entry itself (status, position, ticket-type and
	 * conversion linkage) intact — used only by
	 * {@see \EventOS\Crm\Person_Privacy}'s eraser.
	 *
	 * @param int $person_id CRM Person ID.
	 * @return int Number of entries anonymized.
	 */
	public function anonymize_for_person( int $person_id ): int {
		global $wpdb;

		if ( $person_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . Event_Schema::waitlist_entries() . "
				SET name = %s, email = '', phone = '', updated_at = %s
				WHERE person_id = %d",
				__( 'Redacted', 'eventos' ),
				current_time( 'mysql', true ),
				$person_id
			)
		);
	}

	/**
	 * Next position value for a new entry — monotonically increasing per
	 * ticket type, never reused, never renumbered.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int
	 */
	private function next_position( int $ticket_type_id ): int {
		global $wpdb;

		$table = Event_Schema::waitlist_entries();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$max = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(position) FROM {$table} WHERE ticket_type_id = %d", $ticket_type_id ) );

		return null === $max ? 1 : (int) $max + 1;
	}

	/**
	 * Shape a raw row into the API contract.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		$entry = array(
			'id'                  => (int) $row['id'],
			'event_id'            => (int) $row['event_id'],
			'ticket_type_id'      => (int) $row['ticket_type_id'],
			'person_id'           => (int) $row['person_id'],
			'name'                => (string) $row['name'],
			'email'               => (string) $row['email'],
			'phone'               => (string) $row['phone'],
			'status'              => (string) $row['status'],
			'position'            => (int) $row['position'],
			'promoted_at'         => $row['promoted_at'],
			'expires_at'          => $row['expires_at'],
			'notified_at'         => $row['notified_at'],
			'converted_ticket_id' => (int) $row['converted_ticket_id'],
			'metadata'            => (array) json_decode( (string) $row['metadata'], true ),
			'created_at'          => (string) $row['created_at'],
			'updated_at'          => (string) $row['updated_at'],
		);

		$entry['queue_position'] = $this->live_rank( $entry );

		return $entry;
	}
}

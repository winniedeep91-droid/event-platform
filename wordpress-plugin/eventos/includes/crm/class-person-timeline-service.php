<?php
/**
 * Relationship timeline foundation for the permanent Person.
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
 * Writes to `eventos_person_timeline_events` — the residual store for
 * relationship events that have no other authoritative table (see the Final
 * Implementation Specification, Section 10). Most timeline entries
 * (purchases, attendance) are derived at read time from
 * `eventos_tickets`/WooCommerce rather than duplicated here; this table
 * exists only for entries with nowhere else to live.
 *
 * Deliberately distinct from {@see \EventOS\Activity_Log}: the activity log
 * is system/admin audit history, this is the customer's relationship with
 * the brand. A resolver conflict is logged to Activity_Log, never here.
 *
 * {@see self::record()}/{@see self::for_person()} only ever read/write
 * 'person_created' and 'identity_attached' — the two entries with no other
 * authoritative source, unchanged since Phase 2. {@see self::relationship_history()}
 * is Phase 3's addition: it merges those stored rows with entries derived
 * live from tables that now exist and are genuinely source-backed —
 * tickets/orders (purchase, ticket_issued, ticket_cancelled, attendance) and
 * the new Phase 3 relationship tables (tag_added, note_added,
 * consent_granted, consent_revoked). Campaign and reward entries are
 * deliberately absent: nothing in the schema tracks per-recipient campaign
 * activity yet, and no reward has ever been issued, so fabricating either
 * would be inventing history rather than reporting it.
 */
final class Person_Timeline_Service {

	/**
	 * Entry types this phase is allowed to write. Kept as an explicit
	 * allow-list so a future phase adding a new type does so deliberately,
	 * not by typo.
	 *
	 * @var string[]
	 */
	private const PHASE_2_TYPES = array( 'person_created', 'identity_attached' );

	/**
	 * Record a timeline entry.
	 *
	 * @param int                   $person_id   Person ID.
	 * @param string                $type        Entry type — see self::PHASE_2_TYPES.
	 * @param array<string, mixed>  $payload     Arbitrary entry-specific data.
	 * @param string|null           $occurred_at MySQL datetime; defaults to now.
	 * @return array<string, mixed>|null
	 */
	public function record( int $person_id, string $type, array $payload = array(), ?string $occurred_at = null ): ?array {
		global $wpdb;

		if ( $person_id <= 0 || ! in_array( $type, self::PHASE_2_TYPES, true ) ) {
			return null;
		}

		$now = current_time( 'mysql', true );

		$row = array(
			'person_id'    => $person_id,
			'type'         => $type,
			'payload_json' => wp_json_encode( $payload ),
			'occurred_at'  => $occurred_at ?? $now,
			'created_at'   => $now,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( Person_Schema::person_timeline_events(), $row, array( '%d', '%s', '%s', '%s', '%s' ) );

		return $wpdb->insert_id ? $this->find( (int) $wpdb->insert_id ) : null;
	}

	/**
	 * A Person's timeline entries, newest first.
	 *
	 * @param int $person_id Person ID.
	 * @param int $limit     Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_person( int $person_id, int $limit = 50 ): array {
		global $wpdb;

		$table = Person_Schema::person_timeline_events();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE person_id = %d ORDER BY occurred_at DESC, id DESC LIMIT %d",
				$person_id,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * The full relationship timeline: stored entries merged with entries
	 * derived live from tickets/orders/tags/notes/consent, newest first.
	 *
	 * `$wc_customer_ids`/`$emails` are the caller's responsibility to
	 * resolve (typically via Person_Identity_Repository::for_person()) —
	 * this class stays free of an identity-repository dependency so its
	 * zero-argument constructor keeps working for every existing Phase 2
	 * caller (Person_Resolver, Person_Backfill_Service).
	 *
	 * @param int      $person_id       Person ID.
	 * @param int[]    $wc_customer_ids WooCommerce customer IDs attached to the Person.
	 * @param string[] $emails          Normalized emails attached to the Person.
	 * @param int      $limit           Maximum entries returned.
	 * @return array<int, array<string, mixed>>
	 */
	public function relationship_history( int $person_id, array $wc_customer_ids, array $emails, int $limit = 50 ): array {
		$entries = array_merge(
			$this->for_person( $person_id, $limit ),
			$this->derived_ticket_entries( $wc_customer_ids, $emails ),
			$this->derived_relationship_entries( $person_id )
		);

		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return strtotime( (string) $b['occurred_at'] ) <=> strtotime( (string) $a['occurred_at'] );
			}
		);

		return array_slice( $entries, 0, max( 1, $limit ) );
	}

	/**
	 * Entries derived from tickets/orders: purchase (grouped by real
	 * WooCommerce order), ticket_issued (complimentary tickets with no
	 * order), ticket_cancelled (status = 'cancelled' — covers both refunds
	 * and plain cancellations; the two cannot be distinguished, see
	 * Person_Metrics_Service's docblock) and attendance (checked_in = 1).
	 *
	 * @param int[]    $wc_customer_ids WooCommerce customer IDs.
	 * @param string[] $emails          Normalized emails.
	 * @return array<int, array<string, mixed>>
	 */
	private function derived_ticket_entries( array $wc_customer_ids, array $emails ): array {
		global $wpdb;

		if ( ! $wc_customer_ids && ! $emails ) {
			return array();
		}

		$tickets      = Event_Schema::tickets();
		$guests       = Event_Schema::guests();
		$ticket_types = Event_Schema::ticket_types();
		$events       = Event_Schema::events();

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
				"SELECT t.id, t.event_id, t.wc_order_id, t.status, t.is_complimentary,
					t.checked_in, t.checked_in_at, t.created_at, t.updated_at,
					tt.name AS ticket_type_name, tt.tier, e.title AS event_title
				FROM {$tickets} t
				LEFT JOIN {$guests} g ON g.id = t.guest_id
				LEFT JOIN {$ticket_types} tt ON tt.id = t.ticket_type_id
				LEFT JOIN {$events} e ON e.id = t.event_id
				WHERE (" . implode( ' OR ', $where ) . ')',
				$params
			),
			ARRAY_A
		);

		$purchases = array();
		$entries   = array();

		foreach ( (array) $rows as $row ) {
			$event_context = array(
				'event_id'         => (int) $row['event_id'],
				'event_title'      => (string) $row['event_title'],
				'ticket_type_name' => (string) $row['ticket_type_name'],
				'tier'             => (string) $row['tier'],
			);

			$wc_order_id = (int) $row['wc_order_id'];

			if ( $wc_order_id > 0 ) {
				$key = $wc_order_id . ':' . (int) $row['event_id'] . ':' . (string) $row['ticket_type_name'];

				if ( ! isset( $purchases[ $key ] ) ) {
					$purchases[ $key ] = array_merge(
						$event_context,
						array(
							'wc_order_id' => $wc_order_id,
							'quantity'    => 0,
							'occurred_at' => (string) $row['created_at'],
						)
					);
				}

				++$purchases[ $key ]['quantity'];
			} elseif ( 1 === (int) $row['is_complimentary'] ) {
				$entries[] = array(
					'type'        => 'ticket_issued',
					'occurred_at' => (string) $row['created_at'],
					'payload'     => $event_context,
				);
			}

			if ( 'cancelled' === (string) $row['status'] ) {
				$entries[] = array(
					'type'        => 'ticket_cancelled',
					'occurred_at' => (string) $row['updated_at'],
					'payload'     => $event_context,
				);
			}

			if ( 1 === (int) $row['checked_in'] ) {
				$entries[] = array(
					'type'        => 'attendance',
					'occurred_at' => (string) $row['checked_in_at'],
					'payload'     => $event_context,
				);
			}
		}

		foreach ( $purchases as $purchase ) {
			$amount = null;

			if ( function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $purchase['wc_order_id'] );
				// The order total, not an exact per-ticket-type attribution —
				// see Person_Metrics_Service's docblock on avg_ticket_value.
				$amount = $order ? (float) $order->get_total() : null;
			}

			$entries[] = array(
				'type'        => 'purchase',
				'occurred_at' => $purchase['occurred_at'],
				'payload'     => array(
					'event_id'         => $purchase['event_id'],
					'event_title'      => $purchase['event_title'],
					'ticket_type_name' => $purchase['ticket_type_name'],
					'tier'             => $purchase['tier'],
					'quantity'         => $purchase['quantity'],
					'wc_order_id'      => $purchase['wc_order_id'],
					'order_total'      => $amount,
				),
			);
		}

		return $entries;
	}

	/**
	 * Entries derived from the Phase 3 relationship tables: tags, notes,
	 * consent. tag_removed is not derivable — Person_Tag_Repository::detach()
	 * hard-deletes the row, leaving no timestamped trace to read later.
	 *
	 * @param int $person_id Person ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function derived_relationship_entries( int $person_id ): array {
		$entries = array();

		foreach ( ( new Person_Tag_Repository() )->for_person( $person_id ) as $tag ) {
			$entries[] = array(
				'type'        => 'tag_added',
				'occurred_at' => $tag['created_at'],
				'payload'     => array( 'tag' => $tag['tag'] ),
			);
		}

		foreach ( ( new Person_Note_Repository() )->for_person( $person_id ) as $note ) {
			// Deliberately no note body here — the relationship timeline may
			// eventually be surfaced more broadly than the notes screen
			// itself, and notes are internal-only (see that repository's
			// class docblock).
			$entries[] = array(
				'type'        => 'note_added',
				'occurred_at' => $note['created_at'],
				'payload'     => array( 'author_name' => $note['author_name'] ),
			);
		}

		foreach ( ( new Person_Consent_Repository() )->for_person( $person_id ) as $consent ) {
			if ( $consent['granted_at'] ) {
				$entries[] = array(
					'type'        => 'consent_granted',
					'occurred_at' => $consent['granted_at'],
					'payload'     => array( 'channel' => $consent['channel'] ),
				);
			}

			if ( $consent['revoked_at'] ) {
				$entries[] = array(
					'type'        => 'consent_revoked',
					'occurred_at' => $consent['revoked_at'],
					'payload'     => array( 'channel' => $consent['channel'] ),
				);
			}
		}

		return $entries;
	}

	/**
	 * Read a single entry.
	 *
	 * @param int $id Entry ID.
	 * @return array<string, mixed>|null
	 */
	private function find( int $id ): ?array {
		global $wpdb;

		$table = Person_Schema::person_timeline_events();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Shape a raw row for internal consumers.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'person_id'   => (int) $row['person_id'],
			'type'        => (string) $row['type'],
			'payload'     => (array) ( json_decode( (string) $row['payload_json'], true ) ?: array() ),
			'occurred_at' => (string) $row['occurred_at'],
			'created_at'  => (string) $row['created_at'],
		);
	}
}

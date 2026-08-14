<?php
/**
 * Relationship timeline foundation for the permanent Person.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Crm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes to `eventos_person_timeline_events` — the residual store for
 * relationship events that have no other authoritative table (see the Final
 * Implementation Specification, Section 10). Most future timeline entries
 * (purchases, attendance, refunds) are meant to be derived at read time from
 * `eventos_tickets`/`eventos_checkins` rather than duplicated here; this
 * table exists only for entries with nowhere else to live.
 *
 * Deliberately distinct from {@see \EventOS\Activity_Log}: the activity log
 * is system/admin audit history, this is the customer's relationship with
 * the brand. A resolver conflict is logged to Activity_Log, never here.
 *
 * Phase 2 only ever records 'person_created' and 'identity_attached' —
 * the two entries directly produced by identity resolution itself. Every
 * other entry type your brief names (purchase, ticket, attendance, campaign,
 * reward, tag, note, profile change) is left for the phase that actually
 * owns that data, so this class isn't recording events it can't yet verify.
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

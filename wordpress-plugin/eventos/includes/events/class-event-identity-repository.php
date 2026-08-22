<?php
/**
 * Data access for external identity signals resolving to an Event.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An identity is one external signal (a WooCommerce product-group key, a
 * Quicket event ID, ...) known to belong to exactly one Event. The
 * `UNIQUE KEY type_value` on `eventos_event_identities` is the final
 * backstop this repository relies on — see {@see self::attach_identity()}.
 *
 * Mirrors `EventOS\Crm\Person_Identity_Repository` exactly, scoped to
 * Events instead of People.
 */
final class Event_Identity_Repository {

	/**
	 * Look up the identity row for one signal, if any.
	 *
	 * @param string $type  Identity type, e.g. 'wc_event_group' or 'quicket_event_id'.
	 * @param string $value Already-normalized identity value.
	 * @return array<string, mixed>|null
	 */
	public function find_by_type_value( string $type, string $value ): ?array {
		global $wpdb;

		if ( '' === $type || '' === $value ) {
			return null;
		}

		$table = Event_Schema::event_identities();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s AND value = %s", $type, $value ),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Every identity signal currently attached to an Event.
	 *
	 * @param int $event_id Event ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_event( int $event_id ): array {
		global $wpdb;

		$table = Event_Schema::event_identities();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY id ASC", $event_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Attach an identity signal to an Event — idempotent and conflict-safe.
	 *
	 * Three possible outcomes:
	 * - 'attached': the signal was unclaimed and now belongs to $event_id.
	 * - 'already_attached': the signal already belonged to $event_id — a
	 *   safe no-op, which is what makes re-running a sync/import safe.
	 * - 'conflict': the signal already belongs to a *different* Event. It
	 *   is never reassigned — the caller decides how to handle it rather
	 *   than this repository silently merging anything.
	 *
	 * Concurrency: two requests can both pass the initial lookup and both
	 * attempt the INSERT. The `UNIQUE KEY type_value` lets only one
	 * succeed; the loser recovers by re-reading rather than treating the
	 * failed insert as an error, so a race never produces a duplicate
	 * identity or Event.
	 *
	 * @param int    $event_id Event to attach the identity to.
	 * @param string $type     Identity type, e.g. 'wc_event_group' or 'quicket_event_id'.
	 * @param string $value    Already-normalized identity value.
	 * @return array{status: string, identity: array<string, mixed>|null, owner_event_id?: int}
	 */
	public function attach_identity( int $event_id, string $type, string $value ): array {
		global $wpdb;

		$type  = sanitize_key( $type );
		$value = trim( $value );

		if ( '' === $type || '' === $value || $event_id <= 0 ) {
			return array(
				'status'   => 'invalid',
				'identity' => null,
			);
		}

		$existing = $this->find_by_type_value( $type, $value );

		if ( null !== $existing ) {
			return $this->outcome_for( $event_id, $existing );
		}

		$table = Event_Schema::event_identities();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$table,
			array(
				'event_id'   => $event_id,
				'type'       => $type,
				'value'      => $value,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( $inserted ) {
			return array(
				'status'   => 'attached',
				'identity' => $this->find_by_type_value( $type, $value ),
			);
		}

		// Lost a uniqueness race: another request inserted this exact
		// (type, value) between our lookup and our insert. Recover by
		// re-reading instead of surfacing a database error.
		$existing = $this->find_by_type_value( $type, $value );

		if ( null === $existing ) {
			return array(
				'status'   => 'failed',
				'identity' => null,
			);
		}

		return $this->outcome_for( $event_id, $existing );
	}

	/**
	 * Shape the already-attached vs. conflict outcome for an existing row.
	 *
	 * @param int                  $event_id Event the caller wanted to attach to.
	 * @param array<string, mixed> $existing Existing identity row.
	 * @return array{status: string, identity: array<string, mixed>, owner_event_id?: int}
	 */
	private function outcome_for( int $event_id, array $existing ): array {
		if ( (int) $existing['event_id'] === $event_id ) {
			return array(
				'status'   => 'already_attached',
				'identity' => $existing,
			);
		}

		return array(
			'status'         => 'conflict',
			'identity'       => $existing,
			'owner_event_id' => (int) $existing['event_id'],
		);
	}

	/**
	 * Shape a raw row for internal consumers.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		return array(
			'id'         => (int) $row['id'],
			'event_id'   => (int) $row['event_id'],
			'type'       => (string) $row['type'],
			'value'      => (string) $row['value'],
			'created_at' => (string) $row['created_at'],
		);
	}
}

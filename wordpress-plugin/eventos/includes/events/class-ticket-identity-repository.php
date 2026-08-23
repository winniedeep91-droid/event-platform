<?php
/**
 * Data access for external identity signals resolving to a Ticket.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An identity is one external signal (e.g. a Quicket ticket ID) known to
 * belong to exactly one Ticket. The `UNIQUE KEY type_value` on
 * `eventos_ticket_identities` is the final backstop this repository relies
 * on — see {@see self::attach_identity()}.
 *
 * WooCommerce-sourced tickets keep using `tickets.wc_order_item_id`
 * directly (via `Ticket_Repository::exists_for_order_item()`) and never
 * touch this table — it exists only for sources with no such native
 * column. Mirrors `Event_Identity_Repository`/`Ticket_Type_Identity_Repository`
 * exactly, scoped to Tickets.
 */
final class Ticket_Identity_Repository {

	/**
	 * Look up the identity row for one signal, if any.
	 *
	 * @param string $type  Identity type, e.g. 'quicket_ticket_id'.
	 * @param string $value Already-normalized identity value.
	 * @return array<string, mixed>|null
	 */
	public function find_by_type_value( string $type, string $value ): ?array {
		global $wpdb;

		if ( '' === $type || '' === $value ) {
			return null;
		}

		$table = Event_Schema::ticket_identities();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s AND value = %s", $type, $value ),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Every identity signal currently attached to a Ticket.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_ticket( int $ticket_id ): array {
		global $wpdb;

		$table = Event_Schema::ticket_identities();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE ticket_id = %d ORDER BY id ASC", $ticket_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Attach an identity signal to a Ticket — idempotent and conflict-safe.
	 *
	 * Three possible outcomes:
	 * - 'attached': the signal was unclaimed and now belongs to $ticket_id.
	 * - 'already_attached': the signal already belonged to $ticket_id — a
	 *   safe no-op, which is what makes re-running an import safe.
	 * - 'conflict': the signal already belongs to a *different* Ticket. It
	 *   is never reassigned.
	 *
	 * Concurrency: two requests can both pass the initial lookup and both
	 * attempt the INSERT. The `UNIQUE KEY type_value` lets only one
	 * succeed; the loser recovers by re-reading rather than treating the
	 * failed insert as an error, so a race never produces a duplicate
	 * identity or Ticket.
	 *
	 * @param int    $ticket_id Ticket to attach the identity to.
	 * @param string $type      Identity type, e.g. 'quicket_ticket_id'.
	 * @param string $value     Already-normalized identity value.
	 * @return array{status: string, identity: array<string, mixed>|null, owner_ticket_id?: int}
	 */
	public function attach_identity( int $ticket_id, string $type, string $value ): array {
		global $wpdb;

		$type  = sanitize_key( $type );
		$value = trim( $value );

		if ( '' === $type || '' === $value || $ticket_id <= 0 ) {
			return array(
				'status'   => 'invalid',
				'identity' => null,
			);
		}

		$existing = $this->find_by_type_value( $type, $value );

		if ( null !== $existing ) {
			return $this->outcome_for( $ticket_id, $existing );
		}

		$table = Event_Schema::ticket_identities();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$table,
			array(
				'ticket_id'  => $ticket_id,
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

		return $this->outcome_for( $ticket_id, $existing );
	}

	/**
	 * Shape the already-attached vs. conflict outcome for an existing row.
	 *
	 * @param int                  $ticket_id Ticket the caller wanted to attach to.
	 * @param array<string, mixed> $existing  Existing identity row.
	 * @return array{status: string, identity: array<string, mixed>, owner_ticket_id?: int}
	 */
	private function outcome_for( int $ticket_id, array $existing ): array {
		if ( (int) $existing['ticket_id'] === $ticket_id ) {
			return array(
				'status'   => 'already_attached',
				'identity' => $existing,
			);
		}

		return array(
			'status'          => 'conflict',
			'identity'        => $existing,
			'owner_ticket_id' => (int) $existing['ticket_id'],
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
			'ticket_id'  => (int) $row['ticket_id'],
			'type'       => (string) $row['type'],
			'value'      => (string) $row['value'],
			'created_at' => (string) $row['created_at'],
		);
	}
}

<?php
/**
 * Data access for identity signals resolving to a Person.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Crm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An identity is one signal (a WooCommerce customer ID, a normalized email)
 * known to belong to exactly one Person. The `UNIQUE KEY type_value` on
 * `eventos_person_identities` (Phase 1 schema) is the final backstop this
 * repository relies on — see {@see self::attach_identity()}.
 */
final class Person_Identity_Repository {

	/**
	 * Look up the identity row for one signal, if any.
	 *
	 * @param string $type  Identity type, e.g. 'wc_customer_id' or 'email'.
	 * @param string $value Already-normalized identity value.
	 * @return array<string, mixed>|null
	 */
	public function find_by_type_value( string $type, string $value ): ?array {
		global $wpdb;

		if ( '' === $type || '' === $value ) {
			return null;
		}

		$table = Person_Schema::person_identities();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s AND value = %s", $type, $value ),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Every identity signal currently attached to a Person.
	 *
	 * @param int $person_id Person ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_person( int $person_id ): array {
		global $wpdb;

		$table = Person_Schema::person_identities();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE person_id = %d ORDER BY id ASC", $person_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Delete identifying identity signals for a Person — the CRM half of
	 * {@see \EventOS\Crm\Person_Privacy}'s privacy eraser.
	 *
	 * Deliberately scoped to `email`/`phone` by default: a `wc_customer_id`
	 * identity is just an integer foreign key into WooCommerce's own user
	 * table, not personal data in its own right, and removing it would
	 * break the de-duplication this whole table exists for without
	 * actually erasing anything a data-subject request is about — see
	 * {@see Person_Privacy} for the full reasoning.
	 *
	 * @param int      $person_id Person ID.
	 * @param string[] $types     Identity types to erase.
	 * @return void
	 */
	public function erase_for_person( int $person_id, array $types = array( 'email', 'phone' ) ): void {
		global $wpdb;

		$types = array_values( array_filter( array_map( 'sanitize_key', $types ) ) );

		if ( empty( $types ) ) {
			return;
		}

		$table        = Person_Schema::person_identities();
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE person_id = %d AND type IN ({$placeholders})",
				array_merge( array( $person_id ), $types )
			)
		);
	}

	/**
	 * Attach an identity signal to a Person — idempotent and conflict-safe.
	 *
	 * Three possible outcomes:
	 * - 'attached': the signal was unclaimed and now belongs to $person_id.
	 * - 'already_attached': the signal already belonged to $person_id — a
	 *   safe no-op, which is what makes running the resolver twice safe.
	 * - 'conflict': the signal already belongs to a *different* Person. It
	 *   is never reassigned — the caller (Person_Resolver) surfaces this
	 *   rather than silently merging; actual merge handling is a later
	 *   phase's dedicated tool.
	 *
	 * Concurrency: two requests can both pass the initial lookup and both
	 * attempt the INSERT. The `UNIQUE KEY type_value` lets only one
	 * succeed; the loser recovers by re-reading rather than treating the
	 * failed insert as an error, so a race never produces a duplicate
	 * identity or Person.
	 *
	 * @param int    $person_id  Person to attach the identity to.
	 * @param string $type       Identity type, e.g. 'wc_customer_id' or 'email'.
	 * @param string $value      Already-normalized identity value.
	 * @param string $confidence 'high' or 'low' — phone-style signals that are
	 *                            never used for automatic matching should be
	 *                            recorded as 'low' if attached at all.
	 * @return array{status: string, identity: array<string, mixed>|null, owner_person_id?: int}
	 */
	public function attach_identity( int $person_id, string $type, string $value, string $confidence = 'high' ): array {
		global $wpdb;

		$type  = sanitize_key( $type );
		$value = trim( $value );

		if ( '' === $type || '' === $value || $person_id <= 0 ) {
			return array(
				'status'   => 'invalid',
				'identity' => null,
			);
		}

		$existing = $this->find_by_type_value( $type, $value );

		if ( null !== $existing ) {
			return $this->outcome_for( $person_id, $existing );
		}

		$table = Person_Schema::person_identities();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$table,
			array(
				'person_id'  => $person_id,
				'type'       => $type,
				'value'      => $value,
				'confidence' => $confidence,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
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

		return $this->outcome_for( $person_id, $existing );
	}

	/**
	 * Shape the already-attached vs. conflict outcome for an existing row.
	 *
	 * @param int                   $person_id Person the caller wanted to attach to.
	 * @param array<string, mixed>  $existing  Existing identity row.
	 * @return array{status: string, identity: array<string, mixed>, owner_person_id?: int}
	 */
	private function outcome_for( int $person_id, array $existing ): array {
		if ( (int) $existing['person_id'] === $person_id ) {
			return array(
				'status'   => 'already_attached',
				'identity' => $existing,
			);
		}

		return array(
			'status'          => 'conflict',
			'identity'        => $existing,
			'owner_person_id' => (int) $existing['person_id'],
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
			'person_id'  => (int) $row['person_id'],
			'type'       => (string) $row['type'],
			'value'      => (string) $row['value'],
			'confidence' => (string) $row['confidence'],
			'created_at' => (string) $row['created_at'],
		);
	}
}

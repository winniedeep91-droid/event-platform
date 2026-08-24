<?php
/**
 * Data access for per-channel marketing consent history.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Crm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `channel` is a plain string, deliberately not an enum at the database
 * layer — email/SMS/WhatsApp are the channels this brand happens to use
 * today, not a fixed list the schema assumes. A Person having a
 * `primary_email` never implies consent; consent must be recorded here
 * explicitly.
 *
 * Consent is history, not state: granting never overwrites a prior row.
 * Each grant/revoke is its own timestamped record, so a channel's full
 * consent timeline — granted, revoked, re-granted — stays reconstructable.
 */
final class Person_Consent_Repository {

	/**
	 * Every consent record for a Person, newest first.
	 *
	 * @param int $person_id Person ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_person( int $person_id ): array {
		global $wpdb;

		$table = Person_Schema::person_consents();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE person_id = %d ORDER BY created_at DESC, id DESC", $person_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Whether a Person currently has an active (granted, not revoked) grant
	 * for a channel — the read-only check consumers outside this class need
	 * (e.g. Marketing deciding whether a Person may receive a campaign
	 * email); {@see active_grant()} already computed this internally for
	 * grant()/revoke(), it just was not exposed until now.
	 *
	 * @param int    $person_id Person ID.
	 * @param string $channel   Channel, e.g. 'marketing_email'.
	 * @return bool
	 */
	public function has_active( int $person_id, string $channel ): bool {
		return null !== $this->active_grant( $person_id, sanitize_key( $channel ) );
	}

	/**
	 * Whether a Person was ever granted a channel at all (granted then
	 * possibly revoked) — distinguishes "never opted in" from "opted in,
	 * then unsubscribed" for callers that need to report the difference.
	 *
	 * @param int    $person_id Person ID.
	 * @param string $channel   Channel.
	 * @return bool
	 */
	public function was_ever_granted( int $person_id, string $channel ): bool {
		global $wpdb;

		$channel = sanitize_key( $channel );

		if ( '' === $channel || $person_id <= 0 ) {
			return false;
		}

		$table = Person_Schema::person_consents();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE person_id = %d AND channel = %s",
				$person_id,
				$channel
			)
		);

		return $count > 0;
	}

	/**
	 * Grant consent for a channel.
	 *
	 * Idempotent against an already-active grant: if this channel is
	 * currently granted (no revocation since), returns that record
	 * unchanged rather than creating a duplicate active row. If the
	 * channel was never granted, or was previously revoked, this inserts a
	 * new history row — the prior record (if any) is left exactly as it
	 * was, preserving the timeline.
	 *
	 * @param int         $person_id  Person ID.
	 * @param string      $channel    Channel, e.g. 'email', 'sms', 'whatsapp'.
	 * @param string      $source     Free-form provenance, e.g. 'checkout_optin', 'portal'.
	 * @param string|null $granted_at When consent was actually given, MySQL UTC — defaults to now.
	 *                                Lets a historical import (e.g. a fan list carrying its own
	 *                                subscription date) record the real date rather than the
	 *                                import's own run date, the same distinction {@see created_at}
	 *                                (when this row was written) already keeps from {@see granted_at}.
	 * @return array<string, mixed>|null
	 */
	public function grant( int $person_id, string $channel, string $source = '', ?string $granted_at = null ): ?array {
		global $wpdb;

		$channel = sanitize_key( $channel );

		if ( '' === $channel || $person_id <= 0 ) {
			return null;
		}

		$active = $this->active_grant( $person_id, $channel );

		if ( null !== $active ) {
			return $active;
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Person_Schema::person_consents(),
			array(
				'person_id'  => $person_id,
				'channel'    => $channel,
				'granted_at' => $granted_at ?? $now,
				'source'     => sanitize_text_field( $source ),
				'revoked_at' => null,
				'created_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $wpdb->insert_id ? $this->find( (int) $wpdb->insert_id ) : null;
	}

	/**
	 * Revoke the currently active grant for a channel, if any.
	 *
	 * @param int    $person_id Person ID.
	 * @param string $channel   Channel.
	 * @return void
	 */
	public function revoke( int $person_id, string $channel ): void {
		global $wpdb;

		$active = $this->active_grant( $person_id, sanitize_key( $channel ) );

		if ( null === $active ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Person_Schema::person_consents(),
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			array( 'id' => $active['id'] ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * The currently active (granted, not yet revoked) record for a channel.
	 *
	 * @param int    $person_id Person ID.
	 * @param string $channel   Channel.
	 * @return array<string, mixed>|null
	 */
	private function active_grant( int $person_id, string $channel ): ?array {
		global $wpdb;

		if ( '' === $channel ) {
			return null;
		}

		$table = Person_Schema::person_consents();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE person_id = %d AND channel = %s AND revoked_at IS NULL ORDER BY id DESC LIMIT 1",
				$person_id,
				$channel
			),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Read a single consent record.
	 *
	 * @param int $id Record ID.
	 * @return array<string, mixed>|null
	 */
	private function find( int $id ): ?array {
		global $wpdb;

		$table = Person_Schema::person_consents();

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
			'id'         => (int) $row['id'],
			'person_id'  => (int) $row['person_id'],
			'channel'    => (string) $row['channel'],
			'granted_at' => $row['granted_at'],
			'source'     => (string) $row['source'],
			'revoked_at' => $row['revoked_at'],
			'active'     => null === $row['revoked_at'],
			'created_at' => (string) $row['created_at'],
		);
	}
}

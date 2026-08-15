<?php
/**
 * Data access for global, brand-level Person tags.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Crm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tags are free-form, reusable CRM metadata attached to a permanent Person —
 * "VIP", "Industry", "Press", whatever staff choose. Never a hard-coded
 * enum: the `tag` column is plain data, exactly like the schema intends.
 *
 * Deliberately separate from `eventos_guests.tags` (event-operational,
 * per-ticket) — see that repository's own docblock. This table is the
 * brand-level counterpart.
 */
final class Person_Tag_Repository {

	/**
	 * Every tag attached to a Person, alphabetical.
	 *
	 * @param int $person_id Person ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_person( int $person_id ): array {
		global $wpdb;

		$table = Person_Schema::person_tags();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE person_id = %d ORDER BY tag ASC", $person_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Attach a tag to a Person. Idempotent — re-attaching an already
	 * present tag is a safe no-op, relying on the Phase 1
	 * `UNIQUE KEY person_tag (person_id, tag)` as the final backstop.
	 *
	 * @param int    $person_id Person ID.
	 * @param string $tag       Tag text.
	 * @return array<string, mixed>|null The tag row, or null for an empty tag.
	 */
	public function attach( int $person_id, string $tag ): ?array {
		global $wpdb;

		$tag = trim( sanitize_text_field( $tag ) );

		if ( '' === $tag || $person_id <= 0 ) {
			return null;
		}

		$existing = $this->find( $person_id, $tag );

		if ( null !== $existing ) {
			return $existing;
		}

		$table = Person_Schema::person_tags();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'person_id'  => $person_id,
				'tag'        => $tag,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s' )
		);

		// Re-read rather than trust the insert result: a concurrent request
		// may have inserted the same (person_id, tag) between our lookup and
		// our insert, and re-reading resolves that race the same way
		// Person_Identity_Repository::attach_identity() does.
		return $this->find( $person_id, $tag );
	}

	/**
	 * Remove a tag from a Person.
	 *
	 * @param int    $person_id Person ID.
	 * @param string $tag       Tag text.
	 * @return void
	 */
	public function detach( int $person_id, string $tag ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			Person_Schema::person_tags(),
			array(
				'person_id' => $person_id,
				'tag'       => trim( $tag ),
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Look up one Person's tag row.
	 *
	 * @param int    $person_id Person ID.
	 * @param string $tag       Tag text.
	 * @return array<string, mixed>|null
	 */
	private function find( int $person_id, string $tag ): ?array {
		global $wpdb;

		$table = Person_Schema::person_tags();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE person_id = %d AND tag = %s", $person_id, $tag ),
			ARRAY_A
		);

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
			'tag'        => (string) $row['tag'],
			'created_at' => (string) $row['created_at'],
		);
	}
}

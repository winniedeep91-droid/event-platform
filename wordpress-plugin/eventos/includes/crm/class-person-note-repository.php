<?php
/**
 * Data access for internal staff notes on a permanent Person.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Crm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Person-level notes are internal staff records — never exposed through the
 * future customer-facing portal (Person_Controller/Person_Service must never
 * join this table into anything but an admin-authenticated response).
 * Deliberately separate from `eventos_guests.notes` (event-operational,
 * per-ticket) — see that repository's own docblock.
 */
final class Person_Note_Repository {

	/**
	 * Every note on a Person, newest first.
	 *
	 * @param int $person_id Person ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_person( int $person_id ): array {
		global $wpdb;

		$table = Person_Schema::person_notes();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE person_id = %d ORDER BY created_at DESC, id DESC", $person_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Add a note.
	 *
	 * @param int    $person_id      Person ID.
	 * @param string $body           Note text.
	 * @param int    $author_user_id WordPress user ID authoring the note, 0 when unknown.
	 * @return array<string, mixed>|null
	 */
	public function create( int $person_id, string $body, int $author_user_id = 0 ): ?array {
		global $wpdb;

		$body = trim( wp_kses_post( $body ) );

		if ( '' === $body || $person_id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Person_Schema::person_notes(),
			array(
				'person_id'      => $person_id,
				'author_user_id' => max( 0, $author_user_id ),
				'body'           => $body,
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		return $wpdb->insert_id ? $this->find( (int) $wpdb->insert_id ) : null;
	}

	/**
	 * Delete every note on a Person — free-text staff commentary is exactly
	 * the kind of content a privacy erasure request must remove; see
	 * {@see \EventOS\Crm\Person_Privacy}.
	 *
	 * @param int $person_id Person ID.
	 * @return void
	 */
	public function delete_for_person( int $person_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Person_Schema::person_notes(), array( 'person_id' => $person_id ), array( '%d' ) );
	}

	/**
	 * Read a single note.
	 *
	 * @param int $id Note ID.
	 * @return array<string, mixed>|null
	 */
	private function find( int $id ): ?array {
		global $wpdb;

		$table = Person_Schema::person_notes();

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
		$author_id = (int) $row['author_user_id'];
		$author    = $author_id > 0 ? get_userdata( $author_id ) : false;

		return array(
			'id'             => (int) $row['id'],
			'person_id'      => (int) $row['person_id'],
			'author_user_id' => $author_id,
			'author_name'    => $author ? $author->display_name : '',
			'body'           => (string) $row['body'],
			'created_at'     => (string) $row['created_at'],
		);
	}
}

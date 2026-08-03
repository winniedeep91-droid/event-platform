<?php
/**
 * Data access for event categories and tags.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared persistence for the two taxonomies owned by the Events module.
 */
final class Term_Repository {

	/**
	 * Taxonomy handled by this instance.
	 *
	 * @var string
	 */
	private string $taxonomy;

	/**
	 * Constructor.
	 *
	 * @param string $taxonomy Either category or tag.
	 */
	public function __construct( string $taxonomy = 'category' ) {
		$this->taxonomy = 'tag' === $taxonomy ? 'tag' : 'category';
	}

	/**
	 * Taxonomy key.
	 *
	 * @return string
	 */
	public function taxonomy(): string {
		return $this->taxonomy;
	}

	/**
	 * Table backing the taxonomy.
	 *
	 * @return string
	 */
	private function table(): string {
		return 'tag' === $this->taxonomy ? Event_Schema::tags() : Event_Schema::categories();
	}

	/**
	 * All terms with their usage counts.
	 *
	 * @param string $search Optional search term.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( string $search = '' ): array {
		global $wpdb;

		$table    = $this->table();
		$relation = Event_Schema::event_terms();
		$where    = '';
		$params   = array( $this->taxonomy );

		if ( '' !== $search ) {
			$where    = 'WHERE t.name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.*, COUNT(r.id) AS usage_count FROM {$table} AS t LEFT JOIN {$relation} AS r ON r.term_id = t.id AND r.taxonomy = %s {$where} GROUP BY t.id ORDER BY t.name ASC",
				$params
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Find one term.
	 *
	 * @param int $id Term ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->hydrate( (array) $row ) : null;
	}

	/**
	 * Create a term.
	 *
	 * @param string $name        Display name.
	 * @param string $description Description (categories only).
	 * @param int    $parent_id   Parent category.
	 * @return int
	 */
	public function insert( string $name, string $description = '', int $parent_id = 0 ): int {
		global $wpdb;

		$data = array(
			'name'       => sanitize_text_field( $name ),
			'slug'       => $this->unique_slug( $name ),
			'created_at' => current_time( 'mysql', true ),
		);

		$formats = array( '%s', '%s', '%s' );

		if ( 'category' === $this->taxonomy ) {
			$data['description'] = sanitize_textarea_field( $description );
			$data['parent_id']   = $parent_id;
			$formats[]           = '%s';
			$formats[]           = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert( $this->table(), $data, $formats );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a term.
	 *
	 * @param int                  $id   Term ID.
	 * @param array<string, mixed> $data Accepted: name, description, parent_id.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$values  = array();
		$formats = array();

		if ( isset( $data['name'] ) ) {
			$values['name'] = sanitize_text_field( (string) $data['name'] );
			$values['slug'] = $this->unique_slug( (string) $data['name'], $id );
			$formats[]      = '%s';
			$formats[]      = '%s';
		}

		if ( 'category' === $this->taxonomy && isset( $data['description'] ) ) {
			$values['description'] = sanitize_textarea_field( (string) $data['description'] );
			$formats[]             = '%s';
		}

		if ( 'category' === $this->taxonomy && isset( $data['parent_id'] ) ) {
			$values['parent_id'] = (int) $data['parent_id'];
			$formats[]           = '%d';
		}

		if ( ! $values ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update( $this->table(), $values, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Delete a term and detach it from every event.
	 *
	 * @param int $id Term ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		( new Event_Repository() )->detach_term( $id, $this->taxonomy );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );

		if ( 'category' === $this->taxonomy ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $this->table(), array( 'parent_id' => 0 ), array( 'parent_id' => $id ), array( '%d' ), array( '%d' ) );
		}

		return (bool) $deleted;
	}

	/**
	 * Resolve term names into IDs, creating any that are missing.
	 *
	 * @param string[] $names Term names.
	 * @return int[]
	 */
	public function ensure( array $names ): array {
		global $wpdb;

		$ids   = array();
		$table = $this->table();

		foreach ( $names as $name ) {
			$name = sanitize_text_field( (string) $name );

			if ( '' === $name ) {
				continue;
			}

			$slug = sanitize_title( $name );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) );

			$ids[] = $existing ? $existing : $this->insert( $name );
		}

		return array_values( array_filter( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Unique slug inside the taxonomy.
	 *
	 * @param string $name      Term name.
	 * @param int    $ignore_id Term to ignore.
	 * @return string
	 */
	public function unique_slug( string $name, int $ignore_id = 0 ): string {
		global $wpdb;

		$table  = $this->table();
		$slug   = sanitize_title( $name );
		$slug   = '' === $slug ? $this->taxonomy : $slug;
		$base   = $slug;
		$suffix = 1;

		while ( true ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s AND id <> %d", $slug, $ignore_id ) );

			if ( ! $exists ) {
				return $slug;
			}

			++$suffix;
			$slug = $base . '-' . $suffix;
		}
	}

	/**
	 * Convert a row into an API shape.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	public function hydrate( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'name'        => (string) $row['name'],
			'slug'        => (string) $row['slug'],
			'description' => (string) ( $row['description'] ?? '' ),
			'parent_id'   => (int) ( $row['parent_id'] ?? 0 ),
			'usage_count' => (int) ( $row['usage_count'] ?? 0 ),
			'taxonomy'    => $this->taxonomy,
			'created_at'  => (string) $row['created_at'],
		);
	}
}

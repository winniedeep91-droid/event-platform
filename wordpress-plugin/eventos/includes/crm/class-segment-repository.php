<?php
/**
 * Data access for CRM segments and manual Person membership.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Crm;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Segment CRUD and manual membership only — no evaluation engine. A
 * segment's `rule_config` is stored as-is (JSON) for a later phase to
 * interpret; this repository never evaluates it, and `eventos_person_segments`
 * membership here is only ever set explicitly by a caller, never computed.
 *
 * `eventos_segments` (Phase 1 schema) has no status/archived column — rather
 * than requesting a schema change for one boolean, {@see self::archive()}
 * stores a reserved `_archived` key inside the existing `rule_config` JSON
 * column, which already exists precisely to hold flexible segment data. This
 * is a deliberate, additive choice; see the Phase 3 report for the reasoning.
 */
final class Segment_Repository {

	/**
	 * Create a segment.
	 *
	 * Accepted keys: name (required), slug (derived from name if omitted),
	 * rule_config (array, optional), is_system (bool, optional).
	 *
	 * @param array<string, mixed> $data Segment data.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create( array $data ) {
		global $wpdb;

		$name = trim( sanitize_text_field( (string) ( $data['name'] ?? '' ) ) );

		if ( '' === $name ) {
			return new WP_Error( 'eventos_segment_invalid', __( 'A segment name is required.', 'eventos' ) );
		}

		$slug = sanitize_title( (string) ( $data['slug'] ?? $name ) );

		if ( '' === $slug ) {
			return new WP_Error( 'eventos_segment_invalid', __( 'A segment slug could not be derived.', 'eventos' ) );
		}

		if ( null !== $this->find_by_slug( $slug ) ) {
			return new WP_Error( 'eventos_segment_slug_taken', __( 'That segment slug is already in use.', 'eventos' ) );
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Person_Schema::segments(),
			array(
				'name'        => $name,
				'slug'        => $slug,
				'rule_config' => wp_json_encode( (array) ( $data['rule_config'] ?? array() ) ),
				'is_system'   => ! empty( $data['is_system'] ) ? 1 : 0,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Update a segment's name/slug/rule_config.
	 *
	 * @param int                   $id   Segment ID.
	 * @param array<string, mixed> $data Columns to update.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update( int $id, array $data ) {
		global $wpdb;

		$segment = $this->find( $id );

		if ( null === $segment ) {
			return new WP_Error( 'eventos_segment_not_found', __( 'Segment not found.', 'eventos' ), array( 'status' => 404 ) );
		}

		$row     = array();
		$formats = array();

		if ( isset( $data['name'] ) ) {
			$name = trim( sanitize_text_field( (string) $data['name'] ) );

			if ( '' === $name ) {
				return new WP_Error( 'eventos_segment_invalid', __( 'A segment name is required.', 'eventos' ) );
			}

			$row['name'] = $name;
			$formats[]   = '%s';
		}

		if ( isset( $data['slug'] ) ) {
			$slug     = sanitize_title( (string) $data['slug'] );
			$existing = $this->find_by_slug( $slug );

			if ( null !== $existing && (int) $existing['id'] !== $id ) {
				return new WP_Error( 'eventos_segment_slug_taken', __( 'That segment slug is already in use.', 'eventos' ) );
			}

			$row['slug'] = $slug;
			$formats[]   = '%s';
		}

		if ( array_key_exists( 'rule_config', $data ) ) {
			$row['rule_config'] = wp_json_encode( (array) $data['rule_config'] );
			$formats[]          = '%s';
		}

		if ( ! $row ) {
			return $segment;
		}

		$row['updated_at'] = current_time( 'mysql', true );
		$formats[]         = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Person_Schema::segments(), $row, array( 'id' => $id ), $formats, array( '%d' ) );

		return $this->find( $id );
	}

	/**
	 * Archive a segment without deleting it — see class docblock.
	 *
	 * @param int $id Segment ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function archive( int $id ) {
		$segment = $this->find( $id );

		if ( null === $segment ) {
			return new WP_Error( 'eventos_segment_not_found', __( 'Segment not found.', 'eventos' ), array( 'status' => 404 ) );
		}

		$rule_config              = $segment['rule_config'];
		$rule_config['_archived'] = true;

		return $this->update( $id, array( 'rule_config' => $rule_config ) );
	}

	/**
	 * Read a single segment.
	 *
	 * @param int $id Segment ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Person_Schema::segments();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Every segment.
	 *
	 * @param bool $include_archived Whether to include archived segments.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( bool $include_archived = false ): array {
		global $wpdb;

		$table = Person_Schema::segments();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC", ARRAY_A );

		$segments = array_map( array( $this, 'hydrate' ), (array) $rows );

		if ( $include_archived ) {
			return $segments;
		}

		return array_values( array_filter( $segments, static fn( array $segment ): bool => ! $segment['archived'] ) );
	}

	/**
	 * Attach a Person to a segment. Idempotent, relying on the Phase 1
	 * `UNIQUE KEY person_segment (person_id, segment_id)` as the backstop.
	 *
	 * @param int $segment_id Segment ID.
	 * @param int $person_id  Person ID.
	 * @return void
	 */
	public function attach_person( int $segment_id, int $person_id ): void {
		global $wpdb;

		$table = Person_Schema::person_segments();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE segment_id = %d AND person_id = %d", $segment_id, $person_id )
		);

		if ( $exists > 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'person_id'   => $person_id,
				'segment_id'  => $segment_id,
				'computed_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s' )
		);
	}

	/**
	 * Remove a Person from a segment.
	 *
	 * @param int $segment_id Segment ID.
	 * @param int $person_id  Person ID.
	 * @return void
	 */
	public function detach_person( int $segment_id, int $person_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			Person_Schema::person_segments(),
			array(
				'segment_id' => $segment_id,
				'person_id'  => $person_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Persons currently in a segment.
	 *
	 * @param int $segment_id Segment ID.
	 * @param int $page       Page, 1 based.
	 * @param int $per_page   Page size.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function members( int $segment_id, int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$persons_table         = Person_Schema::persons();
		$person_segments_table = Person_Schema::person_segments();
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = max( 1, $page );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$person_segments_table} WHERE segment_id = %d", $segment_id )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.display_name, p.primary_email, ps.computed_at
				FROM {$person_segments_table} ps
				INNER JOIN {$persons_table} p ON p.id = ps.person_id
				WHERE ps.segment_id = %d
				ORDER BY ps.computed_at DESC
				LIMIT %d OFFSET %d",
				$segment_id,
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);

		$items = array_map(
			static function ( array $row ): array {
				return array(
					'person_id'     => (int) $row['id'],
					'display_name'  => (string) $row['display_name'],
					'primary_email' => (string) $row['primary_email'],
					'computed_at'   => (string) $row['computed_at'],
				);
			},
			(array) $rows
		);

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Every segment a Person currently belongs to.
	 *
	 * @param int $person_id Person ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_person( int $person_id ): array {
		global $wpdb;

		$segments_table        = Person_Schema::segments();
		$person_segments_table = Person_Schema::person_segments();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, ps.computed_at
				FROM {$person_segments_table} ps
				INNER JOIN {$segments_table} s ON s.id = ps.segment_id
				WHERE ps.person_id = %d
				ORDER BY s.name ASC",
				$person_id
			),
			ARRAY_A
		);

		return array_map(
			function ( array $row ): array {
				$segment                = $this->hydrate( $row );
				$segment['computed_at'] = (string) $row['computed_at'];

				return $segment;
			},
			(array) $rows
		);
	}

	/**
	 * Look up a segment by slug.
	 *
	 * @param string $slug Segment slug.
	 * @return array<string, mixed>|null
	 */
	private function find_by_slug( string $slug ): ?array {
		global $wpdb;

		$table = Person_Schema::segments();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Shape a raw row for internal consumers.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		$rule_config = (array) ( json_decode( (string) $row['rule_config'], true ) ?: array() );

		return array(
			'id'          => (int) $row['id'],
			'name'        => (string) $row['name'],
			'slug'        => (string) $row['slug'],
			'rule_config' => $rule_config,
			'archived'    => ! empty( $rule_config['_archived'] ),
			'is_system'   => (bool) $row['is_system'],
			'created_at'  => (string) $row['created_at'],
			'updated_at'  => (string) $row['updated_at'],
		);
	}
}

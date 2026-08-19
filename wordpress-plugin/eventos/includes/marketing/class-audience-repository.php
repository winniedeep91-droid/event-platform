<?php
/**
 * Data access for Marketing audience definitions.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Marketing;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores only the audience *definition* — name, type, criteria. Never
 * stores which Persons currently match; that is always resolved on demand
 * by {@see Audience_Resolver}, so this repository has no `members()`-style
 * method the way {@see \EventOS\Crm\Segment_Repository} does for its
 * (manually curated) membership table.
 */
final class Audience_Repository {

	/**
	 * Every audience type this sprint's resolver can evaluate — kept here so
	 * both this repository's validation and {@see Audience_Resolver} share
	 * one vocabulary rather than duplicating the list.
	 */
	public const TYPES = array(
		'all',
		'event_purchasers',
		'event_ticket_type',
		'event_attendees',
		'event_non_attendees',
		'repeat_customers',
		'high_value',
		'recent_purchasers',
		'lapsed_customers',
		'segment',
	);

	/**
	 * Audience types that require an `event_id`.
	 */
	private const EVENT_SCOPED_TYPES = array( 'event_purchasers', 'event_ticket_type', 'event_attendees', 'event_non_attendees' );

	/**
	 * Every audience, optionally filtered to one event (or global-only).
	 *
	 * @param array<string, mixed> $args Optional filters: event_id (int|null, when present
	 *                                    also includes global audiences), include_archived (bool).
	 * @return array<int, array<string, mixed>>
	 */
	public function all( array $args = array() ): array {
		global $wpdb;

		$table            = Marketing_Schema::audiences();
		$include_archived = ! empty( $args['include_archived'] );
		$where            = $include_archived ? '1=1' : "status != 'archived'";
		$params           = array();

		if ( array_key_exists( 'event_id', $args ) && null !== $args['event_id'] ) {
			$where   .= ' AND (event_id = %d OR event_id IS NULL)';
			$params[] = (int) $args['event_id'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY name ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql, ARRAY_A );

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Read a single audience.
	 *
	 * @param int $id Audience ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Marketing_Schema::audiences();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Create an audience definition.
	 *
	 * @param array<string, mixed> $input Field values.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create( array $input ) {
		global $wpdb;

		$data = $this->sanitize( $input );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Marketing_Schema::audiences(),
			array(
				'event_id'    => $data['event_id'],
				'name'        => $data['name'],
				'description' => $data['description'],
				'type'        => $data['type'],
				'criteria'    => wp_json_encode( $data['criteria'] ),
				'status'      => 'active',
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Update an audience definition.
	 *
	 * @param int                  $id    Audience ID.
	 * @param array<string, mixed> $input Field values.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update( int $id, array $input ) {
		global $wpdb;

		$existing = $this->find( $id );

		if ( null === $existing ) {
			return new WP_Error( 'eventos_not_found', __( 'That audience no longer exists.', 'eventos' ), array( 'status' => 404 ) );
		}

		$merged = array_merge( $existing, $input );
		$data   = $this->sanitize( $merged );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Marketing_Schema::audiences(),
			array(
				'event_id'    => $data['event_id'],
				'name'        => $data['name'],
				'description' => $data['description'],
				'type'        => $data['type'],
				'criteria'    => wp_json_encode( $data['criteria'] ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return $this->find( $id );
	}

	/**
	 * Archive an audience (never deleted, so a campaign that already
	 * referenced it keeps a resolvable, if archived, definition).
	 *
	 * @param int $id Audience ID.
	 * @return true|WP_Error
	 */
	public function archive( int $id ) {
		global $wpdb;

		if ( null === $this->find( $id ) ) {
			return new WP_Error( 'eventos_not_found', __( 'That audience no longer exists.', 'eventos' ), array( 'status' => 404 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Marketing_Schema::audiences(),
			array(
				'status'     => 'archived',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Validate and normalize audience input.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>|WP_Error
	 */
	private function sanitize( array $input ): array|WP_Error {
		$name = trim( sanitize_text_field( (string) ( $input['name'] ?? '' ) ) );

		if ( '' === $name ) {
			return new WP_Error( 'eventos_invalid_audience', __( 'An audience needs a name.', 'eventos' ), array( 'status' => 400 ) );
		}

		$type = (string) ( $input['type'] ?? '' );

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'eventos_invalid_audience', __( 'Unknown audience type.', 'eventos' ), array( 'status' => 400 ) );
		}

		$event_id = array_key_exists( 'event_id', $input ) && null !== $input['event_id'] ? (int) $input['event_id'] : null;

		if ( in_array( $type, self::EVENT_SCOPED_TYPES, true ) && ( null === $event_id || $event_id <= 0 ) ) {
			return new WP_Error( 'eventos_invalid_audience', __( 'This audience type needs an event.', 'eventos' ), array( 'status' => 400 ) );
		}

		$criteria = (array) ( $input['criteria'] ?? array() );

		if ( 'event_ticket_type' === $type && empty( $criteria['ticket_type_id'] ) ) {
			return new WP_Error( 'eventos_invalid_audience', __( 'Select a ticket type.', 'eventos' ), array( 'status' => 400 ) );
		}

		if ( 'segment' === $type && empty( $criteria['segment_id'] ) ) {
			return new WP_Error( 'eventos_invalid_audience', __( 'Select a CRM segment.', 'eventos' ), array( 'status' => 400 ) );
		}

		if ( in_array( $type, array( 'recent_purchasers', 'lapsed_customers' ), true ) ) {
			$days = max( 1, (int) ( $criteria['days'] ?? 0 ) );

			if ( $days <= 0 ) {
				return new WP_Error( 'eventos_invalid_audience', __( 'Enter a time window in days.', 'eventos' ), array( 'status' => 400 ) );
			}

			$criteria['days'] = $days;
		}

		if ( 'high_value' === $type ) {
			$min_spend = (float) ( $criteria['min_spend'] ?? 0 );

			if ( $min_spend <= 0 ) {
				return new WP_Error( 'eventos_invalid_audience', __( 'Enter a minimum spend.', 'eventos' ), array( 'status' => 400 ) );
			}

			$criteria['min_spend'] = $min_spend;
		}

		return array(
			'event_id'    => $event_id,
			'name'        => $name,
			'description' => sanitize_text_field( (string) ( $input['description'] ?? '' ) ),
			'type'        => $type,
			'criteria'    => $criteria,
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
			'id'          => (int) $row['id'],
			'event_id'    => null !== $row['event_id'] ? (int) $row['event_id'] : null,
			'name'        => (string) $row['name'],
			'description' => (string) $row['description'],
			'type'        => (string) $row['type'],
			'criteria'    => (array) ( json_decode( (string) $row['criteria'], true ) ?: array() ),
			'status'      => (string) $row['status'],
			'created_at'  => (string) $row['created_at'],
			'updated_at'  => (string) $row['updated_at'],
		);
	}
}

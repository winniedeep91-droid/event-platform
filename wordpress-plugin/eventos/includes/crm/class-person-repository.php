<?php
/**
 * Data access for the permanent Person record.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Crm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A Person is the global, permanent identity behind every ticket purchase and
 * ticket-holder relationship a human ever has with the brand — the
 * counterpart to the event-scoped {@see \EventOS\Events\Guest_Repository}.
 * This repository only maps rows on and off `eventos_persons`; identity
 * matching lives in {@see Person_Resolver}.
 */
final class Person_Repository {

	/**
	 * Columns that map straight onto the persons table.
	 *
	 * @var array<string, string>
	 */
	private const COLUMNS = array(
		'display_name'             => '%s',
		'first_name'               => '%s',
		'last_name'                => '%s',
		'primary_email'            => '%s',
		'primary_phone'            => '%s',
		'avatar_url'               => '%s',
		'location'                 => '%s',
		'date_of_birth'            => '%s',
		'total_events_attended'    => '%d',
		'total_tickets_purchased'  => '%d',
		'total_spend'              => '%f',
		'avg_order_value'          => '%f',
		'avg_ticket_value'         => '%f',
		'vip_purchase_count'       => '%d',
		'complimentary_count'      => '%d',
		'refund_count'             => '%d',
		'cancellation_count'       => '%d',
		'first_event_id'           => '%d',
		'last_event_id'            => '%d',
		'last_purchase_at'         => '%s',
		'last_attendance_at'       => '%s',
		'created_at'               => '%s',
		'updated_at'               => '%s',
	);

	/**
	 * Columns accepted on creation — identity fields only. Cached metrics
	 * always start at their schema defaults and are populated later by
	 * {@see Person_Metrics_Service}, never guessed at creation time.
	 *
	 * @var string[]
	 */
	private const CREATE_FIELDS = array(
		'display_name',
		'first_name',
		'last_name',
		'primary_email',
		'primary_phone',
		'avatar_url',
		'location',
		'date_of_birth',
	);

	/**
	 * Create a new Person.
	 *
	 * Accepted keys: any of self::CREATE_FIELDS, all optional.
	 *
	 * @param array<string, mixed> $data Person data.
	 * @return array<string, mixed>
	 */
	public function create( array $data ): array {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$row = array(
			'created_at' => $now,
			'updated_at' => $now,
		);

		foreach ( self::CREATE_FIELDS as $field ) {
			if ( 'date_of_birth' === $field ) {
				$row[ $field ] = '' !== (string) ( $data[ $field ] ?? '' ) ? (string) $data[ $field ] : null;
				continue;
			}

			$row[ $field ] = (string) ( $data[ $field ] ?? '' );
		}

		$formats = array_map(
			static function ( string $column ): string {
				return self::COLUMNS[ $column ];
			},
			array_keys( $row )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( Person_Schema::persons(), $row, $formats );

		return $this->find_by_id( (int) $wpdb->insert_id );
	}

	/**
	 * Read a single Person.
	 *
	 * @param int $id Person ID.
	 * @return array<string, mixed>|null
	 */
	public function find_by_id( int $id ): ?array {
		global $wpdb;

		$table = Person_Schema::persons();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Find a Person whose own primary_email already matches — the
	 * resolver's defensive third step when no formal identity row exists
	 * yet (see Person_Resolver::find_or_create()).
	 *
	 * @param string $normalized_email Already-normalized email address.
	 * @return array<string, mixed>|null
	 */
	public function find_by_primary_email( string $normalized_email ): ?array {
		global $wpdb;

		if ( '' === $normalized_email ) {
			return null;
		}

		$table = Person_Schema::persons();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE primary_email = %s ORDER BY id ASC LIMIT 1", $normalized_email ),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Partially update a Person.
	 *
	 * Accepted keys: any column in self::COLUMNS. Callers decide precedence
	 * (e.g. never overwriting a meaningful value with blank) — this method
	 * writes exactly what it is given.
	 *
	 * @param int                   $id   Person ID.
	 * @param array<string, mixed> $data Columns to update.
	 * @return array<string, mixed>|null
	 */
	public function update( int $id, array $data ): ?array {
		global $wpdb;

		$row     = array();
		$formats = array();

		foreach ( $data as $column => $value ) {
			if ( ! isset( self::COLUMNS[ $column ] ) ) {
				continue;
			}

			$row[ $column ] = $value;
			$formats[]      = self::COLUMNS[ $column ];
		}

		if ( ! $row ) {
			return $this->find_by_id( $id );
		}

		$row['updated_at'] = current_time( 'mysql', true );
		$formats[]         = self::COLUMNS['updated_at'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Person_Schema::persons(), $row, array( 'id' => $id ), $formats, array( '%d' ) );

		return $this->find_by_id( $id );
	}

	/**
	 * Every Person, newest first — the export/reporting accessor this
	 * repository otherwise has no reason to expose (day-to-day CRM reads go
	 * through {@see Person_Service}'s richer, paginated queries). Kept
	 * intentionally simple: no filtering beyond an optional limit, since the
	 * only current caller is the People export provider.
	 *
	 * @param array<string, mixed> $args Optional: 'limit' (0 = no limit).
	 * @return array<int, array<string, mixed>>
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$table = Person_Schema::persons();
		$limit = max( 0, (int) ( $args['limit'] ?? 0 ) );
		$sql   = "SELECT * FROM {$table} ORDER BY id ASC";

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' LIMIT %d', $limit ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $sql, ARRAY_A );
		}

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Shape a raw row for internal consumers.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		return array(
			'id'                      => (int) $row['id'],
			'display_name'            => (string) $row['display_name'],
			'first_name'              => (string) $row['first_name'],
			'last_name'               => (string) $row['last_name'],
			'primary_email'           => (string) $row['primary_email'],
			'primary_phone'           => (string) $row['primary_phone'],
			'avatar_url'              => (string) $row['avatar_url'],
			'location'                => (string) $row['location'],
			'date_of_birth'           => $row['date_of_birth'],
			'total_events_attended'   => (int) $row['total_events_attended'],
			'total_tickets_purchased' => (int) $row['total_tickets_purchased'],
			'total_spend'             => (float) $row['total_spend'],
			'avg_order_value'         => (float) $row['avg_order_value'],
			'avg_ticket_value'        => (float) $row['avg_ticket_value'],
			'vip_purchase_count'      => (int) $row['vip_purchase_count'],
			'complimentary_count'     => (int) $row['complimentary_count'],
			'refund_count'            => (int) $row['refund_count'],
			'cancellation_count'      => (int) $row['cancellation_count'],
			'first_event_id'          => (int) $row['first_event_id'],
			'last_event_id'           => (int) $row['last_event_id'],
			'last_purchase_at'        => $row['last_purchase_at'],
			'last_attendance_at'      => $row['last_attendance_at'],
			'created_at'              => (string) $row['created_at'],
			'updated_at'              => (string) $row['updated_at'],
		);
	}
}

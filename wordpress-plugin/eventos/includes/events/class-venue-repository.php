<?php
/**
 * Data access for venues.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Venue persistence.
 */
final class Venue_Repository {

	/**
	 * Column formats.
	 *
	 * @var array<string, string>
	 */
	private const COLUMNS = array(
		'name'                  => '%s',
		'slug'                  => '%s',
		'address_line1'         => '%s',
		'address_line2'         => '%s',
		'city'                  => '%s',
		'province'              => '%s',
		'postal_code'           => '%s',
		'country'               => '%s',
		'latitude'              => '%s',
		'longitude'             => '%s',
		'maps_url'              => '%s',
		'parking_info'          => '%s',
		'capacity'              => '%d',
		'seating_configuration' => '%s',
		'notes'                 => '%s',
		'created_at'            => '%s',
		'updated_at'            => '%s',
	);

	/**
	 * Query venues.
	 *
	 * @param array<string, mixed> $args Accepted: search, city, country, orderby, order, page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'city'     => '',
				'country'  => '',
				'orderby'  => 'name',
				'order'    => 'asc',
				'page'     => 1,
				'per_page' => 20,
			)
		);

		$table  = Event_Schema::venues();
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== (string) $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(name LIKE %s OR city LIKE %s OR address_line1 LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		foreach ( array( 'city', 'country' ) as $key ) {
			if ( '' !== (string) $args[ $key ] ) {
				$where[]  = $key . ' = %s';
				$params[] = (string) $args[ $key ];
			}
		}

		$orderby  = in_array( (string) $args['orderby'], array( 'name', 'city', 'capacity', 'created_at' ), true ) ? (string) $args['orderby'] : 'name';
		$order    = 'desc' === strtolower( (string) $args['order'] ) ? 'DESC' : 'ASC';
		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = $params
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) )
			: (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'items'    => array_map( array( $this, 'hydrate' ), $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Find one venue.
	 *
	 * @param int $id Venue ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Event_Schema::venues();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->hydrate( (array) $row ) : null;
	}

	/**
	 * Insert a venue.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int
	 */
	public function insert( array $data ): int {
		global $wpdb;

		list( $values, $formats ) = $this->prepare_columns( $data );

		if ( ! $values ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert( Event_Schema::venues(), $values, $formats );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a venue.
	 *
	 * @param int                  $id   Venue ID.
	 * @param array<string, mixed> $data Column values.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		list( $values, $formats ) = $this->prepare_columns( $data );

		if ( ! $values ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update( Event_Schema::venues(), $values, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Delete a venue and detach it from its events.
	 *
	 * @param int $id Venue ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$events = Event_Schema::events();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $events, array( 'venue_id' => 0 ), array( 'venue_id' => $id ), array( '%d' ), array( '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->delete( Event_Schema::venues(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Number of events booked at a venue.
	 *
	 * @param int $id Venue ID.
	 * @return int
	 */
	public function event_count( int $id ): int {
		global $wpdb;

		$events = Event_Schema::events();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events} WHERE venue_id = %d", $id ) );
	}

	/**
	 * Total number of venues.
	 *
	 * @return int
	 */
	public function total(): int {
		global $wpdb;

		$table = Event_Schema::venues();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Build a unique venue slug.
	 *
	 * @param string $slug      Desired slug.
	 * @param int    $ignore_id Venue to ignore.
	 * @return string
	 */
	public function unique_slug( string $slug, int $ignore_id = 0 ): string {
		global $wpdb;

		$table  = Event_Schema::venues();
		$slug   = sanitize_title( $slug );
		$slug   = '' === $slug ? 'venue' : $slug;
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
		$seating = json_decode( (string) ( $row['seating_configuration'] ?? '' ), true );

		return array(
			'id'                    => (int) $row['id'],
			'name'                  => (string) $row['name'],
			'slug'                  => (string) $row['slug'],
			'address_line1'         => (string) $row['address_line1'],
			'address_line2'         => (string) $row['address_line2'],
			'city'                  => (string) $row['city'],
			'province'              => (string) $row['province'],
			'postal_code'           => (string) $row['postal_code'],
			'country'               => (string) $row['country'],
			'latitude'              => null === $row['latitude'] ? null : (float) $row['latitude'],
			'longitude'             => null === $row['longitude'] ? null : (float) $row['longitude'],
			'maps_url'              => (string) $row['maps_url'],
			'parking_info'          => (string) ( $row['parking_info'] ?? '' ),
			'capacity'              => (int) $row['capacity'],
			'seating_configuration' => is_array( $seating ) ? $seating : array(),
			'notes'                 => (string) ( $row['notes'] ?? '' ),
			'created_at'            => (string) $row['created_at'],
			'updated_at'            => (string) $row['updated_at'],
		);
	}

	/**
	 * Restrict values to known columns.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return array{0: array<string, mixed>, 1: string[]}
	 */
	private function prepare_columns( array $data ): array {
		$values  = array();
		$formats = array();

		foreach ( self::COLUMNS as $column => $format ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}

			$values[ $column ] = $data[ $column ];
			$formats[]         = $format;
		}

		return array( $values, $formats );
	}
}

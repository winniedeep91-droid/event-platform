<?php
/**
 * Data access for artists.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Artist persistence.
 */
final class Artist_Repository {

	/**
	 * Column formats.
	 *
	 * @var array<string, string>
	 */
	private const COLUMNS = array(
		'name'         => '%s',
		'slug'         => '%s',
		'biography'    => '%s',
		'genres'       => '%s',
		'social_links' => '%s',
		'website'      => '%s',
		'country'      => '%s',
		'image_id'     => '%d',
		'created_at'   => '%s',
		'updated_at'   => '%s',
	);

	/**
	 * Query artists.
	 *
	 * @param array<string, mixed> $args Accepted: search, genre, orderby, order, page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'genre'    => '',
				'orderby'  => 'name',
				'order'    => 'asc',
				'page'     => 1,
				'per_page' => 20,
			)
		);

		$table  = Event_Schema::artists();
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== (string) $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(name LIKE %s OR biography LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		if ( '' !== (string) $args['genre'] ) {
			$where[]  = 'genres LIKE %s';
			$params[] = '%' . $wpdb->esc_like( '"' . (string) $args['genre'] . '"' ) . '%';
		}

		$orderby  = in_array( (string) $args['orderby'], array( 'name', 'created_at' ), true ) ? (string) $args['orderby'] : 'name';
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
	 * Find one artist including its performance schedule.
	 *
	 * @param int $id Artist ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Event_Schema::artists();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		$artist                 = $this->hydrate( (array) $row );
		$artist['performances'] = $this->performances( $id );

		return $artist;
	}

	/**
	 * Performance slots booked for an artist.
	 *
	 * @param int $id Artist ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function performances( int $id ): array {
		global $wpdb;

		$relation = Event_Schema::event_artists();
		$events   = Event_Schema::events();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.event_id, r.billing, r.stage, r.starts_at, r.ends_at, e.title, e.status, e.starts_at AS event_starts_at FROM {$relation} AS r INNER JOIN {$events} AS e ON e.id = r.event_id WHERE r.artist_id = %d ORDER BY e.starts_at DESC",
				$id
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return array(
					'event_id'        => (int) $row['event_id'],
					'event_title'     => (string) $row['title'],
					'event_status'    => (string) $row['status'],
					'event_starts_at' => $row['event_starts_at'],
					'billing'         => (string) $row['billing'],
					'stage'           => (string) $row['stage'],
					'starts_at'       => $row['starts_at'],
					'ends_at'         => $row['ends_at'],
				);
			},
			$rows
		);
	}

	/**
	 * Insert an artist.
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
		$inserted = $wpdb->insert( Event_Schema::artists(), $values, $formats );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update an artist.
	 *
	 * @param int                  $id   Artist ID.
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
		return false !== $wpdb->update( Event_Schema::artists(), $values, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Delete an artist and its bookings.
	 *
	 * @param int $id Artist ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Event_Schema::event_artists(), array( 'artist_id' => $id ), array( '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Event_Schema::schedules(), array( 'artist_id' => 0 ), array( 'artist_id' => $id ), array( '%d' ), array( '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->delete( Event_Schema::artists(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Total number of artists.
	 *
	 * @return int
	 */
	public function total(): int {
		global $wpdb;

		$table = Event_Schema::artists();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Build a unique artist slug.
	 *
	 * @param string $slug      Desired slug.
	 * @param int    $ignore_id Artist to ignore.
	 * @return string
	 */
	public function unique_slug( string $slug, int $ignore_id = 0 ): string {
		global $wpdb;

		$table  = Event_Schema::artists();
		$slug   = sanitize_title( $slug );
		$slug   = '' === $slug ? 'artist' : $slug;
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
		$genres = json_decode( (string) ( $row['genres'] ?? '' ), true );
		$social = json_decode( (string) ( $row['social_links'] ?? '' ), true );

		return array(
			'id'           => (int) $row['id'],
			'name'         => (string) $row['name'],
			'slug'         => (string) $row['slug'],
			'biography'    => (string) ( $row['biography'] ?? '' ),
			'genres'       => is_array( $genres ) ? array_values( array_map( 'strval', $genres ) ) : array(),
			'social_links' => is_array( $social ) ? $social : array(),
			'website'      => (string) $row['website'],
			'country'      => (string) $row['country'],
			'image_id'     => (int) $row['image_id'],
			'image_url'    => (string) ( wp_get_attachment_url( (int) $row['image_id'] ) ?: '' ),
			'created_at'   => (string) $row['created_at'],
			'updated_at'   => (string) $row['updated_at'],
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

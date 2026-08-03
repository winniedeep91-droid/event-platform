<?php
/**
 * Data access for events and their relations.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every SQL statement touching the events tables lives here.
 */
final class Event_Repository {

	/**
	 * Columns that map straight onto the events table.
	 *
	 * @var array<string, string>
	 */
	private const COLUMNS = array(
		'title'             => '%s',
		'subtitle'          => '%s',
		'slug'              => '%s',
		'description'       => '%s',
		'short_description' => '%s',
		'status'            => '%s',
		'visibility'        => '%s',
		'password_hash'     => '%s',
		'ticket_visibility' => '%s',
		'venue_id'          => '%d',
		'timezone'          => '%s',
		'starts_at'         => '%s',
		'ends_at'           => '%s',
		'doors_open_at'     => '%s',
		'capacity'          => '%d',
		'age_restriction'   => '%s',
		'accessibility'     => '%s',
		'featured_image_id' => '%d',
		'organisers'        => '%s',
		'collaborators'     => '%s',
		'recurrence'        => '%s',
		'published_at'      => '%s',
		'created_by'        => '%d',
		'updated_by'        => '%d',
		'created_at'        => '%s',
		'updated_at'        => '%s',
	);

	/**
	 * Sortable columns exposed to clients.
	 *
	 * @var string[]
	 */
	private const SORTABLE = array( 'title', 'starts_at', 'created_at', 'updated_at', 'status', 'capacity' );

	/**
	 * Query events with filters, sorting and pagination.
	 *
	 * Accepted args: search, status, visibility, venue_id, category_id, tag_id,
	 * artist_id, from, to, orderby, order, page, per_page.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'      => '',
				'status'      => '',
				'visibility'  => '',
				'venue_id'    => 0,
				'category_id' => 0,
				'tag_id'      => 0,
				'artist_id'   => 0,
				'from'        => '',
				'to'          => '',
				'orderby'     => 'starts_at',
				'order'       => 'desc',
				'page'        => 1,
				'per_page'    => 20,
			)
		);

		$events = Event_Schema::events();
		$venues = Event_Schema::venues();
		$joins  = array( "LEFT JOIN {$venues} AS v ON v.id = e.venue_id" );
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== (string) $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(e.title LIKE %s OR e.subtitle LIKE %s OR e.short_description LIKE %s OR e.slug LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		foreach ( array( 'status' => 'e.status', 'visibility' => 'e.visibility' ) as $key => $column ) {
			if ( '' !== (string) $args[ $key ] ) {
				$where[]  = $column . ' = %s';
				$params[] = (string) $args[ $key ];
			}
		}

		if ( (int) $args['venue_id'] > 0 ) {
			$where[]  = 'e.venue_id = %d';
			$params[] = (int) $args['venue_id'];
		}

		if ( '' !== (string) $args['from'] ) {
			$where[]  = '(e.starts_at IS NULL OR e.starts_at >= %s)';
			$params[] = (string) $args['from'];
		}

		if ( '' !== (string) $args['to'] ) {
			$where[]  = '(e.starts_at IS NULL OR e.starts_at <= %s)';
			$params[] = (string) $args['to'];
		}

		$terms = Event_Schema::event_terms();

		if ( (int) $args['category_id'] > 0 ) {
			$joins[]  = "INNER JOIN {$terms} AS tc ON tc.event_id = e.id AND tc.taxonomy = 'category' AND tc.term_id = %d";
			$params[] = (int) $args['category_id'];
		}

		if ( (int) $args['tag_id'] > 0 ) {
			$joins[]  = "INNER JOIN {$terms} AS tt ON tt.event_id = e.id AND tt.taxonomy = 'tag' AND tt.term_id = %d";
			$params[] = (int) $args['tag_id'];
		}

		if ( (int) $args['artist_id'] > 0 ) {
			$relation = Event_Schema::event_artists();
			$joins[]  = "INNER JOIN {$relation} AS ea ON ea.event_id = e.id AND ea.artist_id = %d";
			$params[] = (int) $args['artist_id'];
		}

		// Joins carrying placeholders must be prepared together with the where clause.
		$orderby = in_array( (string) $args['orderby'], self::SORTABLE, true ) ? (string) $args['orderby'] : 'starts_at';
		$order   = 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$join_sql  = implode( ' ', $joins );
		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(DISTINCT e.id) FROM {$events} AS e {$join_sql} WHERE {$where_sql}";
		$list_sql  = "SELECT e.*, v.name AS venue_name, v.city AS venue_city FROM {$events} AS e {$join_sql} WHERE {$where_sql} GROUP BY e.id ORDER BY e.{$orderby} {$order}, e.id DESC LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
			: (int) $wpdb->get_var( $count_sql );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ),
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
	 * Fetch one event including relations.
	 *
	 * @param int  $id             Event ID.
	 * @param bool $with_relations Whether to attach artists, media, schedules and terms.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id, bool $with_relations = true ): ?array {
		global $wpdb;

		$events = Event_Schema::events();
		$venues = Event_Schema::venues();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT e.*, v.name AS venue_name, v.city AS venue_city FROM {$events} AS e LEFT JOIN {$venues} AS v ON v.id = e.venue_id WHERE e.id = %d",
				$id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$event = $this->hydrate( (array) $row );

		if ( $with_relations ) {
			$event['artists']    = $this->artists_for( $id );
			$event['media']      = $this->media_for( $id );
			$event['schedules']  = $this->schedules_for( $id );
			$event['categories'] = $this->term_ids_for( $id, 'category' );
			$event['tags']       = $this->term_ids_for( $id, 'tag' );
		}

		return $event;
	}

	/**
	 * Fetch an event by slug.
	 *
	 * @param string $slug Slug.
	 * @return array<string, mixed>|null
	 */
	public function find_by_slug( string $slug ): ?array {
		global $wpdb;

		$events = Event_Schema::events();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$events} WHERE slug = %s", $slug ) );

		return $id ? $this->find( $id ) : null;
	}

	/**
	 * Insert an event row.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int New event ID, 0 on failure.
	 */
	public function insert( array $data ): int {
		global $wpdb;

		list( $values, $formats ) = $this->prepare_columns( $data );

		if ( ! $values ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert( Event_Schema::events(), $values, $formats );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update an event row.
	 *
	 * @param int                  $id   Event ID.
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
		return false !== $wpdb->update( Event_Schema::events(), $values, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Delete an event and everything attached to it.
	 *
	 * @param int $id Event ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		foreach (
			array(
				Event_Schema::event_artists(),
				Event_Schema::event_terms(),
				Event_Schema::media(),
				Event_Schema::schedules(),
			) as $table
		) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, array( 'event_id' => $id ), array( '%d' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->delete( Event_Schema::events(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Build a slug that is unique inside the events table.
	 *
	 * @param string $slug      Desired slug.
	 * @param int    $ignore_id Event ID to ignore (when updating).
	 * @return string
	 */
	public function unique_slug( string $slug, int $ignore_id = 0 ): string {
		global $wpdb;

		$slug   = sanitize_title( $slug );
		$slug   = '' === $slug ? 'event' : $slug;
		$events = Event_Schema::events();
		$base   = $slug;
		$suffix = 1;

		while ( true ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$events} WHERE slug = %s AND id <> %d", $slug, $ignore_id )
			);

			if ( ! $exists ) {
				return $slug;
			}

			++$suffix;
			$slug = $base . '-' . $suffix;
		}
	}

	/**
	 * Replace the artists attached to an event.
	 *
	 * @param int                                $event_id Event ID.
	 * @param array<int, array<string, mixed>>   $artists  Artist rows.
	 * @return void
	 */
	public function set_artists( int $event_id, array $artists ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Event_Schema::event_artists(), array( 'event_id' => $event_id ), array( '%d' ) );

		$position = 0;

		foreach ( $artists as $artist ) {
			$artist_id = (int) ( $artist['artist_id'] ?? 0 );

			if ( $artist_id <= 0 ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				Event_Schema::event_artists(),
				array(
					'event_id'  => $event_id,
					'artist_id' => $artist_id,
					'billing'   => sanitize_key( (string) ( $artist['billing'] ?? 'support' ) ),
					'stage'     => sanitize_text_field( (string) ( $artist['stage'] ?? '' ) ),
					'starts_at' => $this->datetime( $artist['starts_at'] ?? null ),
					'ends_at'   => $this->datetime( $artist['ends_at'] ?? null ),
					'position'  => $position++,
					'notes'     => sanitize_textarea_field( (string) ( $artist['notes'] ?? '' ) ),
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
		}
	}

	/**
	 * Artists attached to an event.
	 *
	 * @param int $event_id Event ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function artists_for( int $event_id ): array {
		global $wpdb;

		$relation = Event_Schema::event_artists();
		$artists  = Event_Schema::artists();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, a.name AS artist_name, a.image_id FROM {$relation} AS r INNER JOIN {$artists} AS a ON a.id = r.artist_id WHERE r.event_id = %d ORDER BY r.position ASC, r.id ASC",
				$event_id
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return array(
					'id'          => (int) $row['id'],
					'artist_id'   => (int) $row['artist_id'],
					'artist_name' => (string) $row['artist_name'],
					'image_id'    => (int) $row['image_id'],
					'billing'     => (string) $row['billing'],
					'stage'       => (string) $row['stage'],
					'starts_at'   => $row['starts_at'],
					'ends_at'     => $row['ends_at'],
					'position'    => (int) $row['position'],
					'notes'       => (string) $row['notes'],
				);
			},
			$rows
		);
	}

	/**
	 * Replace the gallery / media rows of an event.
	 *
	 * @param int                              $event_id Event ID.
	 * @param array<int, array<string, mixed>> $media    Media rows.
	 * @return void
	 */
	public function set_media( int $event_id, array $media ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Event_Schema::media(), array( 'event_id' => $event_id ), array( '%d' ) );

		$position = 0;

		foreach ( $media as $item ) {
			$attachment_id = (int) ( $item['attachment_id'] ?? 0 );

			if ( $attachment_id <= 0 ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				Event_Schema::media(),
				array(
					'event_id'      => $event_id,
					'attachment_id' => $attachment_id,
					'type'          => sanitize_key( (string) ( $item['type'] ?? 'gallery' ) ),
					'title'         => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
					'position'      => $position++,
					'created_at'    => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%s', '%s', '%d', '%s' )
			);
		}
	}

	/**
	 * Media rows of an event, enriched with attachment URLs.
	 *
	 * @param int $event_id Event ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function media_for( int $event_id ): array {
		global $wpdb;

		$table = Event_Schema::media();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY position ASC, id ASC", $event_id ),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return array(
					'id'            => (int) $row['id'],
					'attachment_id' => (int) $row['attachment_id'],
					'type'          => (string) $row['type'],
					'title'         => (string) $row['title'],
					'position'      => (int) $row['position'],
					'url'           => (string) ( wp_get_attachment_url( (int) $row['attachment_id'] ) ?: '' ),
				);
			},
			$rows
		);
	}

	/**
	 * Replace the schedule rows of an event.
	 *
	 * @param int                              $event_id  Event ID.
	 * @param array<int, array<string, mixed>> $schedules Schedule rows.
	 * @return void
	 */
	public function set_schedules( int $event_id, array $schedules ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Event_Schema::schedules(), array( 'event_id' => $event_id ), array( '%d' ) );

		$position = 0;

		foreach ( $schedules as $slot ) {
			$label = sanitize_text_field( (string) ( $slot['label'] ?? '' ) );
			$type  = sanitize_key( (string) ( $slot['type'] ?? 'performance' ) );

			if ( '' === $label && 'performance' === $type && empty( $slot['artist_id'] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				Event_Schema::schedules(),
				array(
					'event_id'  => $event_id,
					'label'     => $label,
					'type'      => $type,
					'stage'     => sanitize_text_field( (string) ( $slot['stage'] ?? '' ) ),
					'artist_id' => (int) ( $slot['artist_id'] ?? 0 ),
					'starts_at' => $this->datetime( $slot['starts_at'] ?? null ),
					'ends_at'   => $this->datetime( $slot['ends_at'] ?? null ),
					'position'  => $position++,
					'notes'     => sanitize_textarea_field( (string) ( $slot['notes'] ?? '' ) ),
				),
				array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
			);
		}
	}

	/**
	 * Schedule rows of an event.
	 *
	 * @param int $event_id Event ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function schedules_for( int $event_id ): array {
		global $wpdb;

		$table   = Event_Schema::schedules();
		$artists = Event_Schema::artists();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, a.name AS artist_name FROM {$table} AS s LEFT JOIN {$artists} AS a ON a.id = s.artist_id WHERE s.event_id = %d ORDER BY s.position ASC, s.id ASC",
				$event_id
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return array(
					'id'          => (int) $row['id'],
					'label'       => (string) $row['label'],
					'type'        => (string) $row['type'],
					'stage'       => (string) $row['stage'],
					'artist_id'   => (int) $row['artist_id'],
					'artist_name' => (string) ( $row['artist_name'] ?? '' ),
					'starts_at'   => $row['starts_at'],
					'ends_at'     => $row['ends_at'],
					'position'    => (int) $row['position'],
					'notes'       => (string) $row['notes'],
				);
			},
			$rows
		);
	}

	/**
	 * Replace the terms of one taxonomy attached to an event.
	 *
	 * @param int      $event_id Event ID.
	 * @param string   $taxonomy Taxonomy key: category or tag.
	 * @param int[]    $term_ids Term IDs.
	 * @return void
	 */
	public function set_terms( int $event_id, string $taxonomy, array $term_ids ): void {
		global $wpdb;

		$taxonomy = 'tag' === $taxonomy ? 'tag' : 'category';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			Event_Schema::event_terms(),
			array(
				'event_id' => $event_id,
				'taxonomy' => $taxonomy,
			),
			array( '%d', '%s' )
		);

		foreach ( array_unique( array_map( 'intval', $term_ids ) ) as $term_id ) {
			if ( $term_id <= 0 ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				Event_Schema::event_terms(),
				array(
					'event_id' => $event_id,
					'term_id'  => $term_id,
					'taxonomy' => $taxonomy,
				),
				array( '%d', '%d', '%s' )
			);
		}
	}

	/**
	 * Term IDs of one taxonomy attached to an event.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $taxonomy Taxonomy key.
	 * @return int[]
	 */
	public function term_ids_for( int $event_id, string $taxonomy ): array {
		global $wpdb;

		$table    = Event_Schema::event_terms();
		$taxonomy = 'tag' === $taxonomy ? 'tag' : 'category';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare( "SELECT term_id FROM {$table} WHERE event_id = %d AND taxonomy = %s", $event_id, $taxonomy )
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Remove every relation pointing at a term.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy key.
	 * @return void
	 */
	public function detach_term( int $term_id, string $taxonomy ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			Event_Schema::event_terms(),
			array(
				'term_id'  => $term_id,
				'taxonomy' => 'tag' === $taxonomy ? 'tag' : 'category',
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Number of events per status.
	 *
	 * @return array<string, int>
	 */
	public function counts_by_status(): array {
		global $wpdb;

		$events = Event_Schema::events();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$events} GROUP BY status", ARRAY_A );

		$counts = array();

		foreach ( Event_Status::all() as $status ) {
			$counts[ $status ] = 0;
		}

		foreach ( $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Number of events starting inside a window.
	 *
	 * @param string $from Inclusive start (MySQL datetime, UTC).
	 * @param string $to   Inclusive end (MySQL datetime, UTC).
	 * @return int
	 */
	public function count_between( string $from, string $to ): int {
		global $wpdb;

		$events = Event_Schema::events();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$events} WHERE starts_at BETWEEN %s AND %s", $from, $to )
		);
	}

	/**
	 * Total advertised capacity of published events in the future.
	 *
	 * @return int
	 */
	public function upcoming_capacity(): int {
		global $wpdb;

		$events = Event_Schema::events();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(capacity), 0) FROM {$events} WHERE status = %s AND starts_at >= %s",
				Event_Status::PUBLISHED,
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Convert a database row into an API friendly array.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	public function hydrate( array $row ): array {
		return array(
			'id'                => (int) $row['id'],
			'title'             => (string) $row['title'],
			'subtitle'          => (string) $row['subtitle'],
			'slug'              => (string) $row['slug'],
			'description'       => (string) ( $row['description'] ?? '' ),
			'short_description' => (string) ( $row['short_description'] ?? '' ),
			'status'            => (string) $row['status'],
			'visibility'        => (string) $row['visibility'],
			'password_protected' => '' !== (string) ( $row['password_hash'] ?? '' ),
			'ticket_visibility' => (string) $row['ticket_visibility'],
			'venue_id'          => (int) $row['venue_id'],
			'venue_name'        => (string) ( $row['venue_name'] ?? '' ),
			'venue_city'        => (string) ( $row['venue_city'] ?? '' ),
			'timezone'          => (string) $row['timezone'],
			'starts_at'         => $row['starts_at'],
			'ends_at'           => $row['ends_at'],
			'doors_open_at'     => $row['doors_open_at'],
			'capacity'          => (int) $row['capacity'],
			'age_restriction'   => (string) $row['age_restriction'],
			'accessibility'     => (string) ( $row['accessibility'] ?? '' ),
			'featured_image_id' => (int) $row['featured_image_id'],
			'featured_image_url' => (string) ( wp_get_attachment_url( (int) $row['featured_image_id'] ) ?: '' ),
			'organisers'        => $this->decode_ids( $row['organisers'] ?? '' ),
			'collaborators'     => $this->decode_ids( $row['collaborators'] ?? '' ),
			'recurrence'        => $this->decode_array( $row['recurrence'] ?? '' ),
			'published_at'      => $row['published_at'],
			'created_by'        => (int) $row['created_by'],
			'updated_by'        => (int) $row['updated_by'],
			'created_at'        => (string) $row['created_at'],
			'updated_at'        => (string) $row['updated_at'],
		);
	}

	/**
	 * Restrict an array to known columns and build the format list.
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

	/**
	 * Normalise a datetime value for storage.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private function datetime( $value ): ?string {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return null;
		}

		$timestamp = strtotime( $value );

		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
	}

	/**
	 * Decode a stored JSON list of integers.
	 *
	 * @param mixed $value Stored value.
	 * @return int[]
	 */
	private function decode_ids( $value ): array {
		$decoded = json_decode( (string) $value, true );

		return is_array( $decoded ) ? array_values( array_map( 'intval', $decoded ) ) : array();
	}

	/**
	 * Decode a stored JSON object.
	 *
	 * @param mixed $value Stored value.
	 * @return array<string, mixed>
	 */
	private function decode_array( $value ): array {
		$decoded = json_decode( (string) $value, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}

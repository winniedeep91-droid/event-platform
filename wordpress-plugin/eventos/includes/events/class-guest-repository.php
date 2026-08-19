<?php
/**
 * Data access for the event operational guest layer.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A guest is the human identity behind exactly one ticket. This is the
 * event operational layer only — a future CRM/Relationships module builds
 * on top of this data rather than this class growing into one.
 */
final class Guest_Repository {

	/**
	 * Columns that map straight onto the guests table.
	 *
	 * @var array<string, string>
	 */
	private const COLUMNS = array(
		'event_id'       => '%d',
		'ticket_id'      => '%d',
		'wc_customer_id' => '%d',
		'name'           => '%s',
		'email'          => '%s',
		'phone'          => '%s',
		'status'         => '%s',
		'tags'           => '%s',
		'notes'          => '%s',
		'created_at'     => '%s',
		'updated_at'     => '%s',
	);

	/**
	 * Create a guest attached to a ticket.
	 *
	 * Accepted keys: event_id, ticket_id, wc_customer_id, name, email, phone, status.
	 *
	 * @param array<string, mixed> $data Guest data.
	 * @return array<string, mixed>
	 */
	public function create( array $data ): array {
		global $wpdb;

		$now    = current_time( 'mysql', true );
		$status = (string) ( $data['status'] ?? 'confirmed' );

		$row = array(
			'event_id'       => (int) $data['event_id'],
			'ticket_id'      => (int) $data['ticket_id'],
			'wc_customer_id' => (int) ( $data['wc_customer_id'] ?? 0 ),
			'name'           => (string) ( $data['name'] ?? '' ),
			'email'          => (string) ( $data['email'] ?? '' ),
			'phone'          => (string) ( $data['phone'] ?? '' ),
			'status'         => in_array( $status, array( 'confirmed', 'waitlisted', 'cancelled', 'no_show' ), true )
				? $status
				: 'confirmed',
			'tags'           => wp_json_encode( array() ),
			'notes'          => wp_json_encode( array() ),
			'created_at'     => $now,
			'updated_at'     => $now,
		);

		$formats = array_map(
			static function ( string $column ): string {
				return self::COLUMNS[ $column ];
			},
			array_keys( $row )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( Event_Schema::guests(), $row, $formats );

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Read a single guest with its ticket and ticket type joined in.
	 *
	 * @param int $id Guest ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$rows = $this->select( 'g.id = %d', array( $id ), 1, 0 );

		return $rows[0] ?? null;
	}

	/**
	 * Read a guest by its ticket.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return array<string, mixed>|null
	 */
	public function find_by_ticket( int $ticket_id ): ?array {
		$rows = $this->select( 'g.ticket_id = %d', array( $ticket_id ), 1, 0 );

		return $rows[0] ?? null;
	}

	/**
	 * Query guests for an event with search, filters and pagination.
	 *
	 * Accepted args: search, status, checked_in (bool|null), page, per_page.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function query( int $event_id, array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'     => '',
				'status'     => '',
				'checked_in' => null,
				'page'       => 1,
				'per_page'   => 20,
			)
		);

		$where  = array( 'g.event_id = %d' );
		$params = array( $event_id );

		if ( '' !== (string) $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(g.name LIKE %s OR g.email LIKE %s OR t.ticket_number LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( '' !== (string) $args['status'] ) {
			$where[]  = 'g.status = %s';
			$params[] = (string) $args['status'];
		}

		if ( null !== $args['checked_in'] ) {
			$where[]  = 't.checked_in = %d';
			$params[] = $args['checked_in'] ? 1 : 0;
		}

		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );

		$total = $this->count( implode( ' AND ', $where ), $params );
		$items = $this->select( implode( ' AND ', $where ), $params, $per_page, ( $page - 1 ) * $per_page );

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Partially update a guest's contact fields.
	 *
	 * Writes exactly the columns given — callers decide precedence (e.g.
	 * never overwriting a real value with a blank one), matching the same
	 * convention {@see \EventOS\Crm\Person_Repository::update()} uses.
	 * Deliberately does not accept `status`/`tags` here — {@see set_status()}
	 * and {@see update_tags()} already own those with their own validation.
	 *
	 * @param int                   $id   Guest ID.
	 * @param array<string, mixed> $data Any of: name, email, phone.
	 * @return void
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;

		$allowed = array( 'name' => '%s', 'email' => '%s', 'phone' => '%s' );
		$row     = array();
		$formats = array();

		foreach ( $data as $column => $value ) {
			if ( ! isset( $allowed[ $column ] ) ) {
				continue;
			}

			$row[ $column ] = $value;
			$formats[]      = $allowed[ $column ];
		}

		if ( ! $row ) {
			return;
		}

		$row['updated_at'] = current_time( 'mysql', true );
		$formats[]          = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Event_Schema::guests(), $row, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Update a guest's status.
	 *
	 * @param int    $id     Guest ID.
	 * @param string $status New status.
	 * @return void
	 */
	public function set_status( int $id, string $status ): void {
		global $wpdb;

		if ( ! in_array( $status, array( 'confirmed', 'waitlisted', 'cancelled', 'no_show' ), true ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Event_Schema::guests(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Append an internal note.
	 *
	 * @param int    $id     Guest ID.
	 * @param string $note   Note content.
	 * @param string $author Display name of the author.
	 * @return array<string, mixed>|null The appended note.
	 */
	public function add_note( int $id, string $note, string $author ): ?array {
		global $wpdb;

		$guest = $this->find( $id );

		if ( null === $guest ) {
			return null;
		}

		$notes   = (array) $guest['notes'];
		$next_id = 1;

		foreach ( $notes as $existing ) {
			$next_id = max( $next_id, (int) $existing['id'] + 1 );
		}

		$entry = array(
			'id'         => $next_id,
			'note'       => $note,
			'author'     => $author,
			'created_at' => current_time( 'mysql', true ),
		);

		$notes[] = $entry;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Event_Schema::guests(),
			array(
				'notes'      => wp_json_encode( $notes ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return $entry;
	}

	/**
	 * Replace a guest's tags.
	 *
	 * @param int      $id   Guest ID.
	 * @param string[] $tags Tag list.
	 * @return void
	 */
	public function update_tags( int $id, array $tags ): void {
		global $wpdb;

		$tags = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $tags ) ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Event_Schema::guests(),
			array(
				'tags'       => wp_json_encode( $tags ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Every event a person has attended, matched by WooCommerce customer ID or email.
	 *
	 * @param int    $wc_customer_id WooCommerce customer ID, 0 when a guest checkout.
	 * @param string $email          Email address.
	 * @return array<int, array<string, mixed>>
	 */
	public function attendance_history( int $wc_customer_id, string $email ): array {
		global $wpdb;

		if ( $wc_customer_id <= 0 && '' === $email ) {
			return array();
		}

		$guests  = Event_Schema::guests();
		$tickets = Event_Schema::tickets();
		$events  = Event_Schema::events();

		$where  = array();
		$params = array();

		if ( $wc_customer_id > 0 ) {
			$where[]  = 'g.wc_customer_id = %d';
			$params[] = $wc_customer_id;
		}

		if ( '' !== $email ) {
			$where[]  = 'g.email = %s';
			$params[] = $email;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id AS event_id, e.title AS event_title, e.starts_at AS event_starts_at, t.checked_in, t.checked_in_at
				FROM {$guests} g
				INNER JOIN {$tickets} t ON t.id = g.ticket_id
				INNER JOIN {$events} e ON e.id = t.event_id
				WHERE t.status != 'cancelled' AND (" . implode( ' OR ', $where ) . ')
				ORDER BY e.starts_at DESC',
				$params
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return array(
					'event_id'        => (int) $row['event_id'],
					'event_title'     => (string) $row['event_title'],
					'event_starts_at' => $row['event_starts_at'],
					'checked_in'      => (bool) $row['checked_in'],
					'checked_in_at'   => $row['checked_in_at'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Count guests matching a where clause.
	 *
	 * @param string             $where  Prepared WHERE clause.
	 * @param array<int, mixed>  $params Bind parameters.
	 * @return int
	 */
	private function count( string $where, array $params ): int {
		global $wpdb;

		$guests  = Event_Schema::guests();
		$tickets = Event_Schema::tickets();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$guests} g INNER JOIN {$tickets} t ON t.id = g.ticket_id WHERE {$where}",
				$params
			)
		);
	}

	/**
	 * Select and hydrate guest rows joined to their ticket and ticket type.
	 *
	 * @param string             $where   Prepared WHERE clause.
	 * @param array<int, mixed>  $params  Bind parameters.
	 * @param int                $limit   Row limit.
	 * @param int                $offset  Row offset.
	 * @return array<int, array<string, mixed>>
	 */
	private function select( string $where, array $params, int $limit, int $offset ): array {
		global $wpdb;

		$guests  = Event_Schema::guests();
		$tickets = Event_Schema::tickets();
		$types   = Event_Schema::ticket_types();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.*, t.ticket_number, t.checked_in, t.checked_in_at, t.checked_in_by, t.is_complimentary, t.wc_order_id,
					tt.id AS ticket_type_id, tt.name AS ticket_type_name
				FROM {$guests} g
				INNER JOIN {$tickets} t ON t.id = g.ticket_id
				LEFT JOIN {$types} tt ON tt.id = t.ticket_type_id
				WHERE {$where}
				ORDER BY g.created_at DESC, g.id DESC
				LIMIT %d OFFSET %d",
				array_merge( $params, array( $limit, $offset ) )
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Shape a joined guest row into the GuestRecord contract.
	 *
	 * @param array<string, mixed> $row Raw joined row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		$checked_in_by = (int) $row['checked_in_by'];

		return array(
			'id'                  => (int) $row['id'],
			'event_id'            => (int) $row['event_id'],
			'ticket_id'           => (int) $row['ticket_id'],
			'wc_order_id'         => (int) $row['wc_order_id'],
			'ticket_type_id'      => (int) $row['ticket_type_id'],
			'ticket_type_name'    => (string) ( $row['ticket_type_name'] ?? '' ),
			'ticket_number'       => (string) $row['ticket_number'],
			'customer_id'         => (int) $row['wc_customer_id'],
			'name'                => (string) $row['name'],
			'email'               => (string) $row['email'],
			'phone'               => (string) $row['phone'],
			'status'              => (string) $row['status'],
			'checked_in'          => (bool) $row['checked_in'],
			'checked_in_at'       => $row['checked_in_at'],
			'checked_in_by'       => $checked_in_by > 0 ? self::user_display_name( $checked_in_by ) : null,
			'is_complimentary'    => (bool) $row['is_complimentary'],
			'tags'                => (array) json_decode( (string) $row['tags'], true ),
			'notes'               => (array) json_decode( (string) $row['notes'], true ),
			'attendance_history'  => $this->attendance_history( (int) $row['wc_customer_id'], (string) $row['email'] ),
			'created_at'          => (string) $row['created_at'],
		);
	}

	/**
	 * Display name for a WordPress user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function user_display_name( int $user_id ): string {
		$user = get_userdata( $user_id );

		return $user ? $user->display_name : '';
	}
}

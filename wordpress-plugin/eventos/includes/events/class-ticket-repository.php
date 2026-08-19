<?php
/**
 * Data access for individual ticket records.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A ticket is the scannable, operational unit EventOS owns: one row per
 * admission, carrying a reference back to the WooCommerce order/order item
 * it was purchased through (or none, for complimentary tickets) without
 * duplicating any financial data WooCommerce already owns.
 */
final class Ticket_Repository {

	/**
	 * Columns that map straight onto the tickets table.
	 *
	 * @var array<string, string>
	 */
	private const COLUMNS = array(
		'event_id'         => '%d',
		'ticket_type_id'   => '%d',
		'guest_id'         => '%d',
		'wc_order_id'      => '%d',
		'wc_order_item_id' => '%d',
		'wc_customer_id'   => '%d',
		'ticket_number'    => '%s',
		'qr_token'         => '%s',
		'status'           => '%s',
		'is_complimentary' => '%d',
		'checked_in'       => '%d',
		'checked_in_at'    => '%s',
		'checked_in_by'    => '%d',
		'created_at'       => '%s',
		'updated_at'       => '%s',
	);

	/**
	 * Issue a new ticket with a unique ticket number and QR token.
	 *
	 * Accepted keys: event_id, ticket_type_id, guest_id, wc_order_id,
	 * wc_order_item_id, wc_customer_id, is_complimentary.
	 *
	 * @param array<string, mixed> $data Ticket data.
	 * @return array<string, mixed>
	 * @throws RuntimeException When a unique ticket number/QR token could not be allocated.
	 */
	public function issue( array $data ): array {
		global $wpdb;

		$table = Event_Schema::tickets();
		$now   = current_time( 'mysql', true );

		$row = array(
			'event_id'         => (int) $data['event_id'],
			'ticket_type_id'   => (int) $data['ticket_type_id'],
			'guest_id'         => (int) ( $data['guest_id'] ?? 0 ),
			'wc_order_id'      => (int) ( $data['wc_order_id'] ?? 0 ),
			'wc_order_item_id' => (int) ( $data['wc_order_item_id'] ?? 0 ),
			'wc_customer_id'   => (int) ( $data['wc_customer_id'] ?? 0 ),
			'status'           => 'active',
			'is_complimentary' => ! empty( $data['is_complimentary'] ) ? 1 : 0,
			'checked_in'       => 0,
			'created_at'       => $now,
			'updated_at'       => $now,
		);

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$attempt_row                  = $row;
			$attempt_row['ticket_number'] = Ticket_Identifier::ticket_number( (int) $data['event_id'] );
			$attempt_row['qr_token']      = Ticket_Identifier::qr_token();

			$formats = array();

			foreach ( array_keys( $attempt_row ) as $column ) {
				$formats[] = self::COLUMNS[ $column ] ?? '%s';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->insert( $table, $attempt_row, $formats );

			if ( $inserted ) {
				$issued = $this->find( (int) $wpdb->insert_id );

				if ( null !== $issued ) {
					return $issued;
				}
			}
		}

		throw new RuntimeException( __( 'Could not allocate a unique ticket after several attempts.', 'eventos' ) );
	}

	/**
	 * Read a single ticket.
	 *
	 * @param int $id Ticket ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Event_Schema::tickets();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Look up a ticket by its ticket number or QR token.
	 *
	 * @param string $code Scanned or typed code.
	 * @return array<string, mixed>|null
	 */
	public function find_by_code( string $code ): ?array {
		global $wpdb;

		$code = trim( $code );

		if ( '' === $code ) {
			return null;
		}

		$table = Event_Schema::tickets();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE qr_token = %s", $code ), ARRAY_A );

		if ( ! $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE ticket_number = %s", strtoupper( $code ) ), ARRAY_A );
		}

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Every ticket issued for a WooCommerce order.
	 *
	 * @param int $wc_order_id WooCommerce order ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_order( int $wc_order_id ): array {
		global $wpdb;

		$table = Event_Schema::tickets();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE wc_order_id = %d ORDER BY id ASC", $wc_order_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Whether tickets have already been issued for an order line item.
	 *
	 * Used by the WooCommerce fulfilment hook so a status bouncing between
	 * processing/completed never issues duplicate tickets.
	 *
	 * @param int $wc_order_item_id WooCommerce order item ID.
	 * @return bool
	 */
	public function exists_for_order_item( int $wc_order_item_id ): bool {
		global $wpdb;

		$table = Event_Schema::tickets();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE wc_order_item_id = %d", $wc_order_item_id )
		) > 0;
	}

	/**
	 * Reactivate tickets for an order item that were cancelled by a previous
	 * refund/cancellation, e.g. a failed order later reinstated to processing.
	 *
	 * @param int $wc_order_item_id WooCommerce order item ID.
	 * @return int Number of tickets reactivated.
	 */
	public function reactivate_for_order_item( int $wc_order_item_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->update(
			Event_Schema::tickets(),
			array(
				'status'     => 'active',
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'wc_order_item_id' => $wc_order_item_id,
				'status'           => 'cancelled',
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Cancel every ticket issued for a WooCommerce order.
	 *
	 * Tickets that were already checked in keep their check-in record so the
	 * door history stays accurate, but move to a cancelled status.
	 *
	 * @param int $wc_order_id WooCommerce order ID.
	 * @return int Number of tickets cancelled.
	 */
	public function cancel_for_order( int $wc_order_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->update(
			Event_Schema::tickets(),
			array(
				'status'     => 'cancelled',
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'wc_order_id' => $wc_order_id,
				'status'      => 'active',
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Cancel up to a given number of active tickets for one ticket type
	 * within one order — used to cancel exactly the tickets a partial
	 * refund covers, oldest first.
	 *
	 * @param int $wc_order_id    WooCommerce order ID.
	 * @param int $ticket_type_id Ticket type ID.
	 * @param int $limit          Maximum number of tickets to cancel.
	 * @return int Number of tickets actually cancelled.
	 */
	public function cancel_n_for_order_type( int $wc_order_id, int $ticket_type_id, int $limit ): int {
		global $wpdb;

		$table = Event_Schema::tickets();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE wc_order_id = %d AND ticket_type_id = %d AND status = 'active' ORDER BY id ASC LIMIT %d",
				$wc_order_id,
				$ticket_type_id,
				max( 1, $limit )
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'cancelled', updated_at = %s WHERE id IN ({$placeholders})",
				array_merge( array( current_time( 'mysql', true ) ), array_map( 'intval', $ids ) )
			)
		);
	}

	/**
	 * Mark a ticket as checked in.
	 *
	 * The update is conditioned on `checked_in = 0` so two near-simultaneous
	 * scans of the same ticket (two doors, two devices) can never both
	 * "win" — only the request that actually flips the flag gets
	 * `claimed => true`; the other correctly observes it was already admitted.
	 *
	 * @param int $id          Ticket ID.
	 * @param int $operator_id User performing the check-in, 0 when unknown.
	 * @return array{claimed: bool, ticket: array<string, mixed>|null}
	 */
	public function mark_checked_in( int $id, int $operator_id ): array {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			Event_Schema::tickets(),
			array(
				'checked_in'    => 1,
				'checked_in_at' => $now,
				'checked_in_by' => $operator_id,
				'updated_at'    => $now,
			),
			array(
				'id'         => $id,
				'checked_in' => 0,
			),
			array( '%d', '%s', '%d', '%s' ),
			array( '%d', '%d' )
		);

		return array(
			'claimed' => (int) $updated > 0,
			'ticket'  => $this->find( $id ),
		);
	}

	/**
	 * Reverse a ticket's check-in.
	 *
	 * @param int $id Ticket ID.
	 * @return array<string, mixed>|null
	 */
	public function undo_checkin( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Event_Schema::tickets(),
			array(
				'checked_in'    => 0,
				'checked_in_at' => null,
				'checked_in_by' => 0,
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%d', '%s' ),
			array( '%d' )
		);

		return $this->find( $id );
	}

	/**
	 * Attach a guest to a ticket.
	 *
	 * @param int $id       Ticket ID.
	 * @param int $guest_id Guest ID.
	 * @return void
	 */
	public function set_guest( int $id, int $guest_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Event_Schema::tickets(),
			array(
				'guest_id'   => $guest_id,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Ticket and check-in totals for an event, used by the live scanner counter.
	 *
	 * @param int $event_id Event ID.
	 * @return array{total: int, checked_in: int}
	 */
	public function counts_for_event( int $event_id ): array {
		global $wpdb;

		$table = Event_Schema::tickets();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total, COALESCE(SUM(checked_in), 0) AS checked_in FROM {$table} WHERE event_id = %d AND status != 'cancelled'",
				$event_id
			),
			ARRAY_A
		);

		return array(
			'total'      => (int) ( $row['total'] ?? 0 ),
			'checked_in' => (int) ( $row['checked_in'] ?? 0 ),
		);
	}

	/**
	 * Number of complimentary tickets issued for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return int
	 */
	public function complimentary_count_for_event( int $event_id ): int {
		global $wpdb;

		$table = Event_Schema::tickets();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND is_complimentary = 1 AND status != 'cancelled'",
				$event_id
			)
		);
	}

	/**
	 * Brand-wide ticket totals across every event, excluding cancelled
	 * tickets — the source for the dashboard's Performance Overview cards.
	 *
	 * @return array{total: int, checked_in: int, complimentary: int}
	 */
	public function totals(): array {
		global $wpdb;

		$table = Event_Schema::tickets();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS total, COALESCE(SUM(checked_in), 0) AS checked_in, COALESCE(SUM(is_complimentary), 0) AS complimentary FROM {$table} WHERE status != 'cancelled'",
			ARRAY_A
		);

		return array(
			'total'         => (int) ( $row['total'] ?? 0 ),
			'checked_in'    => (int) ( $row['checked_in'] ?? 0 ),
			'complimentary' => (int) ( $row['complimentary'] ?? 0 ),
		);
	}

	/**
	 * Tickets issued per day across every event within a date range,
	 * excluding cancelled tickets — the source for the brand-wide "Tickets
	 * sold over time" dashboard chart.
	 *
	 * @param string $from Inclusive lower bound (Y-m-d H:i:s, UTC).
	 * @param string $to   Inclusive upper bound (Y-m-d H:i:s, UTC).
	 * @return array<int, array{date: string, tickets: int}>
	 */
	public function counts_by_day( string $from, string $to ): array {
		global $wpdb;

		$table = Event_Schema::tickets();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS date, COUNT(*) AS tickets FROM {$table} WHERE status != 'cancelled' AND created_at BETWEEN %s AND %s GROUP BY DATE(created_at) ORDER BY date ASC",
				$from,
				$to
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return array(
					'date'    => (string) $row['date'],
					'tickets' => (int) $row['tickets'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Ticket totals grouped by event in a single query — used for batched
	 * per-event summaries (e.g. the dashboard's My Events table) so listing
	 * N events never costs N queries.
	 *
	 * @param int[] $event_ids Event IDs.
	 * @return array<int, array{total: int, checked_in: int}> Event ID => totals.
	 */
	public function counts_by_event( array $event_ids ): array {
		global $wpdb;

		$event_ids = array_values( array_unique( array_map( 'intval', $event_ids ) ) );

		if ( empty( $event_ids ) ) {
			return array();
		}

		$table        = Event_Schema::tickets();
		$placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_id, COUNT(*) AS total, COALESCE(SUM(checked_in), 0) AS checked_in FROM {$table} WHERE status != 'cancelled' AND event_id IN ({$placeholders}) GROUP BY event_id",
				$event_ids
			),
			ARRAY_A
		);

		$result = array();

		foreach ( (array) $rows as $row ) {
			$result[ (int) $row['event_id'] ] = array(
				'total'      => (int) $row['total'],
				'checked_in' => (int) $row['checked_in'],
			);
		}

		return $result;
	}

	/**
	 * A flat, export-friendly listing of tickets joined with their type,
	 * event, guest and check-in data — no existing method returns tickets
	 * independent of a single order/event, so this is the accessor the
	 * Tickets export target needs. Read-only; nothing else in the codebase
	 * calls this, it exists purely for reporting/export.
	 *
	 * @param array<string, mixed> $args Optional: 'event_id' (int, 0 = every event), 'limit' (0 = no limit).
	 * @return array<int, array<string, mixed>>
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$tickets      = Event_Schema::tickets();
		$ticket_types = Event_Schema::ticket_types();
		$events       = Event_Schema::events();
		$guests       = Event_Schema::guests();

		$where  = array( '1=1' );
		$params = array();

		$event_id = (int) ( $args['event_id'] ?? 0 );

		if ( $event_id > 0 ) {
			$where[]  = 't.event_id = %d';
			$params[] = $event_id;
		}

		$limit = max( 0, (int) ( $args['limit'] ?? 0 ) );
		$sql   = "SELECT t.*, tt.name AS ticket_type_name, e.title AS event_title, e.slug AS event_slug,
				g.name AS guest_name, g.email AS guest_email
			FROM {$tickets} t
			LEFT JOIN {$ticket_types} tt ON tt.id = t.ticket_type_id
			LEFT JOIN {$events} e ON e.id = t.event_id
			LEFT JOIN {$guests} g ON g.id = t.guest_id
			WHERE " . implode( ' AND ', $where ) . ' ORDER BY t.id ASC';

		if ( $limit > 0 ) {
			$sql      .= ' LIMIT %d';
			$params[] = $limit;
		}

		if ( $params ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $sql, ARRAY_A );
		}

		return array_map(
			function ( array $row ): array {
				$hydrated                 = $this->hydrate( $row );
				$hydrated['ticket_type_name'] = (string) ( $row['ticket_type_name'] ?? '' );
				$hydrated['event_title']       = (string) ( $row['event_title'] ?? '' );
				$hydrated['event_slug']        = (string) ( $row['event_slug'] ?? '' );
				$hydrated['guest_name']        = (string) ( $row['guest_name'] ?? '' );
				$hydrated['guest_email']       = (string) ( $row['guest_email'] ?? '' );

				return $hydrated;
			},
			(array) $rows
		);
	}

	/**
	 * Search tickets across every event by ticket number or attendee name/
	 * email — the paginated, totaled counterpart to {@see query()}, which
	 * returns an unbounded flat list built only for the export path.
	 * Requires a non-empty term.
	 *
	 * @param array<string, mixed> $args Accepted: term, page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function search_all( array $args = array() ): array {
		$args     = wp_parse_args( $args, array( 'term' => '', 'page' => 1, 'per_page' => 20 ) );
		$term     = trim( (string) $args['term'] );
		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );

		if ( '' === $term ) {
			return array( 'items' => array(), 'total' => 0, 'page' => $page, 'per_page' => $per_page );
		}

		global $wpdb;

		$tickets      = Event_Schema::tickets();
		$ticket_types = Event_Schema::ticket_types();
		$events       = Event_Schema::events();
		$guests       = Event_Schema::guests();

		$like   = '%' . $wpdb->esc_like( $term ) . '%';
		$where  = '(t.ticket_number LIKE %s OR g.name LIKE %s OR g.email LIKE %s)';
		$params = array( $like, $like, $like );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tickets} t LEFT JOIN {$guests} g ON g.id = t.guest_id WHERE {$where}",
				$params
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.*, tt.name AS ticket_type_name, e.title AS event_title, e.slug AS event_slug,
					g.name AS guest_name, g.email AS guest_email
				FROM {$tickets} t
				LEFT JOIN {$ticket_types} tt ON tt.id = t.ticket_type_id
				LEFT JOIN {$events} e ON e.id = t.event_id
				LEFT JOIN {$guests} g ON g.id = t.guest_id
				WHERE {$where}
				ORDER BY t.id DESC
				LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);

		$items = array_map(
			function ( array $row ): array {
				$hydrated                     = $this->hydrate( $row );
				$hydrated['ticket_type_name'] = (string) ( $row['ticket_type_name'] ?? '' );
				$hydrated['event_title']      = (string) ( $row['event_title'] ?? '' );
				$hydrated['event_slug']       = (string) ( $row['event_slug'] ?? '' );
				$hydrated['guest_name']       = (string) ( $row['guest_name'] ?? '' );
				$hydrated['guest_email']      = (string) ( $row['guest_email'] ?? '' );

				return $hydrated;
			},
			(array) $rows
		);

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
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
			'id'               => (int) $row['id'],
			'event_id'         => (int) $row['event_id'],
			'ticket_type_id'   => (int) $row['ticket_type_id'],
			'guest_id'         => (int) $row['guest_id'],
			'wc_order_id'      => (int) $row['wc_order_id'],
			'wc_order_item_id' => (int) $row['wc_order_item_id'],
			'wc_customer_id'   => (int) $row['wc_customer_id'],
			'ticket_number'    => (string) $row['ticket_number'],
			'qr_token'         => (string) $row['qr_token'],
			'status'           => (string) $row['status'],
			'is_complimentary' => (bool) $row['is_complimentary'],
			'checked_in'       => (bool) $row['checked_in'],
			'checked_in_at'    => $row['checked_in_at'],
			'checked_in_by'    => (int) $row['checked_in_by'],
			'created_at'       => (string) $row['created_at'],
			'updated_at'       => (string) $row['updated_at'],
		);
	}
}

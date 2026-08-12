<?php
/**
 * Data access for the scanner's check-in log.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every scan attempt — admitted, already scanned, invalid or cancelled — is
 * logged here, independently of the `checked_in` flag on the ticket itself,
 * so the door history stays complete even for scans that never resolve to a
 * ticket.
 */
final class Checkin_Repository {

	/**
	 * Columns that map straight onto the checkins table.
	 *
	 * @var array<string, string>
	 */
	private const COLUMNS = array(
		'event_id'      => '%d',
		'ticket_id'     => '%d',
		'scanned_value' => '%s',
		'outcome'       => '%s',
		'method'        => '%s',
		'operator_id'   => '%d',
		'device'        => '%s',
		'entry_point'   => '%s',
		'scanned_at'    => '%s',
	);

	/**
	 * Log a scan attempt.
	 *
	 * Accepted keys: event_id, ticket_id, scanned_value, outcome, method,
	 * operator_id, device, entry_point.
	 *
	 * @param array<string, mixed> $data Scan data.
	 * @return int Inserted row ID.
	 */
	public function log( array $data ): int {
		global $wpdb;

		$row = array(
			'event_id'      => (int) $data['event_id'],
			'ticket_id'     => (int) ( $data['ticket_id'] ?? 0 ),
			'scanned_value' => (string) ( $data['scanned_value'] ?? '' ),
			'outcome'       => (string) $data['outcome'],
			'method'        => in_array( (string) ( $data['method'] ?? 'manual' ), array( 'qr', 'manual' ), true )
				? (string) $data['method']
				: 'manual',
			'operator_id'   => (int) ( $data['operator_id'] ?? 0 ),
			'device'        => (string) ( $data['device'] ?? '' ),
			'entry_point'   => (string) ( $data['entry_point'] ?? '' ),
			'scanned_at'    => current_time( 'mysql', true ),
		);

		$formats = array();

		foreach ( array_keys( $row ) as $column ) {
			$formats[] = self::COLUMNS[ $column ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( Event_Schema::checkins(), $row, $formats );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Read a single log row.
	 *
	 * @param int $id Log row ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Event_Schema::checkins();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Delete a log row (used when a check-in is undone).
	 *
	 * @param int $id Log row ID.
	 * @return void
	 */
	public function delete( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Event_Schema::checkins(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Query the scan history for an event.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function query( int $event_id, array $args = array() ): array {
		global $wpdb;

		$args     = wp_parse_args( $args, array( 'page' => 1, 'per_page' => 25 ) );
		$page     = max( 1, (int) $args['page'] );
		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );

		$checkins = Event_Schema::checkins();
		$tickets  = Event_Schema::tickets();
		$types    = Event_Schema::ticket_types();
		$guests   = Event_Schema::guests();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$checkins} WHERE event_id = %d", $event_id ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, t.ticket_number, tt.name AS ticket_type_name, g.name AS guest_name
				FROM {$checkins} c
				LEFT JOIN {$tickets} t ON t.id = c.ticket_id
				LEFT JOIN {$types} tt ON tt.id = t.ticket_type_id
				LEFT JOIN {$guests} g ON g.ticket_id = t.id
				WHERE c.event_id = %d
				ORDER BY c.scanned_at DESC, c.id DESC
				LIMIT %d OFFSET %d",
				$event_id,
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);

		return array(
			'items'    => array_map( array( $this, 'hydrate' ), (array) $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Scanner sessions derived from the log: contiguous runs of scans by the
	 * same operator, device and entry point with no more than a 30 minute gap.
	 *
	 * There is no explicit session start/stop action in the scanner UI, so
	 * sessions are computed from the log rather than tracked separately.
	 *
	 * @param int $event_id Event ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function sessions( int $event_id ): array {
		global $wpdb;

		$window_seconds = 30 * MINUTE_IN_SECONDS;
		$table          = Event_Schema::checkins();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_id = %d ORDER BY operator_id ASC, device ASC, entry_point ASC, scanned_at ASC",
				$event_id
			),
			ARRAY_A
		);

		$sessions = array();
		$current  = null;

		foreach ( (array) $rows as $row ) {
			$key = $row['operator_id'] . '|' . $row['device'] . '|' . $row['entry_point'];
			$ts  = (int) strtotime( (string) $row['scanned_at'] );

			if ( null === $current || $current['key'] !== $key || ( $ts - $current['last_ts'] ) > $window_seconds ) {
				if ( null !== $current ) {
					$sessions[] = $current;
				}

				$current = array(
					'key'         => $key,
					'operator_id' => (int) $row['operator_id'],
					'device'      => (string) $row['device'],
					'entry_point' => (string) $row['entry_point'],
					'scans'       => 0,
					'started_at'  => $row['scanned_at'],
					'ended_at'    => $row['scanned_at'],
					'last_ts'     => $ts,
					'first_id'    => (int) $row['id'],
				);
			}

			++$current['scans'];
			$current['last_ts']  = $ts;
			$current['ended_at'] = $row['scanned_at'];
		}

		if ( null !== $current ) {
			$sessions[] = $current;
		}

		$now = time();

		$shaped = array_map(
			function ( array $session ) use ( $event_id, $now, $window_seconds ): array {
				$active = ( $now - $session['last_ts'] ) <= $window_seconds;

				return array(
					'id'          => 'sess_' . $session['first_id'],
					'event_id'    => $event_id,
					'operator'    => $session['operator_id'] > 0 ? self::user_display_name( $session['operator_id'] ) : '',
					'device'      => $session['device'],
					'entry_point' => $session['entry_point'],
					'scans'       => $session['scans'],
					'started_at'  => $session['started_at'],
					'ended_at'    => $active ? null : $session['ended_at'],
				);
			},
			$sessions
		);

		return array_reverse( $shaped );
	}

	/**
	 * Shape a joined scan log row into the ScanRecord contract.
	 *
	 * @param array<string, mixed> $row Raw joined row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		$operator_id = (int) $row['operator_id'];

		return array(
			'id'               => (int) $row['id'],
			'event_id'         => (int) $row['event_id'],
			'ticket_number'    => '' !== (string) ( $row['ticket_number'] ?? '' ) ? (string) $row['ticket_number'] : (string) $row['scanned_value'],
			'guest_name'       => (string) ( $row['guest_name'] ?? '' ),
			'ticket_type_name' => (string) ( $row['ticket_type_name'] ?? '' ),
			'outcome'          => (string) $row['outcome'],
			'method'           => (string) $row['method'],
			'operator'         => $operator_id > 0 ? self::user_display_name( $operator_id ) : '',
			'device'           => (string) $row['device'],
			'entry_point'      => (string) $row['entry_point'],
			'scanned_at'       => (string) $row['scanned_at'],
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

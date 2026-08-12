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
			$attempt_row                   = $row;
			$attempt_row['ticket_number']  = self::generate_ticket_number( (int) $data['event_id'] );
			$attempt_row['qr_token']       = self::generate_qr_token();

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
	 * Mark a ticket as checked in.
	 *
	 * @param int $id          Ticket ID.
	 * @param int $operator_id User performing the check-in, 0 when unknown.
	 * @return array<string, mixed>|null
	 */
	public function mark_checked_in( int $id, int $operator_id ): ?array {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Event_Schema::tickets(),
			array(
				'checked_in'    => 1,
				'checked_in_at' => $now,
				'checked_in_by' => $operator_id,
				'updated_at'    => $now,
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%d', '%s' ),
			array( '%d' )
		);

		return $this->find( $id );
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
	 * A random, hard to guess ticket number.
	 *
	 * @param int $event_id Event ID.
	 * @return string
	 */
	private static function generate_ticket_number( int $event_id ): string {
		return sprintf( 'EVT%d-%s', $event_id, strtoupper( wp_generate_password( 8, false, false ) ) );
	}

	/**
	 * A cryptographically random QR/check-in token.
	 *
	 * @return string
	 */
	private static function generate_qr_token(): string {
		return bin2hex( random_bytes( 20 ) );
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

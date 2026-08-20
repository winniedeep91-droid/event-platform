<?php
/**
 * Orchestration layer for ticket types, guests, the scanner and reports.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

use EventOS\Activity_Log;
use EventOS\Job_Queue;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Ticketing_Controller talks only to this class, which enforces the
 * business rules (capacity, ownership, valid transitions) on top of the
 * repositories and logs every mutating action.
 */
final class Ticketing_Service {

	private Ticket_Type_Repository $ticket_types;
	private Ticket_Repository $tickets;
	private Guest_Repository $guests;
	private Checkin_Repository $checkins;
	private Ticket_Order_Resolver $order_resolver;
	private Event_Report_Builder $report_builder;

	/**
	 * Constructor.
	 *
	 * @param Ticket_Type_Repository $ticket_types   Ticket type repository.
	 * @param Ticket_Repository      $tickets        Ticket repository.
	 * @param Guest_Repository       $guests         Guest repository.
	 * @param Checkin_Repository     $checkins       Check-in log repository.
	 * @param Ticket_Order_Resolver  $order_resolver Order resolver.
	 * @param Event_Report_Builder   $report_builder Report builder.
	 */
	public function __construct(
		Ticket_Type_Repository $ticket_types,
		Ticket_Repository $tickets,
		Guest_Repository $guests,
		Checkin_Repository $checkins,
		Ticket_Order_Resolver $order_resolver,
		Event_Report_Builder $report_builder
	) {
		$this->ticket_types   = $ticket_types;
		$this->tickets        = $tickets;
		$this->guests         = $guests;
		$this->checkins       = $checkins;
		$this->order_resolver = $order_resolver;
		$this->report_builder = $report_builder;
	}

	/**
	 * Ticket type repository accessor.
	 *
	 * @return Ticket_Type_Repository
	 */
	public function ticket_types(): Ticket_Type_Repository {
		return $this->ticket_types;
	}

	/* --------------------------------------------------------------------- */
	/* Ticket types                                                           */
	/* --------------------------------------------------------------------- */

	/**
	 * Create a ticket type.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $input    Field values.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_ticket_type( int $event_id, array $input ) {
		$result = $this->ticket_types->create( $event_id, $input );

		if ( ! is_wp_error( $result ) ) {
			$this->log( 'ticket_type_created', $event_id, 'ticket_type', (string) $result['id'], null, $result );
		}

		return $result;
	}

	/**
	 * Update a ticket type.
	 *
	 * @param int                  $event_id Event ID.
	 * @param int                  $id       Ticket type ID.
	 * @param array<string, mixed> $input    Field values.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_ticket_type( int $event_id, int $id, array $input ) {
		$before = $this->ticket_types->find( $id );

		if ( null === $before || (int) $before['event_id'] !== $event_id ) {
			return $this->not_found( __( 'That ticket type no longer exists.', 'eventos' ) );
		}

		$result = $this->ticket_types->update( $id, $input );

		if ( ! is_wp_error( $result ) ) {
			$this->log( 'ticket_type_updated', $event_id, 'ticket_type', (string) $id, $before, $result );

			// Capacity increasing (or an unlimited/unset capacity being
			// bounded upward) is itself a way inventory becomes available,
			// same as a cancellation or refund — give the waitlist a pass.
			// A no-op capacity change (or a decrease) still dispatches, but
			// {@see Waitlist_Service::process_ticket_type()} finds nothing
			// to promote, so this stays safe to call unconditionally rather
			// than trying to detect "did capacity actually increase" here.
			Job_Queue::dispatch( Waitlist_Service::JOB_PROCESS, array( 'ticket_type_id' => $id ) );
		}

		return $result;
	}

	/**
	 * Archive a ticket type.
	 *
	 * @param int $event_id Event ID.
	 * @param int $id       Ticket type ID.
	 * @return true|WP_Error
	 */
	public function delete_ticket_type( int $event_id, int $id ) {
		$before = $this->ticket_types->find( $id );

		if ( null === $before || (int) $before['event_id'] !== $event_id ) {
			return $this->not_found( __( 'That ticket type no longer exists.', 'eventos' ) );
		}

		$result = $this->ticket_types->archive( $id );

		if ( true === $result ) {
			$this->log( 'ticket_type_archived', $event_id, 'ticket_type', (string) $id, $before, null );
		}

		return $result;
	}

	/**
	 * Persist a new ticket type display order.
	 *
	 * @param int   $event_id Event ID.
	 * @param int[] $ids      Ticket type IDs.
	 * @return void
	 */
	public function reorder_ticket_types( int $event_id, array $ids ): void {
		$this->ticket_types->reorder( $event_id, array_map( 'intval', $ids ) );
	}

	/**
	 * Issue complimentary tickets outside WooCommerce checkout.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $payload  ticket_type_id, quantity, recipient_name, recipient_email, label, note.
	 * @return array<string, mixed>|WP_Error
	 */
	public function issue_complimentary( int $event_id, array $payload ) {
		$ticket_type_id  = (int) ( $payload['ticket_type_id'] ?? 0 );
		$quantity        = max( 1, min( 100, (int) ( $payload['quantity'] ?? 1 ) ) );
		$recipient_name  = trim( (string) ( $payload['recipient_name'] ?? '' ) );
		$recipient_email = trim( (string) ( $payload['recipient_email'] ?? '' ) );

		if ( '' === $recipient_name || ! is_email( $recipient_email ) ) {
			return new WP_Error(
				'eventos_invalid_complimentary',
				__( 'A recipient name and a valid email address are required.', 'eventos' ),
				array( 'status' => 400 )
			);
		}

		$type = $this->ticket_types->find( $ticket_type_id );

		if ( null === $type || (int) $type['event_id'] !== $event_id ) {
			return $this->not_found( __( 'That ticket type no longer exists.', 'eventos' ) );
		}

		if ( Ticket_Type_Status::would_exceed_capacity( $type['capacity'], (int) $type['sold'], $quantity ) ) {
			return new WP_Error(
				'eventos_capacity_exceeded',
				__( 'Not enough capacity remains on this ticket type.', 'eventos' ),
				array( 'status' => 400 )
			);
		}

		$label = trim( (string) ( $payload['label'] ?? '' ) );
		$note  = trim( (string) ( $payload['note'] ?? '' ) );
		$author = wp_get_current_user();
		$author_name = $author->exists() ? $author->display_name : __( 'EventOS', 'eventos' );

		$ticket_ids = array();

		for ( $i = 0; $i < $quantity; $i++ ) {
			$ticket = $this->tickets->issue(
				array(
					'event_id'         => $event_id,
					'ticket_type_id'   => $ticket_type_id,
					'is_complimentary' => true,
				)
			);

			$guest = $this->guests->create(
				array(
					'event_id'  => $event_id,
					'ticket_id' => $ticket['id'],
					'name'      => $recipient_name,
					'email'     => $recipient_email,
				)
			);

			$this->tickets->set_guest( (int) $ticket['id'], (int) $guest['id'] );

			if ( '' !== $label ) {
				$this->guests->update_tags( (int) $guest['id'], array( $label ) );
			}

			if ( '' !== $note ) {
				$this->guests->add_note( (int) $guest['id'], $note, $author_name );
			}

			$ticket_ids[] = (int) $ticket['id'];
		}

		$this->ticket_types->refresh_stock( $ticket_type_id );

		$this->log(
			'complimentary_issued',
			$event_id,
			'ticket_type',
			(string) $ticket_type_id,
			null,
			array(
				'quantity'        => $quantity,
				'recipient_email' => $recipient_email,
			)
		);

		return array(
			'issued'     => $quantity,
			'ticket_ids' => $ticket_ids,
		);
	}

	/* --------------------------------------------------------------------- */
	/* Orders                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Orders for an event.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query args.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function event_orders( int $event_id, array $args ): array {
		return $this->order_resolver->orders_for_event( $event_id, $args );
	}

	/* --------------------------------------------------------------------- */
	/* Guests                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Guests for an event.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query args.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function event_guests( int $event_id, array $args ): array {
		return $this->guests->query( $event_id, $args );
	}

	/**
	 * A single guest, scoped to an event.
	 *
	 * @param int $event_id Event ID.
	 * @param int $guest_id Guest ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function guest( int $event_id, int $guest_id ) {
		$guest = $this->guests->find( $guest_id );

		if ( null === $guest || (int) $guest['event_id'] !== $event_id ) {
			return $this->not_found( __( 'That guest no longer exists.', 'eventos' ) );
		}

		return $guest;
	}

	/**
	 * Check a guest in.
	 *
	 * @param int $event_id    Event ID.
	 * @param int $guest_id    Guest ID.
	 * @param int $operator_id Current user ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function checkin_guest( int $event_id, int $guest_id, int $operator_id ) {
		$guest = $this->guest( $event_id, $guest_id );

		if ( is_wp_error( $guest ) ) {
			return $guest;
		}

		$result = $this->tickets->mark_checked_in( (int) $guest['ticket_id'], $operator_id );
		$ticket = $result['ticket'];

		$this->checkins->log(
			array(
				'event_id'      => $event_id,
				'ticket_id'     => $guest['ticket_id'],
				'scanned_value' => (string) ( $ticket['ticket_number'] ?? '' ),
				'outcome'       => $result['claimed'] ? 'admitted' : 'already_scanned',
				'method'        => 'manual',
				'operator_id'   => $operator_id,
			)
		);

		$this->log( 'guest_checked_in', $event_id, 'guest', (string) $guest_id, null, null );

		return $this->guest( $event_id, $guest_id );
	}

	/**
	 * Reverse a guest's check-in.
	 *
	 * @param int $event_id Event ID.
	 * @param int $guest_id Guest ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function undo_guest_checkin( int $event_id, int $guest_id ) {
		$guest = $this->guest( $event_id, $guest_id );

		if ( is_wp_error( $guest ) ) {
			return $guest;
		}

		$this->tickets->undo_checkin( (int) $guest['ticket_id'] );
		$this->log( 'guest_checkin_undone', $event_id, 'guest', (string) $guest_id, null, null );

		return $this->guest( $event_id, $guest_id );
	}

	/**
	 * Append an internal note to a guest.
	 *
	 * @param int    $event_id Event ID.
	 * @param int    $guest_id Guest ID.
	 * @param string $note     Note content.
	 * @return array<string, mixed>|WP_Error
	 */
	public function add_guest_note( int $event_id, int $guest_id, string $note ) {
		$guest = $this->guest( $event_id, $guest_id );

		if ( is_wp_error( $guest ) ) {
			return $guest;
		}

		$note = trim( $note );

		if ( '' === $note ) {
			return new WP_Error( 'eventos_invalid_note', __( 'A note cannot be empty.', 'eventos' ), array( 'status' => 400 ) );
		}

		$author = wp_get_current_user();
		$entry  = $this->guests->add_note( $guest_id, $note, $author->exists() ? $author->display_name : __( 'EventOS', 'eventos' ) );

		return $entry ?? $this->not_found( __( 'That guest no longer exists.', 'eventos' ) );
	}

	/**
	 * Replace a guest's tags.
	 *
	 * @param int      $event_id Event ID.
	 * @param int      $guest_id Guest ID.
	 * @param string[] $tags     Tags.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_guest_tags( int $event_id, int $guest_id, array $tags ) {
		$guest = $this->guest( $event_id, $guest_id );

		if ( is_wp_error( $guest ) ) {
			return $guest;
		}

		$this->guests->update_tags( $guest_id, $tags );

		return $this->guest( $event_id, $guest_id );
	}

	/* --------------------------------------------------------------------- */
	/* Scanner                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Validate and, when admitted, check in a scanned or typed code.
	 *
	 * @param int    $event_id    Event ID.
	 * @param string $code        Scanned QR token or typed ticket number.
	 * @param string $method      qr|manual.
	 * @param int    $operator_id Current user ID.
	 * @return array<string, mixed>
	 */
	public function validate_ticket( int $event_id, string $code, string $method, int $operator_id ): array {
		$ticket = $this->tickets->find_by_code( $code );

		if ( null === $ticket || (int) $ticket['event_id'] !== $event_id ) {
			$this->log_scan( $event_id, null, $code, 'invalid', $method, $operator_id );

			return $this->scan_result( false, 'invalid', __( 'Ticket not found for this event.', 'eventos' ), '', '', $code, null );
		}

		$guest      = $this->guests->find_by_ticket( (int) $ticket['id'] );
		$guest_name = null !== $guest ? (string) $guest['name'] : '';
		$type       = $this->ticket_types->find( (int) $ticket['ticket_type_id'] );
		$type_name  = null !== $type ? (string) $type['name'] : '';

		if ( 'cancelled' === $ticket['status'] ) {
			$this->log_scan( $event_id, $ticket, $code, 'cancelled', $method, $operator_id );

			return $this->scan_result( false, 'cancelled', __( 'This ticket was cancelled or refunded.', 'eventos' ), $guest_name, $type_name, $ticket['ticket_number'], null );
		}

		// The update itself is conditioned on checked_in = 0, so two
		// near-simultaneous scans of the same ticket can never both admit —
		// only one request observes claimed => true.
		$result = $this->tickets->mark_checked_in( (int) $ticket['id'], $operator_id );

		if ( ! $result['claimed'] ) {
			$current = $result['ticket'] ?? $ticket;

			$this->log_scan( $event_id, $ticket, $code, 'already_scanned', $method, $operator_id );

			return $this->scan_result( false, 'already_scanned', __( 'This ticket has already been scanned.', 'eventos' ), $guest_name, $type_name, $ticket['ticket_number'], $current['checked_in_at'] ?? null );
		}

		$this->log_scan( $event_id, $ticket, $code, 'admitted', $method, $operator_id );

		return $this->scan_result( true, 'admitted', __( 'Admitted.', 'eventos' ), $guest_name, $type_name, $ticket['ticket_number'], null );
	}

	/**
	 * Reverse a scan, undoing the ticket's check-in when it admitted one.
	 *
	 * @param int $event_id Event ID.
	 * @param int $scan_id  Check-in log row ID.
	 * @return array<string, bool>|WP_Error
	 */
	public function undo_scan( int $event_id, int $scan_id ) {
		$log = $this->checkins->find( $scan_id );

		if ( null === $log || (int) $log['event_id'] !== $event_id ) {
			return $this->not_found( __( 'That scan record no longer exists.', 'eventos' ) );
		}

		if ( 'admitted' === $log['outcome'] && (int) $log['ticket_id'] > 0 ) {
			$this->tickets->undo_checkin( (int) $log['ticket_id'] );
		}

		$this->checkins->delete( $scan_id );
		$this->log( 'scan_undone', $event_id, 'checkin', (string) $scan_id, null, null );

		return array( 'reversed' => true );
	}

	/**
	 * Scan history for an event.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query args.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function scan_history( int $event_id, array $args ): array {
		return $this->checkins->query( $event_id, $args );
	}

	/**
	 * Scanner sessions derived from the check-in log.
	 *
	 * @param int $event_id Event ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function scanner_sessions( int $event_id ): array {
		return $this->checkins->sessions( $event_id );
	}

	/**
	 * Live check-in count for the scanner header.
	 *
	 * @param int $event_id Event ID.
	 * @return array{checked_in: int, total: int, capacity: int}
	 */
	public function live_count( int $event_id ): array {
		$counts    = $this->tickets->counts_for_event( $event_id );
		$capacity  = 0;
		$unlimited = false;

		foreach ( $this->ticket_types->for_event( $event_id ) as $type ) {
			if ( null === $type['capacity'] ) {
				$unlimited = true;
			} else {
				$capacity += (int) $type['capacity'];
			}
		}

		return array(
			'checked_in' => $counts['checked_in'],
			'total'      => $counts['total'],
			'capacity'   => $unlimited ? 0 : $capacity,
		);
	}

	/* --------------------------------------------------------------------- */
	/* Reports                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Full report payload for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return array<string, mixed>
	 */
	public function report( int $event_id ): array {
		return $this->report_builder->build( $event_id );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Log a scan attempt.
	 *
	 * @param int                        $event_id    Event ID.
	 * @param array<string, mixed>|null  $ticket      Ticket row, when resolved.
	 * @param string                     $code        Raw scanned/typed value.
	 * @param string                     $outcome     Scan outcome.
	 * @param string                     $method      qr|manual.
	 * @param int                        $operator_id Current user ID.
	 * @return void
	 */
	private function log_scan( int $event_id, ?array $ticket, string $code, string $outcome, string $method, int $operator_id ): void {
		$this->checkins->log(
			array(
				'event_id'      => $event_id,
				'ticket_id'     => null !== $ticket ? (int) $ticket['id'] : 0,
				'scanned_value' => $code,
				'outcome'       => $outcome,
				'method'        => $method,
				'operator_id'   => $operator_id,
			)
		);
	}

	/**
	 * Shape a scanner validation outcome.
	 *
	 * @param bool        $valid              Whether the ticket admitted.
	 * @param string      $outcome            Outcome slug.
	 * @param string      $message            Human readable message.
	 * @param string      $guest_name         Guest name, when resolved.
	 * @param string      $ticket_type_name   Ticket type name, when resolved.
	 * @param string      $ticket_number      Ticket number, or the raw scanned value.
	 * @param string|null $already_scanned_at Previous check-in time, when relevant.
	 * @return array<string, mixed>
	 */
	private function scan_result( bool $valid, string $outcome, string $message, string $guest_name, string $ticket_type_name, string $ticket_number, ?string $already_scanned_at ): array {
		return array(
			'valid'               => $valid,
			'outcome'             => $outcome,
			'message'             => $message,
			'ticket_number'       => $ticket_number,
			'guest_name'          => $guest_name,
			'ticket_type_name'    => $ticket_type_name,
			'already_scanned_at'  => $already_scanned_at,
		);
	}

	/**
	 * Standard 404.
	 *
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private function not_found( string $message ): WP_Error {
		return new WP_Error( 'eventos_not_found', $message, array( 'status' => 404 ) );
	}

	/**
	 * Record an activity log entry.
	 *
	 * @param string                     $action     Action slug.
	 * @param int                        $event_id   Event ID.
	 * @param string                     $entity_type Entity type.
	 * @param string                     $entity_id  Entity ID.
	 * @param array<string, mixed>|null  $before     Before value.
	 * @param array<string, mixed>|null  $after      After value.
	 * @return void
	 */
	private function log( string $action, int $event_id, string $entity_type, string $entity_id, ?array $before, ?array $after ): void {
		Activity_Log::log(
			array(
				'action'      => $action,
				'module'      => 'events',
				'entity_type' => $entity_type,
				'entity_id'   => $entity_id,
				'before'      => $before,
				'after'       => $after,
				'context'     => array( 'event_id' => $event_id ),
			)
		);
	}
}

<?php
/**
 * REST surface for ticket types, orders, guests, the scanner and reports.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use EventOS\Events\Event_Capabilities;
use EventOS\Events\Ticketing_Service;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request handlers backing the Event Workspace's Ticketing, Orders, Guests,
 * Scanner and Reports tabs. Every route is scoped under an event, exactly
 * like the routes {@see \EventOS\Events\Event_Controller} already declares.
 */
final class Ticketing_Controller {

	/**
	 * Service layer.
	 *
	 * @var Ticketing_Service
	 */
	private Ticketing_Service $service;

	/**
	 * Constructor.
	 *
	 * @param Ticketing_Service $service Service layer.
	 */
	public function __construct( Ticketing_Service $service ) {
		$this->service = $service;
	}

	/**
	 * Endpoint declarations for the REST registry.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function endpoints(): array {
		$view   = Event_Capabilities::VIEW_EVENTS;
		$manage = Event_Capabilities::MANAGE_EVENTS;
		$scan   = Event_Capabilities::CHECK_IN_GUESTS;

		$list_args = array(
			'search'   => array( 'type' => 'string' ),
			'status'   => array( 'type' => 'string' ),
			'page'     => array( 'type' => 'integer' ),
			'per_page' => array( 'type' => 'integer' ),
		);

		return array(
			// ── Ticket types ──────────────────────────────────────────────
			array(
				'route'      => '/events/(?P<id>\d+)/ticket-types',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'ticket_types' ),
				'summary'    => __( 'List ticket types for an event.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/ticket-types',
				'methods'    => 'POST',
				'capability' => $manage,
				'callback'   => array( $this, 'create_ticket_type' ),
				'log_action' => 'ticket_type_created',
				'summary'    => __( 'Create a ticket type.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/ticket-types/reorder',
				'methods'    => 'POST',
				'capability' => $manage,
				'callback'   => array( $this, 'reorder_ticket_types' ),
				'summary'    => __( 'Persist a new ticket type display order.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/ticket-types/(?P<type_id>\d+)',
				'methods'    => 'POST',
				'capability' => $manage,
				'callback'   => array( $this, 'update_ticket_type' ),
				'log_action' => 'ticket_type_updated',
				'summary'    => __( 'Update a ticket type.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/ticket-types/(?P<type_id>\d+)',
				'methods'    => 'DELETE',
				'capability' => $manage,
				'callback'   => array( $this, 'delete_ticket_type' ),
				'log_action' => 'ticket_type_archived',
				'summary'    => __( 'Archive a ticket type.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/complimentary',
				'methods'    => 'POST',
				'capability' => $manage,
				'callback'   => array( $this, 'issue_complimentary' ),
				'log_action' => 'complimentary_issued',
				'summary'    => __( 'Issue complimentary tickets outside checkout.', 'eventos' ),
			),

			// ── Orders ────────────────────────────────────────────────────
			array(
				'route'      => '/events/(?P<id>\d+)/orders',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'orders' ),
				'summary'    => __( 'WooCommerce orders containing tickets for this event.', 'eventos' ),
				'args'       => array_merge( $list_args, array( 'orderby' => array( 'type' => 'string' ), 'order' => array( 'type' => 'string' ) ) ),
			),

			// ── Guests ────────────────────────────────────────────────────
			array(
				'route'      => '/events/(?P<id>\d+)/guests',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'guests' ),
				'summary'    => __( 'List guests for an event.', 'eventos' ),
				'args'       => array_merge( $list_args, array( 'checked_in' => array( 'type' => 'string' ) ) ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/guests/(?P<guest_id>\d+)',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'guest' ),
				'summary'    => __( 'Read a single guest.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/guests/(?P<guest_id>\d+)/checkin',
				'methods'    => 'POST',
				'capability' => $scan,
				'callback'   => array( $this, 'checkin_guest' ),
				'log_action' => 'guest_checked_in',
				'summary'    => __( 'Check a guest in.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/guests/(?P<guest_id>\d+)/checkin',
				'methods'    => 'DELETE',
				'capability' => $scan,
				'callback'   => array( $this, 'undo_checkin' ),
				'log_action' => 'guest_checkin_undone',
				'summary'    => __( 'Reverse a guest check-in.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/guests/(?P<guest_id>\d+)/notes',
				'methods'    => 'POST',
				'capability' => $manage,
				'callback'   => array( $this, 'add_guest_note' ),
				'summary'    => __( 'Add an internal note to a guest.', 'eventos' ),
				'args'       => array( 'note' => array( 'type' => 'string', 'required' => true ) ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/guests/(?P<guest_id>\d+)/tags',
				'methods'    => 'POST',
				'capability' => $manage,
				'callback'   => array( $this, 'update_guest_tags' ),
				'summary'    => __( 'Replace a guest\'s tags.', 'eventos' ),
			),

			// ── Scanner ───────────────────────────────────────────────────
			array(
				'route'      => '/events/(?P<id>\d+)/scanner/sessions',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'scanner_sessions' ),
				'summary'    => __( 'Scanner sessions derived from the check-in log.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/scanner/history',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'scan_history' ),
				'summary'    => __( 'Scan history for an event.', 'eventos' ),
				'args'       => $list_args,
			),
			array(
				'route'      => '/events/(?P<id>\d+)/scanner/validate',
				'methods'    => 'POST',
				'capability' => $scan,
				'callback'   => array( $this, 'validate_ticket' ),
				'summary'    => __( 'Validate and check in a scanned or typed ticket code.', 'eventos' ),
				'args'       => array(
					'code'   => array( 'type' => 'string', 'required' => true ),
					'method' => array( 'type' => 'string' ),
				),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/scanner/history/(?P<scan_id>\d+)',
				'methods'    => 'DELETE',
				'capability' => $scan,
				'callback'   => array( $this, 'undo_scan' ),
				'log_action' => 'scan_undone',
				'summary'    => __( 'Reverse a scan.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/scanner/count',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'live_count' ),
				'summary'    => __( 'Live check-in count for an event.', 'eventos' ),
			),

			// ── Reports ───────────────────────────────────────────────────
			array(
				'route'      => '/events/(?P<id>\d+)/reports',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'report' ),
				'summary'    => __( 'Revenue, sales and attendance report for an event.', 'eventos' ),
			),
		);
	}

	/* --------------------------------------------------------------------- */
	/* Ticket types                                                           */
	/* --------------------------------------------------------------------- */

	/**
	 * List ticket types.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function ticket_types( WP_REST_Request $request ): array {
		return array( 'ticket_types' => $this->service->ticket_types()->for_event( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * Create a ticket type.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function create_ticket_type( WP_REST_Request $request ) {
		return $this->service->create_ticket_type( (int) $request->get_param( 'id' ), $this->payload( $request ) );
	}

	/**
	 * Update a ticket type.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update_ticket_type( WP_REST_Request $request ) {
		return $this->service->update_ticket_type(
			(int) $request->get_param( 'id' ),
			(int) $request->get_param( 'type_id' ),
			$this->payload( $request )
		);
	}

	/**
	 * Archive a ticket type.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function delete_ticket_type( WP_REST_Request $request ) {
		$result = $this->service->delete_ticket_type( (int) $request->get_param( 'id' ), (int) $request->get_param( 'type_id' ) );

		return is_wp_error( $result ) ? $result : array( 'deleted' => true );
	}

	/**
	 * Persist a new ticket type display order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, bool>
	 */
	public function reorder_ticket_types( WP_REST_Request $request ): array {
		$payload = $this->payload( $request );
		$ids     = array_map( 'intval', (array) ( $payload['ids'] ?? array() ) );

		$this->service->reorder_ticket_types( (int) $request->get_param( 'id' ), $ids );

		return array( 'reordered' => true );
	}

	/**
	 * Issue complimentary tickets.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function issue_complimentary( WP_REST_Request $request ) {
		return $this->service->issue_complimentary( (int) $request->get_param( 'id' ), $this->payload( $request ) );
	}

	/* --------------------------------------------------------------------- */
	/* Orders                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Orders containing tickets for an event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function orders( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->service->event_orders(
			(int) $request->get_param( 'id' ),
			array(
				'search'   => (string) $request->get_param( 'search' ),
				'status'   => (string) $request->get_param( 'status' ),
				'orderby'  => (string) $request->get_param( 'orderby' ),
				'order'    => (string) ( $request->get_param( 'order' ) ?: 'desc' ),
				'page'     => (int) ( $request->get_param( 'page' ) ?: 1 ),
				'per_page' => (int) ( $request->get_param( 'per_page' ) ?: 20 ),
			)
		);

		return Rest_Response::collection( $result['items'], $result['total'], $result['page'], $result['per_page'] );
	}

	/* --------------------------------------------------------------------- */
	/* Guests                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * List guests.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function guests( WP_REST_Request $request ): WP_REST_Response {
		$checked_in_param = $request->get_param( 'checked_in' );
		$checked_in       = null;

		if ( 'true' === $checked_in_param ) {
			$checked_in = true;
		} elseif ( 'false' === $checked_in_param ) {
			$checked_in = false;
		}

		$result = $this->service->event_guests(
			(int) $request->get_param( 'id' ),
			array(
				'search'     => (string) $request->get_param( 'search' ),
				'status'     => (string) $request->get_param( 'status' ),
				'checked_in' => $checked_in,
				'page'       => (int) ( $request->get_param( 'page' ) ?: 1 ),
				'per_page'   => (int) ( $request->get_param( 'per_page' ) ?: 20 ),
			)
		);

		return Rest_Response::collection( $result['items'], $result['total'], $result['page'], $result['per_page'] );
	}

	/**
	 * Read a single guest.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function guest( WP_REST_Request $request ) {
		return $this->service->guest( (int) $request->get_param( 'id' ), (int) $request->get_param( 'guest_id' ) );
	}

	/**
	 * Check a guest in.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function checkin_guest( WP_REST_Request $request ) {
		$result = $this->service->checkin_guest(
			(int) $request->get_param( 'id' ),
			(int) $request->get_param( 'guest_id' ),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'checked_in'    => true,
			'checked_in_at' => (string) ( $result['checked_in_at'] ?? current_time( 'mysql', true ) ),
		);
	}

	/**
	 * Reverse a guest's check-in.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function undo_checkin( WP_REST_Request $request ) {
		$result = $this->service->undo_guest_checkin( (int) $request->get_param( 'id' ), (int) $request->get_param( 'guest_id' ) );

		return is_wp_error( $result ) ? $result : array( 'checked_in' => false );
	}

	/**
	 * Add an internal note.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function add_guest_note( WP_REST_Request $request ) {
		$payload = $this->payload( $request );

		return $this->service->add_guest_note(
			(int) $request->get_param( 'id' ),
			(int) $request->get_param( 'guest_id' ),
			(string) ( $payload['note'] ?? '' )
		);
	}

	/**
	 * Replace a guest's tags.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update_guest_tags( WP_REST_Request $request ) {
		$payload = $this->payload( $request );
		$tags    = array_map( 'strval', (array) ( $payload['tags'] ?? array() ) );

		return $this->service->update_guest_tags( (int) $request->get_param( 'id' ), (int) $request->get_param( 'guest_id' ), $tags );
	}

	/* --------------------------------------------------------------------- */
	/* Scanner                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Scanner sessions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function scanner_sessions( WP_REST_Request $request ): array {
		return array( 'sessions' => $this->service->scanner_sessions( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * Scan history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function scan_history( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->service->scan_history(
			(int) $request->get_param( 'id' ),
			array(
				'page'     => (int) ( $request->get_param( 'page' ) ?: 1 ),
				'per_page' => (int) ( $request->get_param( 'per_page' ) ?: 25 ),
			)
		);

		return Rest_Response::collection( $result['items'], $result['total'], $result['page'], $result['per_page'] );
	}

	/**
	 * Validate and check in a scanned or typed ticket code.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function validate_ticket( WP_REST_Request $request ) {
		$payload = $this->payload( $request );
		$code    = trim( (string) ( $payload['code'] ?? $request->get_param( 'code' ) ?? '' ) );
		$method  = in_array( (string) ( $payload['method'] ?? 'manual' ), array( 'qr', 'manual' ), true )
			? (string) $payload['method']
			: 'manual';

		if ( '' === $code ) {
			return Rest_Response::error( 'invalid_code', __( 'A ticket code is required.', 'eventos' ), 400 );
		}

		return $this->service->validate_ticket( (int) $request->get_param( 'id' ), $code, $method, get_current_user_id() );
	}

	/**
	 * Reverse a scan.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function undo_scan( WP_REST_Request $request ) {
		return $this->service->undo_scan( (int) $request->get_param( 'id' ), (int) $request->get_param( 'scan_id' ) );
	}

	/**
	 * Live check-in count.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, int>
	 */
	public function live_count( WP_REST_Request $request ): array {
		return $this->service->live_count( (int) $request->get_param( 'id' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Reports                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Event report.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function report( WP_REST_Request $request ): array {
		return $this->service->report( (int) $request->get_param( 'id' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * JSON body of a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function payload( WP_REST_Request $request ): array {
		$body = $request->get_json_params();

		return is_array( $body ) ? $body : array();
	}
}

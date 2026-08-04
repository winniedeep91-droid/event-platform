<?php
/**
 * REST surface for the Events module.
 *
 * Every route is declared through the central Rest_Registry so authentication,
 * nonce verification, validation, enveloping and error handling stay uniform.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

use EventOS\Rest\Rest_Response;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request handlers for events, venues, artists, categories and tags.
 */
final class Event_Controller {

	/**
	 * Service layer.
	 *
	 * @var Event_Service
	 */
	private Event_Service $service;

	/**
	 * Constructor.
	 *
	 * @param Event_Service $service Service layer.
	 */
	public function __construct( Event_Service $service ) {
		$this->service = $service;
	}

	/**
	 * Endpoint declarations for the REST registry.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function endpoints(): array {
		$id_arg = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);

		return array(
			array(
				'route'      => '/events',
				'methods'    => 'GET',
				'capability' => Event_Capabilities::VIEW_EVENTS,
				'callback'   => array( $this, 'list_events' ),
				'summary'    => __( 'List events with search, filters, sorting and pagination.', 'eventos' ),
				'args'       => array(
					'search'      => array( 'type' => 'string' ),
					'status'      => array( 'type' => 'string' ),
					'visibility'  => array( 'type' => 'string' ),
					'venue_id'    => array( 'type' => 'integer' ),
					'artist_id'   => array( 'type' => 'integer' ),
					'category_id' => array( 'type' => 'integer' ),
					'tag_id'      => array( 'type' => 'integer' ),
					'from'        => array( 'type' => 'string' ),
					'to'          => array( 'type' => 'string' ),
					'orderby'     => array( 'type' => 'string' ),
					'order'       => array( 'type' => 'string' ),
					'page'        => array( 'type' => 'integer' ),
					'per_page'    => array( 'type' => 'integer' ),
				),
			),
			array(
				'route'      => '/events',
				'methods'    => 'POST',
				'capability' => Event_Capabilities::MANAGE_EVENTS,
				'callback'   => array( $this, 'create_event' ),
				'summary'    => __( 'Create an event.', 'eventos' ),
			),
			array(
				'route'      => '/events/options',
				'methods'    => 'GET',
				'capability' => Event_Capabilities::VIEW_EVENTS,
				'callback'   => array( $this, 'options' ),
				'summary'    => __( 'Reference data used by the event forms.', 'eventos' ),
			),
			array(
				'route'      => '/events/dashboard',
				'methods'    => 'GET',
				'capability' => Event_Capabilities::VIEW_EVENTS,
				'callback'   => array( $this, 'dashboard' ),
				'summary'    => __( 'Event dashboard metrics.', 'eventos' ),
			),
			array(
				'route'      => '/events/calendar',
				'methods'    => 'GET',
				'capability' => Event_Capabilities::VIEW_EVENTS,
				'callback'   => array( $this, 'calendar' ),
				'summary'    => __( 'Events falling inside a date range.', 'eventos' ),
				'args'       => array(
					'from' => array( 'type' => 'string' ),
					'to'   => array( 'type' => 'string' ),
				),
			),
			array(
				'route'      => '/events/(?P<id>\d+)',
				'methods'    => 'GET',
				'capability' => Event_Capabilities::VIEW_EVENTS,
				'callback'   => array( $this, 'get_event' ),
				'summary'    => __( 'Read a single event with all of its relations.', 'eventos' ),
				'args'       => $id_arg,
			),
			array(
				'route'      => '/events/(?P<id>\d+)',
				'methods'    => 'POST',
				'capability' => Event_Capabilities::MANAGE_EVENTS,
				'callback'   => array( $this, 'update_event' ),
				'summary'    => __( 'Update an event.', 'eventos' ),
				'args'       => $id_arg,
			),
			array(
				'route'      => '/events/(?P<id>\d+)',
				'methods'    => 'DELETE',
				'capability' => Event_Capabilities::DELETE_EVENTS,
				'callback'   => array( $this, 'delete_event' ),
				'summary'    => __( 'Delete an event and everything attached to it.', 'eventos' ),
				'args'       => $id_arg,
			),
			array(
				'route'      => '/events/(?P<id>\d+)/status',
				'methods'    => 'POST',
				'capability' => Event_Capabilities::PUBLISH_EVENTS,
				'callback'   => array( $this, 'transition_event' ),
				'summary'    => __( 'Move an event to another lifecycle status.', 'eventos' ),
				'args'       => $id_arg + array( 'status' => array( 'type' => 'string', 'required' => true ) ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/duplicate',
				'methods'    => 'POST',
				'capability' => Event_Capabilities::MANAGE_EVENTS,
				'callback'   => array( $this, 'duplicate_event' ),
				'summary'    => __( 'Duplicate an event as a new draft.', 'eventos' ),
				'args'       => $id_arg,
			),
			array(
				'route'      => '/events/(?P<id>\d+)/occurrences',
				'methods'    => 'POST',
				'capability' => Event_Capabilities::MANAGE_EVENTS,
				'callback'   => array( $this, 'generate_occurrences' ),
				'summary'    => __( 'Generate draft occurrences from a recurrence rule.', 'eventos' ),
				'args'       => $id_arg,
			),

			array(
				'route'      => '/venues',
				'methods'    => 'GET',
				'capability' => Event_Capabilities::VIEW_EVENTS,
				'callback'   => array( $this, 'list_venues' ),
				'summary'    => __( 'List venues.', 'eventos' ),
				'args'       => array(
					'search'   => array( 'type' => 'string' ),
					'city'     => array( 'type' => 'string' ),
					'country'  => array( 'type' => 'string' ),
					'orderby'  => array( 'type' => 'string' ),
					'order'    => array( 'type' => 'string' ),
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
				),
			),
			array(
				'route'      => '/venues',
				'methods'    => 'POST',
				'capability' => Event_Capabilities::MANAGE_VENUES,
				'callback'   => array( $this, 'create_venue' ),
				'summary'    => __( 'Create a venue.', 'eventos' ),
			),
			array(
				'route'      => '/venues/(?P<id>\d+)',
				'methods'    => 'GET',
				'capability' => Event_Capabilities::VIEW_EVENTS,
				'callback'   => array( $this, 'get_venue' ),
				'summary'    => __( 'Read a venue.', 'eventos' ),
				'args'       => $id_arg,
			),
			array(
				'route'      => '/venues/(?P<id>\d+)',
				'methods'    => 'POST',
				'capability' => Event_Capabilities::MANAGE_VENUES,
				'callback'   => array( $this, 'update_venue' ),
				'summary'    => __( 'Update a venue.', 'eventos' ),
				'args'       => $id_arg,
			),
			array(
				'route'      => '/venues/(?P<id>\d+)',
				'methods'    => 'DELETE',
				'capability' => Event_Capabilities::MANAGE_VENUES,
				'callback'   => array( $this, 'delete_venue' ),
				'summary'    => __( 'Delete a venue and detach it from its events.', 'eventos' ),
				'args'       => $id_arg,
			),

			array(
				'route'      => '/artists',
				'methods'    => 'GET',
				'capability' => Event_Capabilities::VIEW_EVENTS,
				'callback'   => array( $this, 'list_artists' ),
				'summary'    => __( 'List artists.', 'eventos' ),
				'args'       => array(
					'search'   => array( 'type' => 'string' ),
					'genre'    => array( 'type' => 'string' ),
					'country'  => array( 'type' => 'string' ),
					'orderby'  => array( 'type' => 'string' ),
					'order'    => array( 'type' => 'string' ),
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
				),
			),
			array(
				'route'      => '/artists',
				'methods'    => 'POST',
				'capability' => Event_Capabilities::MANAGE_ARTISTS,
				'callback'   => array( $this, 'create_artist' ),
				'summary'    => __( 'Create an artist.', 'eventos' ),
			),
			array(
				'route'      => '/artists/(?P<id>\d+)',
				'methods'    => 'GET',
				'capability' => Event_Capabilities::VIEW_EVENTS,
				'callback'   => array( $this, 'get_artist' ),
				'summary'    => __( 'Read an artist including their performance schedule.', 'eventos' ),
				'args'       => $id_arg,
			),
			array(
				'route'      => '/artists/(?P<id>\d+)',
				'methods'    => 'POST',
				'capability' => Event_Capabilities::MANAGE_ARTISTS,
				'callback'   => array( $this, 'update_artist' ),
				'summary'    => __( 'Update an artist.', 'eventos' ),
				'args'       => $id_arg,
			),
			array(
				'route'      => '/artists/(?P<id>\d+)',
				'methods'    => 'DELETE',
				'capability' => Event_Capabilities::MANAGE_ARTISTS,
				'callback'   => array( $this, 'delete_artist' ),
				'summary'    => __( 'Delete an artist.', 'eventos' ),
				'args'       => $id_arg,
			),

			array(
				'route'      => '/event-terms/(?P<taxonomy>category|tag)',
				'methods'    => 'GET',
				'capability' => Event_Capabilities::VIEW_EVENTS,
				'callback'   => array( $this, 'list_terms' ),
				'summary'    => __( 'List event categories or tags.', 'eventos' ),
			),
			array(
				'route'      => '/event-terms/(?P<taxonomy>category|tag)',
				'methods'    => 'POST',
				'capability' => Event_Capabilities::MANAGE_TERMS,
				'callback'   => array( $this, 'create_term' ),
				'summary'    => __( 'Create an event category or tag.', 'eventos' ),
			),
			array(
				'route'      => '/event-terms/(?P<taxonomy>category|tag)/(?P<id>\d+)',
				'methods'    => 'POST',
				'capability' => Event_Capabilities::MANAGE_TERMS,
				'callback'   => array( $this, 'update_term' ),
				'summary'    => __( 'Update an event category or tag.', 'eventos' ),
				'args'       => $id_arg,
			),
			array(
				'route'      => '/event-terms/(?P<taxonomy>category|tag)/(?P<id>\d+)',
				'methods'    => 'DELETE',
				'capability' => Event_Capabilities::MANAGE_TERMS,
				'callback'   => array( $this, 'delete_term' ),
				'summary'    => __( 'Delete an event category or tag.', 'eventos' ),
				'args'       => $id_arg,
			),
		);
	}

	/* --------------------------------------------------------------------- */
	/* Events                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * List events.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function list_events( WP_REST_Request $request ) {
		$result = $this->service->events()->query(
			array(
				'search'      => (string) $request->get_param( 'search' ),
				'status'      => (string) $request->get_param( 'status' ),
				'visibility'  => (string) $request->get_param( 'visibility' ),
				'venue_id'    => (int) $request->get_param( 'venue_id' ),
				'artist_id'   => (int) $request->get_param( 'artist_id' ),
				'category_id' => (int) $request->get_param( 'category_id' ),
				'tag_id'      => (int) $request->get_param( 'tag_id' ),
				'from'        => (string) $request->get_param( 'from' ),
				'to'          => (string) $request->get_param( 'to' ),
				'orderby'     => (string) ( $request->get_param( 'orderby' ) ?: 'starts_at' ),
				'order'       => (string) ( $request->get_param( 'order' ) ?: 'desc' ),
				'page'        => (int) ( $request->get_param( 'page' ) ?: 1 ),
				'per_page'    => (int) ( $request->get_param( 'per_page' ) ?: 20 ),
			)
		);

		return Rest_Response::collection( $result['items'], $result['total'], $result['page'], $result['per_page'] );
	}

	/**
	 * Read one event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function get_event( WP_REST_Request $request ) {
		$event = $this->service->events()->find( (int) $request->get_param( 'id' ) );

		return null === $event ? $this->missing() : $event;
	}

	/**
	 * Create an event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function create_event( WP_REST_Request $request ) {
		return $this->service->create_event( $this->payload( $request ) );
	}

	/**
	 * Update an event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update_event( WP_REST_Request $request ) {
		return $this->service->update_event( (int) $request->get_param( 'id' ), $this->payload( $request ) );
	}

	/**
	 * Transition an event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function transition_event( WP_REST_Request $request ) {
		return $this->service->transition_event(
			(int) $request->get_param( 'id' ),
			sanitize_key( (string) $request->get_param( 'status' ) )
		);
	}

	/**
	 * Duplicate an event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function duplicate_event( WP_REST_Request $request ) {
		return $this->service->duplicate_event( (int) $request->get_param( 'id' ) );
	}

	/**
	 * Generate recurring occurrences.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function generate_occurrences( WP_REST_Request $request ) {
		$payload = $this->payload( $request );

		return $this->service->generate_occurrences(
			(int) $request->get_param( 'id' ),
			(array) ( $payload['recurrence'] ?? $payload )
		);
	}

	/**
	 * Delete an event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function delete_event( WP_REST_Request $request ) {
		$deleted = $this->service->delete_event( (int) $request->get_param( 'id' ) );

		return is_wp_error( $deleted ) ? $deleted : array( 'deleted' => true );
	}

	/**
	 * Calendar feed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function calendar( WP_REST_Request $request ) {
		$from = (string) $request->get_param( 'from' );
		$to   = (string) $request->get_param( 'to' );

		$from = $from ? gmdate( 'Y-m-d H:i:s', (int) strtotime( $from ) ) : gmdate( 'Y-m-01 00:00:00' );
		$to   = $to ? gmdate( 'Y-m-d H:i:s', (int) strtotime( $to ) ) : gmdate( 'Y-m-t 23:59:59', (int) strtotime( $from ) );

		$result = $this->service->events()->query(
			array(
				'from'     => $from,
				'to'       => $to,
				'orderby'  => 'starts_at',
				'order'    => 'asc',
				'per_page' => 200,
			)
		);

		return array(
			'from'   => $from,
			'to'     => $to,
			'events' => $result['items'],
		);
	}

	/**
	 * Dashboard metrics.
	 *
	 * @return mixed
	 */
	public function dashboard() {
		return $this->service->dashboard();
	}

	/**
	 * Form reference data.
	 *
	 * @return mixed
	 */
	public function options() {
		return $this->service->form_options();
	}

	/* --------------------------------------------------------------------- */
	/* Venues                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * List venues.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function list_venues( WP_REST_Request $request ) {
		$result = $this->service->venues()->query(
			array(
				'search'   => (string) $request->get_param( 'search' ),
				'city'     => (string) $request->get_param( 'city' ),
				'country'  => (string) $request->get_param( 'country' ),
				'orderby'  => (string) ( $request->get_param( 'orderby' ) ?: 'name' ),
				'order'    => (string) ( $request->get_param( 'order' ) ?: 'asc' ),
				'page'     => (int) ( $request->get_param( 'page' ) ?: 1 ),
				'per_page' => (int) ( $request->get_param( 'per_page' ) ?: 20 ),
			)
		);

		return Rest_Response::collection( $result['items'], $result['total'], $result['page'], $result['per_page'] );
	}

	/**
	 * Read a venue.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function get_venue( WP_REST_Request $request ) {
		$id    = (int) $request->get_param( 'id' );
		$venue = $this->service->venues()->find( $id );

		if ( null === $venue ) {
			return $this->missing();
		}

		$venue['event_count'] = $this->service->venues()->event_count( $id );

		return $venue;
	}

	/**
	 * Create a venue.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function create_venue( WP_REST_Request $request ) {
		return $this->service->create_venue( $this->payload( $request ) );
	}

	/**
	 * Update a venue.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update_venue( WP_REST_Request $request ) {
		return $this->service->update_venue( (int) $request->get_param( 'id' ), $this->payload( $request ) );
	}

	/**
	 * Delete a venue.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function delete_venue( WP_REST_Request $request ) {
		$deleted = $this->service->delete_venue( (int) $request->get_param( 'id' ) );

		return is_wp_error( $deleted ) ? $deleted : array( 'deleted' => true );
	}

	/* --------------------------------------------------------------------- */
	/* Artists                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * List artists.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function list_artists( WP_REST_Request $request ) {
		$result = $this->service->artists()->query(
			array(
				'search'   => (string) $request->get_param( 'search' ),
				'genre'    => (string) $request->get_param( 'genre' ),
				'country'  => (string) $request->get_param( 'country' ),
				'orderby'  => (string) ( $request->get_param( 'orderby' ) ?: 'name' ),
				'order'    => (string) ( $request->get_param( 'order' ) ?: 'asc' ),
				'page'     => (int) ( $request->get_param( 'page' ) ?: 1 ),
				'per_page' => (int) ( $request->get_param( 'per_page' ) ?: 20 ),
			)
		);

		return Rest_Response::collection( $result['items'], $result['total'], $result['page'], $result['per_page'] );
	}

	/**
	 * Read an artist.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function get_artist( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$artist = $this->service->artists()->find( $id );

		if ( null === $artist ) {
			return $this->missing();
		}

		$artist['performances'] = $this->service->artists()->performances( $id );

		return $artist;
	}

	/**
	 * Create an artist.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function create_artist( WP_REST_Request $request ) {
		return $this->service->create_artist( $this->payload( $request ) );
	}

	/**
	 * Update an artist.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update_artist( WP_REST_Request $request ) {
		return $this->service->update_artist( (int) $request->get_param( 'id' ), $this->payload( $request ) );
	}

	/**
	 * Delete an artist.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function delete_artist( WP_REST_Request $request ) {
		$deleted = $this->service->delete_artist( (int) $request->get_param( 'id' ) );

		return is_wp_error( $deleted ) ? $deleted : array( 'deleted' => true );
	}

	/* --------------------------------------------------------------------- */
	/* Taxonomy                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * List categories or tags.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function list_terms( WP_REST_Request $request ) {
		$taxonomy = $this->taxonomy( $request );

		return array(
			'taxonomy' => $taxonomy,
			'items'    => $this->service->terms( $taxonomy )->all( (string) $request->get_param( 'search' ) ),
		);
	}

	/**
	 * Create a term.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function create_term( WP_REST_Request $request ) {
		return $this->service->create_term( $this->taxonomy( $request ), $this->payload( $request ) );
	}

	/**
	 * Update a term.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update_term( WP_REST_Request $request ) {
		return $this->service->update_term(
			$this->taxonomy( $request ),
			(int) $request->get_param( 'id' ),
			$this->payload( $request )
		);
	}

	/**
	 * Delete a term.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function delete_term( WP_REST_Request $request ) {
		$deleted = $this->service->delete_term( $this->taxonomy( $request ), (int) $request->get_param( 'id' ) );

		return is_wp_error( $deleted ) ? $deleted : array( 'deleted' => true );
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

	/**
	 * Taxonomy segment of the route.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private function taxonomy( WP_REST_Request $request ): string {
		return 'tag' === (string) $request->get_param( 'taxonomy' ) ? 'tag' : 'category';
	}

	/**
	 * Standard 404.
	 *
	 * @return WP_Error
	 */
	private function missing(): WP_Error {
		return Rest_Response::error( 'not_found', __( 'That record no longer exists.', 'eventos' ), 404 );
	}
}

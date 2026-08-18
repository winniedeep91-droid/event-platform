<?php
/**
 * Admin CRM REST surface for the permanent Person.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use EventOS\Crm\Crm_Capabilities;
use EventOS\Crm\Person_Backfill_Service;
use EventOS\Crm\Person_Consent_Repository;
use EventOS\Crm\Person_Note_Repository;
use EventOS\Crm\Person_Service;
use EventOS\Crm\Person_Tag_Repository;
use EventOS\Crm\Segment_Repository;
use RuntimeException;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every route here is gated by {@see Crm_Capabilities::MANAGE_CRM} — this is
 * an internal admin surface, not a customer-facing one (the customer portal
 * is a later phase). Reads go through {@see Person_Service}; writes to
 * tags/notes/consent/segments go directly through their own repositories,
 * matching the read-model/write-repository separation already established.
 */
final class Person_Controller {

	/**
	 * Read-model service.
	 *
	 * @var Person_Service
	 */
	private Person_Service $service;

	/**
	 * Tag repository.
	 *
	 * @var Person_Tag_Repository
	 */
	private Person_Tag_Repository $tags;

	/**
	 * Note repository.
	 *
	 * @var Person_Note_Repository
	 */
	private Person_Note_Repository $notes;

	/**
	 * Consent repository.
	 *
	 * @var Person_Consent_Repository
	 */
	private Person_Consent_Repository $consents;

	/**
	 * Segment repository.
	 *
	 * @var Segment_Repository
	 */
	private Segment_Repository $segments;

	/**
	 * Constructor.
	 *
	 * @param Person_Service            $service  Read-model service.
	 * @param Person_Tag_Repository     $tags     Tag repository.
	 * @param Person_Note_Repository    $notes    Note repository.
	 * @param Person_Consent_Repository $consents Consent repository.
	 * @param Segment_Repository        $segments Segment repository.
	 */
	public function __construct(
		Person_Service $service,
		Person_Tag_Repository $tags,
		Person_Note_Repository $notes,
		Person_Consent_Repository $consents,
		Segment_Repository $segments
	) {
		$this->service  = $service;
		$this->tags     = $tags;
		$this->notes    = $notes;
		$this->consents = $consents;
		$this->segments = $segments;
	}

	/**
	 * Endpoint declarations for the REST registry.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function endpoints(): array {
		$manage = Crm_Capabilities::MANAGE_CRM;

		return array(
			array(
				'route'      => '/crm/insights',
				'methods'    => 'GET',
				'capability' => $manage,
				'callback'   => array( $this, 'insights' ),
				'summary'    => __( 'Brand-wide relationship insights.', 'eventos' ),
			),
			array(
				'route'      => '/crm/persons',
				'methods'    => 'GET',
				'capability' => $manage,
				'callback'   => array( $this, 'persons' ),
				'summary'    => __( 'Search/list permanent Persons.', 'eventos' ),
			),
			array(
				'route'      => '/crm/persons/(?P<id>\d+)',
				'methods'    => 'GET',
				'capability' => $manage,
				'callback'   => array( $this, 'person' ),
				'summary'    => __( 'A permanent Person\'s full CRM profile.', 'eventos' ),
			),
			array(
				'route'      => '/crm/persons/(?P<id>\d+)/tags',
				'methods'    => 'GET',
				'capability' => $manage,
				'callback'   => array( $this, 'tags' ),
				'summary'    => __( 'A Person\'s tags.', 'eventos' ),
			),
			array(
				'route'      => '/crm/persons/(?P<id>\d+)/tags',
				'methods'    => 'POST',
				'capability' => $manage,
				'callback'   => array( $this, 'attach_tag' ),
				'log_action' => 'person_tag_attached',
				'summary'    => __( 'Attach a tag to a Person.', 'eventos' ),
			),
			array(
				'route'      => '/crm/persons/(?P<id>\d+)/tags/(?P<tag>[^/]+)',
				'methods'    => 'DELETE',
				'capability' => $manage,
				'callback'   => array( $this, 'detach_tag' ),
				'log_action' => 'person_tag_detached',
				'summary'    => __( 'Remove a tag from a Person.', 'eventos' ),
			),
			array(
				'route'      => '/crm/persons/(?P<id>\d+)/notes',
				'methods'    => 'GET',
				'capability' => $manage,
				'callback'   => array( $this, 'notes' ),
				'summary'    => __( 'A Person\'s internal staff notes.', 'eventos' ),
			),
			array(
				'route'      => '/crm/persons/(?P<id>\d+)/notes',
				'methods'    => 'POST',
				'capability' => $manage,
				'callback'   => array( $this, 'create_note' ),
				'log_action' => 'person_note_added',
				'summary'    => __( 'Add an internal note to a Person.', 'eventos' ),
			),
			array(
				'route'      => '/crm/persons/(?P<id>\d+)/consents',
				'methods'    => 'GET',
				'capability' => $manage,
				'callback'   => array( $this, 'consents' ),
				'summary'    => __( 'A Person\'s consent history.', 'eventos' ),
			),
			array(
				'route'      => '/crm/persons/(?P<id>\d+)/consents',
				'methods'    => 'POST',
				'capability' => $manage,
				'callback'   => array( $this, 'grant_consent' ),
				'log_action' => 'person_consent_granted',
				'summary'    => __( 'Grant consent for a channel.', 'eventos' ),
			),
			array(
				'route'      => '/crm/persons/(?P<id>\d+)/consents/(?P<channel>[^/]+)',
				'methods'    => 'DELETE',
				'capability' => $manage,
				'callback'   => array( $this, 'revoke_consent' ),
				'log_action' => 'person_consent_revoked',
				'summary'    => __( 'Revoke consent for a channel.', 'eventos' ),
			),
			array(
				'route'      => '/crm/segments',
				'methods'    => 'GET',
				'capability' => $manage,
				'callback'   => array( $this, 'segments' ),
				'summary'    => __( 'List CRM segments.', 'eventos' ),
			),
			array(
				'route'      => '/crm/segments',
				'methods'    => 'POST',
				'capability' => $manage,
				'callback'   => array( $this, 'create_segment' ),
				'log_action' => 'segment_created',
				'summary'    => __( 'Create a CRM segment.', 'eventos' ),
			),
			array(
				'route'      => '/crm/segments/(?P<segment_id>\d+)',
				'methods'    => 'POST',
				'capability' => $manage,
				'callback'   => array( $this, 'update_segment' ),
				'log_action' => 'segment_updated',
				'summary'    => __( 'Update a CRM segment.', 'eventos' ),
			),
			array(
				'route'      => '/crm/segments/(?P<segment_id>\d+)',
				'methods'    => 'DELETE',
				'capability' => $manage,
				'callback'   => array( $this, 'archive_segment' ),
				'log_action' => 'segment_archived',
				'summary'    => __( 'Archive a CRM segment.', 'eventos' ),
			),
			array(
				'route'      => '/crm/segments/(?P<segment_id>\d+)/members',
				'methods'    => 'GET',
				'capability' => $manage,
				'callback'   => array( $this, 'segment_members' ),
				'summary'    => __( 'Persons currently in a segment.', 'eventos' ),
			),
			array(
				'route'      => '/crm/segments/(?P<segment_id>\d+)/members',
				'methods'    => 'POST',
				'capability' => $manage,
				'callback'   => array( $this, 'attach_segment_member' ),
				'log_action' => 'segment_member_attached',
				'summary'    => __( 'Add a Person to a segment.', 'eventos' ),
			),
			array(
				'route'      => '/crm/segments/(?P<segment_id>\d+)/members/(?P<person_id>\d+)',
				'methods'    => 'DELETE',
				'capability' => $manage,
				'callback'   => array( $this, 'detach_segment_member' ),
				'log_action' => 'segment_member_detached',
				'summary'    => __( 'Remove a Person from a segment.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/crm/persons',
				'methods'    => 'GET',
				'capability' => $manage,
				'callback'   => array( $this, 'event_persons' ),
				'summary'    => __( 'Permanent Persons associated with an event.', 'eventos' ),
			),
			array(
				'route'      => '/crm/backfill/runs',
				'methods'    => 'GET',
				'capability' => $manage,
				'callback'   => array( $this, 'backfill_runs' ),
				'summary'    => __( 'Historical CRM backfill run history.', 'eventos' ),
			),
			array(
				'route'      => '/crm/backfill/start',
				'methods'    => 'POST',
				'capability' => $manage,
				'callback'   => array( $this, 'start_backfill' ),
				'summary'    => __( 'Start the historical WooCommerce/guest backfill into the permanent Person.', 'eventos' ),
			),
		);
	}

	/**
	 * Search/list Persons.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function persons( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->service->search(
			array(
				'q'              => (string) $request->get_param( 'q' ),
				'wc_customer_id' => (int) $request->get_param( 'wc_customer_id' ),
				'person_id'      => (int) $request->get_param( 'person_id' ),
				'page'           => (int) $request->get_param( 'page' ) ?: 1,
				'per_page'       => (int) $request->get_param( 'per_page' ) ?: 20,
			)
		);

		return Rest_Response::collection( $result['items'], $result['total'], $result['page'], $result['per_page'] );
	}

	/**
	 * Brand-wide relationship insights.
	 *
	 * @return array<string, mixed>
	 */
	public function insights(): array {
		return $this->service->insights();
	}

	/**
	 * A Person's full CRM profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 * @throws RuntimeException When the Person does not exist.
	 */
	public function person( WP_REST_Request $request ): array {
		$profile = $this->service->get_profile( (int) $request->get_param( 'id' ) );

		if ( null === $profile ) {
			throw new RuntimeException( __( 'Person not found.', 'eventos' ), 404 );
		}

		return $profile;
	}

	/**
	 * A Person's tags.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function tags( WP_REST_Request $request ): array {
		return array( 'tags' => $this->tags->for_person( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * Attach a tag.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function attach_tag( WP_REST_Request $request ): array {
		$tag = (string) ( $this->payload( $request )['tag'] ?? '' );

		return array( 'tag' => $this->tags->attach( (int) $request->get_param( 'id' ), $tag ) );
	}

	/**
	 * Detach a tag.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function detach_tag( WP_REST_Request $request ): array {
		$this->tags->detach( (int) $request->get_param( 'id' ), (string) $request->get_param( 'tag' ) );

		return array( 'deleted' => true );
	}

	/**
	 * A Person's internal notes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function notes( WP_REST_Request $request ): array {
		return array( 'notes' => $this->notes->for_person( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * Add an internal note.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function create_note( WP_REST_Request $request ): array {
		$body = (string) ( $this->payload( $request )['body'] ?? '' );

		return array( 'note' => $this->notes->create( (int) $request->get_param( 'id' ), $body, get_current_user_id() ) );
	}

	/**
	 * A Person's consent history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function consents( WP_REST_Request $request ): array {
		return array( 'consents' => $this->consents->for_person( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * Grant consent for a channel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function grant_consent( WP_REST_Request $request ): array {
		$payload = $this->payload( $request );
		$channel = (string) ( $payload['channel'] ?? '' );
		$source  = (string) ( $payload['source'] ?? '' );

		return array( 'consent' => $this->consents->grant( (int) $request->get_param( 'id' ), $channel, $source ) );
	}

	/**
	 * Revoke consent for a channel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function revoke_consent( WP_REST_Request $request ): array {
		$this->consents->revoke( (int) $request->get_param( 'id' ), (string) $request->get_param( 'channel' ) );

		return array( 'revoked' => true );
	}

	/**
	 * List segments.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function segments( WP_REST_Request $request ): array {
		$include_archived = (bool) $request->get_param( 'include_archived' );

		return array( 'segments' => $this->segments->all( $include_archived ) );
	}

	/**
	 * Create a segment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function create_segment( WP_REST_Request $request ) {
		return $this->segments->create( $this->payload( $request ) );
	}

	/**
	 * Update a segment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update_segment( WP_REST_Request $request ) {
		return $this->segments->update( (int) $request->get_param( 'segment_id' ), $this->payload( $request ) );
	}

	/**
	 * Archive a segment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function archive_segment( WP_REST_Request $request ) {
		return $this->segments->archive( (int) $request->get_param( 'segment_id' ) );
	}

	/**
	 * Persons in a segment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function segment_members( WP_REST_Request $request ): WP_REST_Response {
		$page     = (int) $request->get_param( 'page' ) ?: 1;
		$per_page = (int) $request->get_param( 'per_page' ) ?: 20;
		$result   = $this->segments->members( (int) $request->get_param( 'segment_id' ), $page, $per_page );

		return Rest_Response::collection( $result['items'], $result['total'], $page, $per_page );
	}

	/**
	 * Add a Person to a segment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function attach_segment_member( WP_REST_Request $request ): array {
		$payload   = $this->payload( $request );
		$person_id = (int) ( $payload['person_id'] ?? 0 );

		$this->segments->attach_person( (int) $request->get_param( 'segment_id' ), $person_id );

		return array( 'attached' => true );
	}

	/**
	 * Remove a Person from a segment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function detach_segment_member( WP_REST_Request $request ): array {
		$this->segments->detach_person( (int) $request->get_param( 'segment_id' ), (int) $request->get_param( 'person_id' ) );

		return array( 'detached' => true );
	}

	/**
	 * Permanent Persons associated with an event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function event_persons( WP_REST_Request $request ): WP_REST_Response {
		$page     = (int) $request->get_param( 'page' ) ?: 1;
		$per_page = (int) $request->get_param( 'per_page' ) ?: 20;
		$result   = $this->service->list_for_event( (int) $request->get_param( 'id' ), $page, $per_page );

		return Rest_Response::collection( $result['items'], $result['total'], $page, $per_page );
	}

	/**
	 * Historical CRM backfill run history, newest first — see
	 * {@see \EventOS\Crm\Person_Backfill_Service}.
	 *
	 * @return array<string, mixed>
	 */
	public function backfill_runs(): array {
		return array( 'runs' => Person_Backfill_Service::runs() );
	}

	/**
	 * Start a new historical CRM backfill run. Idempotent to call repeatedly
	 * (a fresh run always starts from offset 0 and every resolution goes
	 * through {@see \EventOS\Crm\Person_Resolver}, which never creates a
	 * duplicate Person for an identity signal already attached to one), and
	 * safe to run against live data — it only ever reads WooCommerce/guest
	 * rows and writes to the permanent Person tables, never to WooCommerce
	 * or event/ticket/guest data.
	 *
	 * @return array<string, mixed>
	 */
	public function start_backfill(): array {
		return Person_Backfill_Service::start();
	}

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

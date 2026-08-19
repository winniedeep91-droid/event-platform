<?php
/**
 * REST surface for discount campaigns, promo links and audiences.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use EventOS\Events\Event_Capabilities;
use EventOS\Events\Marketing_Service;
use EventOS\Marketing\Campaign_Send_Service;
use EventOS\Marketing\Marketing_Capabilities;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request handlers backing the Event Workspace's Marketing tab.
 */
final class Marketing_Controller {

	/**
	 * Service layer.
	 *
	 * @var Marketing_Service
	 */
	private Marketing_Service $service;

	/**
	 * Message/send orchestration layer.
	 *
	 * @var Campaign_Send_Service
	 */
	private Campaign_Send_Service $send_service;

	/**
	 * Constructor.
	 *
	 * @param Marketing_Service     $service      Service layer.
	 * @param Campaign_Send_Service $send_service Message/send orchestration layer.
	 */
	public function __construct( Marketing_Service $service, Campaign_Send_Service $send_service ) {
		$this->service      = $service;
		$this->send_service = $send_service;
	}

	/**
	 * Endpoint declarations for the REST registry.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function endpoints(): array {
		$view            = Event_Capabilities::VIEW_EVENTS;
		$manage          = Event_Capabilities::MANAGE_EVENTS;
		$manage_audience = Marketing_Capabilities::MANAGE_MARKETING;

		return array(
			array(
				'route'      => '/events/(?P<id>\d+)/marketing/campaigns',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'campaigns' ),
				'summary'    => __( 'List discount campaigns for an event.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/marketing/campaigns',
				'methods'    => 'POST',
				'capability' => $manage,
				'callback'   => array( $this, 'create_campaign' ),
				'log_action' => 'campaign_created',
				'summary'    => __( 'Create a discount campaign.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/marketing/campaigns/(?P<campaign_id>\d+)',
				'methods'    => 'POST',
				'capability' => $manage,
				'callback'   => array( $this, 'update_campaign' ),
				'log_action' => 'campaign_updated',
				'summary'    => __( 'Update a discount campaign.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/marketing/campaigns/(?P<campaign_id>\d+)',
				'methods'    => 'DELETE',
				'capability' => $manage,
				'callback'   => array( $this, 'delete_campaign' ),
				'log_action' => 'campaign_archived',
				'summary'    => __( 'Archive a discount campaign.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/marketing/links',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'links' ),
				'summary'    => __( 'List promo links for an event.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/marketing/links',
				'methods'    => 'POST',
				'capability' => $manage,
				'callback'   => array( $this, 'create_link' ),
				'log_action' => 'promo_link_created',
				'summary'    => __( 'Create a promo link.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/marketing/links/(?P<link_id>\d+)',
				'methods'    => 'DELETE',
				'capability' => $manage,
				'callback'   => array( $this, 'delete_link' ),
				'log_action' => 'promo_link_deleted',
				'summary'    => __( 'Delete a promo link.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/marketing/audiences',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'audiences' ),
				'summary'    => __( 'Audience segments for an event.', 'eventos' ),
			),
			array(
				'route'      => '/marketing/audiences',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'list_audiences' ),
				'summary'    => __( 'List Marketing audiences (optionally filtered by event).', 'eventos' ),
			),
			array(
				'route'      => '/marketing/audiences',
				'methods'    => 'POST',
				'capability' => $manage_audience,
				'callback'   => array( $this, 'create_audience' ),
				'log_action' => 'audience_created',
				'summary'    => __( 'Create a Marketing audience.', 'eventos' ),
			),
			array(
				'route'      => '/marketing/audiences/(?P<audience_id>\d+)',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'audience' ),
				'summary'    => __( 'Read a single Marketing audience.', 'eventos' ),
			),
			array(
				'route'      => '/marketing/audiences/(?P<audience_id>\d+)',
				'methods'    => 'POST',
				'capability' => $manage_audience,
				'callback'   => array( $this, 'update_audience' ),
				'log_action' => 'audience_updated',
				'summary'    => __( 'Update a Marketing audience.', 'eventos' ),
			),
			array(
				'route'      => '/marketing/audiences/(?P<audience_id>\d+)',
				'methods'    => 'DELETE',
				'capability' => $manage_audience,
				'callback'   => array( $this, 'archive_audience' ),
				'log_action' => 'audience_archived',
				'summary'    => __( 'Archive a Marketing audience.', 'eventos' ),
			),
			array(
				'route'      => '/marketing/audiences/(?P<audience_id>\d+)/count',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'audience_count' ),
				'summary'    => __( 'Live resolved size of a Marketing audience.', 'eventos' ),
			),
			array(
				'route'      => '/marketing/audiences/(?P<audience_id>\d+)/preview',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'audience_preview' ),
				'summary'    => __( 'Sample people a Marketing audience currently resolves to.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/marketing/campaigns/(?P<campaign_id>\d+)/message',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'get_message' ),
				'summary'    => __( 'Read a campaign\'s message.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/marketing/campaigns/(?P<campaign_id>\d+)/message',
				'methods'    => 'POST',
				'capability' => $manage_audience,
				'callback'   => array( $this, 'save_message' ),
				'log_action' => 'campaign_message_saved',
				'summary'    => __( 'Create or update a campaign\'s message.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/marketing/campaigns/(?P<campaign_id>\d+)/prepare',
				'methods'    => 'POST',
				'capability' => $manage_audience,
				'callback'   => array( $this, 'prepare_campaign' ),
				'log_action' => 'campaign_recipients_prepared',
				'summary'    => __( 'Resolve a campaign\'s audience into a permanent recipient snapshot.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/marketing/campaigns/(?P<campaign_id>\d+)/send',
				'methods'    => 'POST',
				'capability' => $manage_audience,
				'callback'   => array( $this, 'send_campaign' ),
				'log_action' => 'campaign_send_started',
				'summary'    => __( 'Start (or resume) sending a campaign to its prepared recipients.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/marketing/campaigns/(?P<campaign_id>\d+)/test-send',
				'methods'    => 'POST',
				'capability' => $manage_audience,
				'callback'   => array( $this, 'test_send_campaign' ),
				'summary'    => __( 'Send an immediate test e-mail without affecting the recipient snapshot.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/marketing/campaigns/(?P<campaign_id>\d+)/preview',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'preview_campaign_message' ),
				'summary'    => __( 'Rendered preview of a campaign\'s message.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/marketing/campaigns/(?P<campaign_id>\d+)/recipients',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'campaign_recipients' ),
				'summary'    => __( 'A campaign\'s recipient snapshot and delivery status.', 'eventos' ),
			),
			array(
				'route'      => '/marketing/unsubscribe',
				'methods'    => 'GET',
				'capability' => '',
				'callback'   => array( $this, 'unsubscribe' ),
				'summary'    => __( 'Public, token-verified marketing e-mail unsubscribe link.', 'eventos' ),
			),
		);
	}

	/**
	 * List campaigns.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function campaigns( WP_REST_Request $request ): array {
		return array( 'campaigns' => $this->service->campaigns_for_event( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * Create a campaign.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function create_campaign( WP_REST_Request $request ) {
		return $this->service->create_campaign( (int) $request->get_param( 'id' ), $this->payload( $request ) );
	}

	/**
	 * Update a campaign.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update_campaign( WP_REST_Request $request ) {
		return $this->service->update_campaign(
			(int) $request->get_param( 'id' ),
			(int) $request->get_param( 'campaign_id' ),
			$this->payload( $request )
		);
	}

	/**
	 * Archive a campaign.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function delete_campaign( WP_REST_Request $request ) {
		$result = $this->service->delete_campaign( (int) $request->get_param( 'id' ), (int) $request->get_param( 'campaign_id' ) );

		return is_wp_error( $result ) ? $result : array( 'deleted' => true );
	}

	/**
	 * List promo links.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function links( WP_REST_Request $request ): array {
		return array( 'links' => $this->service->links_for_event( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * Create a promo link.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function create_link( WP_REST_Request $request ) {
		return $this->service->create_link( (int) $request->get_param( 'id' ), $this->payload( $request ) );
	}

	/**
	 * Delete a promo link.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function delete_link( WP_REST_Request $request ) {
		$result = $this->service->delete_link( (int) $request->get_param( 'id' ), (int) $request->get_param( 'link_id' ) );

		return is_wp_error( $result ) ? $result : array( 'deleted' => true );
	}

	/**
	 * Audience segments.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function audiences( WP_REST_Request $request ): array {
		return array( 'audiences' => $this->service->audiences( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * List Marketing audiences, optionally filtered to one event (which also
	 * includes global audiences) or including archived ones.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function list_audiences( WP_REST_Request $request ): array {
		$event_id = $request->get_param( 'event_id' );

		return array(
			'audiences' => $this->service->list_audiences(
				array(
					'event_id'         => null !== $event_id && '' !== $event_id ? (int) $event_id : null,
					'include_archived' => (bool) $request->get_param( 'include_archived' ),
				)
			),
		);
	}

	/**
	 * Create a Marketing audience.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function create_audience( WP_REST_Request $request ) {
		return $this->service->create_audience( $this->payload( $request ) );
	}

	/**
	 * Read a single Marketing audience.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function audience( WP_REST_Request $request ) {
		return $this->service->find_audience( (int) $request->get_param( 'audience_id' ) );
	}

	/**
	 * Update a Marketing audience.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update_audience( WP_REST_Request $request ) {
		return $this->service->update_audience( (int) $request->get_param( 'audience_id' ), $this->payload( $request ) );
	}

	/**
	 * Archive a Marketing audience.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function archive_audience( WP_REST_Request $request ) {
		$result = $this->service->archive_audience( (int) $request->get_param( 'audience_id' ) );

		return is_wp_error( $result ) ? $result : array( 'archived' => true );
	}

	/**
	 * Live resolved size of a Marketing audience.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function audience_count( WP_REST_Request $request ) {
		return $this->service->audience_count( (int) $request->get_param( 'audience_id' ) );
	}

	/**
	 * Sample people a Marketing audience currently resolves to.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function audience_preview( WP_REST_Request $request ) {
		$limit = (int) $request->get_param( 'limit' ) ?: 5;

		return $this->service->audience_preview( (int) $request->get_param( 'audience_id' ), $limit );
	}

	/**
	 * Read a campaign's message.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function get_message( WP_REST_Request $request ) {
		$campaign = $this->owned_campaign( $request );

		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		$message = $this->send_service->get_message( (int) $campaign['id'] );

		return array( 'message' => $message );
	}

	/**
	 * Create or update a campaign's message.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function save_message( WP_REST_Request $request ) {
		$campaign = $this->owned_campaign( $request );

		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		return $this->send_service->save_message( (int) $campaign['id'], $this->payload( $request ) );
	}

	/**
	 * Resolve a campaign's audience into a permanent recipient snapshot.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function prepare_campaign( WP_REST_Request $request ) {
		$campaign = $this->owned_campaign( $request );

		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		return $this->send_service->prepare( (int) $campaign['id'] );
	}

	/**
	 * Start (or resume) sending a campaign.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function send_campaign( WP_REST_Request $request ) {
		$campaign = $this->owned_campaign( $request );

		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		return $this->send_service->send_now( (int) $campaign['id'] );
	}

	/**
	 * Send an immediate test e-mail.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function test_send_campaign( WP_REST_Request $request ) {
		$campaign = $this->owned_campaign( $request );

		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		$email = (string) ( $this->payload( $request )['email'] ?? '' );

		$result = $this->send_service->send_test( (int) $campaign['id'], $email );

		return is_wp_error( $result ) ? $result : array( 'sent' => true );
	}

	/**
	 * Rendered preview of a campaign's message.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function preview_campaign_message( WP_REST_Request $request ) {
		$campaign = $this->owned_campaign( $request );

		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		return $this->send_service->preview( (int) $campaign['id'] );
	}

	/**
	 * A campaign's recipient snapshot and delivery status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function campaign_recipients( WP_REST_Request $request ) {
		$campaign = $this->owned_campaign( $request );

		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = (int) $request->get_param( 'per_page' ) ?: 50;

		$result = $this->send_service->recipients( (int) $campaign['id'], $page, $per_page );

		return array(
			'recipients' => $result['items'],
			'total'      => $result['total'],
			'counts'     => $this->send_service->counts( (int) $campaign['id'] ),
		);
	}

	/**
	 * Public, token-verified unsubscribe endpoint. Never exposes whether a
	 * token was invalid vs. already used vs. never existed — a generic
	 * "invalid or expired" WP_Error covers every case, so the endpoint
	 * cannot be used to probe for valid tokens.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function unsubscribe( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );

		return $this->send_service->unsubscribe( $token );
	}

	/**
	 * The campaign identified by the request, only if it belongs to the
	 * event in the route — the shared ownership guard every message/send
	 * callback above uses.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function owned_campaign( WP_REST_Request $request ) {
		return $this->service->find_campaign( (int) $request->get_param( 'id' ), (int) $request->get_param( 'campaign_id' ) );
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

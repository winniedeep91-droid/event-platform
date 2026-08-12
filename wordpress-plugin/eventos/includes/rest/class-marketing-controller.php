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
	 * Constructor.
	 *
	 * @param Marketing_Service $service Service layer.
	 */
	public function __construct( Marketing_Service $service ) {
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

<?php
/**
 * Dashboard REST controller.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use EventOS\Activity_Log;
use EventOS\Branding;
use EventOS\Capabilities;
use EventOS\Settings;
use EventOS\System_Status;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aggregated payload powering the EventOS admin dashboard.
 */
final class Dashboard_Controller extends Abstract_Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/dashboard',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'can_view' ),
				),
			)
		);
	}

	/**
	 * Dashboard payload.
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		unset( $request );

		$user = wp_get_current_user();

		/**
		 * Filter the upcoming events shown on the dashboard.
		 *
		 * The Events module populates this list once it is installed.
		 *
		 * @param array $events Upcoming events.
		 */
		$upcoming = (array) apply_filters( 'eventos_dashboard_upcoming_events', array() );

		return rest_ensure_response(
			array(
				'system'          => System_Status::payload(),
				'branding'        => Branding::payload(),
				'general'         => Settings::get_group( 'general' ),
				'activity'        => Activity_Log::recent( 15 ),
				'upcoming_events' => array_values( $upcoming ),
				'current_user'    => array(
					'id'            => (int) $user->ID,
					'name'          => $user->display_name,
					'email'         => $user->user_email,
					'avatar'        => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
					'eventos_roles' => Capabilities::get_user_roles( (int) $user->ID ),
					'capabilities'  => array(
						'manage_settings' => current_user_can( Capabilities::MANAGE_SETTINGS ),
						'manage_team'     => current_user_can( Capabilities::MANAGE_TEAM ),
					),
				),
			)
		);
	}
}

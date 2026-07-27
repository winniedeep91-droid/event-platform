<?php
/**
 * Team REST controller.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use EventOS\Activity_Log;
use EventOS\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists WordPress users with their EventOS roles and manages role assignment.
 */
final class Team_Controller extends Abstract_Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/team/roles',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_roles' ),
					'permission_callback' => array( $this, 'can_view' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/team/members',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'can_manage_team' ),
					'args'                => array(
						'search'   => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/team/members/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'can_manage_team' ),
					'args'                => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Role and capability catalogue.
	 *
	 * @return WP_REST_Response
	 */
	public function get_roles( $request ): WP_REST_Response {
		unset( $request );

		$roles = array();

		foreach ( Capabilities::roles() as $slug => $definition ) {
			$roles[] = array(
				'slug'         => $slug,
				'label'        => $definition['label'],
				'capabilities' => array_values( $definition['capabilities'] ),
			);
		}

		$capabilities = array();

		foreach ( Capabilities::all_capabilities() as $key => $label ) {
			$capabilities[] = array(
				'key'   => $key,
				'label' => $label,
			);
		}

		return rest_ensure_response(
			array(
				'roles'        => $roles,
				'capabilities' => $capabilities,
			)
		);
	}

	/**
	 * List WordPress users with EventOS role assignments.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$search   = (string) $request->get_param( 'search' );

		$query = new WP_User_Query(
			array(
				'number'         => $per_page,
				'paged'          => $page,
				'search'         => $search ? '*' . $search . '*' : '',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'orderby'        => 'display_name',
				'order'          => 'ASC',
			)
		);

		$members = array();

		foreach ( $query->get_results() as $user ) {
			$members[] = $this->prepare_member( $user );
		}

		return rest_ensure_response(
			array(
				'members'  => $members,
				'total'    => (int) $query->get_total(),
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	/**
	 * Update the EventOS roles assigned to a user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$user_id = (int) $request['id'];
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_Error( 'eventos_user_not_found', __( 'User not found.', 'eventos' ), array( 'status' => 404 ) );
		}

		$body  = $request->get_json_params();
		$roles = isset( $body['roles'] ) && is_array( $body['roles'] ) ? $body['roles'] : array();
		$roles = array_map( 'sanitize_key', $roles );

		$current_owner_removal = in_array( 'owner', Capabilities::get_user_roles( $user_id ), true )
			&& ! in_array( 'owner', $roles, true );

		if ( $current_owner_removal && 1 === $this->count_owners() ) {
			return new WP_Error(
				'eventos_last_owner',
				__( 'At least one Owner must remain assigned.', 'eventos' ),
				array( 'status' => 400 )
			);
		}

		$stored = Capabilities::set_user_roles( $user_id, $roles );

		Activity_Log::record( 'team_roles_updated', array( 'roles' => $stored ), 'user', (string) $user_id );

		return rest_ensure_response( $this->prepare_member( get_userdata( $user_id ) ) );
	}

	/**
	 * Number of users holding the Owner role.
	 *
	 * @return int
	 */
	private function count_owners(): int {
		$query = new WP_User_Query(
			array(
				'meta_key'     => Capabilities::USER_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => '"owner"', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => 'LIKE',
				'fields'       => 'ID',
				'number'       => -1,
			)
		);

		return count( $query->get_results() );
	}

	/**
	 * Serialise a user for the API.
	 *
	 * @param \WP_User $user WordPress user.
	 * @return array<string, mixed>
	 */
	private function prepare_member( $user ): array {
		return array(
			'id'              => (int) $user->ID,
			'name'            => $user->display_name,
			'email'           => $user->user_email,
			'avatar'          => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
			'wp_roles'        => array_values( (array) $user->roles ),
			'eventos_roles'   => Capabilities::get_user_roles( (int) $user->ID ),
			'capabilities'    => Capabilities::get_user_capabilities( (int) $user->ID ),
			'registered_date' => $user->user_registered,
		);
	}
}

<?php
/**
 * Invitations REST controller.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use EventOS\Invitations;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD surface for team invitations.
 */
final class Invitations_Controller extends Abstract_Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/invitations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'can_manage_team' ),
					'args'                => array(
						'status' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'can_manage_team' ),
					'args'                => array(
						'email' => array(
							'type'     => 'string',
							'required' => true,
						),
						'roles' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array( 'type' => 'string' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/invitations/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
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
	 * List invitations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		$status = (string) $request->get_param( 'status' );

		return rest_ensure_response( array( 'invitations' => Invitations::all( $status ) ) );
	}

	/**
	 * Create and send an invitation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$email = (string) $request->get_param( 'email' );
		$roles = (array) $request->get_param( 'roles' );

		$invitation = Invitations::create( $email, array_map( 'sanitize_key', $roles ) );

		if ( is_wp_error( $invitation ) ) {
			return $invitation;
		}

		return new WP_REST_Response( $invitation, 201 );
	}

	/**
	 * Revoke an invitation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$id = (int) $request['id'];

		if ( ! Invitations::revoke( $id ) ) {
			return new WP_Error(
				'eventos_invitation_not_pending',
				__( 'Only pending invitations can be revoked.', 'eventos' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( array( 'revoked' => true ) );
	}
}

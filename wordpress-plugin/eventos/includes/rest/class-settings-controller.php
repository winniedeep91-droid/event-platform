<?php
/**
 * Settings REST controller.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use EventOS\Activity_Log;
use EventOS\Branding;
use EventOS\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the settings schema and values to the React admin UI.
 */
final class Settings_Controller extends Abstract_Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'can_view' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/settings/(?P<group>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => $this->group_args(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'can_manage_settings' ),
					'args'                => $this->group_args(),
				),
			)
		);
	}

	/**
	 * Route arguments for the group parameter.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function group_args(): array {
		return array(
			'group' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}

	/**
	 * Return the whole configuration payload.
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		unset( $request );

		return rest_ensure_response(
			array(
				'schema'   => $this->public_schema(),
				'values'   => Settings::get_all(),
				'branding' => Branding::payload(),
			)
		);
	}

	/**
	 * Return one settings group.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$group = (string) $request['group'];

		if ( ! isset( Settings::schema()[ $group ] ) ) {
			return new WP_Error( 'eventos_unknown_group', __( 'Unknown settings group.', 'eventos' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( Settings::get_group( $group ) );
	}

	/**
	 * Persist one settings group.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$group = (string) $request['group'];

		if ( ! isset( Settings::schema()[ $group ] ) ) {
			return new WP_Error( 'eventos_unknown_group', __( 'Unknown settings group.', 'eventos' ), array( 'status' => 404 ) );
		}

		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();

		$values = Settings::update_group( $group, $body );

		Activity_Log::record( 'settings_updated', array( 'group' => $group ), 'settings', $group );

		return rest_ensure_response(
			array(
				'values'   => $values,
				'branding' => Branding::payload(),
			)
		);
	}

	/**
	 * Schema serialised for the admin UI.
	 *
	 * @return array<string, mixed>
	 */
	private function public_schema(): array {
		$schema = array();

		foreach ( Settings::schema() as $group => $definition ) {
			$fields = array();

			foreach ( $definition['fields'] as $key => $field ) {
				$fields[] = array(
					'key'     => $key,
					'label'   => $field['label'],
					'type'    => $field['type'],
					'default' => $field['default'],
					'choices' => array_values( (array) $field['choices'] ),
				);
			}

			$schema[] = array(
				'group'       => $group,
				'label'       => $definition['label'],
				'description' => $definition['description'],
				'fields'      => $fields,
			);
		}

		return $schema;
	}
}

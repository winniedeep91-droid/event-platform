<?php
/**
 * Generic download endpoint for every registered exportable entity.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use EventOS\Export\Export_Service;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single REST route for every module's exports: modules only ever register
 * an entity with {@see \EventOS\Export\Export_Registry}, they never add their
 * own download route or format renderer.
 */
final class Export_Controller {

	/**
	 * Render and download a registered export.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function download( WP_REST_Request $request ) {
		$entity = (string) $request->get_param( 'entity' );
		$format = (string) $request->get_param( 'format' );

		$args = $request->get_query_params();
		unset( $args['entity'], $args['format'], $args['_wpnonce'], $args['rest_route'] );

		$result = Export_Service::export( $entity, $format, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = new WP_REST_Response( $result['content'] );
		$response->header( 'Content-Type', $result['mime'] . '; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="' . $result['filename'] . '"' );
		$response->header( 'X-EventOS-Raw-Body', '1' );

		return $response;
	}
}

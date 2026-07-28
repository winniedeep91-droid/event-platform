<?php
/**
 * Uniform REST response formatting.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use WP_Error;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every enveloped EventOS endpoint answers with the same shape:
 *
 * { "success": true, "data": mixed, "meta": { ... } }
 */
final class Rest_Response {

	/**
	 * Successful response.
	 *
	 * @param mixed                $data   Payload.
	 * @param array<string, mixed> $meta   Metadata such as pagination.
	 * @param int                  $status HTTP status code.
	 * @return WP_REST_Response
	 */
	public static function success( $data = null, array $meta = array(), int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
				'meta'    => (object) $meta,
			),
			$status
		);

		return $response;
	}

	/**
	 * Paginated collection response.
	 *
	 * @param array<int, mixed>    $items    Items on the current page.
	 * @param int                  $total    Total number of matching items.
	 * @param int                  $page     Current page, 1 based.
	 * @param int                  $per_page Page size.
	 * @param array<string, mixed> $meta     Extra metadata.
	 * @return WP_REST_Response
	 */
	public static function collection( array $items, int $total, int $page, int $per_page, array $meta = array() ): WP_REST_Response {
		$per_page = max( 1, $per_page );

		$response = self::success(
			array_values( $items ),
			array_merge(
				$meta,
				array(
					'total'       => $total,
					'page'        => max( 1, $page ),
					'per_page'    => $per_page,
					'total_pages' => (int) ceil( $total / $per_page ),
				)
			)
		);

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * Error response.
	 *
	 * @param string               $code    Machine readable error code.
	 * @param string               $message Human readable message.
	 * @param int                  $status  HTTP status code.
	 * @param array<string, mixed> $data    Extra error data.
	 * @return WP_Error
	 */
	public static function error( string $code, string $message, int $status = 400, array $data = array() ): WP_Error {
		return new WP_Error(
			0 === strpos( $code, 'eventos_' ) ? $code : 'eventos_' . $code,
			$message,
			array_merge( $data, array( 'status' => $status ) )
		);
	}
}

<?php
/**
 * REST controller for the shared platform infrastructure screens.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use EventOS\Activity_Log;
use EventOS\Branding;
use EventOS\Job_Queue;
use EventOS\Notifications;
use EventOS\Platform\Diagnostics;
use EventOS\Platform\Sync_Registry;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves activity, audit, notification, synchronisation and diagnostics data.
 *
 * Every callback is static and declared through {@see Rest_Registry}, exactly
 * like the other infrastructure endpoints, so the documentation controller
 * keeps describing the whole surface automatically.
 */
final class Platform_Controller {

	/**
	 * Paginated activity log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function activity( WP_REST_Request $request ): WP_REST_Response {
		$result = Activity_Log::query( self::activity_args( $request ) );

		return Rest_Response::collection(
			$result['items'],
			$result['total'],
			$result['page'],
			$result['per_page']
		);
	}

	/**
	 * Audit trail: activity entries that carry before/after values.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function audit( WP_REST_Request $request ): WP_REST_Response {
		$args             = self::activity_args( $request );
		$page             = (int) $args['page'];
		$per_page         = (int) $args['per_page'];
		$args['page']     = 1;
		$args['per_page'] = 200;

		$result   = Activity_Log::query( $args );
		$filtered = array_values(
			array_filter(
				$result['items'],
				static function ( array $entry ): bool {
					return null !== $entry['before'] || null !== $entry['after'];
				}
			)
		);

		return Rest_Response::collection(
			array_slice( $filtered, ( $page - 1 ) * $per_page, $per_page ),
			count( $filtered ),
			$page,
			$per_page
		);
	}

	/**
	 * Filter options for the activity and audit screens.
	 *
	 * @return array<string, mixed>
	 */
	public static function activity_filters(): array {
		return array(
			'modules'    => Activity_Log::modules(),
			'severities' => Activity_Log::severities(),
			'total'      => Activity_Log::count(),
		);
	}

	/**
	 * Delete activity entries older than the requested retention window.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, int>
	 */
	public static function purge_activity( WP_REST_Request $request ): array {
		$days = max( 1, (int) $request->get_param( 'days' ) );

		return array( 'deleted' => Activity_Log::purge_older_than( $days ) );
	}

	/**
	 * Notification centre listing.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function notifications( WP_REST_Request $request ): WP_REST_Response {
		$search   = strtolower( trim( (string) $request->get_param( 'search' ) ) );
		$type     = sanitize_key( (string) $request->get_param( 'type' ) );
		$module   = sanitize_key( (string) $request->get_param( 'module' ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ?: 20 ) );

		$items = array_values(
			array_filter(
				Notifications::for_user( 0, false ),
				static function ( array $notification ) use ( $search, $type, $module ): bool {
					if ( '' !== $type && (string) $notification['type'] !== $type ) {
						return false;
					}

					if ( '' !== $module && (string) ( $notification['module'] ?? '' ) !== $module ) {
						return false;
					}

					if ( '' === $search ) {
						return true;
					}

					$haystack = strtolower(
						(string) $notification['title'] . ' ' . (string) ( $notification['message'] ?? '' )
					);

					return false !== strpos( $haystack, $search );
				}
			)
		);

		return Rest_Response::collection(
			array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
			count( $items ),
			$page,
			$per_page,
			array( 'types' => Notifications::types() )
		);
	}

	/**
	 * Dismiss a notification for the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, bool>
	 */
	public static function dismiss_notification( WP_REST_Request $request ): array {
		return array( 'dismissed' => Notifications::dismiss( (string) $request->get_param( 'key' ) ) );
	}

	/**
	 * Delete a persistent notification.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, bool>
	 */
	public static function remove_notification( WP_REST_Request $request ): array {
		return array( 'removed' => Notifications::remove( (string) $request->get_param( 'key' ) ) );
	}

	/**
	 * Delete every persistent notification.
	 *
	 * @return array<string, bool>
	 */
	public static function clear_notifications(): array {
		Notifications::clear();

		return array( 'cleared' => true );
	}

	/**
	 * Synchronisation targets and aggregate counts.
	 *
	 * @return array<string, mixed>
	 */
	public static function sync_targets(): array {
		return array(
			'targets' => Sync_Registry::all(),
			'stats'   => Sync_Registry::stats(),
		);
	}

	/**
	 * Synchronisation run history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function sync_history( WP_REST_Request $request ): WP_REST_Response {
		$result = Sync_Registry::history(
			array(
				'search'   => (string) $request->get_param( 'search' ),
				'target'   => (string) $request->get_param( 'target' ),
				'status'   => (string) $request->get_param( 'status' ),
				'trigger'  => (string) $request->get_param( 'trigger' ),
				'page'     => (int) $request->get_param( 'page' ) ?: 1,
				'per_page' => (int) $request->get_param( 'per_page' ) ?: 20,
			)
		);

		return Rest_Response::collection(
			$result['items'],
			$result['total'],
			$result['page'],
			$result['per_page']
		);
	}

	/**
	 * Run a synchronisation target immediately.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function run_sync( WP_REST_Request $request ): array {
		return Sync_Registry::run( (string) $request->get_param( 'target' ), 'manual' );
	}

	/**
	 * Queue a synchronisation target for background execution.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, int>
	 */
	public static function queue_sync( WP_REST_Request $request ): array {
		return array( 'job_id' => Sync_Registry::queue( (string) $request->get_param( 'target' ) ) );
	}

	/**
	 * Enable or disable a synchronisation target.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function toggle_sync( WP_REST_Request $request ): array {
		$updated = Sync_Registry::set_enabled(
			(string) $request->get_param( 'target' ),
			(bool) $request->get_param( 'enabled' )
		);

		return array(
			'updated' => $updated,
			'targets' => Sync_Registry::all(),
		);
	}

	/**
	 * Clear the synchronisation history.
	 *
	 * @return array<string, bool>
	 */
	public static function clear_sync_history(): array {
		Sync_Registry::clear_history();

		return array( 'cleared' => true );
	}

	/**
	 * Full diagnostics report.
	 *
	 * @return array<string, mixed>
	 */
	public static function diagnostics(): array {
		return Diagnostics::report();
	}

	/**
	 * Background job history used by the diagnostics screen.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function jobs( WP_REST_Request $request ): WP_REST_Response {
		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$search   = strtolower( trim( (string) $request->get_param( 'search' ) ) );

		$result = Job_Queue::history(
			array(
				'status'   => (string) $request->get_param( 'status' ),
				'job_type' => (string) $request->get_param( 'job_type' ),
				'module'   => (string) $request->get_param( 'module' ),
				'page'     => $page,
				'per_page' => $per_page,
			)
		);

		$items = $result['items'];
		$total = $result['total'];

		if ( '' !== $search ) {
			$items = array_values(
				array_filter(
					$items,
					static function ( array $job ) use ( $search ): bool {
						$haystack = strtolower(
							(string) $job['type'] . ' ' . (string) $job['module'] . ' ' . (string) $job['last_error']
						);

						return false !== strpos( $haystack, $search );
					}
				)
			);
			$total = count( $items );
		}

		return Rest_Response::collection( $items, $total, $page, $per_page, array( 'stats' => Job_Queue::stats() ) );
	}

	/**
	 * Retry a failed job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, bool>
	 */
	public static function retry_job( WP_REST_Request $request ): array {
		return array( 'retried' => Job_Queue::retry( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * Cancel a pending job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, bool>
	 */
	public static function cancel_job( WP_REST_Request $request ): array {
		return array( 'cancelled' => Job_Queue::cancel( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * Resolved organisation branding payload.
	 *
	 * @return array<string, mixed>
	 */
	public static function branding(): array {
		return Branding::payload();
	}

	/**
	 * Normalise the shared activity query arguments.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function activity_args( WP_REST_Request $request ): array {
		return array(
			'search'      => (string) $request->get_param( 'search' ),
			'module'      => (string) $request->get_param( 'module' ),
			'action'      => (string) $request->get_param( 'action' ),
			'severity'    => (string) $request->get_param( 'severity' ),
			'entity_type' => (string) $request->get_param( 'entity_type' ),
			'entity_id'   => (string) $request->get_param( 'entity_id' ),
			'user_id'     => (int) $request->get_param( 'user_id' ),
			'since'       => (string) $request->get_param( 'since' ),
			'until'       => (string) $request->get_param( 'until' ),
			'order'       => (string) $request->get_param( 'order' ),
			'page'        => (int) $request->get_param( 'page' ) ?: 1,
			'per_page'    => (int) $request->get_param( 'per_page' ) ?: 20,
		);
	}
}

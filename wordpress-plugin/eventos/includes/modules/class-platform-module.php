<?php
/**
 * Shared platform infrastructure module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Modules;

use EventOS\Abstract_Module;
use EventOS\Capabilities;
use EventOS\Platform\Sync_Registry;
use EventOS\Rest\Platform_Controller;
use EventOS\Rest\Rest_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers activity, audit, notification, branding, synchronisation and
 * diagnostics infrastructure that every other module reuses.
 */
final class Platform_Module extends Abstract_Module {

	/**
	 * Module slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'platform';
	}

	/**
	 * Module name.
	 *
	 * @return string
	 */
	public function name(): string {
		return __( 'Platform', 'eventos' );
	}

	/**
	 * Module description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Activity logging, audit trails, notifications, branding, synchronisation and diagnostics.', 'eventos' );
	}

	/**
	 * Module dependencies.
	 *
	 * @return string[]
	 */
	public function dependencies(): array {
		return array( 'core' );
	}

	/**
	 * Admin screens contributed by the module.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function menu_items(): array {
		return array(
			array(
				'slug'       => 'eventos-activity',
				'title'      => __( 'Activity Log', 'eventos' ),
				'view'       => 'platform/activity',
				'capability' => Capabilities::VIEW_LOGS,
			),
			array(
				'slug'       => 'eventos-audit',
				'title'      => __( 'Audit Trail', 'eventos' ),
				'view'       => 'platform/audit',
				'capability' => Capabilities::VIEW_LOGS,
			),
			array(
				'slug'       => 'eventos-notifications',
				'title'      => __( 'Notifications', 'eventos' ),
				'view'       => 'platform/notifications',
				'capability' => Capabilities::VIEW_DASHBOARD,
			),
			array(
				'slug'       => 'eventos-branding',
				'title'      => __( 'Branding', 'eventos' ),
				'view'       => 'platform/branding',
				'capability' => Capabilities::MANAGE_SETTINGS,
			),
			array(
				'slug'       => 'eventos-sync',
				'title'      => __( 'Synchronisation', 'eventos' ),
				'view'       => 'platform/sync',
				'capability' => Capabilities::MANAGE_SETTINGS,
			),
			array(
				'slug'       => 'eventos-diagnostics',
				'title'      => __( 'Diagnostics', 'eventos' ),
				'view'       => 'platform/diagnostics',
				'capability' => Capabilities::MANAGE_SETTINGS,
			),
			array(
				'slug'       => 'eventos-settings',
				'title'      => __( 'Organisation Settings', 'eventos' ),
				'view'       => 'platform/settings',
				'capability' => Capabilities::MANAGE_SETTINGS,
			),
		);
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		Sync_Registry::bootstrap();

		add_action( 'eventos_register_rest_endpoints', array( $this, 'register_rest_endpoints' ) );
		add_filter( 'eventos_admin_pages', array( $this, 'register_admin_pages' ) );
	}

	/**
	 * Add the module's screens to the EventOS admin menu.
	 *
	 * @param array<string, array<string, mixed>> $pages Existing pages.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_admin_pages( array $pages ): array {
		foreach ( $this->menu_items() as $item ) {
			$pages[ (string) $item['slug'] ] = array(
				'title'      => (string) $item['title'],
				'view'       => (string) $item['view'],
				'capability' => (string) $item['capability'],
			);
		}

		return $pages;
	}

	/**
	 * Declare the module's REST endpoints.
	 *
	 * @return void
	 */
	public function register_rest_endpoints(): void {
		$logs     = Capabilities::VIEW_LOGS;
		$settings = Capabilities::MANAGE_SETTINGS;
		$view     = Capabilities::VIEW_DASHBOARD;

		Rest_Registry::register_many(
			array(
				array(
					'route'      => '/platform/activity',
					'methods'    => 'GET',
					'callback'   => array( Platform_Controller::class, 'activity' ),
					'capability' => $logs,
					'summary'    => __( 'List activity log entries.', 'eventos' ),
					'args'       => $this->activity_args(),
				),
				array(
					'route'      => '/platform/activity/filters',
					'methods'    => 'GET',
					'callback'   => array( Platform_Controller::class, 'activity_filters' ),
					'capability' => $logs,
					'summary'    => __( 'Filter options for the activity screens.', 'eventos' ),
				),
				array(
					'route'      => '/platform/activity/purge',
					'methods'    => 'POST',
					'callback'   => array( Platform_Controller::class, 'purge_activity' ),
					'capability' => $settings,
					'log_action' => 'activity_purged',
					'summary'    => __( 'Delete activity entries older than a retention window.', 'eventos' ),
					'args'       => array(
						'days' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
				array(
					'route'      => '/platform/audit',
					'methods'    => 'GET',
					'callback'   => array( Platform_Controller::class, 'audit' ),
					'capability' => $logs,
					'summary'    => __( 'List audit entries with before and after values.', 'eventos' ),
					'args'       => $this->activity_args(),
				),
				array(
					'route'      => '/platform/notifications',
					'methods'    => 'GET',
					'callback'   => array( Platform_Controller::class, 'notifications' ),
					'capability' => $view,
					'summary'    => __( 'List notifications for the current user.', 'eventos' ),
					'args'       => array(
						'search'   => array( 'type' => 'string' ),
						'type'     => array( 'type' => 'string' ),
						'module'   => array( 'type' => 'string' ),
						'page'     => array( 'type' => 'integer' ),
						'per_page' => array( 'type' => 'integer' ),
					),
				),
				array(
					'route'      => '/platform/notifications/dismiss',
					'methods'    => 'POST',
					'callback'   => array( Platform_Controller::class, 'dismiss_notification' ),
					'capability' => $view,
					'summary'    => __( 'Dismiss a notification for the current user.', 'eventos' ),
					'args'       => array(
						'key' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'route'      => '/platform/notifications/remove',
					'methods'    => 'POST',
					'callback'   => array( Platform_Controller::class, 'remove_notification' ),
					'capability' => $settings,
					'log_action' => 'notification_removed',
					'summary'    => __( 'Delete a persistent notification.', 'eventos' ),
					'args'       => array(
						'key' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'route'      => '/platform/notifications/clear',
					'methods'    => 'POST',
					'callback'   => array( Platform_Controller::class, 'clear_notifications' ),
					'capability' => $settings,
					'log_action' => 'notifications_cleared',
					'summary'    => __( 'Delete every persistent notification.', 'eventos' ),
				),
				array(
					'route'      => '/platform/branding',
					'methods'    => 'GET',
					'callback'   => array( Platform_Controller::class, 'branding' ),
					'capability' => $view,
					'summary'    => __( 'Resolved branding colours and logo URLs.', 'eventos' ),
				),
				array(
					'route'      => '/platform/sync',
					'methods'    => 'GET',
					'callback'   => array( Platform_Controller::class, 'sync_targets' ),
					'capability' => $settings,
					'summary'    => __( 'List synchronisation targets and their state.', 'eventos' ),
				),
				array(
					'route'      => '/platform/sync/history',
					'methods'    => 'GET',
					'callback'   => array( Platform_Controller::class, 'sync_history' ),
					'capability' => $settings,
					'summary'    => __( 'Synchronisation run history.', 'eventos' ),
					'args'       => array(
						'search'   => array( 'type' => 'string' ),
						'target'   => array( 'type' => 'string' ),
						'status'   => array( 'type' => 'string' ),
						'trigger'  => array( 'type' => 'string' ),
						'page'     => array( 'type' => 'integer' ),
						'per_page' => array( 'type' => 'integer' ),
					),
				),
				array(
					'route'      => '/platform/sync/run',
					'methods'    => 'POST',
					'callback'   => array( Platform_Controller::class, 'run_sync' ),
					'capability' => $settings,
					'log_action' => 'sync_run',
					'summary'    => __( 'Run a synchronisation target immediately.', 'eventos' ),
					'args'       => array(
						'target' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'route'      => '/platform/sync/queue',
					'methods'    => 'POST',
					'callback'   => array( Platform_Controller::class, 'queue_sync' ),
					'capability' => $settings,
					'log_action' => 'sync_queued',
					'summary'    => __( 'Queue a synchronisation target for background execution.', 'eventos' ),
					'args'       => array(
						'target' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'route'      => '/platform/sync/toggle',
					'methods'    => 'POST',
					'callback'   => array( Platform_Controller::class, 'toggle_sync' ),
					'capability' => $settings,
					'log_action' => 'sync_toggled',
					'summary'    => __( 'Enable or disable a synchronisation target.', 'eventos' ),
					'args'       => array(
						'target'  => array(
							'type'     => 'string',
							'required' => true,
						),
						'enabled' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
				array(
					'route'      => '/platform/sync/history/clear',
					'methods'    => 'POST',
					'callback'   => array( Platform_Controller::class, 'clear_sync_history' ),
					'capability' => $settings,
					'log_action' => 'sync_history_cleared',
					'summary'    => __( 'Clear the synchronisation run history.', 'eventos' ),
				),
				array(
					'route'      => '/platform/diagnostics',
					'methods'    => 'GET',
					'callback'   => array( Platform_Controller::class, 'diagnostics' ),
					'capability' => $settings,
					'summary'    => __( 'Environment, database, scheduling and configuration checks.', 'eventos' ),
				),
				array(
					'route'      => '/platform/jobs',
					'methods'    => 'GET',
					'callback'   => array( Platform_Controller::class, 'jobs' ),
					'capability' => $settings,
					'summary'    => __( 'Background job history.', 'eventos' ),
					'args'       => array(
						'search'   => array( 'type' => 'string' ),
						'status'   => array( 'type' => 'string' ),
						'job_type' => array( 'type' => 'string' ),
						'module'   => array( 'type' => 'string' ),
						'page'     => array( 'type' => 'integer' ),
						'per_page' => array( 'type' => 'integer' ),
					),
				),
				array(
					'route'      => '/platform/jobs/retry',
					'methods'    => 'POST',
					'callback'   => array( Platform_Controller::class, 'retry_job' ),
					'capability' => $settings,
					'log_action' => 'job_retried',
					'summary'    => __( 'Retry a failed background job.', 'eventos' ),
					'args'       => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
				array(
					'route'      => '/platform/jobs/cancel',
					'methods'    => 'POST',
					'callback'   => array( Platform_Controller::class, 'cancel_job' ),
					'capability' => $settings,
					'log_action' => 'job_cancelled',
					'summary'    => __( 'Cancel a pending background job.', 'eventos' ),
					'args'       => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			),
			$this->slug()
		);
	}

	/**
	 * Shared query arguments for the activity and audit endpoints.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function activity_args(): array {
		return array(
			'search'      => array( 'type' => 'string' ),
			'module'      => array( 'type' => 'string' ),
			'action'      => array( 'type' => 'string' ),
			'severity'    => array(
				'type' => 'string',
				'enum' => array_merge( array( '' ), \EventOS\Activity_Log::severities() ),
			),
			'entity_type' => array( 'type' => 'string' ),
			'entity_id'   => array( 'type' => 'string' ),
			'user_id'     => array( 'type' => 'integer' ),
			'since'       => array( 'type' => 'string' ),
			'until'       => array( 'type' => 'string' ),
			'order'       => array( 'type' => 'string' ),
			'page'        => array( 'type' => 'integer' ),
			'per_page'    => array( 'type' => 'integer' ),
		);
	}
}

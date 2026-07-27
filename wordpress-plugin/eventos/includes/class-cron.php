<?php
/**
 * Scheduled maintenance tasks.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-Cron integration.
 */
final class Cron {

	/**
	 * Daily maintenance hook name.
	 */
	public const DAILY_HOOK = 'eventos_daily_maintenance';

	/**
	 * Activity log retention in days.
	 */
	public const LOG_RETENTION_DAYS = 90;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::DAILY_HOOK, array( __CLASS__, 'run_daily' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
	}

	/**
	 * Ensure the daily event is scheduled.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Remove scheduled events.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::DAILY_HOOK );
	}

	/**
	 * Whether the daily event is scheduled.
	 *
	 * @return bool
	 */
	public static function is_scheduled(): bool {
		return (bool) wp_next_scheduled( self::DAILY_HOOK );
	}

	/**
	 * Daily maintenance: expire invitations, prune logs, refresh storage usage.
	 *
	 * @return void
	 */
	public static function run_daily(): void {
		Invitations::expire_stale();
		Activity_Log::purge_older_than( self::LOG_RETENTION_DAYS );
		delete_transient( 'eventos_storage_usage' );
		System_Status::storage();

		/**
		 * Fires during EventOS daily maintenance so modules can add their own tasks.
		 */
		do_action( 'eventos_daily_maintenance_ran' );
	}
}

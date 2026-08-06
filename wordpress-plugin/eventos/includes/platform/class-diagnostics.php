<?php
/**
 * Diagnostics framework shared by every EventOS module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Platform;

use EventOS\Activity_Log;
use EventOS\Cron;
use EventOS\Installer;
use EventOS\Job_Queue;
use EventOS\Notifications;
use EventOS\Settings;
use EventOS\System_Status;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects environment, database, scheduling and configuration checks.
 *
 * Modules contribute their own checks through the `eventos_diagnostics_checks`
 * filter, so every future module can surface health information without a new
 * screen.
 */
final class Diagnostics {

	/**
	 * Check severities.
	 */
	public const STATUS_PASS = 'pass';
	public const STATUS_WARN = 'warn';
	public const STATUS_FAIL = 'fail';

	/**
	 * Build the full diagnostics report.
	 *
	 * @return array<string, mixed>
	 */
	public static function report(): array {
		$checks = array_merge(
			self::environment_checks(),
			self::database_checks(),
			self::scheduling_checks(),
			self::configuration_checks()
		);

		/**
		 * Filter the diagnostics checks.
		 *
		 * @param array<int, array<string, mixed>> $checks Collected checks.
		 */
		$checks = (array) apply_filters( 'eventos_diagnostics_checks', $checks );
		$checks = array_values( array_filter( $checks, 'is_array' ) );

		$summary = array(
			self::STATUS_PASS => 0,
			self::STATUS_WARN => 0,
			self::STATUS_FAIL => 0,
		);

		foreach ( $checks as $index => $check ) {
			$check = wp_parse_args(
				$check,
				array(
					'id'          => 'check_' . $index,
					'label'       => '',
					'category'    => 'general',
					'status'      => self::STATUS_PASS,
					'value'       => '',
					'description' => '',
					'hint'        => '',
				)
			);

			if ( ! in_array( $check['status'], array( self::STATUS_PASS, self::STATUS_WARN, self::STATUS_FAIL ), true ) ) {
				$check['status'] = self::STATUS_PASS;
			}

			$checks[ $index ]              = $check;
			$summary[ $check['status'] ] += 1;
		}

		return array(
			'generated_at' => current_time( 'mysql', true ),
			'healthy'      => 0 === $summary[ self::STATUS_FAIL ],
			'summary'      => $summary,
			'categories'   => self::categories(),
			'checks'       => $checks,
			'system'       => System_Status::payload(),
			'jobs'         => Job_Queue::stats(),
			'sync'         => Sync_Registry::stats(),
		);
	}

	/**
	 * Known check categories.
	 *
	 * @return array<int, array{slug: string, label: string}>
	 */
	public static function categories(): array {
		return array(
			array(
				'slug'  => 'environment',
				'label' => __( 'Environment', 'eventos' ),
			),
			array(
				'slug'  => 'database',
				'label' => __( 'Database', 'eventos' ),
			),
			array(
				'slug'  => 'scheduling',
				'label' => __( 'Scheduling', 'eventos' ),
			),
			array(
				'slug'  => 'configuration',
				'label' => __( 'Configuration', 'eventos' ),
			),
		);
	}

	/**
	 * Environment checks.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function environment_checks(): array {
		global $wp_version;

		$memory = self::memory_limit_bytes();

		return array(
			array(
				'id'          => 'php_version',
				'label'       => __( 'PHP version', 'eventos' ),
				'category'    => 'environment',
				'status'      => version_compare( PHP_VERSION, System_Status::MIN_PHP, '>=' ) ? self::STATUS_PASS : self::STATUS_FAIL,
				'value'       => PHP_VERSION,
				'description' => sprintf(
					/* translators: %s: minimum PHP version. */
					__( 'EventOS requires PHP %s or newer.', 'eventos' ),
					System_Status::MIN_PHP
				),
				'hint'        => __( 'Ask your host to upgrade PHP.', 'eventos' ),
			),
			array(
				'id'          => 'wordpress_version',
				'label'       => __( 'WordPress version', 'eventos' ),
				'category'    => 'environment',
				'status'      => version_compare( (string) $wp_version, System_Status::MIN_WP, '>=' ) ? self::STATUS_PASS : self::STATUS_FAIL,
				'value'       => (string) $wp_version,
				'description' => sprintf(
					/* translators: %s: minimum WordPress version. */
					__( 'EventOS requires WordPress %s or newer.', 'eventos' ),
					System_Status::MIN_WP
				),
			),
			array(
				'id'          => 'memory_limit',
				'label'       => __( 'PHP memory limit', 'eventos' ),
				'category'    => 'environment',
				'status'      => $memory >= 256 * MB_IN_BYTES ? self::STATUS_PASS : self::STATUS_WARN,
				'value'       => (string) ini_get( 'memory_limit' ),
				'description' => __( '256M or more is recommended for imports and PDF generation.', 'eventos' ),
			),
			array(
				'id'          => 'https',
				'label'       => __( 'HTTPS', 'eventos' ),
				'category'    => 'environment',
				'status'      => is_ssl() ? self::STATUS_PASS : self::STATUS_WARN,
				'value'       => is_ssl() ? __( 'Enabled', 'eventos' ) : __( 'Disabled', 'eventos' ),
				'description' => __( 'Ticket scanning and payment flows require a secure connection.', 'eventos' ),
			),
			array(
				'id'          => 'uploads_writable',
				'label'       => __( 'Uploads directory', 'eventos' ),
				'category'    => 'environment',
				'status'      => System_Status::uploads_writable() ? self::STATUS_PASS : self::STATUS_FAIL,
				'value'       => System_Status::uploads_writable() ? __( 'Writable', 'eventos' ) : __( 'Not writable', 'eventos' ),
				'description' => __( 'Exports, tickets and media uploads are written to the uploads directory.', 'eventos' ),
			),
		);
	}

	/**
	 * Database checks.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function database_checks(): array {
		global $wpdb;

		$tables  = array(
			Installer::invitations_table(),
			Installer::jobs_table(),
			Installer::activity_table(),
		);
		$missing = array();

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			if ( (string) $found !== $table ) {
				$missing[] = $table;
			}
		}

		$db_version = (string) get_option( Installer::DB_VERSION_OPTION, '' );

		return array(
			array(
				'id'          => 'core_tables',
				'label'       => __( 'Core tables', 'eventos' ),
				'category'    => 'database',
				'status'      => $missing ? self::STATUS_FAIL : self::STATUS_PASS,
				'value'       => $missing ? implode( ', ', $missing ) : __( 'Installed', 'eventos' ),
				'description' => __( 'Invitations, job queue and activity log storage.', 'eventos' ),
				'hint'        => $missing ? __( 'Deactivate and reactivate EventOS to reinstall the schema.', 'eventos' ) : '',
			),
			array(
				'id'          => 'schema_version',
				'label'       => __( 'Schema version', 'eventos' ),
				'category'    => 'database',
				'status'      => '' === $db_version ? self::STATUS_WARN : self::STATUS_PASS,
				'value'       => '' === $db_version ? __( 'Unknown', 'eventos' ) : $db_version,
				'description' => __( 'Version recorded by the installer after the last upgrade.', 'eventos' ),
			),
			array(
				'id'          => 'activity_volume',
				'label'       => __( 'Activity log size', 'eventos' ),
				'category'    => 'database',
				'status'      => Activity_Log::count() > 250000 ? self::STATUS_WARN : self::STATUS_PASS,
				'value'       => (string) Activity_Log::count(),
				'description' => __( 'Entries retained in the audit trail.', 'eventos' ),
				'hint'        => __( 'Purge old entries from the activity log screen when the table grows large.', 'eventos' ),
			),
		);
	}

	/**
	 * Scheduling and background processing checks.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function scheduling_checks(): array {
		$stats     = Job_Queue::stats();
		$scheduled = Cron::is_scheduled();
		$next      = wp_next_scheduled( Job_Queue::CRON_HOOK );

		return array(
			array(
				'id'          => 'cron',
				'label'       => __( 'WP-Cron', 'eventos' ),
				'category'    => 'scheduling',
				'status'      => $scheduled ? self::STATUS_PASS : self::STATUS_FAIL,
				'value'       => $scheduled ? __( 'Scheduled', 'eventos' ) : __( 'Not scheduled', 'eventos' ),
				'description' => __( 'Maintenance tasks depend on the EventOS cron event.', 'eventos' ),
			),
			array(
				'id'          => 'job_queue',
				'label'       => __( 'Job queue', 'eventos' ),
				'category'    => 'scheduling',
				'status'      => $next ? self::STATUS_PASS : self::STATUS_WARN,
				'value'       => $next ? gmdate( 'Y-m-d H:i:s', (int) $next ) : __( 'No run scheduled', 'eventos' ),
				'description' => __( 'Next scheduled drain of the background job queue.', 'eventos' ),
			),
			array(
				'id'          => 'failed_jobs',
				'label'       => __( 'Failed jobs', 'eventos' ),
				'category'    => 'scheduling',
				'status'      => ( $stats[ Job_Queue::STATUS_FAILED ] ?? 0 ) > 0 ? self::STATUS_WARN : self::STATUS_PASS,
				'value'       => (string) ( $stats[ Job_Queue::STATUS_FAILED ] ?? 0 ),
				'description' => __( 'Jobs that exhausted every retry attempt.', 'eventos' ),
			),
			array(
				'id'          => 'sync_failures',
				'label'       => __( 'Synchronisation failures', 'eventos' ),
				'category'    => 'scheduling',
				'status'      => ( Sync_Registry::stats()[ Sync_Registry::STATUS_FAILED ] ?? 0 ) > 0 ? self::STATUS_WARN : self::STATUS_PASS,
				'value'       => (string) ( Sync_Registry::stats()[ Sync_Registry::STATUS_FAILED ] ?? 0 ),
				'description' => __( 'Failed runs retained in the synchronisation history.', 'eventos' ),
			),
		);
	}

	/**
	 * Configuration checks.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function configuration_checks(): array {
		$general  = Settings::get_group( 'general' );
		$branding = Settings::get_group( 'branding' );

		$business = trim( (string) ( $general['business_name'] ?? '' ) );
		$logo     = (int) ( $branding['business_logo_id'] ?? 0 );

		return array(
			array(
				'id'          => 'business_name',
				'label'       => __( 'Organisation name', 'eventos' ),
				'category'    => 'configuration',
				'status'      => '' === $business ? self::STATUS_WARN : self::STATUS_PASS,
				'value'       => '' === $business ? __( 'Not set', 'eventos' ) : $business,
				'description' => __( 'Shown on the dashboard, emails and generated documents.', 'eventos' ),
			),
			array(
				'id'          => 'branding_logo',
				'label'       => __( 'Organisation logo', 'eventos' ),
				'category'    => 'configuration',
				'status'      => $logo ? self::STATUS_PASS : self::STATUS_WARN,
				'value'       => $logo ? __( 'Configured', 'eventos' ) : __( 'Missing', 'eventos' ),
				'description' => __( 'Used as the fallback for every branding slot.', 'eventos' ),
			),
			array(
				'id'          => 'notifications',
				'label'       => __( 'Open notifications', 'eventos' ),
				'category'    => 'configuration',
				'status'      => self::STATUS_PASS,
				'value'       => (string) count( Notifications::for_user( 0, false ) ),
				'description' => __( 'Persistent notifications awaiting attention.', 'eventos' ),
			),
		);
	}

	/**
	 * Memory limit in bytes.
	 *
	 * @return int
	 */
	private static function memory_limit_bytes(): int {
		try {
			return (int) wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		} catch ( Throwable $error ) {
			return 0;
		}
	}
}

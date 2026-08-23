<?php
/**
 * Background job framework built on WP-Cron.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Durable job queue with retries, history and failure logging.
 *
 * Modules register a handler once and then queue work with
 * {@see Job_Queue::dispatch()} or {@see Job_Queue::schedule()}; the queue owns
 * cron scheduling, locking, retries and status tracking.
 */
final class Job_Queue {

	/**
	 * Cron hook that drains the queue.
	 */
	public const CRON_HOOK = 'eventos_process_jobs';

	/**
	 * Cron schedule used for draining.
	 */
	public const CRON_SCHEDULE = 'eventos_every_minute';

	/**
	 * Lock transient name.
	 */
	private const LOCK = 'eventos_job_lock';

	/**
	 * Job statuses.
	 */
	public const STATUS_PENDING   = 'pending';
	public const STATUS_RUNNING   = 'running';
	public const STATUS_COMPLETE  = 'complete';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * Registered handlers keyed by job type.
	 *
	 * @var array<string, array{callback: callable, label: string, module: string, max_attempts: int, retry_delay: int}>
	 */
	private static array $handlers = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_due_jobs' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
		}

		/**
		 * Fires so modules can register their job handlers.
		 *
		 * @param string $queue Job queue class name.
		 */
		do_action( 'eventos_register_jobs', __CLASS__ );
	}

	/**
	 * Add the one minute schedule used by the queue.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Registered schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function register_schedule( array $schedules ): array {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (EventOS jobs)', 'eventos' ),
		);

		return $schedules;
	}

	/**
	 * Register a job handler.
	 *
	 * @param string               $type     Job type slug.
	 * @param callable             $callback Handler receiving the payload array and the job row.
	 * @param array<string, mixed> $args     label, module, max_attempts, retry_delay.
	 * @return void
	 */
	public static function register_handler( string $type, callable $callback, array $args = array() ): void {
		$type = sanitize_key( $type );

		if ( '' === $type ) {
			return;
		}

		$args = wp_parse_args(
			$args,
			array(
				'label'        => $type,
				'module'       => 'core',
				'max_attempts' => 3,
				'retry_delay'  => 5 * MINUTE_IN_SECONDS,
			)
		);

		self::$handlers[ $type ] = array(
			'callback'     => $callback,
			'label'        => (string) $args['label'],
			'module'       => sanitize_key( (string) $args['module'] ),
			'max_attempts' => max( 1, (int) $args['max_attempts'] ),
			'retry_delay'  => max( 10, (int) $args['retry_delay'] ),
		);
	}

	/**
	 * Registered handlers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function handlers(): array {
		return self::$handlers;
	}

	/**
	 * Queue a job to run as soon as possible.
	 *
	 * @param string               $type    Job type.
	 * @param array<string, mixed> $payload Payload.
	 * @param array<string, mixed> $args    priority, max_attempts, group.
	 * @return int Job ID, 0 on failure.
	 */
	public static function dispatch( string $type, array $payload = array(), array $args = array() ): int {
		return self::schedule( $type, $payload, 0, $args );
	}

	/**
	 * Queue a job to run after a delay.
	 *
	 * @param string               $type    Job type.
	 * @param array<string, mixed> $payload Payload.
	 * @param int                  $delay   Delay in seconds.
	 * @param array<string, mixed> $args    priority, max_attempts, recurrence, group.
	 * @return int Job ID, 0 on failure.
	 */
	public static function schedule( string $type, array $payload = array(), int $delay = 0, array $args = array() ): int {
		global $wpdb;

		$type = sanitize_key( $type );

		if ( '' === $type ) {
			return 0;
		}

		$defaults = self::$handlers[ $type ] ?? array();

		$args = wp_parse_args(
			$args,
			array(
				'priority'     => 10,
				'max_attempts' => (int) ( $defaults['max_attempts'] ?? 3 ),
				'module'       => (string) ( $defaults['module'] ?? 'core' ),
				'recurrence'   => 0,
				'group'        => '',
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			Installer::jobs_table(),
			array(
				'job_type'     => $type,
				'module'       => sanitize_key( (string) $args['module'] ),
				'job_group'    => sanitize_key( (string) $args['group'] ),
				'payload'      => wp_json_encode( $payload ),
				'status'       => self::STATUS_PENDING,
				'priority'     => (int) $args['priority'],
				'attempts'     => 0,
				'max_attempts' => max( 1, (int) $args['max_attempts'] ),
				'recurrence'   => max( 0, (int) $args['recurrence'] ),
				'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + max( 0, $delay ) ),
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Queue a recurring job if one of the same type is not already queued.
	 *
	 * @param string               $type       Job type.
	 * @param int                  $recurrence Interval in seconds.
	 * @param array<string, mixed> $payload    Payload.
	 * @return int Job ID, 0 when already scheduled.
	 */
	public static function schedule_recurring( string $type, int $recurrence, array $payload = array() ): int {
		global $wpdb;

		$table = Installer::jobs_table();
		$type  = sanitize_key( $type );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE job_type = %s AND status IN ( %s, %s )",
				$type,
				self::STATUS_PENDING,
				self::STATUS_RUNNING
			)
		);

		if ( $existing > 0 ) {
			return 0;
		}

		return self::schedule( $type, $payload, $recurrence, array( 'recurrence' => $recurrence ) );
	}

	/**
	 * Cancel a pending job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public static function cancel( int $job_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->update(
			Installer::jobs_table(),
			array(
				'status'       => self::STATUS_CANCELLED,
				'completed_at' => current_time( 'mysql', true ),
			),
			array(
				'id'     => $job_id,
				'status' => self::STATUS_PENDING,
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Re-queue a failed job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public static function retry( int $job_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->update(
			Installer::jobs_table(),
			array(
				'status'       => self::STATUS_PENDING,
				'attempts'     => 0,
				'last_error'   => '',
				'scheduled_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Process jobs that are due.
	 *
	 * @param int $limit Maximum number of jobs per run.
	 * @return int Number of jobs processed.
	 */
	public static function run_due_jobs( int $limit = 10 ): int {
		global $wpdb;

		if ( get_transient( self::LOCK ) ) {
			return 0;
		}

		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$table     = Installer::jobs_table();
		$now       = current_time( 'mysql', true );
		$processed = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND scheduled_at <= %s ORDER BY priority ASC, scheduled_at ASC LIMIT %d",
				self::STATUS_PENDING,
				$now,
				max( 1, $limit )
			),
			ARRAY_A
		);

		foreach ( (array) $jobs as $job ) {
			if ( self::run_job( $job ) ) {
				++$processed;
			}
		}

		delete_transient( self::LOCK );

		return $processed;
	}

	/**
	 * Execute a single job row.
	 *
	 * @param array<string, mixed> $job Job row.
	 * @return bool Whether this worker actually claimed and ran the job (false if another worker already claimed it first).
	 */
	private static function run_job( array $job ): bool {
		global $wpdb;

		$table    = Installer::jobs_table();
		$id       = (int) $job['id'];
		$type     = (string) $job['job_type'];
		$attempts = (int) $job['attempts'] + 1;
		$payload  = (array) ( json_decode( (string) $job['payload'], true ) ?: array() );

		// Claim the job with an atomic conditional UPDATE — the WHERE clause
		// includes the status this row had when SELECTed, so only the worker
		// whose UPDATE actually matches a still-'pending' row proceeds. Two
		// overlapping run_due_jobs() calls (routine under WP-Cron's
		// pseudo-cron model, and only loosely discouraged by the transient
		// lock in run_due_jobs()) can both SELECT the same due row; without
		// this guard both would also both execute the handler, double-
		// processing the same import batch (or, for a bundle's last stage,
		// double-firing the completion hook and dispatching the next stage
		// twice). No new locking system — this is the job's own existing
		// `status` column used as the mutual-exclusion mechanism.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$claimed = $wpdb->update(
			$table,
			array(
				'status'     => self::STATUS_RUNNING,
				'attempts'   => $attempts,
				'started_at' => current_time( 'mysql', true ),
			),
			array(
				'id'     => $id,
				'status' => self::STATUS_PENDING,
			),
			array( '%s', '%d', '%s' ),
			array( '%d', '%s' )
		);

		if ( ! $claimed ) {
			return false;
		}

		if ( ! isset( self::$handlers[ $type ] ) ) {
			self::fail( $id, $job, $attempts, sprintf( 'No handler registered for job type "%s".', $type ) );

			return true;
		}

		try {
			$result = call_user_func( self::$handlers[ $type ]['callback'], $payload, $job );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'status'       => self::STATUS_COMPLETE,
					'result'       => wp_json_encode( $result ),
					'last_error'   => '',
					'completed_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			Activity_Log::log(
				array(
					'action'    => 'job_completed',
					'module'    => (string) $job['module'],
					'entity'    => 'job',
					'entity_id' => (string) $id,
					'severity'  => Activity_Log::SEVERITY_INFO,
					'context'   => array( 'type' => $type ),
				)
			);

			$recurrence = (int) $job['recurrence'];

			if ( $recurrence > 0 ) {
				self::schedule( $type, $payload, $recurrence, array( 'recurrence' => $recurrence ) );
			}
		} catch ( Throwable $exception ) {
			self::fail( $id, $job, $attempts, $exception->getMessage() );
		}

		return true;
	}

	/**
	 * Mark a job as failed, retrying while attempts remain.
	 *
	 * @param int                  $id       Job ID.
	 * @param array<string, mixed> $job      Job row.
	 * @param int                  $attempts Attempt count.
	 * @param string               $message  Failure message.
	 * @return void
	 */
	private static function fail( int $id, array $job, int $attempts, string $message ): void {
		global $wpdb;

		$table   = Installer::jobs_table();
		$type    = (string) $job['job_type'];
		$max     = max( 1, (int) $job['max_attempts'] );
		$delay   = (int) ( self::$handlers[ $type ]['retry_delay'] ?? 5 * MINUTE_IN_SECONDS );
		$retries = $attempts < $max;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'       => $retries ? self::STATUS_PENDING : self::STATUS_FAILED,
				'last_error'   => $message,
				'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + ( $retries ? $delay : 0 ) ),
				'completed_at' => $retries ? null : current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		Activity_Log::log(
			array(
				'action'    => $retries ? 'job_retry' : 'job_failed',
				'module'    => (string) $job['module'],
				'entity'    => 'job',
				'entity_id' => (string) $id,
				'severity'  => $retries ? Activity_Log::SEVERITY_WARNING : Activity_Log::SEVERITY_ERROR,
				'context'   => array(
					'type'    => $type,
					'attempt' => $attempts,
					'error'   => $message,
				),
			)
		);

		if ( ! $retries ) {
			Notifications::error(
				/* translators: %s: job type. */
				sprintf( __( 'Background job "%s" failed.', 'eventos' ), $type ),
				$message,
				array(
					'persistent' => true,
					'key'        => 'job_failed_' . $id,
					'module'     => (string) $job['module'],
					'capability' => Capabilities::VIEW_LOGS,
				)
			);
		}
	}

	/**
	 * Query the job history.
	 *
	 * @param array<string, mixed> $args status, job_type, module, page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public static function history( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'job_type' => '',
				'module'   => '',
				'page'     => 1,
				'per_page' => 20,
			)
		);

		$table    = Installer::jobs_table();
		$page     = max( 1, (int) $args['page'] );
		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );

		$where  = array( '1=1' );
		$params = array();

		foreach ( array( 'status', 'job_type', 'module' ) as $column ) {
			if ( '' !== (string) $args[ $column ] ) {
				$where[]  = "{$column} = %s";
				$params[] = sanitize_key( (string) $args[ $column ] );
			}
		}

		$clause = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		$items = array_map(
			static function ( array $row ): array {
				return array(
					'id'           => (int) $row['id'],
					'type'         => (string) $row['job_type'],
					'module'       => (string) $row['module'],
					'group'        => (string) $row['job_group'],
					'status'       => (string) $row['status'],
					'attempts'     => (int) $row['attempts'],
					'max_attempts' => (int) $row['max_attempts'],
					'recurrence'   => (int) $row['recurrence'],
					'payload'      => (array) ( json_decode( (string) $row['payload'], true ) ?: array() ),
					'result'       => json_decode( (string) $row['result'], true ),
					'last_error'   => (string) $row['last_error'],
					'scheduled_at' => (string) $row['scheduled_at'],
					'started_at'   => (string) $row['started_at'],
					'completed_at' => (string) $row['completed_at'],
					'created_at'   => (string) $row['created_at'],
				);
			},
			(array) $rows
		);

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Counts per status.
	 *
	 * @return array<string, int>
	 */
	public static function stats(): array {
		global $wpdb;

		$table = Installer::jobs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		$stats = array(
			self::STATUS_PENDING   => 0,
			self::STATUS_RUNNING   => 0,
			self::STATUS_COMPLETE  => 0,
			self::STATUS_FAILED    => 0,
			self::STATUS_CANCELLED => 0,
		);

		foreach ( (array) $rows as $row ) {
			$stats[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $stats;
	}

	/**
	 * Delete finished jobs older than the retention window.
	 *
	 * @param int $days Retention in days.
	 * @return int Deleted rows.
	 */
	public static function purge_older_than( int $days ): int {
		global $wpdb;

		$table  = Installer::jobs_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status IN ( %s, %s ) AND completed_at < %s",
				self::STATUS_COMPLETE,
				self::STATUS_CANCELLED,
				$cutoff
			)
		);
	}

	/**
	 * Remove the cron event.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}
}

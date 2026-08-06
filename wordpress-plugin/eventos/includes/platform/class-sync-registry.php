<?php
/**
 * Synchronisation framework shared by every EventOS module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Platform;

use EventOS\Activity_Log;
use EventOS\Job_Queue;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry of synchronisation targets plus their run history.
 *
 * Modules register a target with a handler; the registry owns enabling,
 * scheduling through the shared job queue, run bookkeeping, audit logging and
 * the queryable history consumed by the admin screens. No module has to
 * implement its own scheduler or history store.
 */
final class Sync_Registry {

	/**
	 * Option holding per target state (enabled flag, last run summary).
	 */
	public const STATE_OPTION = 'eventos_sync_state';

	/**
	 * Option holding the run history.
	 */
	public const HISTORY_OPTION = 'eventos_sync_history';

	/**
	 * Job type used when a run is queued in the background.
	 */
	public const JOB_TYPE = 'eventos_sync_run';

	/**
	 * Maximum number of retained history entries.
	 */
	public const HISTORY_LIMIT = 250;

	/**
	 * Run outcomes.
	 */
	public const STATUS_SUCCESS = 'success';
	public const STATUS_PARTIAL = 'partial';
	public const STATUS_FAILED  = 'failed';
	public const STATUS_SKIPPED = 'skipped';

	/**
	 * Registered targets keyed by slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $targets = array();

	/**
	 * Whether hooks are attached.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Attach the framework to WordPress.
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		if ( self::$hooked ) {
			return;
		}

		self::$hooked = true;

		Job_Queue::register_handler(
			self::JOB_TYPE,
			static function ( array $payload ) {
				return self::run( (string) ( $payload['target'] ?? '' ), 'schedule' );
			},
			array( 'module' => 'platform' )
		);

		/**
		 * Modules register their synchronisation targets on this hook.
		 *
		 * @param string $registry Registry class name.
		 */
		do_action( 'eventos_register_sync_targets', __CLASS__ );
	}

	/**
	 * Register a synchronisation target.
	 *
	 * Accepted keys: slug, label, description, module, direction, handler,
	 * interval, enabled, capability.
	 *
	 * @param array<string, mixed> $target Target definition.
	 * @return void
	 */
	public static function register( array $target ): void {
		if ( empty( $target['slug'] ) || empty( $target['handler'] ) || ! is_callable( $target['handler'] ) ) {
			return;
		}

		$slug = sanitize_key( (string) $target['slug'] );

		self::$targets[ $slug ] = wp_parse_args(
			$target,
			array(
				'slug'        => $slug,
				'label'       => $slug,
				'description' => '',
				'module'      => 'platform',
				'direction'   => 'outbound',
				'interval'    => HOUR_IN_SECONDS,
				'enabled'     => true,
			)
		);

		self::$targets[ $slug ]['slug'] = $slug;
	}

	/**
	 * Register several targets at once.
	 *
	 * @param array<int, array<string, mixed>> $targets Target definitions.
	 * @return void
	 */
	public static function register_many( array $targets ): void {
		foreach ( $targets as $target ) {
			if ( is_array( $target ) ) {
				self::register( $target );
			}
		}
	}

	/**
	 * Whether a target exists.
	 *
	 * @param string $slug Target slug.
	 * @return bool
	 */
	public static function has( string $slug ): bool {
		return isset( self::$targets[ sanitize_key( $slug ) ] );
	}

	/**
	 * All registered targets, decorated with their stored state.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$state   = self::state();
		$targets = array();

		foreach ( self::$targets as $slug => $target ) {
			$stored = (array) ( $state[ $slug ] ?? array() );

			$targets[] = array(
				'slug'        => $slug,
				'label'       => (string) $target['label'],
				'description' => (string) $target['description'],
				'module'      => (string) $target['module'],
				'direction'   => (string) $target['direction'],
				'interval'    => (int) $target['interval'],
				'enabled'     => array_key_exists( 'enabled', $stored )
					? (bool) $stored['enabled']
					: (bool) $target['enabled'],
				'last_status' => (string) ( $stored['last_status'] ?? '' ),
				'last_run_at' => (string) ( $stored['last_run_at'] ?? '' ),
				'last_message' => (string) ( $stored['last_message'] ?? '' ),
				'processed'   => (int) ( $stored['processed'] ?? 0 ),
				'failed'      => (int) ( $stored['failed'] ?? 0 ),
			);
		}

		usort(
			$targets,
			static function ( array $a, array $b ): int {
				return strcmp( (string) $a['label'], (string) $b['label'] );
			}
		);

		return $targets;
	}

	/**
	 * Enable or disable a target.
	 *
	 * @param string $slug    Target slug.
	 * @param bool   $enabled Desired state.
	 * @return bool
	 */
	public static function set_enabled( string $slug, bool $enabled ): bool {
		$slug = sanitize_key( $slug );

		if ( ! self::has( $slug ) ) {
			return false;
		}

		$state                     = self::state();
		$before                    = (bool) ( $state[ $slug ]['enabled'] ?? self::$targets[ $slug ]['enabled'] );
		$state[ $slug ]['enabled'] = $enabled;

		update_option( self::STATE_OPTION, $state, false );

		Activity_Log::log(
			array(
				'action'      => $enabled ? 'sync_target_enabled' : 'sync_target_disabled',
				'module'      => 'platform',
				'entity_type' => 'sync_target',
				'entity_id'   => $slug,
				'before'      => array( 'enabled' => $before ),
				'after'       => array( 'enabled' => $enabled ),
			)
		);

		return true;
	}

	/**
	 * Queue a target for background execution.
	 *
	 * @param string $slug  Target slug.
	 * @param int    $delay Delay in seconds.
	 * @return int Job ID, 0 when the target is unknown.
	 */
	public static function queue( string $slug, int $delay = 0 ): int {
		$slug = sanitize_key( $slug );

		if ( ! self::has( $slug ) ) {
			return 0;
		}

		return Job_Queue::schedule(
			self::JOB_TYPE,
			array( 'target' => $slug ),
			$delay,
			array( 'module' => 'platform' )
		);
	}

	/**
	 * Execute a target immediately and record the run.
	 *
	 * @param string $slug    Target slug.
	 * @param string $trigger manual|schedule|hook.
	 * @return array<string, mixed> Run record.
	 */
	public static function run( string $slug, string $trigger = 'manual' ): array {
		$slug = sanitize_key( $slug );

		if ( ! self::has( $slug ) ) {
			return self::record(
				$slug,
				self::STATUS_FAILED,
				$trigger,
				0,
				0,
				__( 'Unknown synchronisation target.', 'eventos' ),
				0.0
			);
		}

		$target = self::$targets[ $slug ];
		$state  = self::state();
		$active = array_key_exists( 'enabled', (array) ( $state[ $slug ] ?? array() ) )
			? (bool) $state[ $slug ]['enabled']
			: (bool) $target['enabled'];

		if ( ! $active && 'manual' !== $trigger ) {
			return self::record(
				$slug,
				self::STATUS_SKIPPED,
				$trigger,
				0,
				0,
				__( 'Target is disabled.', 'eventos' ),
				0.0
			);
		}

		$started = microtime( true );

		try {
			$result = call_user_func( $target['handler'], $target );
			$result = is_array( $result ) ? $result : array();

			$processed = (int) ( $result['processed'] ?? 0 );
			$failed    = (int) ( $result['failed'] ?? 0 );
			$message   = (string) ( $result['message'] ?? __( 'Synchronisation completed.', 'eventos' ) );
			$status    = $failed > 0 ? self::STATUS_PARTIAL : self::STATUS_SUCCESS;

			return self::record( $slug, $status, $trigger, $processed, $failed, $message, microtime( true ) - $started );
		} catch ( Throwable $error ) {
			return self::record(
				$slug,
				self::STATUS_FAILED,
				$trigger,
				0,
				0,
				$error->getMessage(),
				microtime( true ) - $started
			);
		}
	}

	/**
	 * Query the run history with search, filters and pagination.
	 *
	 * Accepted args: search, target, status, trigger, page, per_page.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public static function history( array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'target'   => '',
				'status'   => '',
				'trigger'  => '',
				'page'     => 1,
				'per_page' => 20,
			)
		);

		$rows     = self::stored_history();
		$search   = strtolower( trim( (string) $args['search'] ) );
		$page     = max( 1, (int) $args['page'] );
		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );

		$filtered = array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $args, $search ): bool {
					foreach ( array( 'target', 'status', 'trigger' ) as $key ) {
						if ( '' !== (string) $args[ $key ] && (string) $row[ $key ] !== (string) $args[ $key ] ) {
							return false;
						}
					}

					if ( '' === $search ) {
						return true;
					}

					$haystack = strtolower( (string) $row['target'] . ' ' . (string) $row['message'] );

					return false !== strpos( $haystack, $search );
				}
			)
		);

		return array(
			'items'    => array_slice( $filtered, ( $page - 1 ) * $per_page, $per_page ),
			'total'    => count( $filtered ),
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Aggregate counts per run status.
	 *
	 * @return array<string, int>
	 */
	public static function stats(): array {
		$stats = array(
			self::STATUS_SUCCESS => 0,
			self::STATUS_PARTIAL => 0,
			self::STATUS_FAILED  => 0,
			self::STATUS_SKIPPED => 0,
		);

		foreach ( self::stored_history() as $row ) {
			$status           = (string) $row['status'];
			$stats[ $status ] = (int) ( $stats[ $status ] ?? 0 ) + 1;
		}

		return $stats;
	}

	/**
	 * Delete the stored history.
	 *
	 * @return void
	 */
	public static function clear_history(): void {
		update_option( self::HISTORY_OPTION, array(), false );
	}

	/**
	 * Stored per target state.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function state(): array {
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persist a run and update the target state.
	 *
	 * @param string $slug      Target slug.
	 * @param string $status    Run status.
	 * @param string $trigger   Run trigger.
	 * @param int    $processed Processed records.
	 * @param int    $failed    Failed records.
	 * @param string $message   Human readable outcome.
	 * @param float  $duration  Duration in seconds.
	 * @return array<string, mixed>
	 */
	private static function record(
		string $slug,
		string $status,
		string $trigger,
		int $processed,
		int $failed,
		string $message,
		float $duration
	): array {
		$run = array(
			'id'         => 'run_' . wp_generate_uuid4(),
			'target'     => $slug,
			'status'     => $status,
			'trigger'    => in_array( $trigger, array( 'manual', 'schedule', 'hook' ), true ) ? $trigger : 'manual',
			'processed'  => $processed,
			'failed'     => $failed,
			'message'    => $message,
			'duration'   => round( $duration, 3 ),
			'user_id'    => get_current_user_id(),
			'created_at' => current_time( 'mysql', true ),
		);

		$history = self::stored_history();
		array_unshift( $history, $run );

		update_option( self::HISTORY_OPTION, array_slice( $history, 0, self::HISTORY_LIMIT ), false );

		$state           = self::state();
		$existing        = (array) ( $state[ $slug ] ?? array() );
		$state[ $slug ]  = array_merge(
			$existing,
			array(
				'last_status'  => $status,
				'last_run_at'  => $run['created_at'],
				'last_message' => $message,
				'processed'    => $processed,
				'failed'       => $failed,
			)
		);

		update_option( self::STATE_OPTION, $state, false );

		Activity_Log::log(
			array(
				'action'      => 'sync_run',
				'module'      => 'platform',
				'entity_type' => 'sync_target',
				'entity_id'   => $slug,
				'after'       => $run,
				'severity'    => self::STATUS_FAILED === $status
					? Activity_Log::SEVERITY_ERROR
					: ( self::STATUS_PARTIAL === $status ? Activity_Log::SEVERITY_WARNING : Activity_Log::SEVERITY_INFO ),
			)
		);

		return $run;
	}

	/**
	 * Raw history rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function stored_history(): array {
		$history = get_option( self::HISTORY_OPTION, array() );

		return is_array( $history ) ? array_values( $history ) : array();
	}
}

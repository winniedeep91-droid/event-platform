<?php
/**
 * Historical backfill of WooCommerce customers and EventOS guests into
 * permanent Persons.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Crm;

use EventOS\Activity_Log;
use EventOS\Events\Event_Schema;
use EventOS\Job_Queue;
use WP_User_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the two historical backfill passes in batches through the
 * background job queue — mirrors {@see \EventOS\Import\Import_Engine}
 * exactly: a persisted run record with an offset, a job that processes one
 * batch and requeues itself while work remains, and no matching logic of
 * its own. Every resolution goes through {@see Person_Resolver}, the same
 * path a future live purchase/guest flow will use.
 *
 * Pass order matters for determinism: WooCommerce customers run first, so
 * their `wc_customer_id` identity already exists by the time the guest
 * pass reaches a guest row carrying the same `wc_customer_id` — it
 * resolves to the same Person instead of the two passes racing to create
 * one first.
 *
 * `Job_Queue::init()` fires `eventos_register_jobs` from inside
 * `Core_Module::init()`, which — by module dependency order — has already
 * finished running by the time `Crm_Module::init()` runs. Attaching to
 * that hook here would silently never fire, exactly the trap
 * {@see \EventOS\Import\Import_Engine::init()} avoids by being called
 * directly from `Core_Module::init()` instead. `Crm_Module::init()` calls
 * {@see self::init()} directly for the same reason — see its docblock.
 *
 * No REST endpoint or admin UI triggers this in Phase 2 — {@see self::start()}
 * is a plain callable, ready for whichever later phase actually runs the
 * historical migration for real.
 */
final class Person_Backfill_Service {

	/**
	 * Option storing backfill runs.
	 */
	public const RUNS_OPTION = 'eventos_crm_backfill_runs';

	/**
	 * Background job type.
	 */
	public const JOB_TYPE = 'eventos_crm_backfill_batch';

	/**
	 * Rows processed per batch.
	 */
	public const BATCH_SIZE = 100;

	/**
	 * Maximum runs kept in history.
	 */
	private const HISTORY_LIMIT = 25;

	/**
	 * Register the job handler directly — see the class docblock for why
	 * this must not be attached via the `eventos_register_jobs` hook.
	 *
	 * @return void
	 */
	public static function init(): void {
		Job_Queue::register_handler(
			self::JOB_TYPE,
			array( __CLASS__, 'handle_job' ),
			array(
				'label'  => __( 'Process CRM identity backfill batch', 'eventos' ),
				'module' => 'crm',
			)
		);
	}

	/**
	 * Create a run and queue its first batch.
	 *
	 * @return array<string, mixed>
	 */
	public static function start(): array {
		$runs = self::runs();
		$id   = 1 + (int) max( array_merge( array( 0 ), array_map( 'intval', array_column( $runs, 'id' ) ) ) );
		$now  = current_time( 'mysql', true );

		$run = array(
			'id'           => $id,
			'status'       => 'queued',
			'stage'        => 'wc_customers',
			'offset'       => 0,
			'resolved'     => 0,
			'created'      => 0,
			'conflicts'    => 0,
			'started_at'   => $now,
			'updated_at'   => $now,
			'completed_at' => null,
		);

		self::save( $run );

		Activity_Log::log(
			array(
				'action'      => 'crm_backfill_started',
				'module'      => 'crm',
				'object_type' => 'crm_backfill_run',
				'object_id'   => (string) $id,
			)
		);

		Job_Queue::dispatch( self::JOB_TYPE, array( 'run_id' => $id ) );

		return $run;
	}

	/**
	 * Process one batch and requeue while work remains.
	 *
	 * @param array<string, mixed> $payload Job payload.
	 * @return array<string, mixed>
	 */
	public static function handle_job( array $payload ): array {
		$run = self::run( (int) ( $payload['run_id'] ?? 0 ) );

		if ( null === $run ) {
			return array( 'status' => 'unknown' );
		}

		if ( in_array( $run['status'], array( 'complete', 'failed' ), true ) ) {
			return array( 'status' => $run['status'] );
		}

		$run['status'] = 'running';

		if ( 'wc_customers' === $run['stage'] ) {
			$run = self::run_wc_customer_batch( $run );
		} elseif ( 'guests' === $run['stage'] ) {
			$run = self::run_guest_batch( $run );
		}

		$run['updated_at'] = current_time( 'mysql', true );

		if ( 'complete' === $run['stage'] ) {
			$run['status']       = 'complete';
			$run['completed_at'] = $run['updated_at'];
		}

		self::save( $run );

		if ( 'complete' === $run['status'] ) {
			Activity_Log::log(
				array(
					'action'      => 'crm_backfill_completed',
					'module'      => 'crm',
					'object_type' => 'crm_backfill_run',
					'object_id'   => (string) $run['id'],
					'context'     => array(
						'resolved'  => $run['resolved'],
						'created'   => $run['created'],
						'conflicts' => $run['conflicts'],
					),
				)
			);
		} else {
			Job_Queue::dispatch( self::JOB_TYPE, array( 'run_id' => $run['id'] ) );
		}

		return array(
			'run_id' => $run['id'],
			'status' => $run['status'],
			'stage'  => $run['stage'],
		);
	}

	/**
	 * Process one batch of registered WooCommerce customers.
	 *
	 * @param array<string, mixed> $run Run record.
	 * @return array<string, mixed>
	 */
	private static function run_wc_customer_batch( array $run ): array {
		$query = new WP_User_Query(
			array(
				'role'    => 'customer',
				'number'  => self::BATCH_SIZE,
				'offset'  => (int) $run['offset'],
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);

		$ids      = $query->get_results();
		$resolver = self::resolver();

		foreach ( $ids as $user_id ) {
			$signals = self::wc_customer_signals( (int) $user_id );

			$result = $resolver->find_or_create(
				array(
					'wc_customer_id' => (int) $user_id,
					'email'          => $signals['email'],
					'name'           => $signals['name'],
					'phone'          => $signals['phone'],
					'source'         => 'wc_customer_backfill',
					'source_id'      => (string) $user_id,
				)
			);

			self::metrics()->recompute( (int) $result['person']['id'] );
			self::tally( $run, $result );
		}

		$count = count( $ids );

		if ( $count < self::BATCH_SIZE ) {
			$run['stage']  = 'guests';
			$run['offset'] = 0;
		} else {
			$run['offset'] = (int) $run['offset'] + $count;
		}

		return $run;
	}

	/**
	 * Process one batch of EventOS guest rows, across every event.
	 *
	 * A direct, read-only query against Event_Schema::guests() rather than
	 * a Guest_Repository method — Guest_Repository is deliberately
	 * event-scoped (see its own class docblock) and this needs every
	 * event, and this is a read that touches no guest row, satisfying
	 * "preserve the original guest row."
	 *
	 * @param array<string, mixed> $run Run record.
	 * @return array<string, mixed>
	 */
	private static function run_guest_batch( array $run ): array {
		global $wpdb;

		$table = Event_Schema::guests();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, wc_customer_id, name, email, phone FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
				self::BATCH_SIZE,
				(int) $run['offset']
			),
			ARRAY_A
		);

		$resolver = self::resolver();

		foreach ( (array) $rows as $row ) {
			$result = $resolver->find_or_create(
				array(
					'wc_customer_id' => (int) $row['wc_customer_id'],
					'email'          => (string) $row['email'],
					'name'           => (string) $row['name'],
					'phone'          => (string) $row['phone'],
					'source'         => 'guest_backfill',
					'source_id'      => (string) $row['id'],
				)
			);

			self::metrics()->recompute( (int) $result['person']['id'] );
			self::tally( $run, $result );
		}

		$count = count( $rows );

		if ( $count < self::BATCH_SIZE ) {
			$run['stage'] = 'complete';
		} else {
			$run['offset'] = (int) $run['offset'] + $count;
		}

		return $run;
	}

	/**
	 * Accumulate one resolver result into the run's counters.
	 *
	 * @param array<string, mixed> $run    Run record, by reference.
	 * @param array<string, mixed> $result Person_Resolver::find_or_create() result.
	 * @return void
	 */
	private static function tally( array &$run, array $result ): void {
		++$run['resolved'];

		if ( $result['created'] ) {
			++$run['created'];
		}

		if ( null !== $result['conflict'] ) {
			++$run['conflicts'];
		}
	}

	/**
	 * Email/name/phone signals for a registered WooCommerce customer, using
	 * the same first_name/last_name-over-billing precedence
	 * `Woocommerce_Controller::customer_payload()` already uses.
	 *
	 * @param int $user_id WordPress/WooCommerce user ID.
	 * @return array{email: string, name: string, phone: string}
	 */
	private static function wc_customer_signals( int $user_id ): array {
		$user  = get_userdata( $user_id );
		$first = (string) get_user_meta( $user_id, 'first_name', true );
		$last  = (string) get_user_meta( $user_id, 'last_name', true );

		if ( '' === $first ) {
			$first = (string) get_user_meta( $user_id, 'billing_first_name', true );
		}

		if ( '' === $last ) {
			$last = (string) get_user_meta( $user_id, 'billing_last_name', true );
		}

		return array(
			'email' => $user ? (string) $user->user_email : '',
			'name'  => trim( $first . ' ' . $last ),
			'phone' => (string) get_user_meta( $user_id, 'billing_phone', true ),
		);
	}

	/**
	 * A resolver bound to fresh repository instances.
	 *
	 * @return Person_Resolver
	 */
	private static function resolver(): Person_Resolver {
		return new Person_Resolver( new Person_Repository(), new Person_Identity_Repository(), new Person_Timeline_Service() );
	}

	/**
	 * A metrics service bound to fresh repository instances.
	 *
	 * @return Person_Metrics_Service
	 */
	private static function metrics(): Person_Metrics_Service {
		return new Person_Metrics_Service( new Person_Repository(), new Person_Identity_Repository() );
	}

	/**
	 * Every stored run, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function runs(): array {
		$stored = get_option( self::RUNS_OPTION, array() );

		return is_array( $stored ) ? array_values( $stored ) : array();
	}

	/**
	 * A single run.
	 *
	 * @param int $run_id Run ID.
	 * @return array<string, mixed>|null
	 */
	public static function run( int $run_id ): ?array {
		foreach ( self::runs() as $run ) {
			if ( (int) $run['id'] === $run_id ) {
				return $run;
			}
		}

		return null;
	}

	/**
	 * Persist a run record.
	 *
	 * @param array<string, mixed> $run Run record.
	 * @return void
	 */
	private static function save( array $run ): void {
		$runs  = self::runs();
		$found = false;

		foreach ( $runs as $index => $stored ) {
			if ( (int) $stored['id'] === (int) $run['id'] ) {
				$runs[ $index ] = $run;
				$found          = true;
				break;
			}
		}

		if ( ! $found ) {
			array_unshift( $runs, $run );
		}

		usort(
			$runs,
			static function ( array $a, array $b ): int {
				return (int) $b['id'] <=> (int) $a['id'];
			}
		);

		update_option( self::RUNS_OPTION, array_slice( $runs, 0, self::HISTORY_LIMIT ), false );
	}
}

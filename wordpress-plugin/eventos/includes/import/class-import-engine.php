<?php
/**
 * Provider agnostic import engine.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Import;

use EventOS\Activity_Log;
use EventOS\Job_Queue;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs imports in batches through the background job queue.
 *
 * The engine owns run state, batching, progress, logging and rollback. It calls
 * providers exclusively through Import_Provider_Interface, so a new source only
 * needs a provider class.
 */
final class Import_Engine {

	/**
	 * Option storing import runs.
	 */
	public const RUNS_OPTION = 'eventos_import_runs';

	/**
	 * Background job type.
	 */
	public const JOB_TYPE = 'eventos_import_batch';

	/**
	 * Rows processed per batch.
	 */
	public const BATCH_SIZE = 100;

	/**
	 * Maximum runs kept in history.
	 */
	private const HISTORY_LIMIT = 25;

	/**
	 * Register hooks and the job handler.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Deferred to `init` for the same reason Core_Module defers
		// Export_Registry::bootstrap() — this method itself runs from inside
		// Core_Module::init(), before Events_Module/Crm_Module have reached
		// their own init() and attached their
		// add_action('eventos_register_import_providers', ...) listeners
		// (which is where those modules' import *targets* — not the built-in
		// providers, which Import_Registry::bootstrap() registers directly —
		// actually get added). Firing the action here would mean nothing
		// they register ever appears at runtime.
		add_action( 'init', array( Import_Registry::class, 'bootstrap' ) );

		Job_Queue::register_handler(
			self::JOB_TYPE,
			array( __CLASS__, 'handle_job' ),
			array(
				'label'  => __( 'Process import batch', 'eventos' ),
				'module' => 'core',
			)
		);
	}

	/**
	 * Validate a source and return a preview without changing anything.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @param int                  $limit  Preview rows.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function preview( array $source, int $limit = 10 ) {
		$provider = self::resolve( $source );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$preview = $provider->preview( $source, $limit );

		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		$preview['provider'] = $provider->slug();

		return $preview;
	}

	/**
	 * Suggested field mapping for a source and target entity.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @param string               $entity Target entity slug.
	 * @return array<string, string>|WP_Error
	 */
	public static function mapping( array $source, string $entity ) {
		$provider = self::resolve( $source );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		return $provider->map_fields( $source, $entity );
	}

	/**
	 * Create a run and queue its first batch.
	 *
	 * @param array<string, mixed> $args Keys: source, entity, mapping, dry_run.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function start( array $args ) {
		$source = (array) ( $args['source'] ?? array() );
		$entity = sanitize_key( (string) ( $args['entity'] ?? '' ) );

		$target = Import_Registry::target( $entity );

		if ( null === $target ) {
			return new WP_Error(
				'eventos_import_unknown_entity',
				__( 'Unknown import target.', 'eventos' ),
				array( 'status' => 404 )
			);
		}

		// Checked before anything about the source is resolved or validated —
		// Neither Import_Registry nor this engine checked the target's own
		// `capability` anywhere before this — Export_Service enforces the
		// equivalent check for exports (see its `export()` method), but the
		// import side never had a REST route wired up to reach it, so the
		// gap was latent rather than exploitable. Closing it here rather
		// than only in the REST controller protects every caller, present
		// and future, not just one route. Checking capability first, ahead
		// of provider detection/validation, also avoids leaking any
		// information about a source's validity to a caller who isn't
		// authorised to import into this target at all.
		if ( ! current_user_can( (string) $target['capability'] ) ) {
			return new WP_Error(
				'eventos_forbidden',
				__( 'You are not allowed to import this data.', 'eventos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$provider = self::resolve( $source );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$valid = $provider->validate( $source );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$mapping = (array) ( $args['mapping'] ?? array() );

		if ( ! $mapping ) {
			$mapping = $provider->map_fields( $source, $entity );

			if ( is_wp_error( $mapping ) ) {
				return $mapping;
			}
		}

		$runs = self::runs();
		$id   = 1 + (int) max( array_merge( array( 0 ), array_map( 'intval', array_column( $runs, 'id' ) ) ) );

		$run = array(
			'id'         => $id,
			'provider'   => $provider->slug(),
			'entity'     => $entity,
			'source'     => $source,
			'mapping'    => array_map( 'strval', $mapping ),
			'dry_run'    => ! empty( $args['dry_run'] ),
			'status'     => 'queued',
			'offset'     => 0,
			'imported'   => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'errors'     => array(),
			'created'    => array(),
			'user_id'    => get_current_user_id(),
			'created_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
		);

		self::save( $run );

		Activity_Log::log(
			array(
				'action'      => 'import_started',
				'module'      => 'core',
				'object_type' => 'import_run',
				'object_id'   => (string) $id,
				'context'     => array(
					'provider' => $run['provider'],
					'entity'   => $entity,
				),
			)
		);

		Job_Queue::dispatch( self::JOB_TYPE, array( 'run_id' => $id ) );

		return $run;
	}

	/**
	 * Job handler processing a single batch and requeuing while work remains.
	 *
	 * @param array<string, mixed> $payload Job payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_job( array $payload ) {
		$run = self::run( (int) ( $payload['run_id'] ?? 0 ) );

		if ( null === $run ) {
			return new WP_Error( 'eventos_import_unknown_run', __( 'Import run not found.', 'eventos' ) );
		}

		if ( in_array( $run['status'], array( 'complete', 'failed', 'rolled_back', 'cancelled' ), true ) ) {
			return array( 'status' => $run['status'] );
		}

		$provider = Import_Registry::provider( (string) $run['provider'] );

		if ( null === $provider ) {
			$run['status']     = 'failed';
			$run['errors'][]   = __( 'The import provider is no longer available.', 'eventos' );
			$run['updated_at'] = current_time( 'mysql', true );
			self::save( $run );

			return new WP_Error( 'eventos_import_provider_missing', __( 'The import provider is no longer available.', 'eventos' ) );
		}

		$result = $provider->import(
			(array) $run['source'],
			(array) $run['mapping'],
			array(
				'run_id'  => $run['id'],
				'entity'  => $run['entity'],
				'dry_run' => (bool) $run['dry_run'],
				'offset'  => (int) $run['offset'],
				'limit'   => self::BATCH_SIZE,
			)
		);

		if ( is_wp_error( $result ) ) {
			$run['status']     = 'failed';
			$run['errors'][]   = $result->get_error_message();
			$run['updated_at'] = current_time( 'mysql', true );
			self::save( $run );

			return $result;
		}

		$run['imported']   = (int) $run['imported'] + (int) $result['imported'];
		$run['skipped']    = (int) $run['skipped'] + (int) $result['skipped'];
		$run['failed']     = (int) $run['failed'] + (int) $result['failed'];
		$run['errors']     = array_slice( array_merge( (array) $run['errors'], (array) $result['errors'] ), 0, 100 );
		$run['created']    = array_merge( (array) $run['created'], (array) $result['created'] );
		$run['offset']     = (int) $run['offset'] + self::BATCH_SIZE;
		$run['status']     = $result['done'] ? 'complete' : 'running';
		$run['updated_at'] = current_time( 'mysql', true );

		self::save( $run );

		if ( ! $result['done'] ) {
			Job_Queue::dispatch( self::JOB_TYPE, array( 'run_id' => $run['id'] ) );
		} else {
			Activity_Log::log(
				array(
					'action'      => 'import_completed',
					'module'      => 'core',
					'object_type' => 'import_run',
					'object_id'   => (string) $run['id'],
					'context'     => array(
						'imported' => $run['imported'],
						'failed'   => $run['failed'],
					),
				)
			);
		}

		return array(
			'run_id'   => $run['id'],
			'status'   => $run['status'],
			'imported' => $run['imported'],
		);
	}

	/**
	 * Undo a completed run.
	 *
	 * @param int $run_id Run identifier.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function rollback( int $run_id ) {
		$run = self::run( $run_id );

		if ( null === $run ) {
			return new WP_Error( 'eventos_import_unknown_run', __( 'Import run not found.', 'eventos' ), array( 'status' => 404 ) );
		}

		$target = Import_Registry::target( (string) $run['entity'] );

		if ( null === $target || ! current_user_can( (string) $target['capability'] ) ) {
			return new WP_Error(
				'eventos_forbidden',
				__( 'You are not allowed to modify this import.', 'eventos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$provider = Import_Registry::provider( (string) $run['provider'] );

		if ( null === $provider ) {
			return new WP_Error( 'eventos_import_provider_missing', __( 'The import provider is no longer available.', 'eventos' ), array( 'status' => 409 ) );
		}

		$result = $provider->rollback( $run );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$run['status']     = 'rolled_back';
		$run['created']    = array();
		$run['updated_at'] = current_time( 'mysql', true );

		self::save( $run );

		Activity_Log::log(
			array(
				'action'      => 'import_rolled_back',
				'module'      => 'core',
				'object_type' => 'import_run',
				'object_id'   => (string) $run_id,
				'severity'    => Activity_Log::SEVERITY_WARNING,
			)
		);

		return $run;
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
	 * @param int $run_id Run identifier.
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

	/**
	 * Resolve the provider for a source definition.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @return Import_Provider_Interface|WP_Error
	 */
	private static function resolve( array $source ) {
		$provider = Import_Registry::detect( $source );

		if ( null === $provider ) {
			return new WP_Error(
				'eventos_import_no_provider',
				__( 'No import provider recognises this source.', 'eventos' ),
				array( 'status' => 400 )
			);
		}

		return $provider;
	}
}

<?php
/**
 * Chains a multi-stage ticketing-platform import (events -> ticket_types ->
 * tickets) through the existing Import_Engine/Job_Queue, one stage at a time.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Import;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A ticketing platform's source data is hierarchical (an event owns ticket
 * types, which own tickets) but `Import_Registry` targets are flat,
 * single-entity batches, and — critically — each stage is very often a
 * genuinely different source (its own CSV file, its own API endpoint),
 * not one shared source reused three times. Rather than inventing a second
 * import engine for the hierarchical case, this runs the *same*
 * `Import_Engine::start()` for each entity in turn, with that stage's own
 * source, using the completion of one stage's run to dispatch the next —
 * still entirely on `Job_Queue`, no second job system.
 *
 * A random per-bundle token travels inside every stage's own source
 * definition (each stage's run keeps its own copy, since `Import_Engine`
 * persists the full source on the run record) so the chain survives even if
 * two bundles happen to share an identical source for one stage — nothing
 * ties the stages together except that token, looked up in the
 * `BUNDLES_OPTION` map of "token => {remaining stages, their sources}".
 */
final class Ticketing_Import_Orchestrator {

	/**
	 * Option holding in-progress bundle chains:
	 * token => { stages: string[], sources: array<string, array>, mappings: array<string, array> }.
	 */
	public const BUNDLES_OPTION = 'eventos_ticketing_import_bundles';

	/**
	 * Default, and only currently supported, stage order.
	 *
	 * @var string[]
	 */
	private const STAGE_ORDER = array( 'events', 'ticket_types', 'tickets' );

	/**
	 * Source key carrying the bundle chain token.
	 */
	private const TOKEN_KEY = '_eventos_bundle_token';

	/**
	 * Register the completion listener.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'eventos_import_run_completed', array( __CLASS__, 'handle_run_completed' ) );
	}

	/**
	 * Start a multi-stage bundle import — one source per stage, and
	 * optionally one explicit mapping per stage (e.g. resolved from an
	 * Import Profile by {@see Import_Registry::start_profile_bundle()}).
	 * A stage with no explicit mapping falls back to `Import_Engine`'s own
	 * automatic column/alias detection, exactly as before this parameter
	 * existed.
	 *
	 * @param array<string, array<string, mixed>>  $stage_sources  Entity slug => that stage's own source definition,
	 *                                                              e.g. `['events' => [...], 'ticket_types' => [...], 'tickets' => [...]]`.
	 * @param string[]                              $stages         Entity slugs to run, in order. Defaults to every
	 *                                                              stage present in $stage_sources.
	 * @param array<string, array<string, mixed>>  $stage_mappings Entity slug => that stage's explicit mapping. Optional.
	 * @return array<string, mixed>|WP_Error The first stage's run record.
	 */
	public static function run_bundle( array $stage_sources, array $stages = array(), array $stage_mappings = array() ) {
		$stages = empty( $stages ) ? self::STAGE_ORDER : $stages;
		$stages = array_values(
			array_filter(
				self::STAGE_ORDER,
				static fn( string $entity ): bool => in_array( $entity, $stages, true ) && isset( $stage_sources[ $entity ] )
			)
		);

		if ( empty( $stages ) ) {
			return new WP_Error( 'eventos_import_bundle_invalid', __( 'No valid import stages with a source were given.', 'eventos' ) );
		}

		$token = wp_generate_password( 16, false, false );
		$first = array_shift( $stages );

		$first_source                    = (array) $stage_sources[ $first ];
		$first_source[ self::TOKEN_KEY ] = $token;

		$first_args = array(
			'source' => $first_source,
			'entity' => $first,
		);

		if ( isset( $stage_mappings[ $first ] ) ) {
			$first_args['mapping'] = $stage_mappings[ $first ];
		}

		$run = Import_Engine::start( $first_args );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		if ( ! empty( $stages ) ) {
			self::remember( $token, $stages, $stage_sources, $stage_mappings );
		}

		return $run;
	}

	/**
	 * Dispatch the next stage — with its own source — if this completed run
	 * belongs to a bundle.
	 *
	 * @param array<string, mixed> $run Completed run record.
	 * @return void
	 */
	public static function handle_run_completed( array $run ): void {
		$source = (array) ( $run['source'] ?? array() );
		$token  = (string) ( $source[ self::TOKEN_KEY ] ?? '' );

		if ( '' === $token ) {
			return;
		}

		$bundles = get_option( self::BUNDLES_OPTION, array() );

		if ( empty( $bundles[ $token ] ) ) {
			return;
		}

		$stages         = (array) ( $bundles[ $token ]['stages'] ?? array() );
		$stage_sources  = (array) ( $bundles[ $token ]['sources'] ?? array() );
		$stage_mappings = (array) ( $bundles[ $token ]['mappings'] ?? array() );
		$next           = array_shift( $stages );

		if ( $stages ) {
			$bundles[ $token ]['stages'] = array_values( $stages );
		} else {
			unset( $bundles[ $token ] );
		}

		update_option( self::BUNDLES_OPTION, $bundles, false );

		if ( null !== $next && isset( $stage_sources[ $next ] ) ) {
			$next_source                    = (array) $stage_sources[ $next ];
			$next_source[ self::TOKEN_KEY ] = $token;

			$next_args = array(
				'source' => $next_source,
				'entity' => $next,
			);

			if ( isset( $stage_mappings[ $next ] ) ) {
				$next_args['mapping'] = $stage_mappings[ $next ];
			}

			Import_Engine::start( $next_args );
		}
	}

	/**
	 * Persist the remaining stages, every stage's own source, and every
	 * stage's own mapping (if any) for one bundle token.
	 *
	 * @param string                               $token          Bundle token.
	 * @param string[]                              $stages         Remaining entity slugs, in order.
	 * @param array<string, array<string, mixed>>  $stage_sources  Every stage's source (only the remaining
	 *                                                              ones are ever read back, but stored as
	 *                                                              given — simplest correct shape).
	 * @param array<string, array<string, mixed>>  $stage_mappings Every stage's explicit mapping, if any.
	 * @return void
	 */
	private static function remember( string $token, array $stages, array $stage_sources, array $stage_mappings = array() ): void {
		$bundles          = get_option( self::BUNDLES_OPTION, array() );
		$bundles[ $token ] = array(
			'stages'   => array_values( $stages ),
			'sources'  => $stage_sources,
			'mappings' => $stage_mappings,
		);

		update_option( self::BUNDLES_OPTION, $bundles, false );
	}
}

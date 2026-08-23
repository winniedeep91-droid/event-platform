<?php
/**
 * Generic REST surface for every registered import target.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use EventOS\Import\Import_Engine;
use EventOS\Import\Import_Registry;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nothing here talks to a specific target or provider directly — every
 * method just calls into {@see Import_Engine}/{@see Import_Registry}, the
 * same way {@see Export_Controller} only ever calls {@see \EventOS\Export\Export_Service}.
 * This is the missing REST layer for import machinery that already existed
 * (preview/dry-run/batching/rollback) but had no route reaching it — see
 * {@see Import_Engine::start()}'s capability check, added alongside this
 * controller, for the other half of making that machinery safely reachable.
 */
final class Import_Controller {

	/**
	 * Providers and targets available to the current user.
	 *
	 * Import_Registry::describe() itself does not filter by capability (only
	 * Export_Registry::describe() does) — filtered here instead, since this
	 * is the one place a capability check protects every caller.
	 *
	 * @return array<string, mixed>
	 */
	public static function describe(): array {
		$described = Import_Registry::describe();

		$described['targets'] = array_values(
			array_filter(
				$described['targets'],
				static function ( array $target ): bool {
					$definition = Import_Registry::target( (string) $target['entity'] );

					return null !== $definition && current_user_can( (string) $definition['capability'] );
				}
			)
		);

		return $described;
	}

	/**
	 * Read-only sample of a source, with no writes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function preview( WP_REST_Request $request ) {
		$source = (array) $request->get_param( 'source' );
		$limit  = min( 500, (int) $request->get_param( 'limit' ) ?: 10 );

		return Import_Engine::preview( $source, $limit );
	}

	/**
	 * Suggested field mapping for a source against a target entity.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function mapping( WP_REST_Request $request ) {
		$source = (array) $request->get_param( 'source' );
		$entity = (string) $request->get_param( 'entity' );

		return Import_Engine::mapping( $source, $entity );
	}

	/**
	 * Start (or dry-run) an import.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function start( WP_REST_Request $request ) {
		return Import_Engine::start(
			array(
				'source'  => (array) $request->get_param( 'source' ),
				'entity'  => (string) $request->get_param( 'entity' ),
				'mapping' => (array) $request->get_param( 'mapping' ),
				'dry_run' => (bool) $request->get_param( 'dry_run' ),
			)
		);
	}

	/**
	 * Every stored run, newest first.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function runs( WP_REST_Request $request ) {
		unset( $request );

		return array( 'runs' => Import_Engine::runs() );
	}

	/**
	 * A single run.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function run( WP_REST_Request $request ) {
		$run = Import_Engine::run( (int) $request->get_param( 'id' ) );

		return null === $run
			? new \WP_Error( 'eventos_import_unknown_run', __( 'Import run not found.', 'eventos' ), array( 'status' => 404 ) )
			: $run;
	}

	/**
	 * Undo a completed run.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function rollback( WP_REST_Request $request ) {
		return Import_Engine::rollback( (int) $request->get_param( 'id' ) );
	}
}

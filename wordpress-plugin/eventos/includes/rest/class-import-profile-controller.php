<?php
/**
 * REST surface for the Import Profile layer (Phase 3) — discovery, mapping
 * review, preview and starting a profile-driven import/bundle.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use EventOS\Import\Import_Registry;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Same discipline as {@see Import_Controller}: nothing here talks to a
 * target writer, identity resolver or repository directly — every method
 * only ever calls {@see Import_Registry}, which in turn only ever calls the
 * unchanged {@see \EventOS\Import\Import_Engine}/{@see \EventOS\Import\Ticketing_Import_Orchestrator}.
 * This controller adds no persistence and no new execution path.
 */
final class Import_Profile_Controller {

	/**
	 * Every registered Import Profile.
	 *
	 * @return array<string, mixed>
	 */
	public static function profiles(): array {
		return array( 'profiles' => array_values( Import_Registry::profiles() ) );
	}

	/**
	 * A single Import Profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function profile( WP_REST_Request $request ) {
		$profile = Import_Registry::profile( (string) $request->get_param( 'id' ) );

		return null === $profile
			? new WP_Error( 'eventos_import_profile_unknown', __( 'Unknown import profile.', 'eventos' ), array( 'status' => 404 ) )
			: $profile;
	}

	/**
	 * Detect a source's columns and resolve the profile's default mapping
	 * for one stage — the read-only suggestion an admin UI reviews/edits
	 * before an import starts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function resolve_mapping( WP_REST_Request $request ) {
		return Import_Registry::resolve_profile_mapping(
			(string) $request->get_param( 'id' ),
			(string) $request->get_param( 'entity' ),
			(array) $request->get_param( 'source' )
		);
	}

	/**
	 * Validate a mapping (the resolved default, or an administrator's
	 * edited version) before an import is allowed to start.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function validate_mapping( WP_REST_Request $request ) {
		$errors = Import_Registry::validate_profile_mapping(
			(string) $request->get_param( 'entity' ),
			(array) $request->get_param( 'mapping' ),
			(array) $request->get_param( 'columns' )
		);

		if ( is_wp_error( $errors ) ) {
			return $errors;
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Preview a small batch of source rows exactly as mapping/normalization
	 * will transform them, without persisting anything.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function preview( WP_REST_Request $request ) {
		$limit = (int) $request->get_param( 'limit' ) ?: 10;

		return Import_Registry::preview_profile_mapping(
			(string) $request->get_param( 'id' ),
			(string) $request->get_param( 'entity' ),
			(array) $request->get_param( 'source' ),
			(array) $request->get_param( 'mapping' ),
			$limit
		);
	}

	/**
	 * Start a single-stage profile-driven import.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function start( WP_REST_Request $request ) {
		return Import_Registry::start_profile_import(
			(string) $request->get_param( 'id' ),
			(string) $request->get_param( 'entity' ),
			(array) $request->get_param( 'source' ),
			(array) $request->get_param( 'mapping' )
		);
	}

	/**
	 * Start a multi-stage bundle import.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function bundle( WP_REST_Request $request ) {
		return Import_Registry::start_profile_bundle(
			(string) $request->get_param( 'id' ),
			(array) $request->get_param( 'stage_sources' ),
			(array) $request->get_param( 'stage_mappings' )
		);
	}
}

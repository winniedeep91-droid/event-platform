<?php
/**
 * REST surface for global and single-entity search.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use EventOS\Search_Registry;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper over {@see Search_Registry} — every authorization decision
 * (which entities a user may search, and whether they may search a given
 * one at all) is enforced inside the registry itself, not here, so it
 * applies uniformly to every caller rather than only this one route.
 */
final class Search_Controller {

	/**
	 * Below this length a term is treated the same as an empty one: no
	 * results, not an error. A one-character global search against eight
	 * entity types would be slow and useless, not merely imprecise.
	 */
	private const MIN_TERM_LENGTH = 2;

	/**
	 * Entities the current user may search.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function entities(): array {
		return Search_Registry::describe();
	}

	/**
	 * Search every entity the user may access, grouped by entity.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function search( WP_REST_Request $request ): array {
		$term = trim( (string) $request->get_param( 'q' ) );

		if ( '' === $term || mb_strlen( $term ) < self::MIN_TERM_LENGTH ) {
			return array( 'term' => $term, 'groups' => array() );
		}

		$entities = (array) ( $request->get_param( 'entities' ) ?? array() );
		$entities = array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', $entities ) ) ) );

		$per_page = (int) ( $request->get_param( 'per_page' ) ?: 8 );

		$groups = Search_Registry::search_all( $term, array( 'per_page' => $per_page ), $entities );

		return array( 'term' => $term, 'groups' => $groups );
	}

	/**
	 * Search a single entity with full pagination — the same query a list
	 * screen would use to filter itself, reusing the identical registration.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function query( WP_REST_Request $request ) {
		$entity = sanitize_key( (string) $request->get_param( 'entity' ) );

		return Search_Registry::query(
			$entity,
			array(
				'term'     => (string) $request->get_param( 'q' ),
				'orderby'  => (string) $request->get_param( 'orderby' ),
				'order'    => (string) $request->get_param( 'order' ),
				'page'     => (int) ( $request->get_param( 'page' ) ?: 1 ),
				'per_page' => (int) ( $request->get_param( 'per_page' ) ?: 20 ),
			)
		);
	}
}

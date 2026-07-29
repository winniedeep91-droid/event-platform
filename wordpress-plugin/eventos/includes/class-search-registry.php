<?php
/**
 * Global search registry and service.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One searchable entity registry shared by every module.
 *
 * A module declares its entity once — searchable fields, filterable fields,
 * sortable columns and a query callback — and gets global search, list table
 * filtering and REST querying without writing any of that plumbing.
 */
final class Search_Registry {

	/**
	 * Registered entities keyed by slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $entities = array();

	/**
	 * Whether the registration hook has fired.
	 *
	 * @var bool
	 */
	private static bool $bootstrapped = false;

	/**
	 * Let modules register their searchable entities.
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		if ( self::$bootstrapped ) {
			return;
		}

		self::$bootstrapped = true;

		/**
		 * Fires so modules can register searchable entities.
		 *
		 * @param string $registry Registry class name.
		 */
		do_action( 'eventos_register_search_entities', __CLASS__ );
	}

	/**
	 * Register a searchable entity.
	 *
	 * Accepted keys: entity, label, module, capability, icon, searchable,
	 * filterable, sortable, default_sort, default_order, query.
	 *
	 * The query callback receives an arguments array with keys term, filters,
	 * orderby, order, page, per_page and returns
	 * array( 'items' => array<int, array<string, mixed>>, 'total' => int ).
	 * Each item should provide id, title, and may provide subtitle, url, status
	 * and meta.
	 *
	 * @param array<string, mixed> $entity Entity definition.
	 * @return void
	 */
	public static function register( array $entity ): void {
		if ( empty( $entity['entity'] ) || empty( $entity['query'] ) || ! is_callable( $entity['query'] ) ) {
			return;
		}

		$slug = sanitize_key( (string) $entity['entity'] );

		self::$entities[ $slug ] = wp_parse_args(
			$entity,
			array(
				'entity'        => $slug,
				'label'         => $slug,
				'module'        => 'core',
				'capability'    => Capabilities::VIEW_DASHBOARD,
				'icon'          => 'search',
				'searchable'    => array(),
				'filterable'    => array(),
				'sortable'      => array(),
				'default_sort'  => '',
				'default_order' => 'desc',
			)
		);
	}

	/**
	 * Register several entities at once.
	 *
	 * @param array<int, array<string, mixed>> $entities Entity definitions.
	 * @return void
	 */
	public static function register_many( array $entities ): void {
		foreach ( $entities as $entity ) {
			if ( is_array( $entity ) ) {
				self::register( $entity );
			}
		}
	}

	/**
	 * All registered entities.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		self::bootstrap();

		return self::$entities;
	}

	/**
	 * A single entity definition.
	 *
	 * @param string $slug Entity slug.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $slug ): ?array {
		self::bootstrap();

		return self::$entities[ sanitize_key( $slug ) ] ?? null;
	}

	/**
	 * Describe the entities the current user may search.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function describe(): array {
		$report = array();

		foreach ( self::all() as $slug => $entity ) {
			if ( ! current_user_can( (string) $entity['capability'] ) ) {
				continue;
			}

			$report[] = array(
				'entity'        => $slug,
				'label'         => (string) $entity['label'],
				'module'        => (string) $entity['module'],
				'icon'          => (string) $entity['icon'],
				'searchable'    => array_values( (array) $entity['searchable'] ),
				'filterable'    => (array) $entity['filterable'],
				'sortable'      => array_values( (array) $entity['sortable'] ),
				'default_sort'  => (string) $entity['default_sort'],
				'default_order' => (string) $entity['default_order'],
			);
		}

		return $report;
	}

	/**
	 * Query a single entity.
	 *
	 * @param string               $slug Entity slug.
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}|WP_Error
	 */
	public static function query( string $slug, array $args = array() ) {
		$entity = self::get( $slug );

		if ( null === $entity ) {
			return new WP_Error(
				'eventos_search_unknown_entity',
				__( 'Unknown search entity.', 'eventos' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( (string) $entity['capability'] ) ) {
			return new WP_Error(
				'eventos_forbidden',
				__( 'You are not allowed to search this data.', 'eventos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$args = self::normalise_args( $entity, $args );

		$result = call_user_func( $entity['query'], $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = array();

		foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
			$item = (array) $item;

			$items[] = array(
				'entity'   => (string) $entity['entity'],
				'id'       => (string) ( $item['id'] ?? '' ),
				'title'    => (string) ( $item['title'] ?? '' ),
				'subtitle' => (string) ( $item['subtitle'] ?? '' ),
				'status'   => (string) ( $item['status'] ?? '' ),
				'url'      => (string) ( $item['url'] ?? '' ),
				'meta'     => (array) ( $item['meta'] ?? array() ),
			);
		}

		return array(
			'items'    => $items,
			'total'    => (int) ( $result['total'] ?? count( $items ) ),
			'page'     => (int) $args['page'],
			'per_page' => (int) $args['per_page'],
		);
	}

	/**
	 * Search every entity the user may access.
	 *
	 * @param string               $term     Search term.
	 * @param array<string, mixed> $args     Extra query arguments.
	 * @param string[]             $entities Restrict to these entity slugs.
	 * @return array<int, array<string, mixed>>
	 */
	public static function search_all( string $term, array $args = array(), array $entities = array() ): array {
		$groups = array();

		foreach ( self::all() as $slug => $entity ) {
			if ( $entities && ! in_array( $slug, $entities, true ) ) {
				continue;
			}

			if ( ! current_user_can( (string) $entity['capability'] ) ) {
				continue;
			}

			$result = self::query( $slug, array_merge( $args, array( 'term' => $term ) ) );

			if ( is_wp_error( $result ) || ! $result['items'] ) {
				continue;
			}

			$groups[] = array(
				'entity' => $slug,
				'label'  => (string) $entity['label'],
				'total'  => $result['total'],
				'items'  => $result['items'],
			);
		}

		return $groups;
	}

	/**
	 * Clamp and sanitise query arguments against the entity declaration.
	 *
	 * @param array<string, mixed> $entity Entity definition.
	 * @param array<string, mixed> $args   Raw arguments.
	 * @return array<string, mixed>
	 */
	private static function normalise_args( array $entity, array $args ): array {
		$sortable = array_map( 'strval', (array) $entity['sortable'] );
		$allowed  = array_keys( (array) $entity['filterable'] );
		$orderby  = isset( $args['orderby'] ) ? sanitize_key( (string) $args['orderby'] ) : '';
		$filters  = array();

		foreach ( (array) ( $args['filters'] ?? array() ) as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( in_array( $key, $allowed, true ) ) {
				$filters[ $key ] = is_array( $value ) ? array_map( 'sanitize_text_field', array_map( 'strval', $value ) ) : sanitize_text_field( (string) $value );
			}
		}

		return array(
			'term'       => sanitize_text_field( (string) ( $args['term'] ?? '' ) ),
			'filters'    => $filters,
			'orderby'    => in_array( $orderby, $sortable, true ) ? $orderby : (string) $entity['default_sort'],
			'order'      => 'asc' === strtolower( (string) ( $args['order'] ?? $entity['default_order'] ) ) ? 'asc' : 'desc',
			'page'       => max( 1, (int) ( $args['page'] ?? 1 ) ),
			'per_page'   => min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) ),
			'searchable' => array_map( 'strval', (array) $entity['searchable'] ),
		);
	}
}

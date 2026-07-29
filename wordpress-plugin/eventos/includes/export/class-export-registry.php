<?php
/**
 * Registry of exportable entities.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Modules register what can be exported; formats live in Export_Service.
 */
final class Export_Registry {

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
	 * Let modules register their exportable entities.
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		if ( self::$bootstrapped ) {
			return;
		}

		self::$bootstrapped = true;

		/**
		 * Fires so modules can register exportable entities.
		 *
		 * @param string $registry Registry class name.
		 */
		do_action( 'eventos_register_exports', __CLASS__ );
	}

	/**
	 * Register an exportable entity.
	 *
	 * Accepted keys: entity, label, capability, module, columns (key => label),
	 * provider (callable(array $args): iterable of associative rows), formats,
	 * filename.
	 *
	 * @param array<string, mixed> $entity Entity definition.
	 * @return void
	 */
	public static function register( array $entity ): void {
		if ( empty( $entity['entity'] ) || empty( $entity['provider'] ) || ! is_callable( $entity['provider'] ) ) {
			return;
		}

		$slug = sanitize_key( (string) $entity['entity'] );

		self::$entities[ $slug ] = wp_parse_args(
			$entity,
			array(
				'entity'     => $slug,
				'label'      => $slug,
				'capability' => 'eventos_run_exports',
				'module'     => 'core',
				'columns'    => array(),
				'formats'    => Export_Service::formats(),
				'filename'   => $slug,
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
	 * Entities the current user may export.
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
				'entity'  => $slug,
				'label'   => (string) $entity['label'],
				'module'  => (string) $entity['module'],
				'columns' => (array) $entity['columns'],
				'formats' => array_values( (array) $entity['formats'] ),
			);
		}

		return $report;
	}
}

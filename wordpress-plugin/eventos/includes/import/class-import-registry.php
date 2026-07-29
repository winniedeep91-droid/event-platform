<?php
/**
 * Registry of import providers and import targets.
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
 * Holds every pluggable importer and every entity that can be imported into.
 *
 * Modules register a target (the thing being written) and, optionally, extra
 * providers (the system being read). Neither side knows about the other.
 */
final class Import_Registry {

	/**
	 * Registered providers keyed by slug.
	 *
	 * @var array<string, Import_Provider_Interface>
	 */
	private static array $providers = array();

	/**
	 * Registered targets keyed by entity slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $targets = array();

	/**
	 * Whether the built in providers have been registered.
	 *
	 * @var bool
	 */
	private static bool $bootstrapped = false;

	/**
	 * Register the providers shipped with EventOS.
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		if ( self::$bootstrapped ) {
			return;
		}

		self::$bootstrapped = true;

		self::register( new Providers\Csv_Provider() );
		self::register( new Providers\WooCommerce_Provider() );
		self::register( new Providers\Quicket_Provider() );
		self::register( new Providers\Howler_Provider() );
		self::register( new Providers\Webtickets_Provider() );
		self::register( new Providers\Fixr_Provider() );

		/**
		 * Fires so modules can register additional import providers and targets.
		 *
		 * @param string $registry Registry class name.
		 */
		do_action( 'eventos_register_import_providers', __CLASS__ );
	}

	/**
	 * Register an import provider.
	 *
	 * @param Import_Provider_Interface $provider Provider instance.
	 * @return void
	 */
	public static function register( Import_Provider_Interface $provider ): void {
		self::$providers[ $provider->slug() ] = $provider;
	}

	/**
	 * All registered providers.
	 *
	 * @return array<string, Import_Provider_Interface>
	 */
	public static function providers(): array {
		self::bootstrap();

		return self::$providers;
	}

	/**
	 * A single provider.
	 *
	 * @param string $slug Provider slug.
	 * @return Import_Provider_Interface|null
	 */
	public static function provider( string $slug ): ?Import_Provider_Interface {
		self::bootstrap();

		return self::$providers[ $slug ] ?? null;
	}

	/**
	 * Find the provider that recognises a source definition.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @return Import_Provider_Interface|null
	 */
	public static function detect( array $source ): ?Import_Provider_Interface {
		foreach ( self::providers() as $provider ) {
			if ( $provider->detect( $source ) ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Register an entity that can be imported into.
	 *
	 * Accepted keys: entity, label, capability, module, fields, writer, deleter.
	 * A field is array( 'label' => '', 'required' => bool, 'type' => 'string',
	 * 'aliases' => array() ).
	 *
	 * @param array<string, mixed> $target Target definition.
	 * @return void
	 */
	public static function register_target( array $target ): void {
		if ( empty( $target['entity'] ) || empty( $target['writer'] ) || ! is_callable( $target['writer'] ) ) {
			return;
		}

		$entity = sanitize_key( (string) $target['entity'] );

		$target = wp_parse_args(
			$target,
			array(
				'entity'     => $entity,
				'label'      => $entity,
				'capability' => 'eventos_run_imports',
				'module'     => 'core',
				'fields'     => array(),
				'deleter'    => null,
			)
		);

		foreach ( $target['fields'] as $key => $field ) {
			$target['fields'][ $key ] = wp_parse_args(
				(array) $field,
				array(
					'label'    => (string) $key,
					'required' => false,
					'type'     => 'string',
					'aliases'  => array(),
				)
			);
		}

		self::$targets[ $entity ] = $target;
	}

	/**
	 * All registered targets.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function targets(): array {
		self::bootstrap();

		return self::$targets;
	}

	/**
	 * A single target definition.
	 *
	 * @param string $entity Entity slug.
	 * @return array<string, mixed>|null
	 */
	public static function target( string $entity ): ?array {
		self::bootstrap();

		return self::$targets[ sanitize_key( $entity ) ] ?? null;
	}

	/**
	 * Validate a mapped record against the target field definitions.
	 *
	 * @param string               $entity Entity slug.
	 * @param array<string, mixed> $record Mapped record.
	 * @return true|WP_Error
	 */
	public static function validate_record( string $entity, array $record ) {
		$target = self::target( $entity );

		if ( null === $target ) {
			return new WP_Error( 'eventos_import_unknown_entity', __( 'Unknown import target.', 'eventos' ) );
		}

		foreach ( $target['fields'] as $key => $field ) {
			$value = $record[ $key ] ?? null;

			if ( ! empty( $field['required'] ) && ( null === $value || '' === $value ) ) {
				return new WP_Error(
					'eventos_import_missing_field',
					sprintf(
						/* translators: %s: field label. */
						__( 'Missing required value for "%s".', 'eventos' ),
						(string) $field['label']
					)
				);
			}

			if ( null === $value || '' === $value ) {
				continue;
			}

			if ( 'email' === $field['type'] && ! is_email( (string) $value ) ) {
				return new WP_Error(
					'eventos_import_invalid_email',
					sprintf(
						/* translators: %s: field label. */
						__( '"%s" is not a valid email address.', 'eventos' ),
						(string) $field['label']
					)
				);
			}

			if ( 'number' === $field['type'] && ! is_numeric( $value ) ) {
				return new WP_Error(
					'eventos_import_invalid_number',
					sprintf(
						/* translators: %s: field label. */
						__( '"%s" must be a number.', 'eventos' ),
						(string) $field['label']
					)
				);
			}
		}

		return true;
	}

	/**
	 * Describe providers and targets for the admin UI and REST API.
	 *
	 * @return array<string, mixed>
	 */
	public static function describe(): array {
		$providers = array();

		foreach ( self::providers() as $slug => $provider ) {
			$ready = method_exists( $provider, 'readiness' ) ? $provider->readiness() : true;

			$providers[] = array(
				'slug'        => $slug,
				'label'       => $provider->label(),
				'description' => $provider->description(),
				'entities'    => $provider->entities(),
				'ready'       => ! is_wp_error( $ready ),
				'status'      => is_wp_error( $ready ) ? $ready->get_error_message() : '',
			);
		}

		$targets = array();

		foreach ( self::targets() as $entity => $target ) {
			$targets[] = array(
				'entity' => $entity,
				'label'  => (string) $target['label'],
				'module' => (string) $target['module'],
				'fields' => $target['fields'],
			);
		}

		return array(
			'providers' => $providers,
			'targets'   => $targets,
		);
	}
}

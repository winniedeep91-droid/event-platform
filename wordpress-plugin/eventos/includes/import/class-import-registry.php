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
	 * Registered Import Profiles keyed by profile id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $profiles = array();

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

		self::register_profile( Profiles\Generic_Csv_Profile::definition() );

		foreach ( Profiles\Platform_Profile_Stubs::definitions() as $stub ) {
			self::register_profile( $stub );
		}

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
	 * Register an Import Profile — declarative source-column-to-target-field
	 * mapping metadata for one exported file shape. A profile describes how
	 * to build the `mapping` {@see Import_Engine::start()} already accepts;
	 * it never touches persistence, identity resolution or orchestration.
	 *
	 * Accepted keys: id, name, provider, format, version, status,
	 * description, bundle (ordered entity slugs for a multi-stage import),
	 * stages (entity slug => ['fields' => [...]], see
	 * {@see Import_Profile_Mapper::resolve_mapping()} for the field spec shape).
	 *
	 * @param array<string, mixed> $profile Profile definition.
	 * @return void
	 */
	public static function register_profile( array $profile ): void {
		$id = sanitize_key( (string) ( $profile['id'] ?? '' ) );

		if ( '' === $id ) {
			return;
		}

		self::$profiles[ $id ] = wp_parse_args(
			$profile,
			array(
				'id'          => $id,
				'name'        => $id,
				'provider'    => '',
				'format'      => 'csv',
				'version'     => '1.0.0',
				'status'      => 'ready',
				'description' => '',
				'bundle'      => array(),
				'stages'      => array(),
			)
		);
	}

	/**
	 * Every registered Import Profile.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function profiles(): array {
		self::bootstrap();

		return self::$profiles;
	}

	/**
	 * A single Import Profile.
	 *
	 * @param string $id Profile id.
	 * @return array<string, mixed>|null
	 */
	public static function profile( string $id ): ?array {
		self::bootstrap();

		return self::$profiles[ sanitize_key( $id ) ] ?? null;
	}

	/**
	 * Start a single-stage import using a registered profile's field
	 * mapping for that stage — resolves the mapping, then hands off to the
	 * unchanged {@see Import_Engine::start()}.
	 *
	 * @param string                $profile_id Profile id.
	 * @param string                $entity     Target entity/stage slug.
	 * @param array<string, mixed>  $source     Source definition for this stage.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function start_profile_import( string $profile_id, string $entity, array $source ) {
		$profile = self::profile( $profile_id );

		if ( null === $profile ) {
			return new WP_Error( 'eventos_import_profile_unknown', __( 'Unknown import profile.', 'eventos' ), array( 'status' => 404 ) );
		}

		$preview = Import_Engine::preview( $source, 1 );

		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		$mapping = Import_Profile_Mapper::resolve_mapping( $profile, $entity, (array) $preview['columns'] );

		if ( is_wp_error( $mapping ) ) {
			return $mapping;
		}

		return Import_Engine::start(
			array(
				'source'  => $source,
				'entity'  => $entity,
				'mapping' => $mapping,
			)
		);
	}

	/**
	 * Start a multi-stage bundle import using a profile's declared stage
	 * order and per-stage field mapping — resolves every stage's mapping,
	 * then hands off entirely to the unchanged
	 * {@see Ticketing_Import_Orchestrator::run_bundle()} for execution and
	 * chaining. A profile only ever *describes* the bundle; the orchestrator
	 * still owns running it.
	 *
	 * @param string                                $profile_id    Profile id.
	 * @param array<string, array<string, mixed>>    $stage_sources Entity slug => that stage's own source definition.
	 * @return array<string, mixed>|WP_Error The first stage's run record.
	 */
	public static function start_profile_bundle( string $profile_id, array $stage_sources ) {
		$profile = self::profile( $profile_id );

		if ( null === $profile ) {
			return new WP_Error( 'eventos_import_profile_unknown', __( 'Unknown import profile.', 'eventos' ), array( 'status' => 404 ) );
		}

		$stages = ! empty( $profile['bundle'] ) ? (array) $profile['bundle'] : array_keys( (array) $profile['stages'] );
		$stages = array_values( array_intersect( $stages, array_keys( $stage_sources ) ) );

		if ( empty( $stages ) ) {
			return new WP_Error( 'eventos_import_profile_no_bundle', __( 'This profile has no bundle stages matching the given sources.', 'eventos' ), array( 'status' => 400 ) );
		}

		$stage_mappings = array();

		foreach ( $stages as $entity ) {
			$preview = Import_Engine::preview( $stage_sources[ $entity ], 1 );

			if ( is_wp_error( $preview ) ) {
				return $preview;
			}

			$mapping = Import_Profile_Mapper::resolve_mapping( $profile, $entity, (array) $preview['columns'] );

			if ( is_wp_error( $mapping ) ) {
				return $mapping;
			}

			$stage_mappings[ $entity ] = $mapping;
		}

		return Ticketing_Import_Orchestrator::run_bundle( $stage_sources, $stages, $stage_mappings );
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

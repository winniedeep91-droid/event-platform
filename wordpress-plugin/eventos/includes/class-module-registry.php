<?php
/**
 * Registry holding every registered EventOS module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

use EventOS\Admin\Asset_Manager;
use EventOS\Admin\Menu_Registry;
use EventOS\Rest\Rest_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Complete module management system.
 *
 * The registry resolves dependencies, applies each module's declaration to the
 * permission, settings, menu, REST and asset registries, runs activation and
 * upgrade hooks, and finally boots the module. Adding a module never requires a
 * change inside EventOS core.
 */
final class Module_Registry {

	/**
	 * Option storing the installed version of every module.
	 */
	public const VERSIONS_OPTION = 'eventos_module_versions';

	/**
	 * Option storing modules the site owner disabled.
	 */
	public const DISABLED_OPTION = 'eventos_disabled_modules';

	/**
	 * Registered modules keyed by slug.
	 *
	 * @var array<string, Module_Interface>
	 */
	private array $modules = array();

	/**
	 * Slugs that booted successfully.
	 *
	 * @var array<string, bool>
	 */
	private array $active = array();

	/**
	 * Slugs that could not boot, keyed by slug with a reason.
	 *
	 * @var array<string, string>
	 */
	private array $failed = array();

	/**
	 * Whether init() has already run.
	 *
	 * @var bool
	 */
	private bool $initialised = false;

	/**
	 * Register a module.
	 *
	 * @param Module_Interface $module Module instance.
	 * @return void
	 */
	public function add( Module_Interface $module ): void {
		$this->modules[ $module->slug() ] = $module;

		if ( $this->initialised ) {
			$this->boot_module( $module );
		}
	}

	/**
	 * Check whether a module slug is registered.
	 *
	 * @param string $slug Module slug.
	 * @return bool
	 */
	public function has( string $slug ): bool {
		return isset( $this->modules[ $slug ] );
	}

	/**
	 * Get a registered module.
	 *
	 * @param string $slug Module slug.
	 * @return Module_Interface|null
	 */
	public function get( string $slug ): ?Module_Interface {
		return $this->modules[ $slug ] ?? null;
	}

	/**
	 * All registered modules.
	 *
	 * @return array<string, Module_Interface>
	 */
	public function all(): array {
		return $this->modules;
	}

	/**
	 * Whether a module booted successfully.
	 *
	 * @param string $slug Module slug.
	 * @return bool
	 */
	public function is_active( string $slug ): bool {
		return ! empty( $this->active[ $slug ] );
	}

	/**
	 * Modules the site owner disabled.
	 *
	 * @return string[]
	 */
	public static function disabled(): array {
		$stored = get_option( self::DISABLED_OPTION, array() );

		return is_array( $stored ) ? array_values( array_map( 'sanitize_key', $stored ) ) : array();
	}

	/**
	 * Enable or disable a module for this installation.
	 *
	 * The core module can never be disabled.
	 *
	 * @param string $slug    Module slug.
	 * @param bool   $enabled Desired state.
	 * @return string[] The stored disabled list.
	 */
	public static function set_enabled( string $slug, bool $enabled ): array {
		$slug     = sanitize_key( $slug );
		$disabled = self::disabled();

		if ( $enabled || 'core' === $slug ) {
			$disabled = array_values( array_diff( $disabled, array( $slug ) ) );
		} elseif ( ! in_array( $slug, $disabled, true ) ) {
			$disabled[] = $slug;
		}

		update_option( self::DISABLED_OPTION, $disabled );

		return $disabled;
	}

	/**
	 * Describe every module for the admin UI and the REST API.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function describe(): array {
		$disabled = self::disabled();
		$versions = $this->installed_versions();
		$report   = array();

		foreach ( $this->modules as $slug => $module ) {
			$report[] = array(
				'slug'              => $slug,
				'name'              => $module->name(),
				'description'       => $module->description(),
				'version'           => $module->version(),
				'installed_version' => (string) ( $versions[ $slug ] ?? '' ),
				'dependencies'      => $module->dependencies(),
				'enabled'           => ! in_array( $slug, $disabled, true ),
				'active'            => $this->is_active( $slug ),
				'status_message'    => (string) ( $this->failed[ $slug ] ?? '' ),
				'permissions'       => array_keys( (array) ( $module->permissions()['capabilities'] ?? array() ) ),
				'menu_items'        => count( $module->menu_items() ),
				'rest_endpoints'    => count( $module->rest_endpoints() ),
				'settings_groups'   => array_keys( $module->settings() ),
			);
		}

		return $report;
	}

	/**
	 * Initialise every registered module once, in dependency order.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( $this->initialised ) {
			return;
		}

		$this->initialised = true;

		foreach ( $this->resolve_order() as $module ) {
			$this->boot_module( $module );
		}

		/**
		 * Fires after every EventOS module booted.
		 *
		 * @param Module_Registry $registry Module registry.
		 */
		do_action( 'eventos_modules_booted', $this );
	}

	/**
	 * Order modules so dependencies boot first.
	 *
	 * @return array<int, Module_Interface>
	 */
	private function resolve_order(): array {
		$ordered = array();
		$visited = array();

		$visit = function ( string $slug, array $stack ) use ( &$visit, &$ordered, &$visited ): void {
			if ( isset( $visited[ $slug ] ) || ! isset( $this->modules[ $slug ] ) || in_array( $slug, $stack, true ) ) {
				return;
			}

			$stack[]          = $slug;
			$visited[ $slug ] = true;

			foreach ( $this->modules[ $slug ]->dependencies() as $dependency ) {
				$visit( (string) $dependency, $stack );
			}

			$ordered[] = $this->modules[ $slug ];
		};

		foreach ( array_keys( $this->modules ) as $slug ) {
			$visit( (string) $slug, array() );
		}

		return $ordered;
	}

	/**
	 * Apply a module declaration and boot it.
	 *
	 * @param Module_Interface $module Module instance.
	 * @return void
	 */
	private function boot_module( Module_Interface $module ): void {
		$slug = $module->slug();

		if ( in_array( $slug, self::disabled(), true ) ) {
			$this->failed[ $slug ] = __( 'Disabled by the site administrator.', 'eventos' );

			return;
		}

		$missing = $this->missing_dependencies( $module );

		if ( $missing ) {
			$this->failed[ $slug ] = sprintf(
				/* translators: %s: comma separated module slugs. */
				__( 'Requires module(s): %s', 'eventos' ),
				implode( ', ', $missing )
			);

			Notifications::add(
				'error',
				/* translators: 1: module name, 2: missing dependencies. */
				sprintf( __( 'EventOS module "%1$s" could not start.', 'eventos' ), $module->name() ),
				$this->failed[ $slug ],
				array(
					'module'     => $slug,
					'persistent' => true,
					'key'        => 'module_dependency_' . $slug,
				)
			);

			return;
		}

		Permissions::bootstrap();
		Permissions::register_declaration( $module->permissions() );
		Settings::register_groups( $module->settings() );
		Menu_Registry::register_many( $module->menu_items(), $slug );
		Rest_Registry::register_many( $module->rest_endpoints(), $slug );
		Asset_Manager::register_many( $module->assets(), $slug );

		$this->run_lifecycle_hooks( $module );

		$module->init();

		$this->active[ $slug ] = true;

		/**
		 * Fires after a single EventOS module booted.
		 *
		 * @param Module_Interface $module Module instance.
		 */
		do_action( 'eventos_module_booted', $module );
	}

	/**
	 * Dependencies that are missing or not active.
	 *
	 * @param Module_Interface $module Module instance.
	 * @return string[]
	 */
	private function missing_dependencies( Module_Interface $module ): array {
		$missing = array();

		foreach ( $module->dependencies() as $dependency ) {
			$dependency = (string) $dependency;

			if ( ! $this->is_active( $dependency ) ) {
				$missing[] = $dependency;
			}
		}

		return $missing;
	}

	/**
	 * Run activation and upgrade hooks based on the stored module version.
	 *
	 * @param Module_Interface $module Module instance.
	 * @return void
	 */
	private function run_lifecycle_hooks( Module_Interface $module ): void {
		$slug      = $module->slug();
		$versions  = $this->installed_versions();
		$installed = (string) ( $versions[ $slug ] ?? '' );
		$current   = $module->version();

		if ( $installed === $current ) {
			return;
		}

		if ( '' === $installed ) {
			$module->activate();
		}

		$module->upgrade( $installed );

		$versions[ $slug ] = $current;
		update_option( self::VERSIONS_OPTION, $versions );

		/**
		 * Fires after a module's activation or upgrade routine ran.
		 *
		 * @param string $slug      Module slug.
		 * @param string $installed Previous version ('' on first activation).
		 * @param string $current   Version now installed.
		 */
		do_action( 'eventos_module_upgraded', $slug, $installed, $current );
	}

	/**
	 * Installed module versions.
	 *
	 * @return array<string, string>
	 */
	private function installed_versions(): array {
		$stored = get_option( self::VERSIONS_OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}
}

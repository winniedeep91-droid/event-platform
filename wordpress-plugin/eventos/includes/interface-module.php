<?php
/**
 * Module contract implemented by every EventOS module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for pluggable EventOS modules.
 *
 * A module is a self describing unit: it declares its identity, the modules it
 * depends on and everything it contributes to the platform (permissions, REST
 * endpoints, menu items, settings and assets). The Module_Registry consumes the
 * declaration, so a module never has to touch EventOS core to be installed.
 */
interface Module_Interface {

	/**
	 * Unique module slug.
	 *
	 * @return string
	 */
	public function slug(): string;

	/**
	 * Human readable module name.
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * Short description shown in the modules screen.
	 *
	 * @return string
	 */
	public function description(): string;

	/**
	 * Module version, used to trigger upgrade routines.
	 *
	 * @return string
	 */
	public function version(): string;

	/**
	 * Slugs of modules that must be present and active.
	 *
	 * @return string[]
	 */
	public function dependencies(): array;

	/**
	 * Capabilities and roles contributed by the module.
	 *
	 * Shape: array(
	 *   'capabilities' => array( 'eventos_do_thing' => 'Label' ),
	 *   'roles'        => array( 'slug' => array( 'label' => '', 'capabilities' => array() ) ),
	 *   'grants'       => array( 'role_slug' => array( 'eventos_do_thing' ) ),
	 * )
	 *
	 * @return array<string, mixed>
	 */
	public function permissions(): array;

	/**
	 * REST endpoint definitions passed to the REST registry.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function rest_endpoints(): array;

	/**
	 * Admin menu items passed to the menu registry.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function menu_items(): array;

	/**
	 * Settings groups passed to the settings registry.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function settings(): array;

	/**
	 * Assets passed to the asset manager.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function assets(): array;

	/**
	 * Runs once, the first time the module is activated.
	 *
	 * @return void
	 */
	public function activate(): void;

	/**
	 * Runs whenever the stored module version is behind the current one.
	 *
	 * @param string $from_version Previously installed version ('' on first install).
	 * @return void
	 */
	public function upgrade( string $from_version ): void;

	/**
	 * Register the module's WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void;
}

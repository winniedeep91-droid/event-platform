<?php
/**
 * Registry holding every registered EventOS module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple ordered registry of modules keyed by slug.
 */
final class Module_Registry {

	/**
	 * Registered modules.
	 *
	 * @var array<string, Module_Interface>
	 */
	private array $modules = array();

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
			$module->init();
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
	 * Initialise every registered module once.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( $this->initialised ) {
			return;
		}

		$this->initialised = true;

		foreach ( $this->modules as $module ) {
			$module->init();
		}
	}
}

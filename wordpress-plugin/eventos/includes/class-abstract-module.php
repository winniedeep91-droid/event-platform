<?php
/**
 * Convenience base class for EventOS modules.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements sane defaults for every part of the module contract.
 *
 * A module only overrides what it actually contributes.
 */
abstract class Abstract_Module implements Module_Interface {

	/**
	 * Short description shown in the modules screen.
	 *
	 * @return string
	 */
	public function description(): string {
		return '';
	}

	/**
	 * Module version.
	 *
	 * @return string
	 */
	public function version(): string {
		return EVENTOS_VERSION;
	}

	/**
	 * Module dependencies.
	 *
	 * @return string[]
	 */
	public function dependencies(): array {
		return array();
	}

	/**
	 * Permissions contributed by the module.
	 *
	 * @return array<string, mixed>
	 */
	public function permissions(): array {
		return array();
	}

	/**
	 * REST endpoints contributed by the module.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function rest_endpoints(): array {
		return array();
	}

	/**
	 * Menu items contributed by the module.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function menu_items(): array {
		return array();
	}

	/**
	 * Settings groups contributed by the module.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function settings(): array {
		return array();
	}

	/**
	 * Assets contributed by the module.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function assets(): array {
		return array();
	}

	/**
	 * First activation hook.
	 *
	 * @return void
	 */
	public function activate(): void {
	}

	/**
	 * Upgrade hook.
	 *
	 * @param string $from_version Previously installed version.
	 * @return void
	 */
	public function upgrade( string $from_version ): void {
		unset( $from_version );
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function init(): void {
	}
}

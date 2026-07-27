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
	 * Register the module's WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void;
}

<?php
/**
 * PSR-4 style autoloader mapped onto WordPress file naming conventions.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader for the EventOS namespace.
 */
final class Autoloader {

	/**
	 * Register the autoloader with SPL.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Resolve an EventOS class name to a file inside includes/.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( __NAMESPACE__ . '\\' ) );
		$parts    = explode( '\\', $relative );
		$short    = array_pop( $parts );
		$prefix   = 'interface-';

		if ( 0 === substr_compare( $short, 'Interface', -9 ) ) {
			$short = substr( $short, 0, -9 );
		} else {
			$prefix = 'class-';
		}

		$directories = array_map(
			static function ( string $part ): string {
				return strtolower( str_replace( '_', '-', $part ) );
			},
			$parts
		);

		$file = EVENTOS_PLUGIN_DIR . 'includes/'
			. ( $directories ? implode( '/', $directories ) . '/' : '' )
			. $prefix . strtolower( str_replace( '_', '-', $short ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}

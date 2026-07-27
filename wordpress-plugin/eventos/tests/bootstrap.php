<?php
/**
 * PHPUnit bootstrap for the WordPress test suite.
 *
 * Requires the WordPress core test library. Install it with:
 *   bin/install-wp-tests.sh wordpress_test root '' localhost latest
 * or set WP_TESTS_DIR to an existing checkout.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

$eventos_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $eventos_tests_dir ) {
	$eventos_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $eventos_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"Could not find the WordPress test library at {$eventos_tests_dir}." . PHP_EOL
		. 'Set WP_TESTS_DIR to a WordPress develop checkout before running PHPUnit.' . PHP_EOL
	);
	exit( 1 );
}

require_once $eventos_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/eventos.php';
	}
);

require $eventos_tests_dir . '/includes/bootstrap.php';

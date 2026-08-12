<?php
/**
 * PHPUnit bootstrap for the dependency-free unit test suite.
 *
 * These tests exercise pure PHP business logic that has no WordPress or
 * database dependency, and can run anywhere PHP + PHPUnit are installed —
 * `composer test:unit`. Everything else (anything touching $wpdb or a
 * WordPress function) belongs in the WordPress-integration suite under
 * tests/integration/ instead, which needs a real WordPress + MySQL test
 * environment; see tests/bootstrap.php for that one.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	// The classes under test only check ABSPATH is defined (the standard
	// WordPress direct-access guard) — the value itself is never used.
	define( 'ABSPATH', __DIR__ . '/' );
}

$eventos_plugin_dir = dirname( __DIR__, 2 );

require_once $eventos_plugin_dir . '/includes/events/class-ticket-identifier.php';
require_once $eventos_plugin_dir . '/includes/events/class-ticket-type-status.php';
require_once $eventos_plugin_dir . '/includes/events/class-campaign-status.php';

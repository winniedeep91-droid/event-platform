<?php
/**
 * Uninstall routine: removes EventOS data when the plugin is deleted.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$eventos_groups = array( 'general', 'branding', 'regional', 'security' );

foreach ( $eventos_groups as $eventos_group ) {
	delete_option( 'eventos_settings_' . $eventos_group );
}

delete_option( 'eventos_db_version' );
delete_option( 'eventos_installed_at' );
delete_transient( 'eventos_storage_usage' );

delete_metadata( 'user', 0, 'eventos_roles', '', true );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}eventos_invitations" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}eventos_activity_log" );
// phpcs:enable

wp_clear_scheduled_hook( 'eventos_daily_maintenance' );

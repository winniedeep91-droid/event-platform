<?php
/**
 * Uninstall routine: removes EventOS data when the plugin is deleted.
 *
 * Only fires when a site owner explicitly deletes the plugin through
 * WordPress's own Plugins screen — which already shows its own "this will
 * permanently delete your data" confirmation before calling this file, so
 * none is added here. Never runs on mere deactivation.
 *
 * The table, option, transient and cron-hook lists below are the
 * authoritative inventory of everything EventOS itself ever creates —
 * cross-referenced against every `class-*-schema.php` file, `class-installer.php`,
 * and every `*_OPTION`/transient/cron-hook constant in `includes/`, not
 * guessed. WordPress core tables, WooCommerce's own tables (orders,
 * products, customers), other plugins' data and WordPress users are never
 * touched — EventOS never owned any of that data to begin with.
 *
 * Every removal below is existence-safe (`DROP TABLE IF EXISTS`,
 * `delete_option()`/`delete_transient()` on a key that was never set, a
 * `DELETE` matching zero rows) so this runs cleanly regardless of which
 * optional modules were ever active, whether WooCommerce was ever
 * installed, or which schema versions were ever reached.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ---------------------------------------------------------------------
// Settings groups. The core four live in includes/class-settings.php;
// every module's settings() method (interface-module.php) can contribute
// more via Settings::register_groups() (class-module-registry.php) —
// currently only the Events module does, with its 'events' group.
// ---------------------------------------------------------------------
$eventos_groups = array( 'general', 'branding', 'regional', 'security', 'events' );

foreach ( $eventos_groups as $eventos_group ) {
	delete_option( 'eventos_settings_' . $eventos_group );
}

// ---------------------------------------------------------------------
// Every other fixed-key EventOS option — one entry per `*_OPTION` (or
// equivalent) constant defined anywhere under includes/.
// ---------------------------------------------------------------------
$eventos_options = array(
	// includes/class-installer.php
	'eventos_db_version',
	'eventos_installed_at',
	// includes/class-settings.php
	'eventos_branding_customized',
	// includes/class-capabilities.php
	'eventos_synced_capabilities_hash',
	// includes/class-module-registry.php
	'eventos_module_versions',
	'eventos_disabled_modules',
	// includes/events/class-event-schema.php
	'eventos_events_schema_version',
	// includes/crm/class-person-schema.php
	'eventos_crm_schema_version',
	// includes/crm/class-person-backfill-service.php
	'eventos_crm_backfill_runs',
	// includes/finance/class-finance-schema.php
	'eventos_finance_schema_version',
	// includes/marketing/class-marketing-schema.php
	'eventos_marketing_schema_version',
	// includes/woocommerce/class-wc-schema.php
	'eventos_wc_schema_version',
	// includes/woocommerce/class-wc-webhooks.php
	'eventos_wc_webhooks_enabled',
	// includes/woocommerce/class-wc-diagnostics.php
	'eventos_wc_last_checked',
	// includes/platform/class-sync-registry.php
	'eventos_sync_state',
	'eventos_sync_history',
	// includes/import/class-import-engine.php
	'eventos_import_runs',
);

foreach ( $eventos_options as $eventos_option ) {
	delete_option( $eventos_option );
}

// ---------------------------------------------------------------------
// Transients. `eventos_storage_usage` and `eventos_job_lock` are
// fixed-key; `eventos_notices_{user_id}` (includes/class-notifications.php)
// is keyed per user, so no finite list of delete_transient() calls could
// cover every site — a direct wildcard delete against wp_options is the
// only complete way to remove every one, matching how WordPress itself
// stores a transient as a `_transient_{key}` / `_transient_timeout_{key}`
// option pair when no external object cache is active.
// ---------------------------------------------------------------------
delete_transient( 'eventos_storage_usage' );
delete_transient( 'eventos_job_lock' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_eventos_notices_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_eventos_notices_' ) . '%'
	)
);

// ---------------------------------------------------------------------
// User meta (includes/class-capabilities.php).
// ---------------------------------------------------------------------
delete_metadata( 'user', 0, 'eventos_roles', '', true );

// ---------------------------------------------------------------------
// WP-Cron hooks. Both are scheduled with wp_schedule_event() and, unlike
// a plugin's own DB rows, persist independently of the plugin's files
// existing — leaving either scheduled would mean a hook WordPress can no
// longer find a callback for keeps firing (and erroring) indefinitely.
// ---------------------------------------------------------------------
wp_clear_scheduled_hook( 'eventos_daily_maintenance' ); // includes/class-cron.php
wp_clear_scheduled_hook( 'eventos_process_jobs' );       // includes/class-job-queue.php

// ---------------------------------------------------------------------
// Every EventOS-owned custom table, grouped by the schema class that owns
// it. WooCommerce's own tables (wp_wc_*, wp_posts shop_order rows, etc.)
// are never referenced here — EventOS reads WooCommerce's data live and
// never mirrors it into a table of its own.
// ---------------------------------------------------------------------
$eventos_tables = array(
	// includes/class-installer.php
	'eventos_invitations',
	'eventos_jobs',
	'eventos_activity_log',
	// includes/events/class-event-schema.php
	'eventos_events',
	'eventos_venues',
	'eventos_artists',
	'eventos_event_artists',
	'eventos_event_categories',
	'eventos_event_tags',
	'eventos_event_terms',
	'eventos_event_media',
	'eventos_event_schedules',
	'eventos_ticket_types',
	'eventos_tickets',
	'eventos_guests',
	'eventos_checkins',
	'eventos_campaigns',
	'eventos_promo_links',
	'eventos_waitlist_entries',
	'eventos_event_identities',
	// includes/crm/class-person-schema.php
	'eventos_persons',
	'eventos_person_identities',
	'eventos_person_tags',
	'eventos_person_notes',
	'eventos_person_consents',
	'eventos_segments',
	'eventos_person_segments',
	'eventos_reward_definitions',
	'eventos_person_rewards',
	'eventos_person_timeline_events',
	'eventos_person_merge_log',
	// includes/marketing/class-marketing-schema.php
	'eventos_marketing_audiences',
	'eventos_campaign_messages',
	'eventos_campaign_recipients',
	// includes/finance/class-finance-schema.php
	'eventos_expenses',
	// includes/woocommerce/class-wc-schema.php
	'eventos_wc_webhook_log',
);

foreach ( $eventos_tables as $eventos_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$eventos_table}" );
}

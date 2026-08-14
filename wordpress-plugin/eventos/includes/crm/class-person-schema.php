<?php
/**
 * Database schema owned by the CRM module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Crm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades every table the permanent Person / Relationship
 * layer needs.
 *
 * "Tickets belong to events. People belong to the brand." — these tables are
 * the global, brand-level counterpart to the event-scoped guest/ticket/
 * checkin tables the Events module owns. Phase 1 only establishes this
 * schema foundation: no existing table is touched, and nothing here is
 * populated yet (see the Final Implementation Specification, Section 17).
 */
final class Person_Schema {

	/**
	 * Schema version stored in the options table.
	 */
	public const VERSION = '1.0.0';

	/**
	 * Option holding the installed schema version.
	 */
	public const VERSION_OPTION = 'eventos_crm_schema_version';

	/**
	 * Prefixed table name.
	 *
	 * @param string $name Table suffix.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'eventos_' . $name;
	}

	/**
	 * Permanent Person records.
	 *
	 * @return string
	 */
	public static function persons(): string {
		return self::table( 'persons' );
	}

	/**
	 * Identity signals (wc_customer_id, email, phone) resolving to a Person.
	 *
	 * @return string
	 */
	public static function person_identities(): string {
		return self::table( 'person_identities' );
	}

	/**
	 * Global, brand-level Person tags.
	 *
	 * @return string
	 */
	public static function person_tags(): string {
		return self::table( 'person_tags' );
	}

	/**
	 * Internal staff notes attached to a Person.
	 *
	 * @return string
	 */
	public static function person_notes(): string {
		return self::table( 'person_notes' );
	}

	/**
	 * Per-channel marketing consent history.
	 *
	 * @return string
	 */
	public static function person_consents(): string {
		return self::table( 'person_consents' );
	}

	/**
	 * Segment rule definitions.
	 *
	 * @return string
	 */
	public static function segments(): string {
		return self::table( 'segments' );
	}

	/**
	 * Materialized Person/segment membership.
	 *
	 * @return string
	 */
	public static function person_segments(): string {
		return self::table( 'person_segments' );
	}

	/**
	 * Reward/entitlement definitions.
	 *
	 * @return string
	 */
	public static function reward_definitions(): string {
		return self::table( 'reward_definitions' );
	}

	/**
	 * Reward instances issued to a Person.
	 *
	 * @return string
	 */
	public static function person_rewards(): string {
		return self::table( 'person_rewards' );
	}

	/**
	 * Residual relationship-timeline entries with no other authoritative source.
	 *
	 * @return string
	 */
	public static function person_timeline_events(): string {
		return self::table( 'person_timeline_events' );
	}

	/**
	 * Audit trail for Person merges.
	 *
	 * @return string
	 */
	public static function person_merge_log(): string {
		return self::table( 'person_merge_log' );
	}

	/**
	 * Create or upgrade every CRM table.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$schema  = array();

		$persons = self::persons();

		$schema[] = "CREATE TABLE {$persons} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			display_name VARCHAR(191) NOT NULL DEFAULT '',
			first_name VARCHAR(100) NOT NULL DEFAULT '',
			last_name VARCHAR(100) NOT NULL DEFAULT '',
			primary_email VARCHAR(191) NOT NULL DEFAULT '',
			primary_phone VARCHAR(50) NOT NULL DEFAULT '',
			avatar_url VARCHAR(500) NOT NULL DEFAULT '',
			location VARCHAR(191) NOT NULL DEFAULT '',
			date_of_birth DATE NULL,
			total_events_attended INT UNSIGNED NOT NULL DEFAULT 0,
			total_tickets_purchased INT UNSIGNED NOT NULL DEFAULT 0,
			total_spend DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			avg_order_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			avg_ticket_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			vip_purchase_count INT UNSIGNED NOT NULL DEFAULT 0,
			complimentary_count INT UNSIGNED NOT NULL DEFAULT 0,
			refund_count INT UNSIGNED NOT NULL DEFAULT 0,
			cancellation_count INT UNSIGNED NOT NULL DEFAULT 0,
			first_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_purchase_at DATETIME NULL,
			last_attendance_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY primary_email (primary_email),
			KEY primary_phone (primary_phone)
		) {$collate};";

		$person_identities = self::person_identities();

		$schema[] = "CREATE TABLE {$person_identities} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			person_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL,
			value VARCHAR(191) NOT NULL,
			confidence VARCHAR(20) NOT NULL DEFAULT 'high',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY type_value (type, value),
			KEY person_id (person_id)
		) {$collate};";

		$person_tags = self::person_tags();

		$schema[] = "CREATE TABLE {$person_tags} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			person_id BIGINT UNSIGNED NOT NULL,
			tag VARCHAR(100) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY person_tag (person_id, tag),
			KEY tag (tag)
		) {$collate};";

		$person_notes = self::person_notes();

		$schema[] = "CREATE TABLE {$person_notes} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			person_id BIGINT UNSIGNED NOT NULL,
			author_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			body LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY person_id (person_id),
			KEY author_user_id (author_user_id)
		) {$collate};";

		$person_consents = self::person_consents();

		$schema[] = "CREATE TABLE {$person_consents} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			person_id BIGINT UNSIGNED NOT NULL,
			channel VARCHAR(30) NOT NULL,
			granted_at DATETIME NULL,
			source VARCHAR(100) NOT NULL DEFAULT '',
			revoked_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY person_channel (person_id, channel)
		) {$collate};";

		$segments = self::segments();

		$schema[] = "CREATE TABLE {$segments} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			rule_config LONGTEXT NULL,
			is_system TINYINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$collate};";

		$person_segments = self::person_segments();

		$schema[] = "CREATE TABLE {$person_segments} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			person_id BIGINT UNSIGNED NOT NULL,
			segment_id BIGINT UNSIGNED NOT NULL,
			computed_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY person_segment (person_id, segment_id),
			KEY segment_id (segment_id)
		) {$collate};";

		$reward_definitions = self::reward_definitions();

		$schema[] = "CREATE TABLE {$reward_definitions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			trigger_type VARCHAR(20) NOT NULL,
			trigger_config LONGTEXT NULL,
			reward_type VARCHAR(30) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY trigger_type (trigger_type),
			KEY status (status)
		) {$collate};";

		$person_rewards = self::person_rewards();

		$schema[] = "CREATE TABLE {$person_rewards} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			person_id BIGINT UNSIGNED NOT NULL,
			reward_definition_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'issued',
			issued_at DATETIME NOT NULL,
			redeemed_at DATETIME NULL,
			redemption_reference VARCHAR(191) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY person_id (person_id),
			KEY reward_definition_id (reward_definition_id),
			KEY status (status)
		) {$collate};";

		$person_timeline_events = self::person_timeline_events();

		$schema[] = "CREATE TABLE {$person_timeline_events} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			person_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(50) NOT NULL,
			payload_json LONGTEXT NULL,
			occurred_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY person_occurred (person_id, occurred_at),
			KEY type (type)
		) {$collate};";

		$person_merge_log = self::person_merge_log();

		$schema[] = "CREATE TABLE {$person_merge_log} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			primary_person_id BIGINT UNSIGNED NOT NULL,
			secondary_person_id BIGINT UNSIGNED NOT NULL,
			merged_by_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			merged_at DATETIME NOT NULL,
			pre_merge_snapshot LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY primary_person_id (primary_person_id),
			KEY secondary_person_id (secondary_person_id)
		) {$collate};";

		foreach ( $schema as $table_sql ) {
			dbDelta( $table_sql );
		}

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Install the schema when it is missing or outdated.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( (string) get_option( self::VERSION_OPTION, '' ) === self::VERSION ) {
			return;
		}

		self::install();
	}
}

<?php
/**
 * Database schema owned by the Events module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades every table the Events module needs.
 */
final class Event_Schema {

	/**
	 * Schema version stored in the options table.
	 */
	public const VERSION = '1.8.0';

	/**
	 * Option holding the installed schema version.
	 */
	public const VERSION_OPTION = 'eventos_events_schema_version';

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
	 * Events table.
	 *
	 * @return string
	 */
	public static function events(): string {
		return self::table( 'events' );
	}

	/**
	 * Venues table.
	 *
	 * @return string
	 */
	public static function venues(): string {
		return self::table( 'venues' );
	}

	/**
	 * Artists table.
	 *
	 * @return string
	 */
	public static function artists(): string {
		return self::table( 'artists' );
	}

	/**
	 * Event/artist relation table.
	 *
	 * @return string
	 */
	public static function event_artists(): string {
		return self::table( 'event_artists' );
	}

	/**
	 * Categories table.
	 *
	 * @return string
	 */
	public static function categories(): string {
		return self::table( 'event_categories' );
	}

	/**
	 * Tags table.
	 *
	 * @return string
	 */
	public static function tags(): string {
		return self::table( 'event_tags' );
	}

	/**
	 * Event/term relation table.
	 *
	 * @return string
	 */
	public static function event_terms(): string {
		return self::table( 'event_terms' );
	}

	/**
	 * Event media table.
	 *
	 * @return string
	 */
	public static function media(): string {
		return self::table( 'event_media' );
	}

	/**
	 * Event schedules table.
	 *
	 * @return string
	 */
	public static function schedules(): string {
		return self::table( 'event_schedules' );
	}

	/**
	 * Ticket types table.
	 *
	 * @return string
	 */
	public static function ticket_types(): string {
		return self::table( 'ticket_types' );
	}

	/**
	 * Individual ticket records table.
	 *
	 * @return string
	 */
	public static function tickets(): string {
		return self::table( 'tickets' );
	}

	/**
	 * Guests/attendees table.
	 *
	 * @return string
	 */
	public static function guests(): string {
		return self::table( 'guests' );
	}

	/**
	 * Check-in scan log table.
	 *
	 * @return string
	 */
	public static function checkins(): string {
		return self::table( 'checkins' );
	}

	/**
	 * Discount campaigns table.
	 *
	 * @return string
	 */
	public static function campaigns(): string {
		return self::table( 'campaigns' );
	}

	/**
	 * Promotional links table.
	 *
	 * @return string
	 */
	public static function promo_links(): string {
		return self::table( 'promo_links' );
	}

	/**
	 * Waitlist entries table.
	 *
	 * @return string
	 */
	public static function waitlist_entries(): string {
		return self::table( 'waitlist_entries' );
	}

	/**
	 * External/source identity signals (a WooCommerce product-group key, a
	 * Quicket event ID, ...) resolving to an Event.
	 *
	 * @return string
	 */
	public static function event_identities(): string {
		return self::table( 'event_identities' );
	}

	/**
	 * External/source identity signals (a Quicket ticket-type ID, ...)
	 * resolving to a Ticket Type. WooCommerce-sourced ticket types keep
	 * using `ticket_types.wc_product_id` directly — this table is only for
	 * sources with no such native column.
	 *
	 * @return string
	 */
	public static function ticket_type_identities(): string {
		return self::table( 'ticket_type_identities' );
	}

	/**
	 * External/source identity signals (e.g. a Quicket ticket ID) resolving
	 * to a Ticket. WooCommerce-sourced tickets keep using
	 * `tickets.wc_order_item_id` directly (via `exists_for_order_item()`)
	 * — this table is only for sources with no such native column.
	 *
	 * @return string
	 */
	public static function ticket_identities(): string {
		return self::table( 'ticket_identities' );
	}

	/**
	 * Create or upgrade every Events table.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$schema  = array();

		$events = self::events();
		$venues = self::venues();

		$schema[] = "CREATE TABLE {$events} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(191) NOT NULL,
			subtitle VARCHAR(191) NOT NULL DEFAULT '',
			slug VARCHAR(191) NOT NULL,
			description LONGTEXT NULL,
			short_description TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			visibility VARCHAR(20) NOT NULL DEFAULT 'public',
			password_hash VARCHAR(255) NOT NULL DEFAULT '',
			ticket_visibility VARCHAR(20) NOT NULL DEFAULT 'public',
			venue_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
			starts_at DATETIME NULL,
			ends_at DATETIME NULL,
			doors_open_at DATETIME NULL,
			capacity INT UNSIGNED NOT NULL DEFAULT 0,
			age_restriction VARCHAR(50) NOT NULL DEFAULT '',
			accessibility TEXT NULL,
			featured_image_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			organisers TEXT NULL,
			collaborators TEXT NULL,
			recurrence TEXT NULL,
			published_at DATETIME NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY status_starts (status, starts_at),
			KEY venue_id (venue_id),
			KEY starts_at (starts_at)
		) {$collate};";

		$schema[] = "CREATE TABLE {$venues} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			address_line1 VARCHAR(191) NOT NULL DEFAULT '',
			address_line2 VARCHAR(191) NOT NULL DEFAULT '',
			city VARCHAR(120) NOT NULL DEFAULT '',
			province VARCHAR(120) NOT NULL DEFAULT '',
			postal_code VARCHAR(30) NOT NULL DEFAULT '',
			country VARCHAR(2) NOT NULL DEFAULT '',
			latitude DECIMAL(10,7) NULL,
			longitude DECIMAL(10,7) NULL,
			maps_url VARCHAR(255) NOT NULL DEFAULT '',
			parking_info TEXT NULL,
			capacity INT UNSIGNED NOT NULL DEFAULT 0,
			seating_configuration TEXT NULL,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY city (city)
		) {$collate};";

		$artists = self::artists();

		$schema[] = "CREATE TABLE {$artists} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			biography LONGTEXT NULL,
			genres TEXT NULL,
			social_links TEXT NULL,
			website VARCHAR(255) NOT NULL DEFAULT '',
			country VARCHAR(2) NOT NULL DEFAULT '',
			image_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY name (name)
		) {$collate};";

		$event_artists = self::event_artists();

		$schema[] = "CREATE TABLE {$event_artists} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			artist_id BIGINT UNSIGNED NOT NULL,
			billing VARCHAR(50) NOT NULL DEFAULT 'support',
			stage VARCHAR(120) NOT NULL DEFAULT '',
			starts_at DATETIME NULL,
			ends_at DATETIME NULL,
			position INT NOT NULL DEFAULT 0,
			notes TEXT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_artist (event_id, artist_id),
			KEY artist_id (artist_id)
		) {$collate};";

		$categories = self::categories();

		$schema[] = "CREATE TABLE {$categories} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			description TEXT NULL,
			parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY parent_id (parent_id)
		) {$collate};";

		$tags = self::tags();

		$schema[] = "CREATE TABLE {$tags} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$collate};";

		$event_terms = self::event_terms();

		$schema[] = "CREATE TABLE {$event_terms} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			term_id BIGINT UNSIGNED NOT NULL,
			taxonomy VARCHAR(20) NOT NULL DEFAULT 'category',
			PRIMARY KEY  (id),
			UNIQUE KEY event_term (event_id, term_id, taxonomy),
			KEY taxonomy_term (taxonomy, term_id)
		) {$collate};";

		$media = self::media();

		$schema[] = "CREATE TABLE {$media} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			attachment_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'gallery',
			title VARCHAR(191) NOT NULL DEFAULT '',
			position INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_attachment (event_id, attachment_id, type),
			KEY event_id (event_id)
		) {$collate};";

		$schedules = self::schedules();

		$schema[] = "CREATE TABLE {$schedules} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			label VARCHAR(191) NOT NULL DEFAULT '',
			type VARCHAR(20) NOT NULL DEFAULT 'performance',
			stage VARCHAR(120) NOT NULL DEFAULT '',
			artist_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			starts_at DATETIME NULL,
			ends_at DATETIME NULL,
			position INT NOT NULL DEFAULT 0,
			notes TEXT NULL,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY artist_id (artist_id),
			KEY starts_at (starts_at)
		) {$collate};";

		$ticket_types = self::ticket_types();

		$schema[] = "CREATE TABLE {$ticket_types} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(191) NOT NULL,
			description TEXT NULL,
			tier VARCHAR(30) NOT NULL DEFAULT 'standard',
			price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			capacity INT UNSIGNED NULL,
			visibility VARCHAR(20) NOT NULL DEFAULT 'public',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			sale_start DATETIME NULL,
			sale_end DATETIME NULL,
			min_per_order INT UNSIGNED NOT NULL DEFAULT 1,
			max_per_order INT UNSIGNED NULL,
			waitlist_enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
			wc_product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			position INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY wc_product_id (wc_product_id),
			KEY status (status)
		) {$collate};";

		$tickets = self::tickets();

		$schema[] = "CREATE TABLE {$tickets} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			ticket_type_id BIGINT UNSIGNED NOT NULL,
			guest_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			wc_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			wc_order_item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			wc_customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ticket_number VARCHAR(40) NOT NULL,
			qr_token CHAR(40) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			is_complimentary TINYINT UNSIGNED NOT NULL DEFAULT 0,
			checked_in TINYINT UNSIGNED NOT NULL DEFAULT 0,
			checked_in_at DATETIME NULL,
			checked_in_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			price DECIMAL(12,2) NULL,
			discount DECIMAL(12,2) NULL,
			fee DECIMAL(12,2) NULL,
			refunded_amount DECIMAL(12,2) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ticket_number (ticket_number),
			UNIQUE KEY qr_token (qr_token),
			KEY event_id (event_id),
			KEY ticket_type_id (ticket_type_id),
			KEY guest_id (guest_id),
			KEY wc_order_id (wc_order_id),
			KEY wc_order_item_id (wc_order_item_id),
			KEY wc_customer_id (wc_customer_id),
			KEY checked_in (checked_in)
		) {$collate};";

		$guests = self::guests();

		$schema[] = "CREATE TABLE {$guests} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			ticket_id BIGINT UNSIGNED NOT NULL,
			wc_customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			name VARCHAR(191) NOT NULL DEFAULT '',
			email VARCHAR(191) NOT NULL DEFAULT '',
			phone VARCHAR(50) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'confirmed',
			tags TEXT NULL,
			notes LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ticket_id (ticket_id),
			KEY event_id (event_id),
			KEY wc_customer_id (wc_customer_id),
			KEY email (email),
			KEY status (status)
		) {$collate};";

		$checkins = self::checkins();

		$schema[] = "CREATE TABLE {$checkins} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			ticket_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			scanned_value VARCHAR(191) NOT NULL DEFAULT '',
			outcome VARCHAR(20) NOT NULL DEFAULT 'invalid',
			method VARCHAR(10) NOT NULL DEFAULT 'manual',
			operator_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			device VARCHAR(191) NOT NULL DEFAULT '',
			entry_point VARCHAR(191) NOT NULL DEFAULT '',
			scanned_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY ticket_id (ticket_id),
			KEY outcome (outcome),
			KEY scanned_at (scanned_at)
		) {$collate};";

		$campaigns = self::campaigns();

		$schema[] = "CREATE TABLE {$campaigns} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			wc_coupon_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			audience_id BIGINT UNSIGNED NULL,
			name VARCHAR(191) NOT NULL,
			code VARCHAR(60) NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'percent',
			value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			applies_to VARCHAR(20) NOT NULL DEFAULT 'all',
			ticket_type_ids TEXT NULL,
			min_spend DECIMAL(12,2) NULL,
			max_uses INT UNSIGNED NULL,
			expires_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY event_id (event_id),
			KEY wc_coupon_id (wc_coupon_id),
			KEY audience_id (audience_id),
			KEY status (status)
		) {$collate};";

		$promo_links = self::promo_links();

		$schema[] = "CREATE TABLE {$promo_links} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			label VARCHAR(191) NOT NULL,
			url TEXT NOT NULL,
			utm_source VARCHAR(191) NOT NULL DEFAULT '',
			utm_medium VARCHAR(191) NOT NULL DEFAULT '',
			utm_campaign VARCHAR(191) NOT NULL DEFAULT '',
			clicks INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_id (event_id)
		) {$collate};";

		$waitlist_entries = self::waitlist_entries();

		// `active_slot` is 1 while a row is 'waiting' or 'promoted', and NULL
		// once it reaches a terminal state (converted/expired/cancelled).
		// InnoDB unique indexes treat every NULL as distinct, so the
		// `active_entry` key below allows unlimited historical (terminal)
		// rows per person/ticket-type but blocks a second concurrently
		// active one at the database level, not just in application code.
		$schema[] = "CREATE TABLE {$waitlist_entries} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			ticket_type_id BIGINT UNSIGNED NOT NULL,
			person_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(191) NOT NULL DEFAULT '',
			email VARCHAR(191) NOT NULL DEFAULT '',
			phone VARCHAR(50) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'waiting',
			active_slot TINYINT UNSIGNED NULL,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			promoted_at DATETIME NULL,
			expires_at DATETIME NULL,
			notified_at DATETIME NULL,
			converted_ticket_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			metadata TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY active_entry (event_id, ticket_type_id, person_id, active_slot),
			KEY ticket_type_status (ticket_type_id, status),
			KEY person_id (person_id),
			KEY status_expires (status, expires_at)
		) {$collate};";

		$event_identities = self::event_identities();

		// Mirrors `eventos_person_identities` (CRM) — a type/value identity
		// signal resolving to exactly one row, here an Event instead of a
		// Person. No `confidence` column: unlike CRM signals, these are
		// exact external-system identifiers (a WooCommerce product-group
		// key, a Quicket event ID, ...), never fuzzy matches.
		$schema[] = "CREATE TABLE {$event_identities} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(30) NOT NULL,
			value VARCHAR(191) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY type_value (type, value),
			KEY event_id (event_id)
		) {$collate};";

		$ticket_type_identities = self::ticket_type_identities();

		// Same identity pattern as `event_identities`, scoped to Ticket Types
		// instead of Events — for non-WooCommerce import sources only.
		$schema[] = "CREATE TABLE {$ticket_type_identities} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ticket_type_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(30) NOT NULL,
			value VARCHAR(191) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY type_value (type, value),
			KEY ticket_type_id (ticket_type_id)
		) {$collate};";

		$ticket_identities = self::ticket_identities();

		// Same identity pattern again, scoped to Tickets — for
		// non-WooCommerce import sources only.
		$schema[] = "CREATE TABLE {$ticket_identities} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ticket_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(30) NOT NULL,
			value VARCHAR(191) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY type_value (type, value),
			KEY ticket_id (ticket_id)
		) {$collate};";

		foreach ( $schema as $statement ) {
			dbDelta( $statement );
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

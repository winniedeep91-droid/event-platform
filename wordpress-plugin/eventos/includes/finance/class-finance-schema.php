<?php
/**
 * Database schema owned by the Finance module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Finance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the tables the Finance module owns.
 *
 * Revenue, refunds and payment-processing fee line items all live in
 * WooCommerce and stay read live from there (see Finance_Report_Builder) —
 * the same "never mirror WooCommerce" rule every other module in EventOS
 * follows. Expenses have no WooCommerce equivalent, so this is the one
 * financial record EventOS actually persists.
 */
final class Finance_Schema {

	/**
	 * Schema version stored in the options table.
	 */
	public const VERSION = '1.0.0';

	/**
	 * Option holding the installed schema version.
	 */
	public const VERSION_OPTION = 'eventos_finance_schema_version';

	/**
	 * Fully qualified expenses table name.
	 *
	 * @return string
	 */
	public static function expenses(): string {
		global $wpdb;

		return $wpdb->prefix . 'eventos_expenses';
	}

	/**
	 * Create or upgrade the module's tables.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$table   = self::expenses();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			category VARCHAR(60) NOT NULL DEFAULT 'other',
			description VARCHAR(255) NOT NULL DEFAULT '',
			amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			currency VARCHAR(10) NOT NULL DEFAULT '',
			expense_date DATE NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'recorded',
			reference VARCHAR(100) NOT NULL DEFAULT '',
			payee VARCHAR(150) NOT NULL DEFAULT '',
			notes TEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY category (category),
			KEY status (status),
			KEY expense_date (expense_date)
		) {$collate};";

		dbDelta( $sql );

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

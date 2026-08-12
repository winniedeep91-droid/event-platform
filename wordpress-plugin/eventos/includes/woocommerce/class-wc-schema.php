<?php
/**
 * Database schema owned by the WooCommerce module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the webhook delivery log table.
 *
 * Product, order, customer and coupon data itself always lives in
 * WooCommerce's own tables — EventOS never mirrors it. The only storage this
 * module owns is the log of order lifecycle events it observed, which has no
 * WooCommerce equivalent.
 */
final class Wc_Schema {

	/**
	 * Schema version stored in the options table.
	 */
	public const VERSION = '1.0.0';

	/**
	 * Option holding the installed schema version.
	 */
	public const VERSION_OPTION = 'eventos_wc_schema_version';

	/**
	 * Fully qualified webhook log table name.
	 *
	 * @return string
	 */
	public static function webhook_log(): string {
		global $wpdb;

		return $wpdb->prefix . 'eventos_wc_webhook_log';
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
		$table   = self::webhook_log();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event VARCHAR(30) NOT NULL,
			wc_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			payload_summary TEXT NULL,
			error TEXT NULL,
			received_at DATETIME NOT NULL,
			processed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY event (event),
			KEY status (status),
			KEY wc_order_id (wc_order_id),
			KEY received_at (received_at)
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

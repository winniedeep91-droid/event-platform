<?php
/**
 * Activation, deactivation and database upgrade routines.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installer handles schema creation and version upgrades.
 */
final class Installer {

	/**
	 * Option storing the installed database version.
	 */
	public const DB_VERSION_OPTION = 'eventos_db_version';

	/**
	 * Option storing the installation timestamp.
	 */
	public const INSTALLED_AT_OPTION = 'eventos_installed_at';

	/**
	 * Plugin activation callback.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::install();
		Capabilities::install_roles();
		Cron::schedule();

		if ( ! get_option( self::INSTALLED_AT_OPTION ) ) {
			add_option( self::INSTALLED_AT_OPTION, gmdate( 'Y-m-d H:i:s' ) );
		}

		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Cron::unschedule();
		flush_rewrite_rules();
	}

	/**
	 * Run pending upgrades on load when the stored version is behind.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed = (string) get_option( self::DB_VERSION_OPTION, '' );

		if ( EVENTOS_DB_VERSION === $installed ) {
			return;
		}

		self::install();
		Capabilities::install_roles();

		/**
		 * Fires after EventOS upgraded its schema.
		 *
		 * @param string $installed Previously installed version ('' on first install).
		 * @param string $current   Version now installed.
		 */
		do_action( 'eventos_upgraded', $installed, EVENTOS_DB_VERSION );
	}

	/**
	 * Create or update custom tables and seed default options.
	 *
	 * WordPress core tables cover users, roles, media and configuration, so custom
	 * tables exist only for invitations and the activity log.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$invitations     = self::invitations_table();
		$activity        = self::activity_table();

		$schema = array();

		$schema[] = "CREATE TABLE {$invitations} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(191) NOT NULL,
			roles TEXT NOT NULL,
			token_hash CHAR(64) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			invited_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			accepted_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			accepted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY email_status (email, status),
			KEY status_expires (status, expires_at),
			KEY invited_by (invited_by)
		) {$charset_collate};";

		$schema[] = "CREATE TABLE {$activity} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(100) NOT NULL,
			object_type VARCHAR(50) NOT NULL DEFAULT '',
			object_id VARCHAR(64) NOT NULL DEFAULT '',
			context LONGTEXT NULL,
			ip_address VARCHAR(45) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY action (action),
			KEY object (object_type, object_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		foreach ( $schema as $table_sql ) {
			dbDelta( $table_sql );
		}

		Settings::install_defaults();

		update_option( self::DB_VERSION_OPTION, EVENTOS_DB_VERSION );
	}

	/**
	 * Fully qualified invitations table name.
	 *
	 * @return string
	 */
	public static function invitations_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'eventos_invitations';
	}

	/**
	 * Fully qualified activity log table name.
	 *
	 * @return string
	 */
	public static function activity_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'eventos_activity_log';
	}

	/**
	 * Report whether all EventOS tables exist.
	 *
	 * @return bool
	 */
	public static function tables_installed(): bool {
		global $wpdb;

		foreach ( array( self::invitations_table(), self::activity_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}
}

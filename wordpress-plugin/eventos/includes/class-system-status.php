<?php
/**
 * Environment and health reporting for the EventOS dashboard.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects system status information.
 */
final class System_Status {

	/**
	 * Minimum supported PHP version.
	 */
	public const MIN_PHP = '8.0';

	/**
	 * Minimum supported WordPress version.
	 */
	public const MIN_WP = '6.4';

	/**
	 * Full status payload.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload(): array {
		global $wp_version;

		$checks = array(
			array(
				'id'      => 'php_version',
				'label'   => __( 'PHP version', 'eventos' ),
				'value'   => PHP_VERSION,
				'passing' => version_compare( PHP_VERSION, self::MIN_PHP, '>=' ),
			),
			array(
				'id'      => 'wordpress_version',
				'label'   => __( 'WordPress version', 'eventos' ),
				'value'   => (string) $wp_version,
				'passing' => version_compare( (string) $wp_version, self::MIN_WP, '>=' ),
			),
			array(
				'id'      => 'database',
				'label'   => __( 'Database tables', 'eventos' ),
				'value'   => Installer::tables_installed() ? __( 'Installed', 'eventos' ) : __( 'Missing', 'eventos' ),
				'passing' => Installer::tables_installed(),
			),
			array(
				'id'      => 'uploads',
				'label'   => __( 'Uploads directory', 'eventos' ),
				'value'   => self::uploads_writable() ? __( 'Writable', 'eventos' ) : __( 'Not writable', 'eventos' ),
				'passing' => self::uploads_writable(),
			),
			array(
				'id'      => 'cron',
				'label'   => __( 'WP-Cron', 'eventos' ),
				'value'   => Cron::is_scheduled() ? __( 'Scheduled', 'eventos' ) : __( 'Not scheduled', 'eventos' ),
				'passing' => Cron::is_scheduled(),
			),
			array(
				'id'      => 'https',
				'label'   => __( 'HTTPS', 'eventos' ),
				'value'   => is_ssl() ? __( 'Enabled', 'eventos' ) : __( 'Disabled', 'eventos' ),
				'passing' => is_ssl(),
			),
		);

		return array(
			'plugin_version'    => EVENTOS_VERSION,
			'db_version'        => (string) get_option( Installer::DB_VERSION_OPTION, '' ),
			'wordpress_version' => (string) $wp_version,
			'php_version'       => PHP_VERSION,
			'mysql_version'     => self::database_version(),
			'woocommerce'       => WooCommerce::status(),
			'multisite'         => is_multisite(),
			'checks'            => $checks,
			'healthy'           => ! in_array( false, wp_list_pluck( $checks, 'passing' ), true ),
			'storage'           => self::storage(),
		);
	}

	/**
	 * Database server version.
	 *
	 * @return string
	 */
	public static function database_version(): string {
		global $wpdb;

		return (string) $wpdb->db_version();
	}

	/**
	 * Whether the uploads directory is writable.
	 *
	 * @return bool
	 */
	public static function uploads_writable(): bool {
		$uploads = wp_upload_dir();

		return empty( $uploads['error'] ) && wp_is_writable( $uploads['basedir'] );
	}

	/**
	 * Storage usage of the uploads directory, cached for an hour.
	 *
	 * @return array<string, mixed>
	 */
	public static function storage(): array {
		$cached = get_transient( 'eventos_storage_usage' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$uploads = wp_upload_dir();
		$bytes   = 0;
		$files   = 0;

		if ( empty( $uploads['error'] ) && is_dir( $uploads['basedir'] ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $uploads['basedir'], \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$bytes += (int) $file->getSize();
					++$files;
				}
			}
		}

		$free  = @disk_free_space( $uploads['basedir'] ?? ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$total = @disk_total_space( $uploads['basedir'] ?? ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$usage = array(
			'used_bytes'       => $bytes,
			'used_human'       => size_format( $bytes, 2 ),
			'file_count'       => $files,
			'disk_free_bytes'  => is_float( $free ) ? (int) $free : 0,
			'disk_total_bytes' => is_float( $total ) ? (int) $total : 0,
			'attachments'      => (int) wp_count_posts( 'attachment' )->inherit,
			'generated_at'     => current_time( 'mysql', true ),
		);

		set_transient( 'eventos_storage_usage', $usage, HOUR_IN_SECONDS );

		return $usage;
	}
}

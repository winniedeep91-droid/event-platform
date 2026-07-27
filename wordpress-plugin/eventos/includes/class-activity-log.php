<?php
/**
 * Activity log writer and reader.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records auditable EventOS actions in a dedicated table.
 */
final class Activity_Log {

	/**
	 * Record an entry.
	 *
	 * @param string               $action      Machine readable action key.
	 * @param array<string, mixed> $context     Additional context.
	 * @param string               $object_type Object type the action applies to.
	 * @param string               $object_id   Object identifier.
	 * @return int Inserted row ID, 0 on failure.
	 */
	public static function record( string $action, array $context = array(), string $object_type = '', string $object_id = '' ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			Installer::activity_table(),
			array(
				'user_id'     => get_current_user_id(),
				'action'      => sanitize_key( $action ),
				'object_type' => sanitize_key( $object_type ),
				'object_id'   => sanitize_text_field( $object_id ),
				'context'     => wp_json_encode( $context ),
				'ip_address'  => self::client_ip(),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Read the most recent entries.
	 *
	 * @param int $limit Maximum number of rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = 20 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );
		$table = Installer::activity_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'format_row' ), (array) $rows );
	}

	/**
	 * Delete entries older than the retention window.
	 *
	 * @param int $days Retention in days.
	 * @return int Number of deleted rows.
	 */
	public static function purge_older_than( int $days ): int {
		global $wpdb;

		$table  = Installer::activity_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Total number of stored entries.
	 *
	 * @return int
	 */
	public static function count(): int {
		global $wpdb;

		$table = Installer::activity_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Normalise a database row for API output.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function format_row( array $row ): array {
		$user = get_userdata( (int) $row['user_id'] );

		return array(
			'id'          => (int) $row['id'],
			'action'      => (string) $row['action'],
			'object_type' => (string) $row['object_type'],
			'object_id'   => (string) $row['object_id'],
			'context'     => json_decode( (string) $row['context'], true ) ?: array(),
			'created_at'  => (string) $row['created_at'],
			'user'        => array(
				'id'   => (int) $row['user_id'],
				'name' => $user ? $user->display_name : __( 'System', 'eventos' ),
			),
		);
	}

	/**
	 * Best effort client IP address.
	 *
	 * @return string
	 */
	private static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		return (string) filter_var( $remote, FILTER_VALIDATE_IP );
	}
}

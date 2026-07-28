<?php
/**
 * Activity log framework shared by every EventOS module.
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
 *
 * Modules log through {@see Activity_Log::log()} and get user, timestamp,
 * module, entity, before/after values and severity stored consistently.
 */
final class Activity_Log {

	/**
	 * Severity levels.
	 */
	public const SEVERITY_DEBUG   = 'debug';
	public const SEVERITY_INFO    = 'info';
	public const SEVERITY_NOTICE  = 'notice';
	public const SEVERITY_WARNING = 'warning';
	public const SEVERITY_ERROR   = 'error';

	/**
	 * Allowed severities.
	 *
	 * @return string[]
	 */
	public static function severities(): array {
		return array(
			self::SEVERITY_DEBUG,
			self::SEVERITY_INFO,
			self::SEVERITY_NOTICE,
			self::SEVERITY_WARNING,
			self::SEVERITY_ERROR,
		);
	}

	/**
	 * Record an entry.
	 *
	 * Accepted keys: action, module, entity_type, entity_id, before, after,
	 * severity, context, user_id.
	 *
	 * @param array<string, mixed> $entry Entry definition.
	 * @return int Inserted row ID, 0 on failure.
	 */
	public static function log( array $entry ): int {
		global $wpdb;

		$entry = wp_parse_args(
			$entry,
			array(
				'action'      => '',
				'module'      => 'core',
				'entity_type' => '',
				'entity_id'   => '',
				'entity'      => '',
				'before'      => null,
				'after'       => null,
				'severity'    => self::SEVERITY_INFO,
				'context'     => array(),
				'user_id'     => null,
			)
		);

		$action = sanitize_key( (string) $entry['action'] );

		if ( '' === $action ) {
			return 0;
		}

		$entity_type = sanitize_key( (string) ( $entry['entity_type'] ?: $entry['entity'] ) );
		$severity    = in_array( $entry['severity'], self::severities(), true )
			? (string) $entry['severity']
			: self::SEVERITY_INFO;

		$user_id = null === $entry['user_id'] ? get_current_user_id() : (int) $entry['user_id'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			Installer::activity_table(),
			array(
				'user_id'      => $user_id,
				'action'       => $action,
				'module'       => sanitize_key( (string) $entry['module'] ),
				'severity'     => $severity,
				'object_type'  => $entity_type,
				'object_id'    => sanitize_text_field( (string) $entry['entity_id'] ),
				'before_value' => null === $entry['before'] ? null : wp_json_encode( $entry['before'] ),
				'after_value'  => null === $entry['after'] ? null : wp_json_encode( $entry['after'] ),
				'context'      => wp_json_encode( (array) $entry['context'] ),
				'ip_address'   => self::client_ip(),
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;

		/**
		 * Fires after an activity log entry was stored.
		 *
		 * @param int   $id    Row ID.
		 * @param array $entry Normalised entry.
		 */
		do_action( 'eventos_activity_logged', $id, $entry );

		return $id;
	}

	/**
	 * Backwards compatible shorthand used by core services.
	 *
	 * @param string               $action      Machine readable action key.
	 * @param array<string, mixed> $context     Additional context.
	 * @param string               $object_type Object type the action applies to.
	 * @param string               $object_id   Object identifier.
	 * @return int
	 */
	public static function record( string $action, array $context = array(), string $object_type = '', string $object_id = '' ): int {
		return self::log(
			array(
				'action'      => $action,
				'context'     => $context,
				'entity_type' => $object_type,
				'entity_id'   => $object_id,
			)
		);
	}

	/**
	 * Read the most recent entries.
	 *
	 * @param int $limit Maximum number of rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = 20 ): array {
		$result = self::query( array( 'per_page' => $limit ) );

		return $result['items'];
	}

	/**
	 * Query the log with filters, sorting and pagination.
	 *
	 * Accepted args: search, module, action, severity, entity_type, entity_id,
	 * user_id, since, until, page, per_page, order.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'      => '',
				'module'      => '',
				'action'      => '',
				'severity'    => '',
				'entity_type' => '',
				'entity_id'   => '',
				'user_id'     => 0,
				'since'       => '',
				'until'       => '',
				'page'        => 1,
				'per_page'    => 20,
				'order'       => 'DESC',
			)
		);

		$table    = Installer::activity_table();
		$page     = max( 1, (int) $args['page'] );
		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$order    = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$where  = array( '1=1' );
		$params = array();

		foreach ( array( 'module', 'action', 'severity' ) as $column ) {
			if ( '' !== (string) $args[ $column ] ) {
				$where[]  = "{$column} = %s";
				$params[] = sanitize_key( (string) $args[ $column ] );
			}
		}

		if ( '' !== (string) $args['entity_type'] ) {
			$where[]  = 'object_type = %s';
			$params[] = sanitize_key( (string) $args['entity_type'] );
		}

		if ( '' !== (string) $args['entity_id'] ) {
			$where[]  = 'object_id = %s';
			$params[] = sanitize_text_field( (string) $args['entity_id'] );
		}

		if ( (int) $args['user_id'] > 0 ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $args['user_id'];
		}

		if ( '' !== (string) $args['since'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = (string) $args['since'];
		}

		if ( '' !== (string) $args['until'] ) {
			$where[]  = 'created_at <= %s';
			$params[] = (string) $args['until'];
		}

		if ( '' !== (string) $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(action LIKE %s OR object_id LIKE %s OR context LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$clause = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		$rows_sql = "SELECT * FROM {$table} WHERE {$clause} ORDER BY created_at {$order}, id {$order} LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results(
			$wpdb->prepare( $rows_sql, array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'items'    => array_map( array( __CLASS__, 'format_row' ), (array) $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Distinct modules present in the log.
	 *
	 * @return string[]
	 */
	public static function modules(): array {
		global $wpdb;

		$table = Installer::activity_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( "SELECT DISTINCT module FROM {$table} ORDER BY module ASC" );

		return array_values( array_filter( array_map( 'strval', (array) $rows ) ) );
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
			'module'      => (string) ( $row['module'] ?? 'core' ),
			'severity'    => (string) ( $row['severity'] ?? self::SEVERITY_INFO ),
			'object_type' => (string) $row['object_type'],
			'object_id'   => (string) $row['object_id'],
			'entity'      => array(
				'type' => (string) $row['object_type'],
				'id'   => (string) $row['object_id'],
			),
			'before'      => self::decode( $row['before_value'] ?? null ),
			'after'       => self::decode( $row['after_value'] ?? null ),
			'context'     => (array) ( self::decode( $row['context'] ?? null ) ?? array() ),
			'created_at'  => (string) $row['created_at'],
			'user'        => array(
				'id'   => (int) $row['user_id'],
				'name' => $user ? $user->display_name : __( 'System', 'eventos' ),
			),
		);
	}

	/**
	 * Decode a stored JSON column.
	 *
	 * @param mixed $value Raw column value.
	 * @return mixed
	 */
	private static function decode( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$decoded = json_decode( (string) $value, true );

		return null === $decoded ? (string) $value : $decoded;
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

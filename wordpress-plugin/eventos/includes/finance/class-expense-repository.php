<?php
/**
 * Data access for event expenses.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Finance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expense persistence. An expense is never hard-deleted once saved — it is
 * voided (status = 'void') so an event's financial history stays auditable
 * and a P&L computed before the void still reconciles against the log.
 */
final class Expense_Repository {

	/**
	 * Status: a normal, counted expense.
	 */
	public const STATUS_RECORDED = 'recorded';

	/**
	 * Status: voided — excluded from every financial total.
	 */
	public const STATUS_VOID = 'void';

	/**
	 * Column formats.
	 *
	 * @var array<string, string>
	 */
	private const COLUMNS = array(
		'event_id'     => '%d',
		'category'     => '%s',
		'description'  => '%s',
		'amount'       => '%f',
		'currency'     => '%s',
		'expense_date' => '%s',
		'status'       => '%s',
		'reference'    => '%s',
		'payee'        => '%s',
		'notes'        => '%s',
		'created_by'   => '%d',
		'created_at'   => '%s',
		'updated_at'   => '%s',
	);

	/**
	 * Create an expense.
	 *
	 * Accepted keys: event_id, category, description, amount, currency,
	 * expense_date, reference, payee, notes, created_by. status always
	 * starts at STATUS_RECORDED.
	 *
	 * @param array<string, mixed> $data Expense data.
	 * @return array<string, mixed>|null
	 */
	public function create( array $data ): ?array {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$row = array(
			'event_id'     => (int) ( $data['event_id'] ?? 0 ),
			'category'     => (string) ( $data['category'] ?? 'other' ),
			'description'  => (string) ( $data['description'] ?? '' ),
			'amount'       => (float) ( $data['amount'] ?? 0 ),
			'currency'     => (string) ( $data['currency'] ?? '' ),
			'expense_date' => '' !== (string) ( $data['expense_date'] ?? '' ) ? (string) $data['expense_date'] : null,
			'status'       => self::STATUS_RECORDED,
			'reference'    => (string) ( $data['reference'] ?? '' ),
			'payee'        => (string) ( $data['payee'] ?? '' ),
			'notes'        => (string) ( $data['notes'] ?? '' ),
			'created_by'   => (int) ( $data['created_by'] ?? 0 ),
			'created_at'   => $now,
			'updated_at'   => $now,
		);

		$formats = array_map(
			static function ( string $column ): string {
				return self::COLUMNS[ $column ];
			},
			array_keys( $row )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( Finance_Schema::expenses(), $row, $formats );

		return $wpdb->insert_id ? $this->find( (int) $wpdb->insert_id ) : null;
	}

	/**
	 * Read a single expense.
	 *
	 * @param int $id Expense ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Finance_Schema::expenses();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Query expenses.
	 *
	 * @param array<string, mixed> $args Accepted: event_id, search, category,
	 *                                    status, orderby, order, page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'event_id' => 0,
				'search'   => '',
				'category' => '',
				'status'   => '',
				'orderby'  => 'expense_date',
				'order'    => 'desc',
				'page'     => 1,
				'per_page' => 20,
			)
		);

		$table  = Finance_Schema::expenses();
		$where  = array( '1=1' );
		$params = array();

		if ( (int) $args['event_id'] > 0 ) {
			$where[]  = 'event_id = %d';
			$params[] = (int) $args['event_id'];
		}

		if ( '' !== (string) $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(description LIKE %s OR payee LIKE %s OR reference LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( '' !== (string) $args['category'] ) {
			$where[]  = 'category = %s';
			$params[] = (string) $args['category'];
		}

		if ( '' !== (string) $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		} else {
			// Voided expenses never appear in a default list — a caller has
			// to explicitly ask for status=void to see them.
			$where[] = "status = '" . self::STATUS_RECORDED . "'";
		}

		$orderby  = in_array( (string) $args['orderby'], array( 'expense_date', 'amount', 'category', 'created_at' ), true ) ? (string) $args['orderby'] : 'expense_date';
		$order    = 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = $params
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) )
			: (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );

		$query_params   = $params;
		$query_params[] = $per_page;
		$query_params[] = $offset;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d",
				$query_params
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'items'    => array_map( array( $this, 'hydrate' ), $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Update an expense.
	 *
	 * @param int                   $id   Expense ID.
	 * @param array<string, mixed> $data Columns to update.
	 * @return array<string, mixed>|null
	 */
	public function update( int $id, array $data ): ?array {
		global $wpdb;

		$row     = array();
		$formats = array();

		foreach ( $data as $column => $value ) {
			if ( ! isset( self::COLUMNS[ $column ] ) || 'event_id' === $column || 'created_by' === $column ) {
				continue;
			}

			$row[ $column ] = $value;
			$formats[]      = self::COLUMNS[ $column ];
		}

		if ( ! $row ) {
			return $this->find( $id );
		}

		$row['updated_at'] = current_time( 'mysql', true );
		$formats[]         = self::COLUMNS['updated_at'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Finance_Schema::expenses(), $row, array( 'id' => $id ), $formats, array( '%d' ) );

		return $this->find( $id );
	}

	/**
	 * Void an expense (safe delete — see class docblock).
	 *
	 * @param int $id Expense ID.
	 * @return bool
	 */
	public function void( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update(
			Finance_Schema::expenses(),
			array(
				'status'     => self::STATUS_VOID,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Total recorded expenses across every event — the unscoped counterpart
	 * to {@see total_for_events()}, used by the organisation-wide P&L when
	 * no event filter is given.
	 *
	 * @return float
	 */
	public function total_all(): float {
		global $wpdb;

		$table = Finance_Schema::expenses();

		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status = %s",
				self::STATUS_RECORDED
			)
		);
	}

	/**
	 * Total recorded expenses for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return float
	 */
	public function total_for_event( int $event_id ): float {
		global $wpdb;

		$table = Finance_Schema::expenses();

		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE event_id = %d AND status = %s",
				$event_id,
				self::STATUS_RECORDED
			)
		);
	}

	/**
	 * Total recorded expenses across a set of events — one query regardless
	 * of how many events are requested, the same batching Brand_Report_Builder
	 * uses for revenue so an organisation-level summary never issues one
	 * query per event.
	 *
	 * @param int[] $event_ids Event IDs.
	 * @return float
	 */
	public function total_for_events( array $event_ids ): float {
		global $wpdb;

		$event_ids = array_values( array_unique( array_map( 'intval', $event_ids ) ) );

		if ( empty( $event_ids ) ) {
			return 0.0;
		}

		$table        = Finance_Schema::expenses();
		$placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status = %s AND event_id IN ({$placeholders})",
				array_merge( array( self::STATUS_RECORDED ), $event_ids )
			)
		);
	}

	/**
	 * Recorded expense totals for an event, grouped by category.
	 *
	 * @param int $event_id Event ID.
	 * @return array<int, array{category: string, total: float, count: int}>
	 */
	public function totals_by_category( int $event_id ): array {
		global $wpdb;

		$table = Finance_Schema::expenses();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT category, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt FROM {$table} WHERE event_id = %d AND status = %s GROUP BY category ORDER BY total DESC",
				$event_id,
				self::STATUS_RECORDED
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return array(
					'category' => (string) $row['category'],
					'total'    => (float) $row['total'],
					'count'    => (int) $row['cnt'],
				);
			},
			$rows
		);
	}

	/**
	 * Convert a row into an API shape.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		return array(
			'id'           => (int) $row['id'],
			'event_id'     => (int) $row['event_id'],
			'category'     => (string) $row['category'],
			'description'  => (string) $row['description'],
			'amount'       => (float) $row['amount'],
			'currency'     => (string) $row['currency'],
			'expense_date' => $row['expense_date'],
			'status'       => (string) $row['status'],
			'reference'    => (string) $row['reference'],
			'payee'        => (string) $row['payee'],
			'notes'        => (string) $row['notes'],
			'created_by'   => (int) $row['created_by'],
			'created_at'   => (string) $row['created_at'],
			'updated_at'   => (string) $row['updated_at'],
		);
	}
}

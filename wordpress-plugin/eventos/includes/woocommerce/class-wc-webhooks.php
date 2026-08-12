<?php
/**
 * WooCommerce order lifecycle logging.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Woocommerce;

use RuntimeException;
use Throwable;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records WooCommerce order lifecycle events in the webhook delivery log.
 *
 * EventOS and WooCommerce run in the same PHP process, so there is no HTTP
 * round trip and nothing to sign or verify: the module listens to the same
 * order hooks WooCommerce fires internally and writes a durable log entry
 * for each one, resolving the order's EventOS event mapping as it goes.
 * "Registering" webhooks simply turns this listening on.
 */
final class Wc_Webhooks {

	/**
	 * Option toggling whether order events are logged.
	 */
	public const ENABLED_OPTION = 'eventos_wc_webhooks_enabled';

	/**
	 * Every event type this module can log.
	 */
	public const EVENTS = array( 'order.created', 'order.updated', 'order.completed', 'order.refunded' );

	/**
	 * Attach the WooCommerce order hooks.
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		add_action( 'woocommerce_new_order', array( __CLASS__, 'handle_order_created' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'handle_status_changed' ), 10, 4 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'handle_refunded' ), 10, 2 );
	}

	/**
	 * Whether order events are currently being logged.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	/**
	 * Turn on order event logging.
	 *
	 * @return array{registered: string[], already_registered: string[]}
	 */
	public static function register(): array {
		if ( self::enabled() ) {
			return array(
				'registered'          => array(),
				'already_registered'  => self::EVENTS,
			);
		}

		update_option( self::ENABLED_OPTION, true );

		return array(
			'registered'         => self::EVENTS,
			'already_registered' => array(),
		);
	}

	/**
	 * Turn off order event logging.
	 *
	 * @return array{deregistered: string[]}
	 */
	public static function deregister(): array {
		if ( ! self::enabled() ) {
			return array( 'deregistered' => array() );
		}

		update_option( self::ENABLED_OPTION, false );

		return array( 'deregistered' => self::EVENTS );
	}

	/**
	 * WooCommerce fired a new order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function handle_order_created( $order_id ): void {
		self::record( 'order.created', (int) $order_id );
	}

	/**
	 * WooCommerce changed an order's status.
	 *
	 * @param int         $order_id Order ID.
	 * @param string      $from     Previous status.
	 * @param string      $to       New status.
	 * @param WC_Order|null $order  Order object.
	 * @return void
	 */
	public static function handle_status_changed( $order_id, $from, $to, $order = null ): void {
		unset( $from, $order );

		self::record( 'completed' === $to ? 'order.completed' : 'order.updated', (int) $order_id );
	}

	/**
	 * WooCommerce recorded a refund.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 * @return void
	 */
	public static function handle_refunded( $order_id, $refund_id ): void {
		unset( $refund_id );

		self::record( 'order.refunded', (int) $order_id );
	}

	/**
	 * Process an order event and write a log entry, when logging is enabled.
	 *
	 * @param string $event    Event slug.
	 * @param int    $order_id Order ID.
	 * @return void
	 */
	private static function record( string $event, int $order_id ): void {
		if ( ! self::enabled() ) {
			return;
		}

		list( $status, $summary, $error ) = self::process_order( $order_id );

		self::insert_log( $event, $order_id, $status, $summary, $error );
	}

	/**
	 * Resolve an order's EventOS mapping and describe the outcome.
	 *
	 * @param int $order_id Order ID.
	 * @return array{0: string, 1: string, 2: string|null}
	 */
	private static function process_order( int $order_id ): array {
		try {
			$order = $order_id > 0 ? wc_get_order( $order_id ) : false;

			if ( ! $order instanceof WC_Order ) {
				return array( 'skipped', sprintf( 'Order #%d no longer exists.', $order_id ), null );
			}

			$event_id = Wc_Meta::resolve_order_event_id( $order );

			if ( $event_id > 0 ) {
				$order->update_meta_data( Wc_Meta::EVENT_META, $event_id );
				$order->save_meta_data();
			}

			$summary = sprintf(
				'Order #%1$d · %2$s · %3$s',
				$order_id,
				$order->get_status(),
				$event_id > 0 ? sprintf( 'event #%d', $event_id ) : 'unmapped'
			);

			return array( 'processed', $summary, null );
		} catch ( Throwable $exception ) {
			return array( 'failed', sprintf( 'Order #%d', $order_id ), $exception->getMessage() );
		}
	}

	/**
	 * Insert a webhook log row.
	 *
	 * @param string      $event    Event slug.
	 * @param int         $order_id Order ID.
	 * @param string      $status   Outcome status.
	 * @param string      $summary  Human readable summary.
	 * @param string|null $error    Error message, if any.
	 * @return int Inserted row ID.
	 */
	private static function insert_log( string $event, int $order_id, string $status, string $summary, ?string $error ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->insert(
			Wc_Schema::webhook_log(),
			array(
				'event'           => $event,
				'wc_order_id'     => $order_id,
				'status'          => $status,
				'payload_summary' => $summary,
				'error'           => $error,
				'received_at'     => $now,
				'processed_at'    => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Re-run an order event's processing and update its log row.
	 *
	 * @param int $log_id Log row ID.
	 * @return array{retried: bool, status: string}
	 * @throws RuntimeException When the log row does not exist.
	 */
	public static function retry( int $log_id ): array {
		global $wpdb;

		$table = Wc_Schema::webhook_log();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $log_id ), ARRAY_A );

		if ( ! $row ) {
			throw new RuntimeException( __( 'Webhook log entry not found.', 'eventos' ) );
		}

		list( $status, $summary, $error ) = self::process_order( (int) $row['wc_order_id'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'          => $status,
				'payload_summary' => $summary,
				'error'           => $error,
				'processed_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $log_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return array(
			'retried' => true,
			'status'  => $status,
		);
	}

	/**
	 * Query the webhook log with search, filters and pagination.
	 *
	 * Accepted args: search, status, event, page, per_page.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'status'   => '',
				'event'    => '',
				'page'     => 1,
				'per_page' => 20,
			)
		);

		$table    = Wc_Schema::webhook_log();
		$page     = max( 1, (int) $args['page'] );
		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== (string) $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( (string) $args['status'] );
		}

		if ( '' !== (string) $args['event'] ) {
			$where[]  = 'event = %s';
			$params[] = sanitize_text_field( (string) $args['event'] );
		}

		if ( '' !== (string) $args['search'] ) {
			$where[]  = '(wc_order_id = %d OR payload_summary LIKE %s)';
			$params[] = (int) $args['search'];
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}

		$clause = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		$items = array_map(
			static function ( array $row ): array {
				return array(
					'id'              => (int) $row['id'],
					'event'           => (string) $row['event'],
					'wc_order_id'     => (int) $row['wc_order_id'],
					'status'          => (string) $row['status'],
					'payload_summary' => (string) $row['payload_summary'],
					'error'           => '' !== (string) $row['error'] ? (string) $row['error'] : null,
					'processed_at'    => '' !== (string) $row['processed_at'] ? $row['processed_at'] : null,
					'received_at'     => (string) $row['received_at'],
				);
			},
			(array) $rows
		);

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}
}

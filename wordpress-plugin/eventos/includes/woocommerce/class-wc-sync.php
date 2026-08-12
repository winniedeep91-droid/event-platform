<?php
/**
 * WooCommerce synchronisation targets.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Woocommerce;

use EventOS\Job_Queue;
use EventOS\Platform\Sync_Registry;
use EventOS\WooCommerce;
use RuntimeException;
use WC_Order;
use WP_User_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the four WooCommerce sync targets with the shared Sync_Registry
 * and shapes their state into the payload the WooCommerce admin screens
 * expect. No separate sync store is kept — Sync_Registry already owns
 * scheduling, history and run bookkeeping for every module.
 */
final class Wc_Sync {

	/**
	 * Target slugs, keyed by the short name used in REST payloads.
	 */
	public const TARGETS = array(
		'products'  => 'woocommerce_products',
		'orders'    => 'woocommerce_orders',
		'customers' => 'woocommerce_customers',
		'coupons'   => 'woocommerce_coupons',
	);

	/**
	 * Register the sync targets.
	 *
	 * Called directly from the module's boot instead of the
	 * `eventos_register_sync_targets` hook: {@see Sync_Registry::bootstrap()}
	 * fires that hook synchronously the moment the Platform module boots, so a
	 * listener attached by a module that boots afterwards would never run.
	 * {@see Sync_Registry::register()} has no such ordering requirement.
	 *
	 * @return void
	 */
	public static function register_targets(): void {
		Sync_Registry::register_many(
			array(
				array(
					'slug'        => self::TARGETS['products'],
					'label'       => __( 'WooCommerce products', 'eventos' ),
					'description' => __( 'Refresh event and ticket type mappings on WooCommerce products.', 'eventos' ),
					'module'      => 'woocommerce',
					'direction'   => 'inbound',
					'interval'    => HOUR_IN_SECONDS,
					'handler'     => array( __CLASS__, 'sync_products' ),
				),
				array(
					'slug'        => self::TARGETS['orders'],
					'label'       => __( 'WooCommerce orders', 'eventos' ),
					'description' => __( 'Resolve the EventOS event each WooCommerce order belongs to.', 'eventos' ),
					'module'      => 'woocommerce',
					'direction'   => 'inbound',
					'interval'    => HOUR_IN_SECONDS,
					'handler'     => array( __CLASS__, 'sync_orders' ),
				),
				array(
					'slug'        => self::TARGETS['customers'],
					'label'       => __( 'WooCommerce customers', 'eventos' ),
					'description' => __( 'Refresh the WooCommerce customer directory used by the CRM screens.', 'eventos' ),
					'module'      => 'woocommerce',
					'direction'   => 'inbound',
					'interval'    => HOUR_IN_SECONDS,
					'handler'     => array( __CLASS__, 'sync_customers' ),
				),
				array(
					'slug'        => self::TARGETS['coupons'],
					'label'       => __( 'WooCommerce coupons', 'eventos' ),
					'description' => __( 'Refresh WooCommerce coupons and their campaign assignments.', 'eventos' ),
					'module'      => 'woocommerce',
					'direction'   => 'inbound',
					'interval'    => HOUR_IN_SECONDS,
					'handler'     => array( __CLASS__, 'sync_coupons' ),
				),
			)
		);
	}

	/**
	 * Stamp every WooCommerce product as synced.
	 *
	 * @return array<string, mixed>
	 */
	public static function sync_products(): array {
		self::require_woocommerce();

		$ids = wc_get_products(
			array(
				'limit'  => -1,
				'status' => array( 'publish', 'draft', 'pending', 'private' ),
				'return' => 'ids',
			)
		);

		$now = current_time( 'mysql', true );

		foreach ( $ids as $id ) {
			update_post_meta( (int) $id, Wc_Meta::SYNCED_META, $now );
		}

		return array(
			'processed' => count( $ids ),
			'failed'    => 0,
			/* translators: %d: number of products. */
			'message'   => sprintf( _n( 'Synced %d product.', 'Synced %d products.', count( $ids ), 'eventos' ), count( $ids ) ),
		);
	}

	/**
	 * Resolve and cache the EventOS event for every WooCommerce order.
	 *
	 * @return array<string, mixed>
	 */
	public static function sync_orders(): array {
		self::require_woocommerce();

		$orders = wc_get_orders(
			array(
				'limit'  => -1,
				'return' => 'objects',
			)
		);

		$now       = current_time( 'mysql', true );
		$processed = 0;
		$failed    = 0;

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			try {
				$event_id = Wc_Meta::resolve_order_event_id( $order );

				if ( $event_id > 0 ) {
					$order->update_meta_data( Wc_Meta::EVENT_META, $event_id );
				}

				$order->update_meta_data( Wc_Meta::SYNCED_META, $now );
				$order->save();

				++$processed;
			} catch ( \Throwable $error ) {
				++$failed;
			}
		}

		return array(
			'processed' => $processed,
			'failed'    => $failed,
			/* translators: %d: number of orders. */
			'message'   => sprintf( _n( 'Synced %d order.', 'Synced %d orders.', $processed, 'eventos' ), $processed ),
		);
	}

	/**
	 * Stamp every WooCommerce customer as synced.
	 *
	 * @return array<string, mixed>
	 */
	public static function sync_customers(): array {
		self::require_woocommerce();

		$query = new WP_User_Query(
			array(
				'role'   => 'customer',
				'fields' => 'ID',
				'number' => -1,
			)
		);

		$ids = $query->get_results();
		$now = current_time( 'mysql', true );

		foreach ( $ids as $id ) {
			update_user_meta( (int) $id, Wc_Meta::SYNCED_META, $now );
		}

		return array(
			'processed' => count( $ids ),
			'failed'    => 0,
			/* translators: %d: number of customers. */
			'message'   => sprintf( _n( 'Synced %d customer.', 'Synced %d customers.', count( $ids ), 'eventos' ), count( $ids ) ),
		);
	}

	/**
	 * Stamp every WooCommerce coupon as synced.
	 *
	 * @return array<string, mixed>
	 */
	public static function sync_coupons(): array {
		self::require_woocommerce();

		$ids = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'numberposts'    => -1,
				'fields'         => 'ids',
				'suppress_filters' => false,
			)
		);

		$now = current_time( 'mysql', true );

		foreach ( $ids as $id ) {
			update_post_meta( (int) $id, Wc_Meta::SYNCED_META, $now );
		}

		return array(
			'processed' => count( $ids ),
			'failed'    => 0,
			/* translators: %d: number of coupons. */
			'message'   => sprintf( _n( 'Synced %d coupon.', 'Synced %d coupons.', count( $ids ), 'eventos' ), count( $ids ) ),
		);
	}

	/**
	 * Current status of every WooCommerce sync target, shaped for the admin screens.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function status(): array {
		$targets = array();

		foreach ( Sync_Registry::all() as $target ) {
			$targets[ (string) $target['slug'] ] = $target;
		}

		$running = self::running_targets();
		$counts  = self::counts();
		$status  = array();

		foreach ( self::TARGETS as $key => $slug ) {
			$target      = $targets[ $slug ] ?? array();
			$last_status = (string) ( $target['last_status'] ?? '' );

			if ( in_array( $slug, $running, true ) ) {
				$state = 'running';
			} elseif ( '' === $last_status ) {
				$state = 'idle';
			} elseif ( Sync_Registry::STATUS_FAILED === $last_status ) {
				$state = 'error';
			} else {
				$state = 'complete';
			}

			$status[ $key ] = array(
				'status'   => $state,
				'last_run' => '' !== (string) ( $target['last_run_at'] ?? '' ) ? $target['last_run_at'] : null,
				'total'    => (int) ( $counts[ $key ] ?? 0 ),
				'synced'   => (int) ( $target['processed'] ?? 0 ),
				'errors'   => (int) ( $target['failed'] ?? 0 ),
			);
		}

		return $status;
	}

	/**
	 * Sync target slugs that currently have a pending or running background job.
	 *
	 * @return string[]
	 */
	private static function running_targets(): array {
		$running = array();

		foreach ( array( 'pending', 'running' ) as $job_status ) {
			$result = Job_Queue::history(
				array(
					'job_type' => Sync_Registry::JOB_TYPE,
					'status'   => $job_status,
					'per_page' => 50,
				)
			);

			foreach ( $result['items'] as $job ) {
				$target = (string) ( $job['payload']['target'] ?? '' );

				if ( '' !== $target ) {
					$running[] = $target;
				}
			}
		}

		return array_values( array_unique( $running ) );
	}

	/**
	 * Live WooCommerce object counts, keyed the same as {@see self::TARGETS}.
	 *
	 * @return array<string, int>
	 */
	private static function counts(): array {
		if ( ! WooCommerce::is_active() ) {
			return array(
				'products'  => 0,
				'orders'    => 0,
				'customers' => 0,
				'coupons'   => 0,
			);
		}

		$product_counts = (array) wp_count_posts( 'product' );
		$products       = 0;

		foreach ( array( 'publish', 'draft', 'pending', 'private' ) as $product_status ) {
			$products += (int) ( $product_counts[ $product_status ] ?? 0 );
		}

		$orders = 0;

		if ( function_exists( 'wc_get_orders' ) ) {
			$result = wc_get_orders(
				array(
					'limit'    => 1,
					'paginate' => true,
				)
			);
			$orders = (int) ( $result->total ?? 0 );
		}

		$user_counts = count_users();
		$customers   = (int) ( $user_counts['avail_roles']['customer'] ?? 0 );

		$coupon_counts = (array) wp_count_posts( 'shop_coupon' );
		$coupons       = (int) ( $coupon_counts['publish'] ?? 0 );

		return array(
			'products'  => $products,
			'orders'    => $orders,
			'customers' => $customers,
			'coupons'   => $coupons,
		);
	}

	/**
	 * Guard clause used by every handler.
	 *
	 * @return void
	 * @throws RuntimeException When WooCommerce is not active.
	 */
	private static function require_woocommerce(): void {
		if ( ! WooCommerce::is_active() ) {
			throw new RuntimeException( __( 'WooCommerce is not installed or active.', 'eventos' ) );
		}
	}
}

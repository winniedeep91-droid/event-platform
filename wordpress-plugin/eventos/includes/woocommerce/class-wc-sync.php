<?php
/**
 * WooCommerce synchronisation targets.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Woocommerce;

use EventOS\Crm\Person_Backfill_Service;
use EventOS\Crm\Person_Identity_Repository;
use EventOS\Crm\Person_Metrics_Service;
use EventOS\Crm\Person_Repository;
use EventOS\Crm\Person_Resolver;
use EventOS\Crm\Person_Timeline_Service;
use EventOS\Events\Guest_Repository;
use EventOS\Events\Ticket_Fulfillment;
use EventOS\Events\Ticket_Order_Resolver;
use EventOS\Events\Ticket_Repository;
use EventOS\Events\Ticket_Type_Repository;
use EventOS\Events\Waitlist_Repository;
use EventOS\Events\Waitlist_Service;
use EventOS\Job_Queue;
use EventOS\Platform\Sync_Registry;
use EventOS\WooCommerce;
use RuntimeException;
use Throwable;
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
		'tickets'   => 'woocommerce_tickets',
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
				array(
					'slug'        => self::TARGETS['tickets'],
					'label'       => __( 'WooCommerce ticket fulfilment', 'eventos' ),
					'description' => __( 'Issue EventOS tickets for paid orders that never triggered live fulfilment — e.g. orders placed before their product had a linked ticket type. Safe to re-run: already-fulfilled orders are skipped.', 'eventos' ),
					'module'      => 'woocommerce',
					'direction'   => 'inbound',
					'interval'    => HOUR_IN_SECONDS,
					'handler'     => array( __CLASS__, 'sync_tickets' ),
				),
			)
		);
	}

	/**
	 * Stamp every WooCommerce product as synced, and auto-provision Events
	 * and Ticket Types from every variable product — see
	 * {@see Wc_Event_Provisioning} for the WooCommerce event model
	 * (variable product = Event, variations = Ticket Types).
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

		$provisioning = Wc_Event_Provisioning::sync();

		return array_merge(
			$provisioning,
			array(
				'processed' => count( $ids ),
				'failed'    => 0,
				'message'   => sprintf(
					/* translators: %1$d: number of products, %2$d: events created, %3$d: events matched, %4$d: ticket types created, %5$d: ticket types updated. */
					__( 'Synced %1$d product(s). Events created: %2$d, matched: %3$d. Ticket types created: %4$d, updated: %5$d.', 'eventos' ),
					count( $ids ),
					$provisioning['events_created'],
					$provisioning['events_matched'],
					$provisioning['ticket_types_created'],
					$provisioning['ticket_types_updated']
				),
			)
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
	 * Re-run ticket fulfilment for every paid order — a one-time (and
	 * safely repeatable) backfill for orders whose "processing"/"completed"
	 * transition happened before their product had a ticket type linked to
	 * it (see {@see \EventOS\Events\Ticket_Fulfillment::backfill_order()}).
	 * Live checkout never needs this — it is only for orders that predate
	 * an Event/Ticket Type being provisioned for their product, most
	 * commonly right after {@see Wc_Event_Provisioning::sync()} first links
	 * an existing WooCommerce product's history.
	 *
	 * Deliberately scoped to the same {@see \EventOS\Events\Ticket_Order_Resolver::PAID_STATUSES}
	 * every revenue figure already uses — an order this sync would skip is
	 * also an order that was never counted as revenue, so there is no
	 * order this method treats as "sold" that Finance/Brand reporting does
	 * not already treat as sold too.
	 *
	 * @return array<string, mixed>
	 */
	public static function sync_tickets(): array {
		self::require_woocommerce();

		$order_ids = wc_get_orders(
			array(
				'limit'  => -1,
				'status' => Ticket_Order_Resolver::PAID_STATUSES,
				'return' => 'ids',
			)
		);

		$fulfilment = new Ticket_Fulfillment(
			new Ticket_Type_Repository(),
			new Ticket_Repository(),
			new Guest_Repository(),
			new Waitlist_Service( new Waitlist_Repository(), new Ticket_Type_Repository(), new Ticket_Repository() )
		);

		$processed = 0;
		$failed    = 0;

		foreach ( $order_ids as $order_id ) {
			try {
				$fulfilment->backfill_order( (int) $order_id );
				++$processed;
			} catch ( Throwable $error ) {
				++$failed;
			}
		}

		return array(
			'processed' => $processed,
			'failed'    => $failed,
			/* translators: %d: number of orders checked. */
			'message'   => sprintf( _n( 'Checked %d paid order for missing tickets.', 'Checked %d paid orders for missing tickets.', $processed, 'eventos' ), $processed ),
		);
	}

	/**
	 * Resolve every WooCommerce customer — both registered accounts and
	 * guest checkouts — into a permanent CRM Person (creating one if none
	 * exists yet, updating the match if one does) and stamp the source
	 * record as synced.
	 *
	 * Goes through the same {@see Person_Resolver::find_or_create()} path
	 * every other purchaser-resolution call site uses — live ticket
	 * fulfilment ({@see \EventOS\Modules\Crm_Module::handle_ticket_order_fulfilled()})
	 * and the one-time historical migration
	 * ({@see Person_Backfill_Service::run_wc_customer_batch()}) — rather
	 * than a second, parallel CRM mechanism. `find_or_create()` is
	 * idempotent by construction (an existing `wc_customer_id`/email
	 * identity resolves to its already-attached Person instead of a new
	 * one), so re-running this sync never duplicates a Person or identity.
	 *
	 * Two passes, matching the two ways WooCommerce ever has a "customer":
	 *
	 * 1. Registered accounts — every WordPress user with the `customer`
	 *    role, resolved by `wc_customer_id` + account email.
	 * 2. Guest checkouts — WooCommerce lets an order complete with no
	 *    account at all (`WC_Order::get_customer_id() === 0`); that
	 *    purchaser has no WordPress user row for a `WP_User_Query` to
	 *    ever find, so it is resolved from the order's own billing
	 *    details instead — the exact same `get_billing_first_name()` /
	 *    `get_billing_last_name()` / `get_billing_email()` /
	 *    `get_billing_phone()` accessors
	 *    {@see \EventOS\Events\Ticket_Fulfillment::fulfil_order_locked()}
	 *    already uses for the identical case on a live purchase. An order
	 *    with neither a customer_id nor a billing email has nothing to
	 *    resolve against and is skipped, mirroring
	 *    {@see \EventOS\Modules\Crm_Module::handle_ticket_order_fulfilled()}'s
	 *    own guard. Repeat guest orders from the same email safely
	 *    collapse onto one Person — `find_or_create()`'s own idempotency,
	 *    not a deduplication step here.
	 *
	 * This previously only stamped `_eventos_synced_at` user meta — the
	 * same lightweight pattern {@see sync_products()}/{@see sync_orders()}/
	 * {@see sync_coupons()} correctly use for annotating a WooCommerce
	 * record WooCommerce itself still owns — but a WooCommerce *customer*
	 * has no EventOS-owned counterpart to annotate; the actual "sync"
	 * customers need is a resolved Person, which this was never doing.
	 *
	 * @return array<string, mixed>
	 */
	public static function sync_customers(): array {
		self::require_woocommerce();

		$now      = current_time( 'mysql', true );
		$resolver = new Person_Resolver( new Person_Repository(), new Person_Identity_Repository(), new Person_Timeline_Service() );
		$metrics  = new Person_Metrics_Service( new Person_Repository(), new Person_Identity_Repository() );
		$total    = 0;
		$failed   = 0;

		// Pass 1: registered accounts.
		$query = new WP_User_Query(
			array(
				'role'   => 'customer',
				'fields' => 'ID',
				'number' => -1,
			)
		);

		$user_ids = $query->get_results();
		$total   += count( $user_ids );

		foreach ( $user_ids as $id ) {
			$user_id = (int) $id;
			$signals = Person_Backfill_Service::wc_customer_signals( $user_id );

			try {
				$result = $resolver->find_or_create(
					array(
						'wc_customer_id' => $user_id,
						'email'          => $signals['email'],
						'name'           => $signals['name'],
						'phone'          => $signals['phone'],
						'source'         => 'wc_customer_sync',
						'source_id'      => (string) $user_id,
					)
				);

				$metrics->recompute( (int) $result['person']['id'] );

				update_user_meta( $user_id, Wc_Meta::SYNCED_META, $now );
			} catch ( \Throwable $error ) {
				++$failed;
			}
		}

		// Pass 2: guest checkouts — every order with no linked account.
		$orders = wc_get_orders(
			array(
				'limit'  => -1,
				'return' => 'objects',
			)
		);

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || (int) $order->get_customer_id() > 0 ) {
				continue;
			}

			$email = (string) $order->get_billing_email();

			if ( '' === $email ) {
				continue;
			}

			++$total;

			try {
				$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

				$result = $resolver->find_or_create(
					array(
						'wc_customer_id' => 0,
						'email'          => $email,
						'name'           => $name,
						'phone'          => (string) $order->get_billing_phone(),
						'source'         => 'wc_guest_customer_sync',
						'source_id'      => (string) $order->get_id(),
					)
				);

				$metrics->recompute( (int) $result['person']['id'] );
			} catch ( \Throwable $error ) {
				++$failed;
			}
		}

		$processed = $total - $failed;

		return array(
			'processed' => $processed,
			'failed'    => $failed,
			/* translators: %d: number of customers. */
			'message'   => sprintf( _n( 'Synced %d customer.', 'Synced %d customers.', $processed, 'eventos' ), $processed ),
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
				'tickets'   => 0,
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

		$tickets = 0;

		if ( function_exists( 'wc_get_orders' ) ) {
			// "Total" for this target is scoped to what it actually
			// processes — every paid order, not every order regardless of
			// status — so it reads meaningfully next to 'processed'/'errors'
			// rather than always looking partially "unsynced".
			$paid_result = wc_get_orders(
				array(
					'limit'    => 1,
					'status'   => Ticket_Order_Resolver::PAID_STATUSES,
					'paginate' => true,
				)
			);
			$tickets     = (int) ( $paid_result->total ?? 0 );
		}

		return array(
			'products'  => $products,
			'orders'    => $orders,
			'customers' => $customers,
			'coupons'   => $coupons,
			'tickets'   => $tickets,
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

<?php
/**
 * REST controller for the WooCommerce integration screens.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use EventOS\Events\Guest_Repository;
use EventOS\Platform\Sync_Registry;
use EventOS\WooCommerce;
use EventOS\Woocommerce\Wc_Diagnostics;
use EventOS\Woocommerce\Wc_Meta;
use EventOS\Woocommerce\Wc_Sync;
use EventOS\Woocommerce\Wc_Webhooks;
use RuntimeException;
use WC_Coupon;
use WC_Order;
use WC_Product;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_User_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes live WooCommerce data for the Products, Orders, Customers,
 * Coupons, Webhooks, Diagnostics and Synchronisation screens.
 *
 * Every product, order, customer and coupon record is read straight from
 * WooCommerce on each request — nothing is mirrored into EventOS tables. The
 * only EventOS-owned storage is a handful of meta keys ({@see Wc_Meta}) that
 * link a WooCommerce object to an event, ticket type or campaign, and the
 * webhook delivery log ({@see Wc_Webhooks}).
 */
final class Woocommerce_Controller {

	// ── Connection ───────────────────────────────────────────────────────

	/**
	 * WooCommerce connection status.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		return Wc_Diagnostics::connection_status();
	}

	/**
	 * Re-check the WooCommerce connection.
	 *
	 * @return array<string, mixed>
	 */
	public static function recheck_status(): array {
		return Wc_Diagnostics::recheck();
	}

	// ── Products ─────────────────────────────────────────────────────────

	/**
	 * List WooCommerce products.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function products( WP_REST_Request $request ): WP_REST_Response {
		self::require_active();

		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$search   = (string) $request->get_param( 'search' );
		$status   = (string) $request->get_param( 'status' );
		$synced   = $request->get_param( 'synced' );

		$args = array(
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'status'   => array( 'publish', 'draft', 'pending', 'private' ),
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( '' !== $status ) {
			$args['status'] = array( $status );
		}

		if ( null !== $synced && '' !== $synced ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => Wc_Meta::EVENT_META,
					'compare' => '1' === (string) $synced ? 'EXISTS' : 'NOT EXISTS',
				),
			);
		}

		$result = wc_get_products( $args );
		$items  = array_map( array( __CLASS__, 'product_payload' ), $result->products );

		return Rest_Response::collection( $items, (int) $result->total, $page, $per_page );
	}

	/**
	 * A single WooCommerce product.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function product( WP_REST_Request $request ): array {
		self::require_active();

		$product = wc_get_product( (int) $request->get_param( 'id' ) );

		if ( ! $product ) {
			throw new RuntimeException( __( 'Product not found.', 'eventos' ), 404 );
		}

		return self::product_payload( $product );
	}

	/**
	 * Queue the products sync target.
	 *
	 * @return array<string, mixed>
	 */
	public static function sync_products(): array {
		self::require_active();

		$job_id = Sync_Registry::queue( Wc_Sync::TARGETS['products'] );

		return array(
			'queued' => $job_id > 0,
			'job_id' => (string) $job_id,
		);
	}

	/**
	 * Map a product to an event and, optionally, a ticket type.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function map_product( WP_REST_Request $request ): array {
		self::require_active();

		$id = (int) $request->get_param( 'id' );

		if ( ! wc_get_product( $id ) ) {
			throw new RuntimeException( __( 'Product not found.', 'eventos' ), 404 );
		}

		$event_id = (int) $request->get_param( 'event_id' );

		if ( $event_id <= 0 ) {
			throw new RuntimeException( __( 'An event is required.', 'eventos' ), 400 );
		}

		$ticket_type_id = (int) $request->get_param( 'ticket_type_id' );

		update_post_meta( $id, Wc_Meta::EVENT_META, $event_id );

		if ( $ticket_type_id > 0 ) {
			update_post_meta( $id, Wc_Meta::TICKET_TYPE_META, $ticket_type_id );
		} else {
			delete_post_meta( $id, Wc_Meta::TICKET_TYPE_META );
		}

		update_post_meta( $id, Wc_Meta::SYNCED_META, current_time( 'mysql', true ) );

		return self::product_payload( wc_get_product( $id ) );
	}

	/**
	 * Remove a product's event mapping.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, bool>
	 */
	public static function unmap_product( WP_REST_Request $request ): array {
		self::require_active();

		$id = (int) $request->get_param( 'id' );

		delete_post_meta( $id, Wc_Meta::EVENT_META );
		delete_post_meta( $id, Wc_Meta::TICKET_TYPE_META );

		return array( 'unmapped' => true );
	}

	// ── Orders ───────────────────────────────────────────────────────────

	/**
	 * List WooCommerce orders.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function orders( WP_REST_Request $request ): WP_REST_Response {
		self::require_active();

		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$search   = (string) $request->get_param( 'search' );
		$status   = (string) $request->get_param( 'status' );
		$orderby  = (string) $request->get_param( 'orderby' ) ?: 'date';
		$order    = strtoupper( (string) $request->get_param( 'order' ) ?: 'DESC' );

		$args = array(
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => $orderby,
			'order'    => in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC',
			'return'   => 'objects',
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( '' !== $status ) {
			$args['status'] = array( $status );
		}

		$result = wc_get_orders( $args );
		$items  = array_map( array( __CLASS__, 'order_payload' ), $result->orders );

		return Rest_Response::collection( $items, (int) $result->total, $page, $per_page );
	}

	/**
	 * A single WooCommerce order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function order( WP_REST_Request $request ): array {
		self::require_active();

		$order = wc_get_order( (int) $request->get_param( 'id' ) );

		if ( ! $order instanceof WC_Order ) {
			throw new RuntimeException( __( 'Order not found.', 'eventos' ), 404 );
		}

		return self::order_payload( $order );
	}

	/**
	 * Queue the orders sync target.
	 *
	 * @return array<string, mixed>
	 */
	public static function sync_orders(): array {
		self::require_active();

		$job_id = Sync_Registry::queue( Wc_Sync::TARGETS['orders'] );

		return array(
			'queued' => $job_id > 0,
			'job_id' => (string) $job_id,
		);
	}

	/**
	 * Re-resolve a single order's EventOS mapping immediately.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function sync_order_status( WP_REST_Request $request ): array {
		self::require_active();

		$order = wc_get_order( (int) $request->get_param( 'id' ) );

		if ( ! $order instanceof WC_Order ) {
			throw new RuntimeException( __( 'Order not found.', 'eventos' ), 404 );
		}

		$event_id = Wc_Meta::resolve_order_event_id( $order );

		if ( $event_id > 0 ) {
			$order->update_meta_data( Wc_Meta::EVENT_META, $event_id );
		}

		$order->update_meta_data( Wc_Meta::SYNCED_META, current_time( 'mysql', true ) );
		$order->save();

		return array(
			'synced'       => true,
			'eos_event_id' => $event_id > 0 ? $event_id : null,
		);
	}

	/**
	 * Export orders as CSV.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function export_orders( WP_REST_Request $request ): WP_REST_Response {
		self::require_active();

		$status = (string) $request->get_param( 'status' );
		$search = (string) $request->get_param( 'search' );

		$args = array(
			'limit'  => -1,
			'return' => 'objects',
		);

		if ( '' !== $status ) {
			$args['status'] = array( $status );
		}

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$orders = wc_get_orders( $args );
		$rows   = array( array( 'Order', 'Status', 'Customer', 'Email', 'Total', 'Currency', 'Event ID', 'Created' ) );

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$event_id = Wc_Meta::resolve_order_event_id( $order );
			$created  = $order->get_date_created();

			$rows[] = array(
				$order->get_id(),
				$order->get_status(),
				trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				$order->get_billing_email(),
				$order->get_total(),
				$order->get_currency(),
				$event_id > 0 ? $event_id : '',
				$created ? $created->date( 'c' ) : '',
			);
		}

		return self::csv_response( $rows, 'woocommerce-orders.csv' );
	}

	// ── Customers ────────────────────────────────────────────────────────

	/**
	 * List WooCommerce customers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function customers( WP_REST_Request $request ): WP_REST_Response {
		self::require_active();

		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$search   = (string) $request->get_param( 'search' );
		$segment  = (string) $request->get_param( 'status' );

		$query_args = array(
			'role'   => 'customer',
			'number' => -1,
			'fields' => 'ID',
		);

		if ( '' !== $search ) {
			$query_args['search']         = '*' . $search . '*';
			$query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		$ids   = ( new WP_User_Query( $query_args ) )->get_results();
		$items = array();

		foreach ( $ids as $id ) {
			$payload = self::customer_payload( (int) $id );

			if ( '' !== $segment && ! in_array( $segment, $payload['eos_segments'], true ) ) {
				continue;
			}

			$items[] = $payload;
		}

		$total = count( $items );
		$items = array_slice( $items, ( $page - 1 ) * $per_page, $per_page );

		return Rest_Response::collection( $items, $total, $page, $per_page );
	}

	/**
	 * A single WooCommerce customer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function customer( WP_REST_Request $request ): array {
		self::require_active();

		$id   = (int) $request->get_param( 'id' );
		$user = get_userdata( $id );

		if ( ! $user ) {
			throw new RuntimeException( __( 'Customer not found.', 'eventos' ), 404 );
		}

		return self::customer_payload( $id );
	}

	/**
	 * Queue the customers sync target.
	 *
	 * @return array<string, mixed>
	 */
	public static function sync_customers(): array {
		self::require_active();

		$job_id = Sync_Registry::queue( Wc_Sync::TARGETS['customers'] );

		return array(
			'queued' => $job_id > 0,
			'job_id' => (string) $job_id,
		);
	}

	/**
	 * Queue the coupons sync target.
	 *
	 * @return array<string, mixed>
	 */
	public static function sync_coupons(): array {
		self::require_active();

		$job_id = Sync_Registry::queue( Wc_Sync::TARGETS['coupons'] );

		return array(
			'queued' => $job_id > 0,
			'job_id' => (string) $job_id,
		);
	}

	/**
	 * Customer segment counts.
	 *
	 * @return array<string, mixed>
	 */
	public static function customer_segments(): array {
		self::require_active();

		$ids = ( new WP_User_Query(
			array(
				'role'   => 'customer',
				'number' => -1,
				'fields' => 'ID',
			)
		) )->get_results();

		$counts = array(
			'new'        => 0,
			'repeat'     => 0,
			'high_value' => 0,
			'lapsed'     => 0,
		);

		foreach ( $ids as $id ) {
			foreach ( self::customer_segments_for( self::customer_order_stats( (int) $id ) ) as $segment ) {
				$counts[ $segment ] = (int) ( $counts[ $segment ] ?? 0 ) + 1;
			}
		}

		$labels = array(
			'new'        => __( 'New', 'eventos' ),
			'repeat'     => __( 'Repeat attendees', 'eventos' ),
			'high_value' => __( 'High value', 'eventos' ),
			'lapsed'     => __( 'Lapsed', 'eventos' ),
		);

		$segments = array();

		foreach ( $counts as $slug => $count ) {
			$segments[] = array(
				'id'    => $slug,
				'label' => (string) ( $labels[ $slug ] ?? $slug ),
				'count' => $count,
			);
		}

		return array( 'segments' => $segments );
	}

	// ── Coupons ──────────────────────────────────────────────────────────

	/**
	 * List WooCommerce coupons.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function coupons( WP_REST_Request $request ): WP_REST_Response {
		self::require_active();

		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$search   = (string) $request->get_param( 'search' );
		$type     = (string) $request->get_param( 'status' );

		$query_args = array(
			'post_type'      => 'shop_coupon',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		if ( '' !== $type ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_discount_type',
					'value' => $type,
				),
			);
		}

		$query = new WP_Query( $query_args );
		$items = array_map(
			static function ( $id ): array {
				return self::coupon_payload( new WC_Coupon( (int) $id ) );
			},
			$query->posts
		);

		return Rest_Response::collection( $items, (int) $query->found_posts, $page, $per_page );
	}

	/**
	 * A single WooCommerce coupon.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function coupon( WP_REST_Request $request ): array {
		self::require_active();

		$coupon = new WC_Coupon( (int) $request->get_param( 'id' ) );

		if ( ! $coupon->get_id() ) {
			throw new RuntimeException( __( 'Coupon not found.', 'eventos' ), 404 );
		}

		return self::coupon_payload( $coupon );
	}

	/**
	 * Assign a coupon to an EventOS campaign.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function assign_coupon( WP_REST_Request $request ): array {
		self::require_active();

		$id     = (int) $request->get_param( 'id' );
		$coupon = new WC_Coupon( $id );

		if ( ! $coupon->get_id() ) {
			throw new RuntimeException( __( 'Coupon not found.', 'eventos' ), 404 );
		}

		$campaign_id = (int) $request->get_param( 'campaign_id' );
		$event_id    = (int) $request->get_param( 'event_id' );

		if ( $campaign_id <= 0 || $event_id <= 0 ) {
			throw new RuntimeException( __( 'An event and campaign are required.', 'eventos' ), 400 );
		}

		update_post_meta( $id, Wc_Meta::CAMPAIGN_META, $campaign_id );
		update_post_meta( $id, Wc_Meta::EVENT_META, $event_id );

		return self::coupon_payload( new WC_Coupon( $id ) );
	}

	/**
	 * Remove a coupon's campaign assignment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, bool>
	 */
	public static function unassign_coupon( WP_REST_Request $request ): array {
		self::require_active();

		$id = (int) $request->get_param( 'id' );

		delete_post_meta( $id, Wc_Meta::CAMPAIGN_META );
		delete_post_meta( $id, Wc_Meta::EVENT_META );

		return array( 'unassigned' => true );
	}

	// ── Synchronisation ──────────────────────────────────────────────────

	/**
	 * Status of every WooCommerce sync target.
	 *
	 * @return array<string, mixed>
	 */
	public static function sync_status(): array {
		return Wc_Sync::status();
	}

	// ── Webhooks ─────────────────────────────────────────────────────────

	/**
	 * Webhook delivery log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function webhook_log( WP_REST_Request $request ): WP_REST_Response {
		$result = Wc_Webhooks::query(
			array(
				'search'   => (string) $request->get_param( 'search' ),
				'status'   => (string) $request->get_param( 'status' ),
				'event'    => (string) $request->get_param( 'event' ),
				'page'     => (int) $request->get_param( 'page' ) ?: 1,
				'per_page' => (int) $request->get_param( 'per_page' ) ?: 20,
			)
		);

		return Rest_Response::collection( $result['items'], $result['total'], $result['page'], $result['per_page'] );
	}

	/**
	 * Turn on order event logging.
	 *
	 * @return array<string, mixed>
	 */
	public static function register_webhooks(): array {
		return Wc_Webhooks::register();
	}

	/**
	 * Turn off order event logging.
	 *
	 * @return array<string, mixed>
	 */
	public static function deregister_webhooks(): array {
		return Wc_Webhooks::deregister();
	}

	/**
	 * Retry a single webhook log entry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function retry_webhook( WP_REST_Request $request ): array {
		return Wc_Webhooks::retry( (int) $request->get_param( 'id' ) );
	}

	/**
	 * Export the webhook log as CSV.
	 *
	 * @return WP_REST_Response
	 */
	public static function export_log(): WP_REST_Response {
		$result = Wc_Webhooks::query(
			array(
				'page'     => 1,
				'per_page' => 1000,
			)
		);

		$rows = array( array( 'ID', 'Event', 'Order', 'Status', 'Summary', 'Error', 'Received', 'Processed' ) );

		foreach ( $result['items'] as $row ) {
			$rows[] = array(
				$row['id'],
				$row['event'],
				$row['wc_order_id'],
				$row['status'],
				$row['payload_summary'],
				$row['error'] ?? '',
				$row['received_at'],
				$row['processed_at'] ?? '',
			);
		}

		return self::csv_response( $rows, 'woocommerce-webhook-log.csv' );
	}

	// ── Payload builders ─────────────────────────────────────────────────

	/**
	 * Shape a WooCommerce product into the WcProductRecord contract.
	 *
	 * @param WC_Product $product Product.
	 * @return array<string, mixed>
	 */
	private static function product_payload( WC_Product $product ): array {
		$id = $product->get_id();

		$categories = array_map(
			static function ( $term ): array {
				return array( 'id' => (int) $term->term_id, 'name' => (string) $term->name, 'slug' => (string) $term->slug );
			},
			wp_get_post_terms( $id, 'product_cat' )
		);

		$tags = array_map(
			static function ( $term ): array {
				return array( 'id' => (int) $term->term_id, 'name' => (string) $term->name, 'slug' => (string) $term->slug );
			},
			wp_get_post_terms( $id, 'product_tag' )
		);

		$images     = array();
		$image_ids  = array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) );

		foreach ( $image_ids as $image_id ) {
			$images[] = array(
				'id'  => (int) $image_id,
				'src' => (string) wp_get_attachment_url( (int) $image_id ),
				'alt' => (string) get_post_meta( (int) $image_id, '_wp_attachment_image_alt', true ),
			);
		}

		$event_id       = (int) get_post_meta( $id, Wc_Meta::EVENT_META, true );
		$ticket_type_id = (int) get_post_meta( $id, Wc_Meta::TICKET_TYPE_META, true );
		$synced_at      = (string) get_post_meta( $id, Wc_Meta::SYNCED_META, true );
		$created        = $product->get_date_created();
		$modified       = $product->get_date_modified();

		return array(
			'id'                 => $id,
			'name'               => $product->get_name(),
			'slug'               => $product->get_slug(),
			'type'               => $product->get_type(),
			'status'             => $product->get_status(),
			'description'        => (string) $product->get_description(),
			'short_description'  => (string) $product->get_short_description(),
			'sku'                => (string) $product->get_sku(),
			'price'              => (float) $product->get_price(),
			'regular_price'      => (float) $product->get_regular_price(),
			'sale_price'         => '' !== (string) $product->get_sale_price() ? (float) $product->get_sale_price() : null,
			'stock_quantity'     => null !== $product->get_stock_quantity() ? (int) $product->get_stock_quantity() : null,
			'stock_status'       => $product->get_stock_status(),
			'manage_stock'       => (bool) $product->get_manage_stock(),
			'categories'         => $categories,
			'tags'               => $tags,
			'images'             => $images,
			'eos_event_id'       => $event_id > 0 ? $event_id : null,
			'eos_ticket_type_id' => $ticket_type_id > 0 ? $ticket_type_id : null,
			'eos_synced_at'      => '' !== $synced_at ? $synced_at : null,
			'created_at'         => $created ? $created->date( 'c' ) : '',
			'updated_at'         => $modified ? $modified->date( 'c' ) : '',
		);
	}

	/**
	 * Shape a WooCommerce order into the WcOrderRecord contract.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	private static function order_payload( WC_Order $order ): array {
		$line_items = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			$product_id   = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
			$purchased_id = Wc_Meta::resolve_purchased_product_id( $item );
			$quantity     = method_exists( $item, 'get_quantity' ) ? (float) $item->get_quantity() : 1.0;
			$total        = (float) $item->get_total();

			$line_items[] = array(
				'id'                 => (int) $item_id,
				'product_id'         => $product_id,
				'variation_id'       => method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0,
				'name'               => $item->get_name(),
				'quantity'           => (int) $quantity,
				'price'              => $quantity > 0 ? $total / $quantity : $total,
				'subtotal'           => method_exists( $item, 'get_subtotal' ) ? (float) $item->get_subtotal() : $total,
				'total'              => $total,
				'tax'                => method_exists( $item, 'get_total_tax' ) ? (float) $item->get_total_tax() : 0.0,
				'eos_ticket_type_id' => $purchased_id ? ( Wc_Meta::resolve_line_item_ticket_type( $purchased_id ) ?: null ) : null,
			);
		}

		$refunds = array_map(
			static function ( $refund ): array {
				$created = $refund->get_date_created();

				return array(
					'id'          => $refund->get_id(),
					'amount'      => (float) $refund->get_amount(),
					'reason'      => (string) $refund->get_reason(),
					'refunded_by' => self::user_display_name( (int) $refund->get_refunded_by() ),
					'created_at'  => $created ? $created->date( 'c' ) : '',
				);
			},
			$order->get_refunds()
		);

		$coupon_lines = array();

		foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
			$coupon_lines[] = array(
				'id'       => $coupon_item->get_id(),
				'code'     => $coupon_item->get_code(),
				'discount' => (float) $coupon_item->get_discount(),
			);
		}

		$notes = array_map(
			static function ( $note ): array {
				return array(
					'id'            => (int) $note->id,
					'note'          => (string) $note->content,
					'added_by'      => (string) $note->added_by,
					'customer_note' => (bool) $note->customer_note,
					'created_at'    => $note->date_created ? $note->date_created->date( 'c' ) : '',
				);
			},
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) )
		);

		$event_id  = Wc_Meta::resolve_order_event_id( $order );
		$synced_at = (string) $order->get_meta( Wc_Meta::SYNCED_META, true );
		$created   = $order->get_date_created();
		$modified  = $order->get_date_modified();

		return array(
			'id'                   => $order->get_id(),
			'wc_order_id'          => $order->get_id(),
			'status'               => $order->get_status(),
			'currency'             => $order->get_currency(),
			'total'                => (float) $order->get_total(),
			'subtotal'             => (float) $order->get_subtotal(),
			'tax'                  => (float) $order->get_total_tax(),
			'shipping_total'       => (float) $order->get_shipping_total(),
			'discount_total'       => (float) $order->get_discount_total(),
			'customer_id'          => (int) $order->get_customer_id(),
			'customer_name'        => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'customer_email'       => (string) $order->get_billing_email(),
			'customer_phone'       => (string) $order->get_billing_phone(),
			'billing'              => self::order_address( $order, 'billing' ),
			'shipping'             => self::order_address( $order, 'shipping' ),
			'payment_method'       => (string) $order->get_payment_method(),
			'payment_method_title' => (string) $order->get_payment_method_title(),
			'transaction_id'       => (string) $order->get_transaction_id(),
			'line_items'           => $line_items,
			'refunds'              => $refunds,
			'coupon_lines'         => $coupon_lines,
			'notes'                => $notes,
			'eos_event_id'         => $event_id > 0 ? $event_id : null,
			'eos_synced_at'        => '' !== $synced_at ? $synced_at : null,
			'created_at'           => $created ? $created->date( 'c' ) : '',
			'updated_at'           => $modified ? $modified->date( 'c' ) : '',
		);
	}

	/**
	 * Read a billing/shipping address off an order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $type  billing|shipping.
	 * @return array<string, string>
	 */
	private static function order_address( WC_Order $order, string $type ): array {
		$get = static function ( string $field ) use ( $order, $type ): string {
			$method = 'get_' . $type . '_' . $field;

			return method_exists( $order, $method ) ? (string) $order->{$method}() : '';
		};

		return array(
			'first_name' => $get( 'first_name' ),
			'last_name'  => $get( 'last_name' ),
			'company'    => $get( 'company' ),
			'address_1'  => $get( 'address_1' ),
			'address_2'  => $get( 'address_2' ),
			'city'       => $get( 'city' ),
			'state'      => $get( 'state' ),
			'postcode'   => $get( 'postcode' ),
			'country'    => $get( 'country' ),
			'email'      => 'billing' === $type ? (string) $order->get_billing_email() : '',
			'phone'      => $get( 'phone' ),
		);
	}

	/**
	 * Display name for a WordPress user, blank when there is none.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function user_display_name( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$user = get_userdata( $user_id );

		return $user ? $user->display_name : '';
	}

	/**
	 * Shape a WordPress customer into the WcCustomerRecord contract.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, mixed>
	 */
	private static function customer_payload( int $user_id ): array {
		$user = get_userdata( $user_id );

		$billing = array(
			'first_name' => (string) get_user_meta( $user_id, 'billing_first_name', true ),
			'last_name'  => (string) get_user_meta( $user_id, 'billing_last_name', true ),
			'company'    => (string) get_user_meta( $user_id, 'billing_company', true ),
			'address_1'  => (string) get_user_meta( $user_id, 'billing_address_1', true ),
			'address_2'  => (string) get_user_meta( $user_id, 'billing_address_2', true ),
			'city'       => (string) get_user_meta( $user_id, 'billing_city', true ),
			'state'      => (string) get_user_meta( $user_id, 'billing_state', true ),
			'postcode'   => (string) get_user_meta( $user_id, 'billing_postcode', true ),
			'country'    => (string) get_user_meta( $user_id, 'billing_country', true ),
			'email'      => $user ? (string) $user->user_email : '',
			'phone'      => (string) get_user_meta( $user_id, 'billing_phone', true ),
		);

		$stats      = self::customer_order_stats( $user_id );
		$synced_at  = (string) get_user_meta( $user_id, Wc_Meta::SYNCED_META, true );
		$first      = (string) get_user_meta( $user_id, 'first_name', true );
		$last       = (string) get_user_meta( $user_id, 'last_name', true );
		$registered = $user && $user->user_registered ? gmdate( 'c', strtotime( $user->user_registered ) ) : '';

		// Same cross-event query the per-event Guest tab already uses (see
		// Guest_Repository::hydrate()) — matched here by WC customer ID or
		// email so it also covers guest checkouts with no WP user account.
		$attendance = ( new Guest_Repository() )->attendance_history( $user_id, $user ? (string) $user->user_email : '' );

		return array(
			'id'                     => $user_id,
			'wc_customer_id'         => $user_id,
			'email'                  => $user ? (string) $user->user_email : '',
			'first_name'             => '' !== $first ? $first : $billing['first_name'],
			'last_name'              => '' !== $last ? $last : $billing['last_name'],
			'username'               => $user ? (string) $user->user_login : '',
			'avatar_url'             => get_avatar_url( $user_id, array( 'size' => 96 ) ),
			'billing'                => $billing,
			'total_spent'            => $stats['total_spent'],
			'total_orders'           => $stats['total_orders'],
			'date_created'           => $registered,
			'date_modified'          => $registered,
			'eos_events_attended'    => count( array_unique( array_column( $attendance, 'event_id' ) ) ),
			'eos_attendance_history' => $attendance,
			'eos_segments'           => self::customer_segments_for( $stats ),
			'eos_synced_at'          => '' !== $synced_at ? $synced_at : null,
		);
	}

	/**
	 * Order totals and recency for a customer, read straight from WooCommerce.
	 *
	 * @param int $user_id User ID.
	 * @return array{total_spent: float, total_orders: int, last_order_at: string}
	 */
	private static function customer_order_stats( int $user_id ): array {
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => -1,
				'return'      => 'objects',
			)
		);

		$spent         = 0.0;
		$last          = '';
		$paid_statuses = array( 'completed', 'processing', 'on-hold' );

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			if ( in_array( $order->get_status(), $paid_statuses, true ) ) {
				$spent += (float) $order->get_total();
			}

			$created = $order->get_date_created();

			if ( $created && ( '' === $last || $created->getTimestamp() > strtotime( $last ) ) ) {
				$last = $created->date( 'c' );
			}
		}

		return array(
			'total_spent'   => $spent,
			'total_orders'  => count( $orders ),
			'last_order_at' => $last,
		);
	}

	/**
	 * Derive segment slugs from a customer's order stats.
	 *
	 * @param array{total_spent: float, total_orders: int, last_order_at: string} $stats Order stats.
	 * @return string[]
	 */
	private static function customer_segments_for( array $stats ): array {
		$segments = array();
		$orders   = (int) $stats['total_orders'];
		$spent    = (float) $stats['total_spent'];
		$last     = (string) $stats['last_order_at'];

		if ( $orders <= 1 ) {
			$segments[] = 'new';
		}

		if ( $orders >= 2 ) {
			$segments[] = 'repeat';
		}

		if ( $spent >= 5000 ) {
			$segments[] = 'high_value';
		}

		if ( '' !== $last && strtotime( $last ) < strtotime( '-180 days' ) ) {
			$segments[] = 'lapsed';
		}

		return $segments;
	}

	/**
	 * Shape a WooCommerce coupon into the WcCouponRecord contract.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @return array<string, mixed>
	 */
	private static function coupon_payload( WC_Coupon $coupon ): array {
		$id          = $coupon->get_id();
		$expires     = $coupon->get_date_expires();
		$created     = $coupon->get_date_created();
		$modified    = $coupon->get_date_modified();
		$event_id    = (int) get_post_meta( $id, Wc_Meta::EVENT_META, true );
		$campaign_id = (int) get_post_meta( $id, Wc_Meta::CAMPAIGN_META, true );

		return array(
			'id'                   => $id,
			'wc_coupon_id'         => $id,
			'code'                 => $coupon->get_code(),
			'type'                 => $coupon->get_discount_type(),
			'amount'               => (float) $coupon->get_amount(),
			'description'          => (string) $coupon->get_description(),
			'usage_count'          => (int) $coupon->get_usage_count(),
			'usage_limit'          => $coupon->get_usage_limit() ? (int) $coupon->get_usage_limit() : null,
			'usage_limit_per_user' => $coupon->get_usage_limit_per_user() ? (int) $coupon->get_usage_limit_per_user() : null,
			'individual_use'       => (bool) $coupon->get_individual_use(),
			'free_shipping'        => (bool) $coupon->get_free_shipping(),
			'minimum_amount'       => '' !== (string) $coupon->get_minimum_amount() ? (float) $coupon->get_minimum_amount() : null,
			'maximum_amount'       => '' !== (string) $coupon->get_maximum_amount() ? (float) $coupon->get_maximum_amount() : null,
			'date_expires'         => $expires ? $expires->date( 'c' ) : null,
			'eos_campaign_id'      => $campaign_id > 0 ? $campaign_id : null,
			'eos_event_id'         => $event_id > 0 ? $event_id : null,
			'created_at'           => $created ? $created->date( 'c' ) : '',
			'updated_at'           => $modified ? $modified->date( 'c' ) : '',
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Guard clause for every endpoint that reads or writes live WooCommerce data.
	 *
	 * @return void
	 * @throws RuntimeException When WooCommerce is not installed or active.
	 */
	private static function require_active(): void {
		if ( ! WooCommerce::is_active() ) {
			throw new RuntimeException( __( 'WooCommerce is not installed or active.', 'eventos' ), 424 );
		}
	}

	/**
	 * Build a raw CSV download response.
	 *
	 * @param array<int, array<int, mixed>> $rows     Rows, first row is the header.
	 * @param string                        $filename Download filename.
	 * @return WP_REST_Response
	 */
	private static function csv_response( array $rows, string $filename ): WP_REST_Response {
		$handle = fopen( 'php://temp', 'w+' );

		foreach ( $rows as $row ) {
			fputcsv( $handle, $row );
		}

		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );

		$response = new WP_REST_Response( $csv );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );
		$response->header( 'X-EventOS-Raw-Body', '1' );

		return $response;
	}
}

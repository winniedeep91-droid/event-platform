<?php
/**
 * WooCommerce integration module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Modules;

use EventOS\Abstract_Module;
use EventOS\Capabilities;
use EventOS\Rest\Rest_Registry;
use EventOS\Rest\Woocommerce_Controller;
use EventOS\WooCommerce;
use EventOS\Woocommerce\Wc_Diagnostics;
use EventOS\Woocommerce\Wc_Schema;
use EventOS\Woocommerce\Wc_Sync;
use EventOS\Woocommerce\Wc_Webhooks;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the WooCommerce Products, Orders, Customers, Coupons, Webhooks,
 * Diagnostics and Synchronisation screens into EventOS.
 *
 * WooCommerce remains the only commerce and payment system: this module
 * never stores product, order, customer or coupon data of its own — it reads
 * WooCommerce live and keeps a small set of meta keys linking WooCommerce
 * objects back to EventOS events, ticket types and campaigns.
 */
final class Woocommerce_Module extends Abstract_Module {

	/**
	 * Module slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'woocommerce';
	}

	/**
	 * Module name.
	 *
	 * @return string
	 */
	public function name(): string {
		return __( 'WooCommerce', 'eventos' );
	}

	/**
	 * Module description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Products, orders, customers, coupons, order event logging and synchronisation with WooCommerce.', 'eventos' );
	}

	/**
	 * Module dependencies.
	 *
	 * @return string[]
	 */
	public function dependencies(): array {
		return array( 'core' );
	}

	/**
	 * Admin screens contributed by the module.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function menu_items(): array {
		return array(
			array(
				'slug'       => 'wc-products',
				'title'      => __( 'Products', 'eventos' ),
				'view'       => 'wc-products',
				'capability' => Capabilities::VIEW_DASHBOARD,
			),
			array(
				// Deliberately not "wc-orders": WooCommerce's own HPOS Orders
				// screen (Automattic\WooCommerce\Internal\Admin\Orders\PageController)
				// already registers a native admin page at that exact slug
				// under the WooCommerce top-level menu. Reusing it collided
				// with WooCommerce's own page, so admin.php?page=wc-orders
				// rendered WooCommerce's native Orders screen — with its own
				// sidebar highlighted — instead of this module's page.
				'slug'       => 'eventos-orders',
				'title'      => __( 'Orders', 'eventos' ),
				'view'       => 'wc-orders',
				'capability' => Capabilities::VIEW_DASHBOARD,
			),
			array(
				'slug'       => 'wc-customers',
				'title'      => __( 'Customers', 'eventos' ),
				'view'       => 'wc-customers',
				'capability' => Capabilities::VIEW_DASHBOARD,
			),
			array(
				'slug'       => 'wc-coupons',
				'title'      => __( 'Coupons', 'eventos' ),
				'view'       => 'wc-coupons',
				'capability' => Capabilities::VIEW_DASHBOARD,
			),
			array(
				'slug'       => 'wc-webhooks',
				'title'      => __( 'Webhooks', 'eventos' ),
				'view'       => 'wc-webhooks',
				'capability' => Capabilities::MANAGE_SETTINGS,
			),
			array(
				'slug'       => 'wc-diagnostics',
				'title'      => __( 'WooCommerce Diagnostics', 'eventos' ),
				'view'       => 'wc-diagnostics',
				'capability' => Capabilities::MANAGE_SETTINGS,
			),
			array(
				'slug'       => 'wc-sync',
				'title'      => __( 'WooCommerce Synchronisation', 'eventos' ),
				'view'       => 'wc-sync',
				'capability' => Capabilities::MANAGE_SETTINGS,
			),
		);
	}

	/**
	 * Install the webhook log table on first activation.
	 *
	 * @return void
	 */
	public function activate(): void {
		Wc_Schema::install();
	}

	/**
	 * Keep the schema current on upgrades.
	 *
	 * @param string $from_version Previously installed version.
	 * @return void
	 */
	public function upgrade( string $from_version ): void {
		unset( $from_version );

		Wc_Schema::install();
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		Wc_Schema::maybe_install();

		// Called directly rather than through the `eventos_register_sync_targets`
		// hook: Sync_Registry::register() has no ordering requirement, unlike the
		// hook (see Wc_Sync::register_targets() docblock).
		Wc_Sync::register_targets();

		Wc_Webhooks::bootstrap();
		Wc_Diagnostics::bootstrap();

		add_action( 'eventos_register_rest_endpoints', array( $this, 'register_rest_endpoints' ) );
		add_filter( 'eventos_admin_pages', array( $this, 'register_admin_pages' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_raw_response' ), 10, 2 );
	}

	/**
	 * Add the module's screens to the EventOS admin menu, only while
	 * WooCommerce is actually installed and active.
	 *
	 * @param array<string, array<string, mixed>> $pages Existing pages.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_admin_pages( array $pages ): array {
		if ( ! WooCommerce::is_active() ) {
			return $pages;
		}

		foreach ( $this->menu_items() as $item ) {
			$pages[ (string) $item['slug'] ] = array(
				'title'      => (string) $item['title'],
				'view'       => (string) $item['view'],
				'capability' => (string) $item['capability'],
			);
		}

		return $pages;
	}

	/**
	 * Declare the module's REST endpoints.
	 *
	 * @return void
	 */
	public function register_rest_endpoints(): void {
		$view   = Capabilities::VIEW_DASHBOARD;
		$manage = Capabilities::MANAGE_SETTINGS;

		Rest_Registry::register_many(
			array(
				array(
					'route'    => '/woocommerce/status',
					'methods'  => 'GET',
					'callback' => array( Woocommerce_Controller::class, 'status' ),
					'capability' => $view,
					'summary'  => __( 'WooCommerce connection status.', 'eventos' ),
				),
				array(
					'route'      => '/woocommerce/status/recheck',
					'methods'    => 'POST',
					'callback'   => array( Woocommerce_Controller::class, 'recheck_status' ),
					'capability' => $manage,
					'log_action' => 'wc_connection_rechecked',
					'summary'    => __( 'Re-check the WooCommerce connection.', 'eventos' ),
				),

				array(
					'route'      => '/woocommerce/products',
					'methods'    => 'GET',
					'callback'   => array( Woocommerce_Controller::class, 'products' ),
					'capability' => $view,
					'summary'    => __( 'List WooCommerce products.', 'eventos' ),
					'args'       => $this->list_args(),
				),
				array(
					'route'      => '/woocommerce/products/(?P<id>\d+)',
					'methods'    => 'GET',
					'callback'   => array( Woocommerce_Controller::class, 'product' ),
					'capability' => $view,
					'summary'    => __( 'A single WooCommerce product.', 'eventos' ),
				),
				array(
					'route'      => '/woocommerce/products/sync',
					'methods'    => 'POST',
					'callback'   => array( Woocommerce_Controller::class, 'sync_products' ),
					'capability' => $manage,
					'log_action' => 'wc_products_sync_queued',
					'summary'    => __( 'Queue a product synchronisation.', 'eventos' ),
					'args'       => array( 'event_id' => array( 'type' => 'integer' ) ),
				),
				array(
					'route'      => '/woocommerce/products/(?P<id>\d+)/map',
					'methods'    => 'POST',
					'callback'   => array( Woocommerce_Controller::class, 'map_product' ),
					'capability' => $manage,
					'log_action' => 'wc_product_mapped',
					'summary'    => __( 'Map a product to an event and ticket type.', 'eventos' ),
					'args'       => array(
						'event_id'       => array(
							'type'     => 'integer',
							'required' => true,
						),
						'ticket_type_id' => array( 'type' => 'integer' ),
					),
				),
				array(
					'route'      => '/woocommerce/products/(?P<id>\d+)/map',
					'methods'    => 'DELETE',
					'callback'   => array( Woocommerce_Controller::class, 'unmap_product' ),
					'capability' => $manage,
					'log_action' => 'wc_product_unmapped',
					'summary'    => __( 'Remove a product mapping.', 'eventos' ),
				),

				array(
					'route'      => '/woocommerce/orders',
					'methods'    => 'GET',
					'callback'   => array( Woocommerce_Controller::class, 'orders' ),
					'capability' => $view,
					'summary'    => __( 'List WooCommerce orders.', 'eventos' ),
					'args'       => $this->list_args(),
				),
				array(
					'route'      => '/woocommerce/orders/export',
					'methods'    => 'GET',
					'callback'   => array( Woocommerce_Controller::class, 'export_orders' ),
					'capability' => $view,
					'envelope'   => false,
					'summary'    => __( 'Export WooCommerce orders as CSV.', 'eventos' ),
					'args'       => $this->list_args(),
				),
				array(
					'route'      => '/woocommerce/orders/(?P<id>\d+)',
					'methods'    => 'GET',
					'callback'   => array( Woocommerce_Controller::class, 'order' ),
					'capability' => $view,
					'summary'    => __( 'A single WooCommerce order.', 'eventos' ),
				),
				array(
					'route'      => '/woocommerce/orders/sync',
					'methods'    => 'POST',
					'callback'   => array( Woocommerce_Controller::class, 'sync_orders' ),
					'capability' => $manage,
					'log_action' => 'wc_orders_sync_queued',
					'summary'    => __( 'Queue an order synchronisation.', 'eventos' ),
					'args'       => array( 'event_id' => array( 'type' => 'integer' ) ),
				),
				array(
					'route'      => '/woocommerce/orders/(?P<id>\d+)/sync',
					'methods'    => 'POST',
					'callback'   => array( Woocommerce_Controller::class, 'sync_order_status' ),
					'capability' => $manage,
					'log_action' => 'wc_order_synced',
					'summary'    => __( 'Re-resolve a single order\'s event mapping.', 'eventos' ),
				),

				array(
					'route'      => '/woocommerce/customers',
					'methods'    => 'GET',
					'callback'   => array( Woocommerce_Controller::class, 'customers' ),
					'capability' => $view,
					'summary'    => __( 'List WooCommerce customers.', 'eventos' ),
					'args'       => $this->list_args(),
				),
				array(
					'route'      => '/woocommerce/customers/segments',
					'methods'    => 'GET',
					'callback'   => array( Woocommerce_Controller::class, 'customer_segments' ),
					'capability' => $view,
					'summary'    => __( 'Customer segment counts.', 'eventos' ),
				),
				array(
					'route'      => '/woocommerce/customers/(?P<id>\d+)',
					'methods'    => 'GET',
					'callback'   => array( Woocommerce_Controller::class, 'customer' ),
					'capability' => $view,
					'summary'    => __( 'A single WooCommerce customer.', 'eventos' ),
				),
				array(
					'route'      => '/woocommerce/customers/sync',
					'methods'    => 'POST',
					'callback'   => array( Woocommerce_Controller::class, 'sync_customers' ),
					'capability' => $manage,
					'log_action' => 'wc_customers_sync_queued',
					'summary'    => __( 'Queue a customer synchronisation.', 'eventos' ),
				),

				array(
					'route'      => '/woocommerce/coupons',
					'methods'    => 'GET',
					'callback'   => array( Woocommerce_Controller::class, 'coupons' ),
					'capability' => $view,
					'summary'    => __( 'List WooCommerce coupons.', 'eventos' ),
					'args'       => $this->list_args(),
				),
				array(
					'route'      => '/woocommerce/coupons/(?P<id>\d+)',
					'methods'    => 'GET',
					'callback'   => array( Woocommerce_Controller::class, 'coupon' ),
					'capability' => $view,
					'summary'    => __( 'A single WooCommerce coupon.', 'eventos' ),
				),
				array(
					'route'      => '/woocommerce/coupons/sync',
					'methods'    => 'POST',
					'callback'   => array( Woocommerce_Controller::class, 'sync_coupons' ),
					'capability' => $manage,
					'log_action' => 'wc_coupons_sync_queued',
					'summary'    => __( 'Queue a coupon synchronisation.', 'eventos' ),
				),
				array(
					'route'      => '/woocommerce/coupons/(?P<id>\d+)/assign',
					'methods'    => 'POST',
					'callback'   => array( Woocommerce_Controller::class, 'assign_coupon' ),
					'capability' => $manage,
					'log_action' => 'wc_coupon_assigned',
					'summary'    => __( 'Assign a coupon to an EventOS campaign.', 'eventos' ),
					'args'       => array(
						'campaign_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
						'event_id'    => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
				array(
					'route'      => '/woocommerce/coupons/(?P<id>\d+)/assign',
					'methods'    => 'DELETE',
					'callback'   => array( Woocommerce_Controller::class, 'unassign_coupon' ),
					'capability' => $manage,
					'log_action' => 'wc_coupon_unassigned',
					'summary'    => __( 'Remove a coupon assignment.', 'eventos' ),
				),

				array(
					'route'      => '/woocommerce/sync/status',
					'methods'    => 'GET',
					'callback'   => array( Woocommerce_Controller::class, 'sync_status' ),
					'capability' => $view,
					'summary'    => __( 'Status of every WooCommerce sync target.', 'eventos' ),
				),

				array(
					'route'      => '/woocommerce/webhooks/log',
					'methods'    => 'GET',
					'callback'   => array( Woocommerce_Controller::class, 'webhook_log' ),
					'capability' => $view,
					'summary'    => __( 'WooCommerce order event log.', 'eventos' ),
					'args'       => $this->list_args(),
				),
				array(
					'route'      => '/woocommerce/webhooks/register',
					'methods'    => 'POST',
					'callback'   => array( Woocommerce_Controller::class, 'register_webhooks' ),
					'capability' => $manage,
					'log_action' => 'wc_webhooks_registered',
					'summary'    => __( 'Turn on WooCommerce order event logging.', 'eventos' ),
				),
				array(
					'route'      => '/woocommerce/webhooks/register',
					'methods'    => 'DELETE',
					'callback'   => array( Woocommerce_Controller::class, 'deregister_webhooks' ),
					'capability' => $manage,
					'log_action' => 'wc_webhooks_deregistered',
					'summary'    => __( 'Turn off WooCommerce order event logging.', 'eventos' ),
				),
				array(
					'route'      => '/woocommerce/webhooks/log/(?P<id>\d+)/retry',
					'methods'    => 'POST',
					'callback'   => array( Woocommerce_Controller::class, 'retry_webhook' ),
					'capability' => $manage,
					'log_action' => 'wc_webhook_retried',
					'summary'    => __( 'Retry a webhook log entry.', 'eventos' ),
				),
				array(
					'route'      => '/woocommerce/log/export',
					'methods'    => 'GET',
					'callback'   => array( Woocommerce_Controller::class, 'export_log' ),
					'capability' => $view,
					'envelope'   => false,
					'summary'    => __( 'Export the webhook log as CSV.', 'eventos' ),
				),
			),
			$this->slug()
		);
	}

	/**
	 * Shared query arguments for the module's list endpoints.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function list_args(): array {
		return array(
			'search'   => array( 'type' => 'string' ),
			'status'   => array( 'type' => 'string' ),
			'event'    => array( 'type' => 'string' ),
			'event_id' => array( 'type' => 'integer' ),
			'synced'   => array( 'type' => 'string' ),
			'orderby'  => array( 'type' => 'string' ),
			'order'    => array( 'type' => 'string' ),
			'page'     => array( 'type' => 'integer' ),
			'per_page' => array( 'type' => 'integer' ),
		);
	}

	/**
	 * Serve a raw (non-JSON) response body for CSV export endpoints.
	 *
	 * @param bool                  $served  Whether the request has already been served.
	 * @param WP_REST_Response|mixed $result Response result.
	 * @return bool
	 */
	public function serve_raw_response( $served, $result ) {
		if ( ! $result instanceof WP_REST_Response ) {
			return $served;
		}

		$headers = $result->get_headers();

		if ( empty( $headers['X-EventOS-Raw-Body'] ) ) {
			return $served;
		}

		unset( $headers['X-EventOS-Raw-Body'] );

		foreach ( $headers as $key => $value ) {
			header( "{$key}: {$value}" );
		}

		echo (string) $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		return true;
	}
}

<?php
/**
 * Core Configuration module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Modules;

use EventOS\Abstract_Module;
use EventOS\Capabilities;
use EventOS\Cron;
use EventOS\Export\Export_Registry;
use EventOS\Import\Import_Engine;
use EventOS\Import\Import_Registry;
use EventOS\Import\Providers\Csv_Provider;
use EventOS\Import\Providers\Fixr_Provider;
use EventOS\Import\Providers\Howler_Provider;
use EventOS\Import\Providers\Quicket_Provider;
use EventOS\Import\Providers\Webtickets_Provider;
use EventOS\Import\Providers\WooCommerce_Provider;
use EventOS\Rest\Docs_Controller;
use EventOS\Rest\Export_Controller;
use EventOS\Rest\Rest_Registry;
use EventOS\Search_Registry;
use EventOS\Invitations;
use EventOS\Rest\Dashboard_Controller;
use EventOS\Rest\Invitations_Controller;
use EventOS\Rest\Settings_Controller;
use EventOS\Rest\Team_Controller;
use EventOS\Security;
use EventOS\WooCommerce;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstraps configuration, roles, invitations and the REST surface.
 */
final class Core_Module extends Abstract_Module {

	/**
	 * Module slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'core';
	}

	/**
	 * Module name.
	 *
	 * @return string
	 */
	public function name(): string {
		return __( 'Core Configuration', 'eventos' );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		Capabilities::init();
		Security::init();
		Invitations::init();
		Cron::init();
		Import_Engine::init();
		Rest_Registry::init();
		Export_Registry::bootstrap();
		Search_Registry::bootstrap();

		add_action( 'eventos_register_import_providers', array( $this, 'register_import_providers' ) );
		add_action( 'eventos_register_rest_endpoints', array( $this, 'register_infrastructure_endpoints' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_raw_response' ), 10, 2 );
		WooCommerce::init();

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register the import providers shipped with core.
	 *
	 * @return void
	 */
	public function register_import_providers(): void {
		foreach (
			array(
				new Csv_Provider(),
				new WooCommerce_Provider(),
				new Quicket_Provider(),
				new Howler_Provider(),
				new Webtickets_Provider(),
				new Fixr_Provider(),
			) as $provider
		) {
			Import_Registry::register( $provider );
		}
	}

	/**
	 * Declare the infrastructure endpoints through the central REST registry.
	 *
	 * @return void
	 */
	public function register_infrastructure_endpoints(): void {
		Rest_Registry::register_many(
			array(
				array(
					'route'      => '/docs',
					'methods'    => 'GET',
					'capability' => Capabilities::VIEW_DASHBOARD,
					'callback'   => array( Docs_Controller::class, 'index' ),
					'summary'    => __( 'List every registered EventOS endpoint.', 'eventos' ),
					'args'       => array(
						'module' => array(
							'type'        => 'string',
							'description' => __( 'Limit the reference to one module slug.', 'eventos' ),
						),
					),
				),
				array(
					'route'      => '/docs/openapi',
					'methods'    => 'GET',
					'capability' => Capabilities::VIEW_DASHBOARD,
					'callback'   => array( Docs_Controller::class, 'openapi' ),
					'envelope'   => false,
					'summary'    => __( 'OpenAPI 3.1 document for the EventOS API.', 'eventos' ),
				),
				array(
					'route'      => '/exports/(?P<entity>[a-z0-9_-]+)/(?P<format>csv|json|pdf)',
					'methods'    => 'GET',
					'capability' => Capabilities::VIEW_DASHBOARD,
					'callback'   => array( Export_Controller::class, 'download' ),
					'envelope'   => false,
					'summary'    => __( 'Download a registered export as CSV, JSON or PDF.', 'eventos' ),
				),
			),
			$this->slug()
		);
	}

	/**
	 * Serve a raw (non-JSON) response body for download endpoints.
	 *
	 * @param bool $served Whether the request has already been served.
	 * @param mixed $result Response result.
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

	/**
	 * Register the module's REST controllers.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		foreach (
			array(
				new Settings_Controller(),
				new Team_Controller(),
				new Invitations_Controller(),
				new Dashboard_Controller(),
			) as $controller
		) {
			$controller->register_routes();
		}
	}
}

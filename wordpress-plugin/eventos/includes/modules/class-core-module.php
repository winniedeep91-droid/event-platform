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
use EventOS\Import\Ticketing_Import_Orchestrator;
use EventOS\Import\Providers\Csv_Provider;
use EventOS\Import\Providers\Fixr_Provider;
use EventOS\Import\Providers\Howler_Provider;
use EventOS\Import\Providers\Quicket_Provider;
use EventOS\Import\Providers\Webtickets_Provider;
use EventOS\Import\Providers\WooCommerce_Provider;
use EventOS\Job_Queue;
use EventOS\Rest\Docs_Controller;
use EventOS\Rest\Export_Controller;
use EventOS\Rest\Import_Controller;
use EventOS\Rest\Import_Profile_Controller;
use EventOS\Rest\Rest_Registry;
use EventOS\Rest\Search_Controller;
use EventOS\Search_Registry;
use EventOS\Invitations;
use EventOS\Rest\Dashboard_Controller;
use EventOS\Rest\Invitations_Controller;
use EventOS\Rest\Settings_Controller;
use EventOS\Rest\Team_Controller;
use EventOS\Security;
use EventOS\Settings;
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
		Job_Queue::init();
		Import_Engine::init();
		Ticketing_Import_Orchestrator::init();
		Rest_Registry::init();

		// Deferred to the `init` hook rather than called synchronously here:
		// every module's own init() runs inside one `plugins_loaded` callback
		// (see Plugin::register_modules()), in the fixed order Core, Platform,
		// Events, WooCommerce, Crm — Core always runs first. Calling
		// Export_Registry::bootstrap() here would fire its
		// `eventos_register_exports` action before Events_Module/Crm_Module
		// have reached their own init() and attached their
		// add_action('eventos_register_exports', ...) listeners, so nothing
		// they register would ever actually appear — the exact same
		// ordering hazard Person_Backfill_Service's docblock documents for
		// `eventos_register_jobs`, just not previously caught here because
		// nothing had exercised these registrations at runtime yet. `init`
		// fires once, later, after every module's init() has already run.
		// Same hazard, same fix as Export_Registry above: Search_Registry::bootstrap()
		// fires `eventos_register_search_entities` the moment it's called, and
		// Events_Module/Crm_Module only attach their listeners once their own
		// (later-running) init() executes — so this must defer to `init` too,
		// not fire synchronously here.
		add_action( 'init', array( Export_Registry::class, 'bootstrap' ) );
		add_action( 'init', array( Search_Registry::class, 'bootstrap' ) );

		add_action( 'eventos_register_import_providers', array( $this, 'register_import_providers' ) );
		add_action( 'eventos_register_rest_endpoints', array( $this, 'register_infrastructure_endpoints' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_raw_response' ), 10, 2 );
		Settings::init();
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
				array(
					'route'      => '/exports',
					'methods'    => 'GET',
					'capability' => Capabilities::VIEW_DASHBOARD,
					'callback'   => static function () {
						return array( 'entities' => Export_Registry::describe() );
					},
					'summary'    => __( 'List exportable entities the current user may download.', 'eventos' ),
				),
				array(
					'route'      => '/imports/targets',
					'methods'    => 'GET',
					'capability' => Capabilities::VIEW_DASHBOARD,
					'callback'   => static function () {
						return Import_Controller::describe();
					},
					'summary'    => __( 'List importable entities and available providers.', 'eventos' ),
				),
				array(
					'route'      => '/imports/preview',
					'methods'    => 'POST',
					'capability' => Capabilities::RUN_IMPORTS,
					'callback'   => array( Import_Controller::class, 'preview' ),
					'summary'    => __( 'Read-only sample of an import source.', 'eventos' ),
				),
				array(
					'route'      => '/imports/mapping',
					'methods'    => 'POST',
					'capability' => Capabilities::RUN_IMPORTS,
					'callback'   => array( Import_Controller::class, 'mapping' ),
					'summary'    => __( 'Suggested field mapping for a source against a target entity.', 'eventos' ),
				),
				array(
					'route'      => '/imports/start',
					'methods'    => 'POST',
					'capability' => Capabilities::RUN_IMPORTS,
					'callback'   => array( Import_Controller::class, 'start' ),
					'summary'    => __( 'Start (or dry-run) an import against a registered target.', 'eventos' ),
				),
				array(
					'route'      => '/imports/runs',
					'methods'    => 'GET',
					'capability' => Capabilities::RUN_IMPORTS,
					'callback'   => array( Import_Controller::class, 'runs' ),
					'summary'    => __( 'Every import run, newest first.', 'eventos' ),
				),
				array(
					'route'      => '/imports/runs/(?P<id>\d+)',
					'methods'    => 'GET',
					'capability' => Capabilities::RUN_IMPORTS,
					'callback'   => array( Import_Controller::class, 'run' ),
					'summary'    => __( 'A single import run.', 'eventos' ),
				),
				array(
					'route'      => '/imports/runs/(?P<id>\d+)/rollback',
					'methods'    => 'POST',
					'capability' => Capabilities::RUN_IMPORTS,
					'callback'   => array( Import_Controller::class, 'rollback' ),
					'log_action' => 'import_rolled_back',
					'summary'    => __( 'Undo a completed import run.', 'eventos' ),
				),
				array(
					'route'      => '/imports/profiles',
					'methods'    => 'GET',
					'capability' => Capabilities::VIEW_DASHBOARD,
					'callback'   => array( Import_Profile_Controller::class, 'profiles' ),
					'summary'    => __( 'List every registered Import Profile.', 'eventos' ),
				),
				array(
					'route'      => '/imports/profiles/(?P<id>[a-z0-9-]+)',
					'methods'    => 'GET',
					'capability' => Capabilities::VIEW_DASHBOARD,
					'callback'   => array( Import_Profile_Controller::class, 'profile' ),
					'summary'    => __( 'A single Import Profile.', 'eventos' ),
				),
				array(
					'route'      => '/imports/profiles/(?P<id>[a-z0-9-]+)/mapping',
					'methods'    => 'POST',
					'capability' => Capabilities::RUN_IMPORTS,
					'callback'   => array( Import_Profile_Controller::class, 'resolve_mapping' ),
					'summary'    => __( 'Detect a source\'s columns and resolve the profile\'s default mapping for one stage.', 'eventos' ),
				),
				array(
					'route'      => '/imports/profiles/(?P<id>[a-z0-9-]+)/validate',
					'methods'    => 'POST',
					'capability' => Capabilities::RUN_IMPORTS,
					'callback'   => array( Import_Profile_Controller::class, 'validate_mapping' ),
					'summary'    => __( 'Validate a mapping before an import is allowed to start.', 'eventos' ),
				),
				array(
					'route'      => '/imports/profiles/(?P<id>[a-z0-9-]+)/preview',
					'methods'    => 'POST',
					'capability' => Capabilities::RUN_IMPORTS,
					'callback'   => array( Import_Profile_Controller::class, 'preview' ),
					'summary'    => __( 'Preview a small batch of source rows exactly as mapping will transform them.', 'eventos' ),
				),
				array(
					'route'      => '/imports/profiles/(?P<id>[a-z0-9-]+)/start',
					'methods'    => 'POST',
					'capability' => Capabilities::RUN_IMPORTS,
					'callback'   => array( Import_Profile_Controller::class, 'start' ),
					'summary'    => __( 'Start a single-stage profile-driven import.', 'eventos' ),
				),
				array(
					'route'      => '/imports/profiles/(?P<id>[a-z0-9-]+)/bundle',
					'methods'    => 'POST',
					'capability' => Capabilities::RUN_IMPORTS,
					'callback'   => array( Import_Profile_Controller::class, 'bundle' ),
					'summary'    => __( 'Start a multi-stage profile-driven bundle import.', 'eventos' ),
				),
				array(
					'route'      => '/search/entities',
					'methods'    => 'GET',
					'capability' => Capabilities::VIEW_DASHBOARD,
					'callback'   => array( Search_Controller::class, 'entities' ),
					'summary'    => __( 'Entities the current user may search, and their searchable/filterable/sortable fields.', 'eventos' ),
				),
				array(
					'route'      => '/search',
					'methods'    => 'GET',
					'capability' => Capabilities::VIEW_DASHBOARD,
					'callback'   => array( Search_Controller::class, 'search' ),
					'summary'    => __( 'Search every entity the current user may access, grouped by entity.', 'eventos' ),
				),
				array(
					'route'      => '/search/(?P<entity>[a-z0-9_-]+)',
					'methods'    => 'GET',
					'capability' => Capabilities::VIEW_DASHBOARD,
					'callback'   => array( Search_Controller::class, 'query' ),
					'summary'    => __( 'Paginated search against a single registered entity.', 'eventos' ),
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
		// Every module with raw-body (CSV/PDF) download endpoints hooks
		// this same filter (see Woocommerce_Module::serve_raw_response()).
		// Once one of them has already echoed the body, every later
		// callback in the chain must no-op — otherwise the response body
		// is echoed once per hooked module, corrupting every export.
		if ( $served ) {
			return $served;
		}

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

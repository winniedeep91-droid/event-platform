<?php
/**
 * CRM / permanent Person module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Modules;

use EventOS\Abstract_Module;
use EventOS\Crm\Crm_Capabilities;
use EventOS\Crm\Person_Backfill_Service;
use EventOS\Crm\Person_Consent_Repository;
use EventOS\Crm\Person_Identity_Repository;
use EventOS\Crm\Person_Metrics_Service;
use EventOS\Crm\Person_Note_Repository;
use EventOS\Crm\Person_Repository;
use EventOS\Crm\Person_Resolver;
use EventOS\Crm\Person_Schema;
use EventOS\Crm\Person_Service;
use EventOS\Crm\Person_Tag_Repository;
use EventOS\Crm\Person_Timeline_Service;
use EventOS\Crm\Segment_Repository;
use EventOS\Rest\Person_Controller;
use EventOS\Rest\Rest_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the permanent Person / Relationship (CRM) module into EventOS.
 *
 * "Tickets belong to events. People belong to the brand." — this module owns
 * the global Person entity and everything built on top of it (identity,
 * tags/notes, consent, segments, rewards, relationship timeline). The event
 * operational layer (guests, tickets, checkins) stays owned by the Events
 * module and is untouched by this one.
 *
 * Phase 1 established the schema foundation (Final Implementation
 * Specification, Section 17). Phase 2 added identity resolution and
 * historical backfill. Phase 3 added the CRM read-model/REST layer. Phase 4
 * adds the internal EventOS admin screens on top of that REST layer — still
 * no new capability: every route and every menu item here reuses the
 * `eventos_manage_crm` capability Core already registers, referenced via
 * {@see Crm_Capabilities::MANAGE_CRM}.
 */
final class Crm_Module extends Abstract_Module {

	/**
	 * Read-model service.
	 *
	 * @var Person_Service|null
	 */
	private ?Person_Service $person_service = null;

	/**
	 * Module slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'crm';
	}

	/**
	 * Module name.
	 *
	 * @return string
	 */
	public function name(): string {
		return __( 'CRM', 'eventos' );
	}

	/**
	 * Module description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Permanent Person identity and relationship history, global across every event.', 'eventos' );
	}

	/**
	 * Module dependencies.
	 *
	 * @return string[]
	 */
	public function dependencies(): array {
		return array( 'core', 'events' );
	}

	/**
	 * Admin screens contributed by the module.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function menu_items(): array {
		return array(
			array(
				'slug'       => 'eventos-crm-people',
				'title'      => __( 'Customers', 'eventos' ),
				'view'       => 'crm/people',
				'capability' => Crm_Capabilities::MANAGE_CRM,
			),
			array(
				'slug'       => 'eventos-crm-segments',
				'title'      => __( 'Segments', 'eventos' ),
				'view'       => 'crm/segments',
				'capability' => Crm_Capabilities::MANAGE_CRM,
			),
			array(
				'slug'       => 'eventos-crm-insights',
				'title'      => __( 'Relationship Insights', 'eventos' ),
				'view'       => 'crm/insights',
				'capability' => Crm_Capabilities::MANAGE_CRM,
			),
		);
	}

	/**
	 * Add the module's screens to the EventOS admin menu.
	 *
	 * @param array<string, array<string, mixed>> $pages Existing pages.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_admin_pages( array $pages ): array {
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
	 * Read-model service accessor.
	 *
	 * @return Person_Service
	 */
	public function person_service(): Person_Service {
		if ( null === $this->person_service ) {
			$this->person_service = new Person_Service(
				new Person_Repository(),
				new Person_Identity_Repository(),
				new Person_Tag_Repository(),
				new Person_Note_Repository(),
				new Person_Consent_Repository(),
				new Segment_Repository(),
				new Person_Timeline_Service()
			);
		}

		return $this->person_service;
	}

	/**
	 * Install the module's tables on first activation.
	 *
	 * @return void
	 */
	public function activate(): void {
		Person_Schema::install();
	}

	/**
	 * Keep the schema current on upgrades.
	 *
	 * @param string $from_version Previously installed version.
	 * @return void
	 */
	public function upgrade( string $from_version ): void {
		unset( $from_version );

		Person_Schema::install();
	}

	/**
	 * Register runtime hooks.
	 *
	 * Person_Backfill_Service::init() is called directly rather than via
	 * the `eventos_register_jobs` hook — by the time this module's init()
	 * runs, Core_Module has already booted (and with it, Job_Queue::init(),
	 * which is what fires that hook), so attaching to it here would
	 * silently never register the handler. See
	 * Person_Backfill_Service's class docblock for the full explanation,
	 * and Import_Engine::init() (called the same direct way from
	 * Core_Module::init()) for the existing precedent.
	 *
	 * @return void
	 */
	public function init(): void {
		Person_Schema::maybe_install();

		Person_Backfill_Service::init();

		add_action( 'eventos_register_rest_endpoints', array( $this, 'register_rest_endpoints' ) );
		add_filter( 'eventos_admin_pages', array( $this, 'register_admin_pages' ) );
		add_action( 'eventos_ticket_order_fulfilled', array( $this, 'handle_ticket_order_fulfilled' ), 10, 5 );
	}

	/**
	 * Resolve/update the permanent Person for an order that just fulfilled
	 * at least one EventOS ticket — the live counterpart to
	 * {@see Person_Backfill_Service}'s historical passes, going through the
	 * exact same {@see Person_Resolver} so a Person created by one path is
	 * found by the other. Fired from
	 * {@see \EventOS\Events\Ticket_Fulfillment::fulfil_order()} (see that
	 * method's docblock) rather than a raw WooCommerce hook, so this only
	 * ever runs for orders already confirmed to be EventOS-relevant.
	 *
	 * @param int    $order_id    WooCommerce order ID.
	 * @param int    $customer_id WooCommerce customer ID, 0 for a guest checkout.
	 * @param string $email       Billing email.
	 * @param string $name        Billing first + last name.
	 * @param string $phone       Billing phone.
	 * @return void
	 */
	public function handle_ticket_order_fulfilled( int $order_id, int $customer_id, string $email, string $name, string $phone ): void {
		if ( $customer_id <= 0 && '' === trim( $email ) ) {
			// Nothing to resolve against — Person_Resolver would otherwise
			// create an unmatchable blank Person on every such call.
			return;
		}

		$resolver = new Person_Resolver( new Person_Repository(), new Person_Identity_Repository(), new Person_Timeline_Service() );

		$result = $resolver->find_or_create(
			array(
				'wc_customer_id' => $customer_id,
				'email'          => $email,
				'name'           => $name,
				'phone'          => $phone,
				'source'         => 'ticket_fulfillment',
				'source_id'      => (string) $order_id,
			)
		);

		( new Person_Metrics_Service( new Person_Repository(), new Person_Identity_Repository() ) )->recompute( (int) $result['person']['id'] );
	}

	/**
	 * Register the CRM REST routes.
	 *
	 * Hooked to `eventos_register_rest_endpoints`, which `Rest_Registry`
	 * fires from the native `rest_api_init` hook — unlike
	 * `eventos_register_jobs` (see Person_Backfill_Service's docblock),
	 * this one fires on every request after every module has already
	 * booted, so the hook-based pattern every other module's REST
	 * controller uses (e.g. Events_Module) is safe here.
	 *
	 * @return void
	 */
	public function register_rest_endpoints(): void {
		$controller = new Person_Controller(
			$this->person_service(),
			new Person_Tag_Repository(),
			new Person_Note_Repository(),
			new Person_Consent_Repository(),
			new Segment_Repository()
		);

		Rest_Registry::register_many( $controller->endpoints(), $this->slug() );
	}
}

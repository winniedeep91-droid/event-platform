<?php
/**
 * CRM / permanent Person module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Modules;

use EventOS\Abstract_Module;
use EventOS\Capabilities;
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
use EventOS\Export\Export_Registry;
use EventOS\Import\Import_Registry;
use EventOS\Rest\Person_Controller;
use EventOS\Rest\Rest_Registry;
use EventOS\Search_Registry;
use WP_Error;

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
		add_action( 'eventos_register_exports', array( $this, 'register_exports' ) );
		add_action( 'eventos_register_import_providers', array( $this, 'register_import_targets' ) );
		add_action( 'eventos_register_search_entities', array( $this, 'register_search_entities' ) );
	}

	/**
	 * Register CRM People as a globally searchable entity.
	 *
	 * Reuses {@see Person_Service::search()} directly — the exact same
	 * indexed name/email/phone query the People list screen already runs —
	 * rather than a second person-search data layer.
	 *
	 * @return void
	 */
	public function register_search_entities(): void {
		$person_service = $this->person_service();

		Search_Registry::register(
			array(
				'entity'     => 'people',
				'label'      => __( 'People', 'eventos' ),
				'module'     => $this->slug(),
				'capability' => Crm_Capabilities::MANAGE_CRM,
				'icon'       => 'user',
				'searchable' => array( 'display_name', 'primary_email', 'primary_phone' ),
				'query'      => static function ( array $args ) use ( $person_service ): array {
					$term = trim( (string) ( $args['term'] ?? '' ) );

					if ( '' === $term ) {
						return array( 'items' => array(), 'total' => 0 );
					}

					$result = $person_service->search(
						array(
							'q'        => $term,
							'page'     => (int) ( $args['page'] ?? 1 ),
							'per_page' => (int) ( $args['per_page'] ?? 20 ),
						)
					);

					return array(
						'items' => array_map(
							static function ( array $person ): array {
								return array(
									'id'       => $person['person_id'],
									'title'    => (string) $person['display_name'],
									'subtitle' => (string) ( $person['primary_email'] ?: $person['primary_phone'] ),
									'url'      => admin_url( 'admin.php?page=eventos-crm-people&person=' . $person['person_id'] ),
								);
							},
							$result['items']
						),
						'total' => $result['total'],
					);
				},
			)
		);
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

	/**
	 * Register CRM People as an exportable entity.
	 *
	 * Deliberately omits internal identifiers a spreadsheet consumer has no
	 * use for (no wc_customer_id, no raw identity rows) and exposes the
	 * marketing-consent state as a derived, human-readable status rather than
	 * dumping the full grant/revoke history — the authoritative history stays
	 * on the CRM consent screen; this is a snapshot for backup/analysis.
	 *
	 * @return void
	 */
	public function register_exports(): void {
		$persons = new Person_Repository();
		$consent = new Person_Consent_Repository();

		Export_Registry::register(
			array(
				'entity'     => 'people',
				'label'      => __( 'CRM People', 'eventos' ),
				'module'     => $this->slug(),
				'capability' => Capabilities::RUN_EXPORTS,
				'filename'   => 'eventos-people',
				'columns'    => array(
					'id'                      => __( 'ID', 'eventos' ),
					'display_name'            => __( 'Name', 'eventos' ),
					'first_name'              => __( 'First name', 'eventos' ),
					'last_name'               => __( 'Last name', 'eventos' ),
					'primary_email'           => __( 'Email', 'eventos' ),
					'primary_phone'           => __( 'Phone', 'eventos' ),
					'location'                => __( 'Location', 'eventos' ),
					'marketing_consent'       => __( 'Marketing consent', 'eventos' ),
					'total_events_attended'   => __( 'Events attended', 'eventos' ),
					'total_tickets_purchased' => __( 'Tickets purchased', 'eventos' ),
					'total_spend'             => __( 'Lifetime spend', 'eventos' ),
					'last_purchase_at'        => __( 'Last purchase', 'eventos' ),
					'created_at'              => __( 'Known since', 'eventos' ),
				),
				'provider'   => static function ( array $args ) use ( $persons, $consent ): array {
					unset( $args );

					return array_map(
						static function ( array $person ) use ( $consent ): array {
							$id = (int) $person['id'];

							if ( $consent->has_active( $id, 'email' ) ) {
								$status = 'granted';
							} elseif ( $consent->was_ever_granted( $id, 'email' ) ) {
								$status = 'revoked';
							} else {
								$status = 'unknown';
							}

							$person['marketing_consent'] = $status;

							return $person;
						},
						$persons->query()
					);
				},
			)
		);
	}

	/**
	 * Register CRM People as an importable entity.
	 *
	 * The writer resolves an existing Person by e-mail through the same
	 * {@see Person_Resolver} the live ticket-purchase pipeline already uses
	 * — an imported row is never a second identity-resolution mechanism, it
	 * just feeds the one that already exists. Consent is the one field this
	 * writer treats specially: it only ever GRANTS, and only when the Person
	 * has never had any consent record for the channel before — an import
	 * can never silently re-grant someone who explicitly unsubscribed
	 * (`was_ever_granted()` would already be true for them), and it can
	 * never downgrade someone with an existing active grant either (nothing
	 * to do in that case). See Person_Consent_Repository's "history, not
	 * state" model — this writer adds history, it never rewrites it.
	 *
	 * @return void
	 */
	public function register_import_targets(): void {
		$persons    = new Person_Repository();
		$identities = new Person_Identity_Repository();
		$resolver   = new Person_Resolver( $persons, $identities, new Person_Timeline_Service() );
		$consent    = new Person_Consent_Repository();

		Import_Registry::register_target(
			array(
				'entity'     => 'people',
				'label'      => __( 'CRM People', 'eventos' ),
				'module'     => $this->slug(),
				'capability' => Capabilities::RUN_IMPORTS,
				'fields'     => array(
					'email'       => array( 'label' => __( 'Email', 'eventos' ), 'required' => true, 'type' => 'email', 'aliases' => array( 'primary_email', 'e-mail' ) ),
					'first_name'  => array( 'label' => __( 'First name', 'eventos' ), 'aliases' => array( 'firstname', 'given_name' ) ),
					'last_name'   => array( 'label' => __( 'Last name', 'eventos' ), 'aliases' => array( 'lastname', 'surname', 'family_name' ) ),
					'name'        => array( 'label' => __( 'Full name', 'eventos' ), 'aliases' => array( 'display_name', 'full_name' ) ),
					'phone'       => array( 'label' => __( 'Phone', 'eventos' ), 'aliases' => array( 'primary_phone', 'mobile' ) ),
					'location'    => array( 'label' => __( 'Location', 'eventos' ), 'aliases' => array( 'city' ) ),
					'consent'     => array( 'label' => __( 'Marketing consent (yes/no)', 'eventos' ), 'aliases' => array( 'marketing_consent', 'opt_in' ) ),
					'source'      => array( 'label' => __( 'Source', 'eventos' ), 'aliases' => array( 'origin' ) ),
				),
				'writer'     => static function ( array $record ) use ( $resolver, $consent ) {
					$email = sanitize_email( (string) ( $record['email'] ?? '' ) );

					if ( '' === $email || ! is_email( $email ) ) {
						return new WP_Error( 'eventos_import_invalid_email', __( 'A valid email is required to import a person.', 'eventos' ) );
					}

					$name = trim( (string) ( $record['name'] ?? '' ) );

					if ( '' === $name ) {
						$name = trim( (string) ( $record['first_name'] ?? '' ) . ' ' . (string) ( $record['last_name'] ?? '' ) );
					}

					$result = $resolver->find_or_create(
						array(
							'email'     => $email,
							'name'      => $name,
							'phone'     => (string) ( $record['phone'] ?? '' ),
							'source'    => '' !== trim( (string) ( $record['source'] ?? '' ) ) ? (string) $record['source'] : 'import',
							'source_id' => '',
						)
					);

					$person_id = (int) $result['person']['id'];

					$wants_consent = in_array(
						strtolower( trim( (string) ( $record['consent'] ?? '' ) ) ),
						array( 'yes', 'true', '1', 'granted', 'opted_in', 'opt_in' ),
						true
					);

					if ( $wants_consent && ! $consent->was_ever_granted( $person_id, 'email' ) ) {
						$consent->grant( $person_id, 'email', 'import' );
					}

					return $person_id;
				},
				// Preview-only: lets a dry-run report "new" vs "existing"
				// without writing anything, mirroring the same e-mail lookup
				// the real writer uses — see Abstract_Import_Provider::import()'s
				// optional 'classifier' support. No deleter is registered
				// (see the writer's docblock above): rollback cannot tell
				// "created" apart from "updated an existing Person" from the
				// writer's return value alone, and deleting could destroy a
				// pre-existing CRM contact.
				'classifier' => static function ( array $record ) use ( $persons ): string {
					$email = sanitize_email( (string) ( $record['email'] ?? '' ) );

					return '' !== $email && null !== $persons->find_by_primary_email( $email ) ? 'existing' : 'new';
				},
			)
		);
	}
}

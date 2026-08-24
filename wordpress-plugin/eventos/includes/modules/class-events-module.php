<?php
/**
 * Events module registration.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Modules;

use EventOS\Abstract_Module;
use EventOS\Capabilities;
use EventOS\Events\Campaign_Repository;
use EventOS\Events\Checkin_Repository;
use EventOS\Events\Event_Capabilities;
use EventOS\Events\Event_Controller;
use EventOS\Events\Event_Report_Builder;
use EventOS\Events\Event_Identity_Repository;
use EventOS\Events\Event_Identity_Resolver;
use EventOS\Events\Event_Schema;
use EventOS\Events\Event_Service;
use EventOS\Events\Event_Status;
use EventOS\Events\Guest_Repository;
use EventOS\Events\Ticket_Identity_Repository;
use EventOS\Events\Ticket_Identity_Resolver;
use EventOS\Events\Ticket_Type_Identity_Repository;
use EventOS\Events\Ticket_Type_Identity_Resolver;
use EventOS\Events\Marketing_Service;
use EventOS\Events\Promo_Link_Repository;
use EventOS\Events\Ticket_Display;
use EventOS\Events\Ticket_Fulfillment;
use EventOS\Events\Ticket_Order_Resolver;
use EventOS\Events\Ticket_Repository;
use EventOS\Events\Ticket_Type_Repository;
use EventOS\Events\Ticketing_Service;
use EventOS\Events\Waitlist_Repository;
use EventOS\Events\Waitlist_Service;
use EventOS\Crm\Person_Consent_Repository;
use EventOS\Crm\Person_Identity_Repository;
use EventOS\Crm\Person_Repository;
use EventOS\Crm\Person_Resolver;
use EventOS\Crm\Person_Timeline_Service;
use EventOS\Crm\Segment_Repository;
use EventOS\Export\Export_Registry;
use EventOS\Import\Import_Registry;
use EventOS\Marketing\Audience_Repository;
use EventOS\Marketing\Audience_Resolver;
use EventOS\Marketing\Campaign_Message_Repository;
use EventOS\Marketing\Campaign_Recipient_Repository;
use EventOS\Marketing\Campaign_Send_Service;
use EventOS\Marketing\Marketing_Capabilities;
use EventOS\Marketing\Marketing_Mail_Service;
use EventOS\Marketing\Marketing_Schema;
use EventOS\Marketing\Personalization_Renderer;
use EventOS\Rest\Marketing_Controller;
use EventOS\Rest\Rest_Registry;
use EventOS\Rest\Ticketing_Controller;
use EventOS\Search_Registry;
use EventOS\Settings;
use EventOS\WooCommerce;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the Events module into every piece of EventOS infrastructure.
 */
final class Events_Module extends Abstract_Module {

	/**
	 * Service layer.
	 *
	 * @var Event_Service|null
	 */
	private ?Event_Service $service = null;

	/**
	 * Ticketing service layer.
	 *
	 * @var Ticketing_Service|null
	 */
	private ?Ticketing_Service $ticketing_service = null;

	/**
	 * Marketing service layer.
	 *
	 * @var Marketing_Service|null
	 */
	private ?Marketing_Service $marketing_service = null;

	/**
	 * Campaign message/send orchestration layer.
	 *
	 * @var Campaign_Send_Service|null
	 */
	private ?Campaign_Send_Service $campaign_send_service = null;

	/**
	 * Waitlist orchestration layer.
	 *
	 * @var Waitlist_Service|null
	 */
	private ?Waitlist_Service $waitlist_service = null;

	/**
	 * Module slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'events';
	}

	/**
	 * Module name.
	 *
	 * @return string
	 */
	public function name(): string {
		return __( 'Events', 'eventos' );
	}

	/**
	 * Module description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Events, venues, artists, schedules and the event taxonomy.', 'eventos' );
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
	 * Service accessor.
	 *
	 * @return Event_Service
	 */
	public function service(): Event_Service {
		if ( null === $this->service ) {
			$this->service = new Event_Service();
		}

		return $this->service;
	}

	/**
	 * Ticketing service accessor.
	 *
	 * @return Ticketing_Service
	 */
	public function ticketing_service(): Ticketing_Service {
		if ( null === $this->ticketing_service ) {
			$ticket_types = new Ticket_Type_Repository();
			$tickets      = new Ticket_Repository();
			$order_resolver = new Ticket_Order_Resolver( $ticket_types );

			$this->ticketing_service = new Ticketing_Service(
				$ticket_types,
				$tickets,
				new Guest_Repository(),
				new Checkin_Repository(),
				$order_resolver,
				new Event_Report_Builder( $ticket_types, $tickets, $order_resolver )
			);
		}

		return $this->ticketing_service;
	}

	/**
	 * Waitlist service accessor.
	 *
	 * @return Waitlist_Service
	 */
	public function waitlist_service(): Waitlist_Service {
		if ( null === $this->waitlist_service ) {
			$this->waitlist_service = new Waitlist_Service(
				new Waitlist_Repository(),
				$this->ticketing_service()->ticket_types(),
				new Ticket_Repository()
			);
		}

		return $this->waitlist_service;
	}

	/**
	 * Marketing service accessor.
	 *
	 * @return Marketing_Service
	 */
	public function marketing_service(): Marketing_Service {
		if ( null === $this->marketing_service ) {
			$this->marketing_service = new Marketing_Service(
				new Campaign_Repository( $this->ticketing_service()->ticket_types() ),
				new Promo_Link_Repository(),
				new Audience_Repository(),
				new Audience_Resolver( new Person_Repository(), new Person_Identity_Repository(), new Segment_Repository() )
			);
		}

		return $this->marketing_service;
	}

	/**
	 * Campaign message/send service accessor.
	 *
	 * @return Campaign_Send_Service
	 */
	public function campaign_send_service(): Campaign_Send_Service {
		if ( null === $this->campaign_send_service ) {
			$this->campaign_send_service = new Campaign_Send_Service(
				new Campaign_Repository( $this->ticketing_service()->ticket_types() ),
				new Campaign_Message_Repository(),
				new Campaign_Recipient_Repository(),
				new Audience_Repository(),
				new Audience_Resolver( new Person_Repository(), new Person_Identity_Repository(), new Segment_Repository() ),
				new Person_Repository(),
				new Person_Identity_Repository(),
				new Person_Consent_Repository(),
				new Marketing_Mail_Service(),
				new Personalization_Renderer()
			);
		}

		return $this->campaign_send_service;
	}

	/**
	 * Capabilities and grants contributed by the module.
	 *
	 * @return array<string, mixed>
	 */
	public function permissions(): array {
		return array(
			'capabilities' => array_merge( Event_Capabilities::definitions(), Marketing_Capabilities::definitions() ),
			'grants'       => array_merge_recursive( Event_Capabilities::grants(), Marketing_Capabilities::grants() ),
		);
	}

	/**
	 * Admin screens contributed by the module.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function menu_items(): array {
		return array(
			array(
				'slug'       => 'eventos-events-list',
				'title'      => __( 'My Events', 'eventos' ),
				'view'       => 'events/list',
				'capability' => Event_Capabilities::VIEW_EVENTS,
			),
			array(
				'slug'       => 'eventos-events-calendar',
				'title'      => __( 'Calendar', 'eventos' ),
				'view'       => 'events/calendar',
				'capability' => Event_Capabilities::VIEW_EVENTS,
			),
			array(
				'slug'       => 'eventos-venues',
				'title'      => __( 'Venues', 'eventos' ),
				'view'       => 'events/venues',
				'capability' => Event_Capabilities::VIEW_EVENTS,
			),
			array(
				'slug'       => 'eventos-artists',
				'title'      => __( 'Artists', 'eventos' ),
				'view'       => 'events/artists',
				'capability' => Event_Capabilities::VIEW_EVENTS,
			),
			array(
				'slug'       => 'eventos-event-terms',
				'title'      => __( 'Categories & Tags', 'eventos' ),
				'view'       => 'events/terms',
				'capability' => Event_Capabilities::VIEW_EVENTS,
			),
		);
	}

	/**
	 * Settings contributed by the module.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function settings(): array {
		return array(
			'events' => array(
				'label'       => __( 'Events', 'eventos' ),
				'description' => __( 'Defaults applied to every new event on this installation.', 'eventos' ),
				'fields'      => array(
					'default_status'            => Settings::define_field(
						__( 'Default status', 'eventos' ),
						'select',
						Event_Status::DRAFT,
						Event_Status::all()
					),
					'default_visibility'        => Settings::define_field(
						__( 'Default visibility', 'eventos' ),
						'select',
						'public',
						array_keys( Event_Status::visibilities() )
					),
					'default_ticket_visibility' => Settings::define_field(
						__( 'Default ticket visibility', 'eventos' ),
						'select',
						'public',
						array_keys( Event_Status::ticket_visibilities() )
					),
					'default_capacity'          => Settings::define_field( __( 'Default capacity', 'eventos' ), 'number', 0 ),
					'default_age_restriction'   => Settings::define_field( __( 'Default age restriction', 'eventos' ), 'text', '' ),
					'default_doors_offset'      => Settings::define_field( __( 'Doors open minutes before start', 'eventos' ), 'number', 60 ),
					'archive_after_days'        => Settings::define_field( __( 'Suggest archiving events after (days)', 'eventos' ), 'number', 90 ),
				),
			),
		);
	}

	/**
	 * Install the module's tables on first activation.
	 *
	 * @return void
	 */
	public function activate(): void {
		Event_Schema::install();
		Marketing_Schema::install();
	}

	/**
	 * Keep the schema current on upgrades.
	 *
	 * @param string $from_version Previously installed version.
	 * @return void
	 */
	public function upgrade( string $from_version ): void {
		unset( $from_version );

		Event_Schema::install();
		Marketing_Schema::install();
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		Event_Schema::maybe_install();
		Marketing_Schema::maybe_install();

		add_action( 'eventos_register_rest_endpoints', array( $this, 'register_rest_endpoints' ) );
		add_action( 'eventos_register_search_entities', array( $this, 'register_search_entities' ) );
		add_action( 'eventos_register_exports', array( $this, 'register_exports' ) );
		add_action( 'eventos_register_import_providers', array( $this, 'register_import_targets' ) );
		add_filter( 'eventos_admin_pages', array( $this, 'register_admin_pages' ) );

		( new Ticket_Fulfillment( new Ticket_Type_Repository(), new Ticket_Repository(), new Guest_Repository(), $this->waitlist_service() ) )->bootstrap();
		( new Ticket_Display( new Ticket_Repository(), new Ticket_Type_Repository() ) )->bootstrap();

		// Registered directly here, not via the `eventos_register_jobs`
		// hook: that hook fires from inside Core_Module::init(), which has
		// already run by the time Events_Module (dependent on 'core')
		// reaches its own init() — see Person_Backfill_Service's docblock
		// for the identical gotcha with Crm_Module.
		$this->campaign_send_service()->register_job_handler();
		$this->waitlist_service()->register_job_handlers();
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
	 * Declare the module's REST endpoints.
	 *
	 * @return void
	 */
	public function register_rest_endpoints(): void {
		$controller = new Event_Controller( $this->service() );

		Rest_Registry::register_many( $controller->endpoints(), $this->slug() );

		$ticketing = new Ticketing_Controller( $this->ticketing_service(), $this->waitlist_service() );

		Rest_Registry::register_many( $ticketing->endpoints(), $this->slug() );

		$marketing = new Marketing_Controller( $this->marketing_service(), $this->campaign_send_service() );

		Rest_Registry::register_many( $marketing->endpoints(), $this->slug() );
	}

	/**
	 * Register searchable entities.
	 *
	 * @return void
	 */
	public function register_search_entities(): void {
		$service = $this->service();

		Search_Registry::register_many(
			array(
				array(
					'entity'        => 'events',
					'label'         => __( 'Events', 'eventos' ),
					'module'        => $this->slug(),
					'capability'    => Event_Capabilities::VIEW_EVENTS,
					'icon'          => 'calendar',
					'searchable'    => array( 'title', 'subtitle', 'short_description', 'slug' ),
					'filterable'    => array( 'status', 'visibility', 'venue_id', 'category_id', 'tag_id', 'artist_id', 'from', 'to' ),
					'sortable'      => array( 'title', 'starts_at', 'created_at', 'updated_at', 'status', 'capacity' ),
					'default_sort'  => 'starts_at',
					'default_order' => 'desc',
					'query'         => static function ( array $args ) use ( $service ): array {
						$filters = (array) ( $args['filters'] ?? array() );
						$result  = $service->events()->query(
							array_merge(
								$filters,
								array(
									'search'   => (string) ( $args['term'] ?? '' ),
									'orderby'  => (string) ( $args['orderby'] ?? 'starts_at' ),
									'order'    => (string) ( $args['order'] ?? 'desc' ),
									'page'     => (int) ( $args['page'] ?? 1 ),
									'per_page' => (int) ( $args['per_page'] ?? 20 ),
								)
							)
						);

						return array(
							'items' => array_map(
								static function ( array $event ): array {
									return array(
										'id'       => $event['id'],
										'title'    => $event['title'],
										'subtitle' => $event['venue_name'] ?: $event['subtitle'],
										'status'   => $event['status'],
										'url'      => admin_url( 'admin.php?page=eventos-events-list&event=' . $event['id'] ),
										'meta'     => array( 'starts_at' => $event['starts_at'] ),
									);
								},
								$result['items']
							),
							'total' => $result['total'],
						);
					},
				),
				array(
					'entity'     => 'venues',
					'label'      => __( 'Venues', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Event_Capabilities::VIEW_EVENTS,
					'icon'       => 'location',
					'searchable' => array( 'name', 'city', 'address_line1' ),
					'filterable' => array( 'city', 'country' ),
					'sortable'   => array( 'name', 'city', 'capacity', 'created_at' ),
					'query'      => static function ( array $args ) use ( $service ): array {
						$result = $service->venues()->query(
							array_merge(
								(array) ( $args['filters'] ?? array() ),
								array(
									'search'   => (string) ( $args['term'] ?? '' ),
									'orderby'  => (string) ( $args['orderby'] ?? 'name' ),
									'order'    => (string) ( $args['order'] ?? 'asc' ),
									'page'     => (int) ( $args['page'] ?? 1 ),
									'per_page' => (int) ( $args['per_page'] ?? 20 ),
								)
							)
						);

						return array(
							'items' => array_map(
								static function ( array $venue ): array {
									return array(
										'id'       => $venue['id'],
										'title'    => $venue['name'],
										'subtitle' => trim( $venue['city'] . ' ' . $venue['country'] ),
										'url'      => admin_url( 'admin.php?page=eventos-venues&venue=' . $venue['id'] ),
									);
								},
								$result['items']
							),
							'total' => $result['total'],
						);
					},
				),
				array(
					'entity'     => 'artists',
					'label'      => __( 'Artists', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Event_Capabilities::VIEW_EVENTS,
					'icon'       => 'microphone',
					'searchable' => array( 'name', 'biography', 'genres' ),
					'filterable' => array( 'genre', 'country' ),
					'sortable'   => array( 'name', 'created_at' ),
					'query'      => static function ( array $args ) use ( $service ): array {
						$result = $service->artists()->query(
							array_merge(
								(array) ( $args['filters'] ?? array() ),
								array(
									'search'   => (string) ( $args['term'] ?? '' ),
									'orderby'  => (string) ( $args['orderby'] ?? 'name' ),
									'order'    => (string) ( $args['order'] ?? 'asc' ),
									'page'     => (int) ( $args['page'] ?? 1 ),
									'per_page' => (int) ( $args['per_page'] ?? 20 ),
								)
							)
						);

						return array(
							'items' => array_map(
								static function ( array $artist ): array {
									return array(
										'id'       => $artist['id'],
										'title'    => $artist['name'],
										'subtitle' => implode( ', ', (array) $artist['genres'] ),
										'url'      => admin_url( 'admin.php?page=eventos-artists&artist=' . $artist['id'] ),
									);
								},
								$result['items']
							),
							'total' => $result['total'],
						);
					},
				),
				array(
					'entity'     => 'guests',
					'label'      => __( 'Guests', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Event_Capabilities::VIEW_EVENTS,
					'icon'       => 'ticket',
					'searchable' => array( 'name', 'email', 'ticket_number' ),
					'query'      => static function ( array $args ): array {
						$result = ( new Guest_Repository() )->search_all(
							array(
								'term'     => (string) ( $args['term'] ?? '' ),
								'page'     => (int) ( $args['page'] ?? 1 ),
								'per_page' => (int) ( $args['per_page'] ?? 20 ),
							)
						);

						return array(
							'items' => array_map(
								static function ( array $guest ): array {
									// The event name disambiguates same-named
									// guests attending different events — a
									// bare name alone would be ambiguous.
									return array(
										'id'       => $guest['id'],
										'title'    => (string) $guest['name'],
										'subtitle' => trim( (string) $guest['event_title'] . ' · ' . (string) $guest['ticket_type_name'] ),
										'status'   => $guest['status'],
										'url'      => admin_url( 'admin.php?page=eventos-events-list&event=' . $guest['event_id'] . '&tab=guests' ),
									);
								},
								$result['items']
							),
							'total' => $result['total'],
						);
					},
				),
				array(
					'entity'     => 'tickets',
					'label'      => __( 'Tickets', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Event_Capabilities::VIEW_EVENTS,
					'icon'       => 'ticket',
					'searchable' => array( 'ticket_number', 'guest_name', 'guest_email' ),
					'query'      => static function ( array $args ): array {
						$result = ( new Ticket_Repository() )->search_all(
							array(
								'term'     => (string) ( $args['term'] ?? '' ),
								'page'     => (int) ( $args['page'] ?? 1 ),
								'per_page' => (int) ( $args['per_page'] ?? 20 ),
							)
						);

						return array(
							'items' => array_map(
								static function ( array $ticket ): array {
									$attendee = '' !== $ticket['guest_name'] ? (string) $ticket['guest_name'] : __( 'Unassigned', 'eventos' );

									return array(
										'id'       => $ticket['id'],
										'title'    => (string) $ticket['ticket_number'],
										'subtitle' => trim( (string) $ticket['event_title'] . ' · ' . (string) $ticket['ticket_type_name'] . ' · ' . $attendee ),
										'status'   => $ticket['status'],
										'url'      => admin_url( 'admin.php?page=eventos-events-list&event=' . $ticket['event_id'] . '&tab=ticketing' ),
									);
								},
								$result['items']
							),
							'total' => $result['total'],
						);
					},
				),
				array(
					'entity'     => 'orders',
					'label'      => __( 'Orders', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Capabilities::VIEW_DASHBOARD,
					'icon'       => 'receipt',
					'searchable' => array( 'order_number', 'billing_name', 'billing_email' ),
					'query'      => static function ( array $args ): array {
						$term = trim( (string) ( $args['term'] ?? '' ) );

						if ( '' === $term || ! WooCommerce::is_active() ) {
							return array( 'items' => array(), 'total' => 0 );
						}

						$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
						$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

						$result = wc_get_orders(
							array(
								's'        => $term,
								'limit'    => $per_page,
								'page'     => $page,
								'paginate' => true,
								'orderby'  => 'date',
								'order'    => 'DESC',
								'return'   => 'objects',
							)
						);

						$order_ids = array_map(
							static function ( $order ): int {
								return $order->get_id();
							},
							$result->orders
						);

						// One batched lookup for every order's EventOS event,
						// rather than a query per result row.
						$events_by_order = array();

						if ( $order_ids ) {
							global $wpdb;

							$tickets_table = Event_Schema::tickets();
							$events_table  = Event_Schema::events();
							$placeholders  = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
							$rows = $wpdb->get_results(
								$wpdb->prepare(
									"SELECT DISTINCT t.wc_order_id, t.event_id, e.title FROM {$tickets_table} t INNER JOIN {$events_table} e ON e.id = t.event_id WHERE t.wc_order_id IN ({$placeholders})",
									$order_ids
								),
								ARRAY_A
							);

							foreach ( (array) $rows as $row ) {
								$events_by_order[ (int) $row['wc_order_id'] ] = array(
									'event_id' => (int) $row['event_id'],
									'title'    => (string) $row['title'],
								);
							}
						}

						return array(
							'items' => array_map(
								static function ( $order ) use ( $events_by_order ): array {
									$order_id = $order->get_id();
									$name     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
									$event    = $events_by_order[ $order_id ] ?? null;

									return array(
										'id'       => $order_id,
										'title'    => '#' . $order->get_order_number(),
										'subtitle' => trim( $name . ( null !== $event ? ' · ' . $event['title'] : '' ) ),
										'status'   => $order->get_status(),
										'url'      => null !== $event
											? admin_url( 'admin.php?page=eventos-events-list&event=' . $event['event_id'] . '&tab=orders' )
											: admin_url( 'admin.php?page=wc-orders' ),
									);
								},
								$result->orders
							),
							'total' => (int) $result->total,
						);
					},
				),
				array(
					'entity'     => 'campaigns',
					'label'      => __( 'Campaigns', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Event_Capabilities::VIEW_EVENTS,
					'icon'       => 'megaphone',
					'searchable' => array( 'name', 'code' ),
					'query'      => function ( array $args ): array {
						$campaigns = new Campaign_Repository( $this->ticketing_service()->ticket_types() );
						$result    = $campaigns->search_all(
							array(
								'term'     => (string) ( $args['term'] ?? '' ),
								'page'     => (int) ( $args['page'] ?? 1 ),
								'per_page' => (int) ( $args['per_page'] ?? 20 ),
							)
						);

						return array(
							'items' => array_map(
								static function ( array $campaign ): array {
									return array(
										'id'       => $campaign['id'],
										'title'    => (string) $campaign['name'],
										'subtitle' => trim( (string) $campaign['event_title'] . ' · ' . (string) $campaign['code'] ),
										'status'   => $campaign['status'],
										'url'      => admin_url( 'admin.php?page=eventos-events-list&event=' . $campaign['event_id'] . '&tab=marketing' ),
									);
								},
								$result['items']
							),
							'total' => $result['total'],
						);
					},
				),
			)
		);
	}

	/**
	 * Register exportable entities.
	 *
	 * @return void
	 */
	public function register_exports(): void {
		$service = $this->service();

		Export_Registry::register_many(
			array(
				array(
					'entity'     => 'events',
					'label'      => __( 'Events', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Capabilities::RUN_EXPORTS,
					'filename'   => 'eventos-events',
					'columns'    => array(
						'id'                => __( 'ID', 'eventos' ),
						'title'             => __( 'Title', 'eventos' ),
						'subtitle'          => __( 'Subtitle', 'eventos' ),
						'slug'              => __( 'Slug', 'eventos' ),
						'status'            => __( 'Status', 'eventos' ),
						'visibility'        => __( 'Visibility', 'eventos' ),
						'venue_name'        => __( 'Venue', 'eventos' ),
						'timezone'          => __( 'Timezone', 'eventos' ),
						'starts_at'         => __( 'Starts', 'eventos' ),
						'ends_at'           => __( 'Ends', 'eventos' ),
						'doors_open_at'     => __( 'Doors open', 'eventos' ),
						'capacity'          => __( 'Capacity', 'eventos' ),
						'age_restriction'   => __( 'Age restriction', 'eventos' ),
						'short_description' => __( 'Short description', 'eventos' ),
					),
					'provider'   => static function ( array $args ) use ( $service ): array {
						$result = $service->events()->query( array_merge( $args, array( 'per_page' => 200 ) ) );

						return $result['items'];
					},
				),
				array(
					'entity'     => 'venues',
					'label'      => __( 'Venues', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Capabilities::RUN_EXPORTS,
					'filename'   => 'eventos-venues',
					'columns'    => array(
						'id'            => __( 'ID', 'eventos' ),
						'name'          => __( 'Name', 'eventos' ),
						'address_line1' => __( 'Address', 'eventos' ),
						'city'          => __( 'City', 'eventos' ),
						'province'      => __( 'Province', 'eventos' ),
						'postal_code'   => __( 'Postal code', 'eventos' ),
						'country'       => __( 'Country', 'eventos' ),
						'capacity'      => __( 'Capacity', 'eventos' ),
					),
					'provider'   => static function ( array $args ) use ( $service ): array {
						$result = $service->venues()->query( array_merge( $args, array( 'per_page' => 200 ) ) );

						return $result['items'];
					},
				),
				array(
					'entity'     => 'artists',
					'label'      => __( 'Artists', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Capabilities::RUN_EXPORTS,
					'filename'   => 'eventos-artists',
					'columns'    => array(
						'id'      => __( 'ID', 'eventos' ),
						'name'    => __( 'Name', 'eventos' ),
						'country' => __( 'Country', 'eventos' ),
						'website' => __( 'Website', 'eventos' ),
					),
					'provider'   => static function ( array $args ) use ( $service ): array {
						$result = $service->artists()->query( array_merge( $args, array( 'per_page' => 200 ) ) );

						return $result['items'];
					},
				),
				array(
					'entity'     => 'event_orders',
					'label'      => __( 'Event orders', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Capabilities::RUN_EXPORTS,
					'filename'   => 'eventos-event-orders',
					'columns'    => array(
						'wc_order_id'    => __( 'Order', 'eventos' ),
						'customer_name'  => __( 'Customer', 'eventos' ),
						'customer_email' => __( 'Email', 'eventos' ),
						'ticket_count'   => __( 'Tickets', 'eventos' ),
						'total'          => __( 'Total', 'eventos' ),
						'currency'       => __( 'Currency', 'eventos' ),
						'status'         => __( 'Status', 'eventos' ),
						'payment_method' => __( 'Payment', 'eventos' ),
						'created_at'     => __( 'Date', 'eventos' ),
					),
					'provider'   => function ( array $args ): array {
						$event_id = (int) ( $args['event_id'] ?? 0 );

						if ( $event_id <= 0 ) {
							return array();
						}

						return $this->ticketing_service()->event_orders( $event_id, array( 'per_page' => 1000 ) )['items'];
					},
				),
				array(
					'entity'     => 'event_report',
					'label'      => __( 'Event report', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Capabilities::RUN_EXPORTS,
					'filename'   => 'eventos-event-report',
					'columns'    => array(
						'name'     => __( 'Ticket type', 'eventos' ),
						'tier'     => __( 'Tier', 'eventos' ),
						'sold'     => __( 'Sold', 'eventos' ),
						'capacity' => __( 'Capacity', 'eventos' ),
						'gross'    => __( 'Gross revenue', 'eventos' ),
						'net'      => __( 'Net revenue', 'eventos' ),
					),
					'provider'   => function ( array $args ): array {
						$event_id = (int) ( $args['event_id'] ?? 0 );

						if ( $event_id <= 0 ) {
							return array();
						}

						return $this->ticketing_service()->report( $event_id )['revenue_by_ticket_type'];
					},
				),
				array(
					'entity'     => 'tickets',
					'label'      => __( 'Tickets', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Capabilities::RUN_EXPORTS,
					'filename'   => 'eventos-tickets',
					'columns'    => array(
						'id'                => __( 'ID', 'eventos' ),
						'ticket_number'     => __( 'Ticket number', 'eventos' ),
						'event_title'       => __( 'Event', 'eventos' ),
						'ticket_type_name'  => __( 'Ticket type', 'eventos' ),
						'guest_name'        => __( 'Attendee', 'eventos' ),
						'guest_email'       => __( 'Attendee email', 'eventos' ),
						'status'            => __( 'Status', 'eventos' ),
						'is_complimentary'  => __( 'Complimentary', 'eventos' ),
						'checked_in'        => __( 'Checked in', 'eventos' ),
						'checked_in_at'     => __( 'Checked in at', 'eventos' ),
						'wc_order_id'       => __( 'Order', 'eventos' ),
						'created_at'        => __( 'Issued', 'eventos' ),
					),
					// event_id filters to one event; omitted/0 exports every
					// ticket in the install (Ticket_Repository::query() treats
					// event_id=0 as "no filter" — see that method's docblock).
					'provider'   => static function ( array $args ): array {
						return ( new Ticket_Repository() )->query( array( 'event_id' => (int) ( $args['event_id'] ?? 0 ) ) );
					},
				),
				array(
					'entity'     => 'orders',
					'label'      => __( 'Orders', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Capabilities::RUN_EXPORTS,
					'filename'   => 'eventos-orders',
					'columns'    => array(
						'wc_order_id'    => __( 'Order', 'eventos' ),
						'event_title'    => __( 'Event', 'eventos' ),
						'customer_name'  => __( 'Customer', 'eventos' ),
						'customer_email' => __( 'Email', 'eventos' ),
						'ticket_count'   => __( 'Tickets', 'eventos' ),
						'total'          => __( 'Total', 'eventos' ),
						'currency'       => __( 'Currency', 'eventos' ),
						'status'         => __( 'Status', 'eventos' ),
						'payment_method' => __( 'Payment', 'eventos' ),
						'refund_total'   => __( 'Refunded', 'eventos' ),
						'created_at'     => __( 'Date', 'eventos' ),
					),
					// Reuses Ticketing_Service::event_orders() — the exact same
					// WooCommerce-order-reading path the per-event Orders tab
					// and the existing 'event_orders' export already use — for
					// every EventOS event, rather than duplicating order
					// storage/lookup. Store-wide unless event_id narrows it.
					'provider'   => function ( array $args ): array {
						$event_id = (int) ( $args['event_id'] ?? 0 );
						$events   = $event_id > 0
							? array( array( 'id' => $event_id, 'title' => (string) ( $this->service()->events()->find( $event_id )['title'] ?? '' ) ) )
							: $this->service()->events()->query( array( 'per_page' => 1000 ) )['items'];

						$rows = array();

						foreach ( $events as $event ) {
							$orders = $this->ticketing_service()->event_orders( (int) $event['id'], array( 'per_page' => 1000 ) )['items'];

							foreach ( $orders as $order ) {
								$order['event_title']  = (string) $event['title'];
								$order['refund_total'] = array_sum( array_column( (array) ( $order['refunds'] ?? array() ), 'amount' ) );
								unset( $order['refunds'] );
								$rows[] = $order;
							}
						}

						return $rows;
					},
				),
				array(
					'entity'     => 'guests',
					'label'      => __( 'Guests', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Capabilities::RUN_EXPORTS,
					'filename'   => 'eventos-guests',
					'columns'    => array(
						'id'               => __( 'ID', 'eventos' ),
						'event_id'         => __( 'Event ID', 'eventos' ),
						'name'             => __( 'Name', 'eventos' ),
						'email'            => __( 'Email', 'eventos' ),
						'phone'            => __( 'Phone', 'eventos' ),
						'ticket_number'    => __( 'Ticket number', 'eventos' ),
						'ticket_type_name' => __( 'Ticket type', 'eventos' ),
						'status'           => __( 'Status', 'eventos' ),
						'checked_in'       => __( 'Checked in', 'eventos' ),
						'checked_in_at'    => __( 'Checked in at', 'eventos' ),
						'tags'             => __( 'Tags', 'eventos' ),
					),
					'provider'   => function ( array $args ): array {
						$event_id  = (int) ( $args['event_id'] ?? 0 );
						$event_ids = $event_id > 0
							? array( $event_id )
							: array_column( $this->service()->events()->query( array( 'per_page' => 1000 ) )['items'], 'id' );

						$guests = new Guest_Repository();
						$rows   = array();

						foreach ( $event_ids as $id ) {
							foreach ( $guests->query( (int) $id, array( 'per_page' => 1000 ) )['items'] as $row ) {
								unset( $row['notes'], $row['attendance_history'] );
								$rows[] = $row;
							}
						}

						return $rows;
					},
				),
				array(
					'entity'     => 'audiences',
					'label'      => __( 'Marketing audiences', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Capabilities::RUN_EXPORTS,
					'filename'   => 'eventos-audiences',
					'columns'    => array(
						'id'             => __( 'ID', 'eventos' ),
						'name'           => __( 'Name', 'eventos' ),
						'description'    => __( 'Description', 'eventos' ),
						'type'           => __( 'Type', 'eventos' ),
						'scope'          => __( 'Scope', 'eventos' ),
						'status'         => __( 'Status', 'eventos' ),
						'criteria'       => __( 'Criteria', 'eventos' ),
						'estimated_size' => __( 'Estimated size', 'eventos' ),
						'created_at'     => __( 'Created', 'eventos' ),
					),
					// A live, resolved count at export time — never a stored
					// membership list — per Audience_Resolver's own "the rule,
					// not the result" design (see its class docblock).
					'provider'   => function ( array $args ): array {
						unset( $args );

						$audiences = new Audience_Repository();
						$resolver  = new Audience_Resolver( new Person_Repository(), new Person_Identity_Repository(), new Segment_Repository() );
						$events    = array_column( $this->service()->events()->query( array( 'per_page' => 1000 ) )['items'], 'title', 'id' );

						return array_map(
							static function ( array $audience ) use ( $resolver, $events ): array {
								$audience['scope']          = null !== $audience['event_id'] ? ( $events[ $audience['event_id'] ] ?? '' ) : __( 'Global', 'eventos' );
								$audience['criteria']       = wp_json_encode( $audience['criteria'] );
								$audience['estimated_size'] = $resolver->count( $audience );

								return $audience;
							},
							$audiences->all( array( 'include_archived' => true ) )
						);
					},
				),
				array(
					'entity'     => 'campaigns',
					'label'      => __( 'Marketing campaigns', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Capabilities::RUN_EXPORTS,
					'filename'   => 'eventos-campaigns',
					'columns'    => array(
						'id'                 => __( 'ID', 'eventos' ),
						'event_title'        => __( 'Event', 'eventos' ),
						'name'               => __( 'Name', 'eventos' ),
						'code'               => __( 'Discount code', 'eventos' ),
						'type'               => __( 'Discount type', 'eventos' ),
						'value'              => __( 'Discount value', 'eventos' ),
						'status'             => __( 'Status', 'eventos' ),
						'audience_name'      => __( 'Audience', 'eventos' ),
						'uses'               => __( 'Uses', 'eventos' ),
						'expires_at'         => __( 'Expires', 'eventos' ),
						'message_subject'    => __( 'Message subject', 'eventos' ),
						'message_status'     => __( 'Message status', 'eventos' ),
						'recipients_sent'    => __( 'Recipients sent', 'eventos' ),
						'recipients_pending' => __( 'Recipients pending', 'eventos' ),
						'recipients_failed'  => __( 'Recipients failed', 'eventos' ),
						'created_at'         => __( 'Created', 'eventos' ),
					),
					// Deliberately excludes the recipient-by-recipient snapshot
					// (real e-mail addresses, per-row delivery status) — only
					// aggregate counts. That level of detail stays behind the
					// same capability the Marketing tab's own recipient view
					// already requires; exporting it in bulk here would be a
					// meaningfully bigger PII exposure than this build intends.
					'provider'   => function ( array $args ): array {
						$event_id = (int) ( $args['event_id'] ?? 0 );
						$events   = $event_id > 0
							? array( array( 'id' => $event_id, 'title' => (string) ( $this->service()->events()->find( $event_id )['title'] ?? '' ) ) )
							: $this->service()->events()->query( array( 'per_page' => 1000 ) )['items'];

						$campaigns  = new Campaign_Repository( $this->ticketing_service()->ticket_types() );
						$messages   = new Campaign_Message_Repository();
						$recipients = new Campaign_Recipient_Repository();
						$audiences  = new Audience_Repository();
						$rows       = array();

						foreach ( $events as $event ) {
							foreach ( $campaigns->for_event( (int) $event['id'] ) as $campaign ) {
								$campaign['event_title']   = (string) $event['title'];
								$audience                   = null !== $campaign['audience_id'] ? $audiences->find( (int) $campaign['audience_id'] ) : null;
								$campaign['audience_name'] = $audience['name'] ?? '';

								$message                          = $messages->for_campaign( (int) $campaign['id'] );
								$campaign['message_subject']      = $message['subject'] ?? '';
								$campaign['message_status']       = $message['status'] ?? '';

								$counts                              = $recipients->counts( (int) $campaign['id'] );
								$campaign['recipients_sent']        = $counts['sent'];
								$campaign['recipients_pending']     = $counts['pending'];
								$campaign['recipients_failed']      = $counts['failed'];

								unset( $campaign['ticket_type_ids'] );
								$rows[] = $campaign;
							}
						}

						return $rows;
					},
				),
				array(
					'entity'     => 'campaign_messages',
					'label'      => __( 'Campaign messages', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Capabilities::RUN_EXPORTS,
					'filename'   => 'eventos-campaign-messages',
					'columns'    => array(
						'id'            => __( 'ID', 'eventos' ),
						'campaign_id'   => __( 'Campaign ID', 'eventos' ),
						'campaign_name' => __( 'Campaign', 'eventos' ),
						'subject'       => __( 'Subject', 'eventos' ),
						'preview_text'  => __( 'Preview text', 'eventos' ),
						'sender_name'   => __( 'Sender name', 'eventos' ),
						'sender_email'  => __( 'Sender email', 'eventos' ),
						'reply_to'      => __( 'Reply-to', 'eventos' ),
						'body_html'     => __( 'HTML body', 'eventos' ),
						'body_text'     => __( 'Plain-text body', 'eventos' ),
						'status'        => __( 'Status', 'eventos' ),
						'created_at'    => __( 'Created', 'eventos' ),
					),
					'provider'   => function ( array $args ): array {
						$event_id = (int) ( $args['event_id'] ?? 0 );
						$events   = $event_id > 0
							? array( $event_id )
							: array_column( $this->service()->events()->query( array( 'per_page' => 1000 ) )['items'], 'id' );

						$campaigns = new Campaign_Repository( $this->ticketing_service()->ticket_types() );
						$messages  = new Campaign_Message_Repository();
						$rows      = array();

						foreach ( $events as $id ) {
							foreach ( $campaigns->for_event( (int) $id ) as $campaign ) {
								$message = $messages->for_campaign( (int) $campaign['id'] );

								if ( null === $message ) {
									continue;
								}

								$message['campaign_name'] = (string) $campaign['name'];
								$rows[]                    = $message;
							}
						}

						return $rows;
					},
				),
			)
		);
	}

	/**
	 * Register import targets so any provider can write events data.
	 *
	 * @return void
	 */
	public function register_import_targets(): void {
		$service        = $this->service();
		$event_resolver = new Event_Identity_Resolver( $service, new Event_Identity_Repository() );

		Import_Registry::register_target(
			array(
				'entity'     => 'events',
				'label'      => __( 'Events', 'eventos' ),
				'module'     => $this->slug(),
				'capability' => Capabilities::RUN_IMPORTS,
				'fields'     => array(
					'title'             => array( 'label' => __( 'Title', 'eventos' ), 'required' => true, 'aliases' => array( 'name', 'event', 'event_name' ) ),
					'subtitle'          => array( 'label' => __( 'Subtitle', 'eventos' ), 'aliases' => array( 'tagline' ) ),
					'short_description' => array( 'label' => __( 'Short description', 'eventos' ), 'aliases' => array( 'summary', 'excerpt' ) ),
					'description'       => array( 'label' => __( 'Description', 'eventos' ), 'aliases' => array( 'content', 'details' ) ),
					'starts_at'         => array( 'label' => __( 'Starts', 'eventos' ), 'type' => 'string', 'aliases' => array( 'start', 'start_date', 'date' ) ),
					'ends_at'           => array( 'label' => __( 'Ends', 'eventos' ), 'type' => 'string', 'aliases' => array( 'end', 'end_date' ) ),
					'doors_open_at'     => array( 'label' => __( 'Doors open', 'eventos' ), 'type' => 'string', 'aliases' => array( 'doors' ) ),
					'timezone'          => array( 'label' => __( 'Timezone', 'eventos' ), 'aliases' => array( 'tz' ) ),
					'capacity'          => array( 'label' => __( 'Capacity', 'eventos' ), 'type' => 'integer', 'aliases' => array( 'max_attendees' ) ),
					'age_restriction'   => array( 'label' => __( 'Age restriction', 'eventos' ), 'aliases' => array( 'age' ) ),
					'venue_name'        => array( 'label' => __( 'Venue', 'eventos' ), 'aliases' => array( 'location', 'venue' ) ),
					'status'            => array( 'label' => __( 'Status', 'eventos' ), 'aliases' => array( 'state' ) ),
					'source'            => array( 'label' => __( 'Source', 'eventos' ), 'aliases' => array( 'provider', 'platform' ) ),
					'source_id'         => array( 'label' => __( 'Source event ID', 'eventos' ), 'aliases' => array( 'external_id', 'event_id', 'source_event_id' ) ),
				),
				'writer'     => static function ( array $record ) use ( $service, $event_resolver ) {
					$venue_name = trim( (string) ( $record['venue_name'] ?? '' ) );
					$source     = trim( (string) ( $record['source'] ?? '' ) );
					$source_id  = trim( (string) ( $record['source_id'] ?? '' ) );

					unset( $record['venue_name'], $record['source'], $record['source_id'] );

					if ( '' !== $venue_name ) {
						$existing = $service->venues()->query( array( 'search' => $venue_name, 'per_page' => 1 ) );

						if ( ! empty( $existing['items'] ) ) {
							$record['venue_id'] = (int) $existing['items'][0]['id'];
						} else {
							$venue = $service->create_venue( array( 'name' => $venue_name ) );

							if ( ! is_wp_error( $venue ) ) {
								$record['venue_id'] = (int) $venue['id'];
							}
						}
					}

					// A source identity lets re-importing the same external
					// event resolve to the Event already created for it
					// instead of creating a duplicate. Without one (a plain
					// CSV row with no source id), there is nothing to
					// dedupe against, so this falls through to a plain
					// create — unchanged from before.
					if ( '' !== $source && '' !== $source_id ) {
						$resolved = $event_resolver->resolve_or_create( $source, $source_id, $record );

						return is_wp_error( $resolved ) ? $resolved : (int) $resolved['event']['id'];
					}

					$created = $service->create_event( $record );

					return is_wp_error( $created ) ? $created : (int) $created['id'];
				},
				'deleter'    => static function ( int $id ) use ( $service ) {
					return $service->delete_event( $id );
				},
			)
		);

		Import_Registry::register_target(
			array(
				'entity'     => 'venues',
				'label'      => __( 'Venues', 'eventos' ),
				'module'     => $this->slug(),
				'capability' => Capabilities::RUN_IMPORTS,
				'fields'     => array(
					'name'          => array( 'label' => __( 'Name', 'eventos' ), 'required' => true, 'aliases' => array( 'venue', 'location' ) ),
					'address_line1' => array( 'label' => __( 'Address', 'eventos' ), 'aliases' => array( 'address', 'street' ) ),
					'city'          => array( 'label' => __( 'City', 'eventos' ), 'aliases' => array( 'town' ) ),
					'province'      => array( 'label' => __( 'Province', 'eventos' ), 'aliases' => array( 'state', 'region' ) ),
					'postal_code'   => array( 'label' => __( 'Postal code', 'eventos' ), 'aliases' => array( 'zip', 'postcode' ) ),
					'country'       => array( 'label' => __( 'Country', 'eventos' ), 'aliases' => array( 'country_code' ) ),
					'capacity'      => array( 'label' => __( 'Capacity', 'eventos' ), 'type' => 'integer' ),
				),
				'writer'     => static function ( array $record ) use ( $service ) {
					$created = $service->create_venue( $record );

					return is_wp_error( $created ) ? $created : (int) $created['id'];
				},
				'deleter'    => static function ( int $id ) use ( $service ) {
					return $service->delete_venue( $id );
				},
			)
		);

		Import_Registry::register_target(
			array(
				'entity'     => 'artists',
				'label'      => __( 'Artists', 'eventos' ),
				'module'     => $this->slug(),
				'capability' => Capabilities::RUN_IMPORTS,
				'fields'     => array(
					'name'      => array( 'label' => __( 'Name', 'eventos' ), 'required' => true, 'aliases' => array( 'artist', 'performer' ) ),
					'biography' => array( 'label' => __( 'Biography', 'eventos' ), 'aliases' => array( 'bio', 'about' ) ),
					'website'   => array( 'label' => __( 'Website', 'eventos' ), 'aliases' => array( 'url' ) ),
					'country'   => array( 'label' => __( 'Country', 'eventos' ), 'aliases' => array( 'country_code' ) ),
				),
				'writer'     => static function ( array $record ) use ( $service ) {
					$created = $service->create_artist( $record );

					return is_wp_error( $created ) ? $created : (int) $created['id'];
				},
				'deleter'    => static function ( int $id ) use ( $service ) {
					return $service->delete_artist( $id );
				},
			)
		);

		Import_Registry::register_target(
			array(
				'entity'     => 'guests',
				'label'      => __( 'Guests', 'eventos' ),
				'module'     => $this->slug(),
				'capability' => Capabilities::RUN_IMPORTS,
				'fields'     => array(
					'ticket_number' => array( 'label' => __( 'Ticket number', 'eventos' ), 'required' => true, 'aliases' => array( 'ticket', 'ticket_no', 'ticket_code' ) ),
					'name'          => array( 'label' => __( 'Name', 'eventos' ), 'aliases' => array( 'guest_name', 'full_name' ) ),
					'email'         => array( 'label' => __( 'Email', 'eventos' ), 'type' => 'email', 'aliases' => array( 'guest_email' ) ),
					'phone'         => array( 'label' => __( 'Phone', 'eventos' ), 'aliases' => array( 'mobile' ) ),
				),
				// A guest can only ever be imported onto an EXISTING ticket —
				// this importer never creates a ticket (see the Tickets export
				// target's docblock for why ticket import is deliberately not
				// offered). If that ticket already has a guest, this only
				// updates contact fields on the existing guest (never blanking
				// a value the row left empty); if the ticket has none yet, a
				// new guest is created and attached — the relationship is
				// always resolved through a real ticket, so a guest import can
				// never orphan a person or a ticket.
				'writer'     => static function ( array $record ) {
					$tickets = new Ticket_Repository();
					$guests  = new Guest_Repository();

					$ticket_number = trim( (string) ( $record['ticket_number'] ?? '' ) );
					$ticket        = '' !== $ticket_number ? $tickets->find_by_code( $ticket_number ) : null;

					if ( null === $ticket ) {
						return new WP_Error( 'eventos_import_ticket_not_found', __( 'No ticket matches this ticket number — a guest cannot be imported without an existing ticket.', 'eventos' ) );
					}

					$name  = trim( (string) ( $record['name'] ?? '' ) );
					$email = sanitize_email( (string) ( $record['email'] ?? '' ) );
					$phone = trim( (string) ( $record['phone'] ?? '' ) );

					if ( (int) $ticket['guest_id'] > 0 ) {
						$updates = array();

						if ( '' !== $name ) {
							$updates['name'] = $name;
						}

						if ( '' !== $email ) {
							$updates['email'] = $email;
						}

						if ( '' !== $phone ) {
							$updates['phone'] = $phone;
						}

						if ( $updates ) {
							$guests->update( (int) $ticket['guest_id'], $updates );
						}

						return (int) $ticket['guest_id'];
					}

					$guest = $guests->create(
						array(
							'event_id'       => (int) $ticket['event_id'],
							'ticket_id'      => (int) $ticket['id'],
							'wc_customer_id' => (int) $ticket['wc_customer_id'],
							'name'           => $name,
							'email'          => $email,
							'phone'          => $phone,
						)
					);

					$tickets->set_guest( (int) $ticket['id'], (int) $guest['id'] );

					return (int) $guest['id'];
				},
				// No deleter: the writer's return value is the same guest ID
				// whether it just created a brand-new guest or only updated an
				// already-existing one, and rollback cannot tell those two
				// cases apart — deleting could destroy a guest record that
				// existed before the import ran. Safer to report rollback as
				// incomplete for these rows than to risk that.
			)
		);

		Import_Registry::register_target(
			array(
				'entity'     => 'ticket_types',
				'label'      => __( 'Ticket types', 'eventos' ),
				'module'     => $this->slug(),
				'capability' => Capabilities::RUN_IMPORTS,
				'fields'     => array(
					'event_source'    => array( 'label' => __( 'Event source', 'eventos' ), 'required' => true, 'aliases' => array( 'provider', 'platform' ) ),
					'event_source_id' => array( 'label' => __( 'Source event ID', 'eventos' ), 'required' => true, 'aliases' => array( 'event_id', 'source_event_id' ) ),
					'source'          => array( 'label' => __( 'Source', 'eventos' ) ),
					'source_id'       => array( 'label' => __( 'Source ticket-type ID', 'eventos' ), 'required' => true, 'aliases' => array( 'external_id', 'ticket_type_id' ) ),
					'name'            => array( 'label' => __( 'Name', 'eventos' ), 'required' => true, 'aliases' => array( 'ticket_type', 'tier_name' ) ),
					'price'           => array( 'label' => __( 'Price', 'eventos' ), 'type' => 'number', 'aliases' => array( 'cost' ) ),
					'capacity'        => array( 'label' => __( 'Capacity', 'eventos' ), 'type' => 'integer' ),
					'status'          => array( 'label' => __( 'Status', 'eventos' ) ),
				),
				// Every provider's "did we already import this?" question is
				// answered the same way at every stage of this cascade: an
				// external identity, resolved through the same
				// find-or-create shape {@see Event_Identity_Resolver} uses
				// for events. A ticket type always belongs to an
				// already-imported event, so the event's own identity is
				// looked up first (never created here) and its row's
				// `event_id` is what the ticket type attaches to.
				'writer'     => static function ( array $record ) {
					$event_source    = trim( (string) ( $record['event_source'] ?? '' ) );
					$event_source_id = trim( (string) ( $record['event_source_id'] ?? '' ) );
					$source          = trim( (string) ( $record['source'] ?? $event_source ) );
					$source_id       = trim( (string) ( $record['source_id'] ?? '' ) );

					if ( '' === $event_source || '' === $event_source_id ) {
						return new WP_Error( 'eventos_import_event_not_found', __( 'A source event is required to import a ticket type.', 'eventos' ) );
					}

					$event_identity = ( new Event_Identity_Repository() )->find_by_type_value( $event_source, $event_source_id );

					if ( null === $event_identity ) {
						return new WP_Error( 'eventos_import_event_not_found', __( 'No event matches this source event ID — import the event first.', 'eventos' ) );
					}

					if ( '' === $source_id ) {
						return new WP_Error( 'eventos_import_missing_field', __( 'A source ticket-type ID is required.', 'eventos' ) );
					}

					$resolver = new Ticket_Type_Identity_Resolver( new Ticket_Type_Repository(), new Ticket_Type_Identity_Repository() );

					$status = trim( (string) ( $record['status'] ?? '' ) );

					$resolved = $resolver->resolve_or_create(
						$source,
						$source_id,
						(int) $event_identity['event_id'],
						array_filter(
							array(
								'name'     => (string) ( $record['name'] ?? '' ),
								'price'    => (float) ( $record['price'] ?? 0 ),
								'capacity' => array_key_exists( 'capacity', $record ) && '' !== (string) $record['capacity'] ? (int) $record['capacity'] : null,
								'status'   => '' !== $status ? $status : null,
							),
							static fn( $value ): bool => null !== $value
						)
					);

					return is_wp_error( $resolved ) ? $resolved : (int) $resolved['ticket_type']['id'];
				},
			)
		);

		Import_Registry::register_target(
			array(
				'entity'     => 'tickets',
				'label'      => __( 'Tickets', 'eventos' ),
				'module'     => $this->slug(),
				'capability' => Capabilities::RUN_IMPORTS,
				'fields'     => array(
						// event_source/event_source_id/ticket_type_source_id/
						// source_id are only conditionally required — see
						// wc_order_id below — so their presence is validated
						// in the writer itself (both branches already did
						// this inline before wc_order_id existed) rather than
						// declaratively here.
					'event_source'          => array( 'label' => __( 'Event source', 'eventos' ) ),
					'event_source_id'       => array( 'label' => __( 'Source event ID', 'eventos' ) ),
					'ticket_type_source'    => array( 'label' => __( 'Ticket type source', 'eventos' ) ),
					'ticket_type_source_id' => array( 'label' => __( 'Source ticket-type ID', 'eventos' ) ),
					'source'                => array( 'label' => __( 'Source', 'eventos' ) ),
					'source_id'             => array( 'label' => __( 'Source ticket ID', 'eventos' ) ),
					// An alternative to event_source*/ticket_type_source*/
					// source*: when a row already has a real WooCommerce
					// order behind it, the ticket already exists (created by
					// live checkout or the WC ticket-fulfilment backfill,
					// correctly attached to whichever variation was actually
					// purchased) — this row only needs to reconcile
					// attendance onto that existing ticket, not create a new
					// one. wc_order_occurrence disambiguates an order with
					// more than one ticket (1-based, ordered by ticket id).
					'wc_order_id'           => array( 'label' => __( 'WooCommerce order ID', 'eventos' ), 'type' => 'number' ),
					'wc_order_occurrence'   => array( 'label' => __( 'Position within the order', 'eventos' ), 'type' => 'number' ),
					'name'                  => array( 'label' => __( 'Attendee name', 'eventos' ), 'aliases' => array( 'guest_name', 'attendee' ) ),
					'email'                 => array( 'label' => __( 'Attendee email', 'eventos' ), 'type' => 'email', 'aliases' => array( 'guest_email' ) ),
					'phone'                 => array( 'label' => __( 'Attendee phone', 'eventos' ) ),
					'status'                => array( 'label' => __( 'Ticket status', 'eventos' ) ),
					'is_complimentary'      => array( 'label' => __( 'Complimentary', 'eventos' ), 'aliases' => array( 'complimentary', 'comp', 'is_comp', 'guestlist' ) ),
					'checked_in'            => array( 'label' => __( 'Checked in', 'eventos' ), 'aliases' => array( 'check_in', 'checked-in', 'attendance', 'scanned', 'scan_status' ) ),
					'checked_in_at'         => array( 'label' => __( 'Checked-in at', 'eventos' ), 'aliases' => array( 'check_in_time', 'scan_time' ) ),
					'price'                 => array( 'label' => __( 'Price paid', 'eventos' ), 'type' => 'number' ),
					'discount'              => array( 'label' => __( 'Discount', 'eventos' ), 'type' => 'number' ),
					'fee'                   => array( 'label' => __( 'Fee', 'eventos' ), 'type' => 'number' ),
					'refunded_amount'       => array( 'label' => __( 'Refunded amount', 'eventos' ), 'type' => 'number' ),
				),
				// A generic ticketing-platform "attendee" row: resolves its
				// Event and Ticket Type by their own already-imported
				// identities (never creates either here — import events,
				// then ticket_types, then tickets, in that order), resolves
				// or creates the Person through the exact same CRM identity
				// path every other purchaser-resolution call site uses
				// ({@see \EventOS\Crm\Person_Resolver::find_or_create()}),
				// then issues the Ticket and its Guest. EventOS has no
				// Order entity anywhere — WC_Order is the only "order" in
				// this codebase — so an external order reference has
				// nowhere to persist and is intentionally not stored;
				// idempotency comes entirely from the ticket's own source
				// identity via {@see Ticket_Identity_Resolver}.
				'writer'     => static function ( array $record ) {
					$wc_order_id = (int) ( $record['wc_order_id'] ?? 0 );

					if ( $wc_order_id > 0 ) {
						return self::reconcile_wc_order_ticket( $record, $wc_order_id );
					}

					$event_source    = trim( (string) ( $record['event_source'] ?? '' ) );
					$event_source_id = trim( (string) ( $record['event_source_id'] ?? '' ) );

					if ( '' === $event_source || '' === $event_source_id ) {
						return new WP_Error( 'eventos_import_event_not_found', __( 'A source event is required to import a ticket.', 'eventos' ) );
					}

					$event_identity = ( new Event_Identity_Repository() )->find_by_type_value( $event_source, $event_source_id );

					if ( null === $event_identity ) {
						return new WP_Error( 'eventos_import_event_not_found', __( 'No event matches this source event ID — import the event first.', 'eventos' ) );
					}

					$ticket_type_source    = trim( (string) ( $record['ticket_type_source'] ?? $event_source ) );
					$ticket_type_source_id = trim( (string) ( $record['ticket_type_source_id'] ?? '' ) );

					if ( '' === $ticket_type_source_id ) {
						return new WP_Error( 'eventos_import_missing_field', __( 'A source ticket-type ID is required.', 'eventos' ) );
					}

					$ticket_type_identity = ( new Ticket_Type_Identity_Repository() )->find_by_type_value( $ticket_type_source, $ticket_type_source_id );

					// Ticket types provisioned from WooCommerce (see
					// Wc_Event_Provisioning::resolve_ticket_type()) never get
					// a row in the generic ticket_type_identities table —
					// unlike Events, that provisioning path only ever sets
					// the wc_product_id *column* directly on ticket_types,
					// matching how Ticket_Fulfillment::find_ticket_type_by_product()
					// already looks a WC-sourced ticket type up. Without this
					// fallback, a 'wc_product_id' source could never resolve
					// an existing WC-linked ticket type through this import
					// target at all — only ones a prior CSV import created.
					if ( null === $ticket_type_identity && 'wc_product_id' === $ticket_type_source ) {
						$wc_ticket_type = ( new Ticket_Type_Repository() )->find_by_wc_product_id( (int) $ticket_type_source_id );

						if ( null !== $wc_ticket_type ) {
							$ticket_type_identity = array( 'ticket_type_id' => $wc_ticket_type['id'] );
						}
					}

					if ( null === $ticket_type_identity ) {
						return new WP_Error( 'eventos_import_ticket_type_not_found', __( 'No ticket type matches this source ID — import ticket types first.', 'eventos' ) );
					}

					$source    = trim( (string) ( $record['source'] ?? $event_source ) );
					$source_id = trim( (string) ( $record['source_id'] ?? '' ) );

					if ( '' === $source_id ) {
						return new WP_Error( 'eventos_import_missing_field', __( 'A source ticket ID is required.', 'eventos' ) );
					}

					$name  = trim( (string) ( $record['name'] ?? '' ) );
					$email = sanitize_email( (string) ( $record['email'] ?? '' ) );
					$phone = trim( (string) ( $record['phone'] ?? '' ) );

					// Same "accept a resolved bool from a transform, or a raw
					// recognised word if the mapping applied none" tolerance
					// checked_in already uses below — only ever applied at
					// creation, matching how the WC live-fulfilment path also
					// only ever sets this once, at issue() time.
					$is_complimentary_raw = $record['is_complimentary'] ?? '';
					$is_complimentary     = is_bool( $is_complimentary_raw )
						? $is_complimentary_raw
						: in_array( strtolower( trim( (string) $is_complimentary_raw ) ), array( 'yes', 'true', '1', 'complimentary', 'comp', 'guestlist' ), true );

					$person_resolver = new Person_Resolver( new Person_Repository(), new Person_Identity_Repository(), new Person_Timeline_Service() );

					$person_resolver->find_or_create(
						array(
							'email'     => $email,
							'name'      => $name,
							'phone'     => $phone,
							'source'    => $source,
							'source_id' => $source_id,
						)
					);

					$ticket_resolver = new Ticket_Identity_Resolver( new Ticket_Repository(), new Ticket_Identity_Repository() );

					$resolved = $ticket_resolver->resolve_or_create(
						$source,
						$source_id,
						array(
							'event_id'         => (int) $event_identity['event_id'],
							'ticket_type_id'   => (int) $ticket_type_identity['ticket_type_id'],
							'wc_customer_id'   => 0,
							'is_complimentary' => $is_complimentary,
						)
					);

					if ( is_wp_error( $resolved ) ) {
						return $resolved;
					}

					$ticket  = $resolved['ticket'];
					$tickets = new Ticket_Repository();

					if ( $resolved['created'] ) {
						$guests = new Guest_Repository();

						$guest = $guests->create(
							array(
								'event_id'       => (int) $event_identity['event_id'],
								'ticket_id'      => (int) $ticket['id'],
								'wc_customer_id' => 0,
								'name'           => $name,
								'email'          => $email,
								'phone'          => $phone,
							)
						);

						$tickets->set_guest( (int) $ticket['id'], (int) $guest['id'] );
					}

					// Applied on every resolution, not only on first
					// creation — a re-imported export reflecting a status
					// the source recorded later (e.g. a since-refunded
					// ticket) should update the existing Ticket rather than
					// only ever setting status once.
					$status = trim( (string) ( $record['status'] ?? '' ) );

					if ( '' !== $status && $status !== $ticket['status'] ) {
						$tickets->set_status( (int) $ticket['id'], $status );
					}

					$warning = self::reconcile_attendance( $ticket, $record, $tickets );

					// Financial reconciliation. Unlike attendance, these
					// fields have no competing live writer, so they are set
					// (overwritten) from the source on every import — the
					// same source row simply replaces its own prior values
					// rather than accumulating a second record. Only applied
					// when the row actually carries at least one financial
					// field, so an attendee-only import never wipes
					// financial data set by a previous, richer import.
					$price_raw           = trim( (string) ( $record['price'] ?? '' ) );
					$discount_raw        = trim( (string) ( $record['discount'] ?? '' ) );
					$fee_raw             = trim( (string) ( $record['fee'] ?? '' ) );
					$refunded_amount_raw = trim( (string) ( $record['refunded_amount'] ?? '' ) );

					if ( '' !== $price_raw || '' !== $discount_raw || '' !== $fee_raw || '' !== $refunded_amount_raw ) {
						// The 'money' transform accepts a leading '-' (a
						// parenthesized/negative accounting convention some
						// exports use), but every one of these fields is a
						// magnitude in EventOS's own model — refunds are
						// already always non-negative elsewhere (see
						// Finance_Report_Builder), and a negative price/
						// discount/fee has no meaning here. Normalize the
						// sign away rather than let a source's negative
						// convention silently invert a P&L figure.
						$tickets->set_financials(
							(int) $ticket['id'],
							'' !== $price_raw ? abs( (float) $price_raw ) : null,
							'' !== $discount_raw ? abs( (float) $discount_raw ) : null,
							'' !== $fee_raw ? abs( (float) $fee_raw ) : null,
							'' !== $refunded_amount_raw ? abs( (float) $refunded_amount_raw ) : null
						);
					}

					return null !== $warning
						? array(
							'id'      => (int) $ticket['id'],
							'warning' => $warning,
						)
						: (int) $ticket['id'];
				},
			)
		);

		Import_Registry::register_target(
			array(
				'entity'     => 'audiences',
				'label'      => __( 'Marketing audiences', 'eventos' ),
				'module'     => $this->slug(),
				'capability' => Capabilities::RUN_IMPORTS,
				'fields'     => array(
					'name'           => array( 'label' => __( 'Name', 'eventos' ), 'required' => true ),
					'description'    => array( 'label' => __( 'Description', 'eventos' ) ),
					'type'           => array( 'label' => __( 'Type', 'eventos' ), 'required' => true ),
					'event_slug'     => array( 'label' => __( 'Event slug', 'eventos' ), 'aliases' => array( 'event' ) ),
					'ticket_type_id' => array( 'label' => __( 'Ticket type ID', 'eventos' ), 'type' => 'number' ),
					'min_spend'      => array( 'label' => __( 'Minimum spend', 'eventos' ), 'type' => 'number' ),
					'days'           => array( 'label' => __( 'Days', 'eventos' ), 'type' => 'number' ),
					'segment_id'     => array( 'label' => __( 'CRM segment ID', 'eventos' ), 'type' => 'number' ),
				),
				// Only ever creates an audience *definition* — the rule, never
				// a resolved people list — so this can never duplicate a CRM
				// Person the way importing an actual membership list would.
				// An event-scoped type requires a real, existing event
				// (resolved by slug, never by trusting a raw imported ID); a
				// 'segment' type requires a real, existing local segment —
				// otherwise the row is rejected rather than silently creating
				// an audience that would resolve to nothing or error later.
				'writer'     => function ( array $record ) {
					$type = trim( (string) ( $record['type'] ?? '' ) );

					if ( ! in_array( $type, Audience_Repository::TYPES, true ) ) {
						return new WP_Error(
							'eventos_import_invalid_audience_type',
							/* translators: %s: audience type. */
							sprintf( __( '"%s" is not a valid audience type.', 'eventos' ), $type )
						);
					}

					$event_id = null;

					if ( in_array( $type, array( 'event_purchasers', 'event_ticket_type', 'event_attendees', 'event_non_attendees' ), true ) ) {
						$slug  = trim( (string) ( $record['event_slug'] ?? '' ) );
						$event = '' !== $slug ? $this->service()->events()->find_by_slug( $slug ) : null;

						if ( null === $event ) {
							return new WP_Error( 'eventos_import_event_not_found', __( 'An existing event_slug is required for this audience type.', 'eventos' ) );
						}

						$event_id = (int) $event['id'];
					}

					$criteria = array();

					if ( 'event_ticket_type' === $type ) {
						$criteria['ticket_type_id'] = (int) ( $record['ticket_type_id'] ?? 0 );
					} elseif ( 'high_value' === $type ) {
						$criteria['min_spend'] = (float) ( $record['min_spend'] ?? 0 );
					} elseif ( in_array( $type, array( 'recent_purchasers', 'lapsed_customers' ), true ) ) {
						$criteria['days'] = (int) ( $record['days'] ?? 0 );
					} elseif ( 'segment' === $type ) {
						$segment_id = (int) ( $record['segment_id'] ?? 0 );

						if ( $segment_id <= 0 || null === ( new Segment_Repository() )->find( $segment_id ) ) {
							return new WP_Error( 'eventos_import_segment_not_found', __( 'An existing segment_id is required for a segment audience.', 'eventos' ) );
						}

						$criteria['segment_id'] = $segment_id;
					}

					$created = ( new Audience_Repository() )->create(
						array(
							'name'        => (string) ( $record['name'] ?? '' ),
							'description' => (string) ( $record['description'] ?? '' ),
							'type'        => $type,
							'event_id'    => $event_id,
							'criteria'    => $criteria,
						)
					);

					return is_wp_error( $created ) ? $created : (int) $created['id'];
				},
				'deleter'    => static function ( $id ) {
					// Audiences are always soft-deleted (archive) — never a
					// destructive removal — so this is safe to run on rollback
					// unconditionally, unlike the Guests/People targets above.
					return true === ( new Audience_Repository() )->archive( (int) $id );
				},
			)
		);

		Import_Registry::register_target(
			array(
				'entity'     => 'campaigns',
				'label'      => __( 'Marketing campaigns', 'eventos' ),
				'module'     => $this->slug(),
				'capability' => Capabilities::RUN_IMPORTS,
				'fields'     => array(
					'event_slug'           => array( 'label' => __( 'Event slug', 'eventos' ), 'required' => true, 'aliases' => array( 'event' ) ),
					'name'                 => array( 'label' => __( 'Name', 'eventos' ), 'required' => true ),
					'code'                 => array( 'label' => __( 'Discount code', 'eventos' ), 'required' => true ),
					'type'                 => array( 'label' => __( 'Discount type', 'eventos' ), 'aliases' => array( 'discount_type' ) ),
					'value'                => array( 'label' => __( 'Discount value', 'eventos' ), 'type' => 'number' ),
					'message_subject'      => array( 'label' => __( 'Message subject', 'eventos' ) ),
					'message_sender_name'  => array( 'label' => __( 'Message sender name', 'eventos' ) ),
					'message_sender_email' => array( 'label' => __( 'Message sender email', 'eventos' ), 'type' => 'email' ),
					'message_body_html'    => array( 'label' => __( 'Message HTML body', 'eventos' ) ),
				),
				// Every imported campaign starts 'draft' — Campaign_Repository
				// ::create()'s own default, and this writer never passes a
				// 'status' key, so an imported campaign's coupon is never live
				// at checkout until a human reviews and activates it. Same
				// principle for the optional inline message: saved (if
				// present) but never prepared or sent — Campaign_Message_
				// Repository::save() defaults a new message to 'draft' too,
				// and this writer never calls prepare()/send_now(). No
				// recipient snapshot rows are ever created by an import, so a
				// historical campaign can never generate pending sends purely
				// by being imported — that requires a deliberate, separate
				// "Prepare recipients" action from the Marketing tab.
				'writer'     => function ( array $record ) {
					$slug  = trim( (string) ( $record['event_slug'] ?? '' ) );
					$event = '' !== $slug ? $this->service()->events()->find_by_slug( $slug ) : null;

					if ( null === $event ) {
						return new WP_Error( 'eventos_import_event_not_found', __( 'An existing event_slug is required to import a campaign.', 'eventos' ) );
					}

					$campaigns = new Campaign_Repository( $this->ticketing_service()->ticket_types() );

					$created = $campaigns->create(
						(int) $event['id'],
						array(
							'name'  => (string) ( $record['name'] ?? '' ),
							'code'  => (string) ( $record['code'] ?? '' ),
							'type'  => 'fixed' === (string) ( $record['type'] ?? 'percent' ) ? 'fixed' : 'percent',
							'value' => (float) ( $record['value'] ?? 0 ),
						)
					);

					if ( is_wp_error( $created ) ) {
						return $created;
					}

					$campaign_id = (int) $created['id'];
					$subject     = trim( (string) ( $record['message_subject'] ?? '' ) );
					$body_html   = trim( (string) ( $record['message_body_html'] ?? '' ) );

					if ( '' !== $subject && '' !== $body_html ) {
						( new Campaign_Message_Repository() )->save(
							$campaign_id,
							array(
								'subject'      => $subject,
								'sender_name'  => (string) ( $record['message_sender_name'] ?? '' ),
								'sender_email' => (string) ( $record['message_sender_email'] ?? '' ),
								'body_html'    => $body_html,
							)
						);
					}

					return $campaign_id;
				},
				'deleter'    => function ( $id ) {
					// archive() only ever soft-archives the campaign and
					// unpublishes its WooCommerce coupon draft — never a
					// destructive delete — so it is safe on rollback.
					$result = ( new Campaign_Repository( $this->ticketing_service()->ticket_types() ) )->archive( (int) $id );

					return ! is_wp_error( $result );
				},
			)
		);
	}

	/**
	 * The 'tickets' import target writer's attendance-reconciliation step —
	 * shared between the normal (source-identity) path and
	 * {@see self::reconcile_wc_order_ticket()}'s WC-order-matched path, so
	 * the exact same Case A-I rules (first-write-wins on check-in, a source
	 * timestamp alone counts as evidence of attendance, an unrecognised
	 * value/timestamp warns rather than guesses) apply no matter which way
	 * the target ticket was found.
	 *
	 * @param array<string, mixed> $ticket  The resolved ticket row (before this call).
	 * @param array<string, mixed> $record  Mapped import row.
	 * @param Ticket_Repository    $tickets Ticket repository.
	 * @return string|null Non-fatal warning, or null.
	 */
	private static function reconcile_attendance( array $ticket, array $record, Ticket_Repository $tickets ): ?string {
		$warning = null;

		// The check-in timestamp is "first write wins" — once a ticket is
		// checked in (whether by this import or a live scanner event) it is
		// never re-mutated by a later import, so a stale re-run can never
		// silently erase or rewrite a real scan. A parsed timestamp on its
		// own is treated as authoritative evidence of attendance even when
		// the boolean column is blank or absent.
		$checked_in_raw    = $record['checked_in'] ?? '';
		$checked_in_at_raw = trim( (string) ( $record['checked_in_at'] ?? '' ) );
		$checked_in_at     = null;

		if ( '' !== $checked_in_at_raw ) {
			if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $checked_in_at_raw ) ) {
				$checked_in_at = $checked_in_at_raw;
			} else {
				$warning = __( 'Check-in timestamp could not be understood and was ignored.', 'eventos' );
			}
		}

		$source_checked_in = null;

		if ( is_bool( $checked_in_raw ) ) {
			$source_checked_in = $checked_in_raw;
		} elseif ( '' !== trim( (string) $checked_in_raw ) ) {
			$warning = ( null !== $warning ? $warning . ' ' : '' ) . __( 'Attendance value was not recognised and was ignored.', 'eventos' );
		}

		if ( null === $source_checked_in && null !== $checked_in_at ) {
			$source_checked_in = true;
		}

		if ( null !== $source_checked_in ) {
			if ( (bool) $ticket['checked_in'] ) {
				if ( false === $source_checked_in ) {
					$warning = ( null !== $warning ? $warning . ' ' : '' ) . __( 'Source reports this ticket as not checked in, but it was already checked in — the existing check-in was kept.', 'eventos' );
				}
			} elseif ( true === $source_checked_in ) {
				$tickets->set_checked_in( (int) $ticket['id'], true, $checked_in_at, 0 );
			}
		}

		return $warning;
	}

	/**
	 * The 'tickets' import target's alternative row shape: reconcile
	 * attendance onto a ticket that already exists because a real
	 * WooCommerce order already fulfilled it (live checkout, or the
	 * WooCommerce ticket-fulfilment backfill) — never creates anything,
	 * never touches identity/Person/Guest/financials, since all of that is
	 * already correct from the real order. Only ever reached when a row
	 * maps a `wc_order_id` — see the 'tickets' target registration above.
	 *
	 * @param array<string, mixed> $record      Mapped import row.
	 * @param int                  $wc_order_id WooCommerce order ID.
	 * @return int|array<string, mixed>|WP_Error
	 */
	private static function reconcile_wc_order_ticket( array $record, int $wc_order_id ) {
		$occurrence = max( 1, (int) ( $record['wc_order_occurrence'] ?? 1 ) );
		$tickets    = new Ticket_Repository();
		$order_tickets = $tickets->for_order( $wc_order_id );
		$ticket     = $order_tickets[ $occurrence - 1 ] ?? null;

		if ( null === $ticket ) {
			return new WP_Error(
				'eventos_import_wc_ticket_not_found',
				sprintf(
					/* translators: 1: WooCommerce order ID, 2: position within the order. */
					__( 'No EventOS ticket found for WooCommerce order #%1$d at position %2$d — run the WooCommerce ticket fulfilment sync first.', 'eventos' ),
					$wc_order_id,
					$occurrence
				)
			);
		}

		$warning = self::reconcile_attendance( $ticket, $record, $tickets );

		return null !== $warning
			? array(
				'id'      => (int) $ticket['id'],
				'warning' => $warning,
			)
			: (int) $ticket['id'];
	}
}

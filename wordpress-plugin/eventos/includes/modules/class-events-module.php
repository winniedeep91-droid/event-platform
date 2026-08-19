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
use EventOS\Events\Event_Schema;
use EventOS\Events\Event_Service;
use EventOS\Events\Event_Status;
use EventOS\Events\Guest_Repository;
use EventOS\Events\Marketing_Service;
use EventOS\Events\Promo_Link_Repository;
use EventOS\Events\Ticket_Fulfillment;
use EventOS\Events\Ticket_Order_Resolver;
use EventOS\Events\Ticket_Repository;
use EventOS\Events\Ticket_Type_Repository;
use EventOS\Events\Ticketing_Service;
use EventOS\Crm\Person_Identity_Repository;
use EventOS\Crm\Person_Repository;
use EventOS\Crm\Segment_Repository;
use EventOS\Export\Export_Registry;
use EventOS\Import\Import_Registry;
use EventOS\Marketing\Audience_Repository;
use EventOS\Marketing\Audience_Resolver;
use EventOS\Marketing\Marketing_Capabilities;
use EventOS\Marketing\Marketing_Schema;
use EventOS\Rest\Marketing_Controller;
use EventOS\Rest\Rest_Registry;
use EventOS\Rest\Ticketing_Controller;
use EventOS\Search_Registry;
use EventOS\Settings;

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

		Import_Registry::bootstrap();

		( new Ticket_Fulfillment( new Ticket_Type_Repository(), new Ticket_Repository(), new Guest_Repository() ) )->bootstrap();
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

		$ticketing = new Ticketing_Controller( $this->ticketing_service() );

		Rest_Registry::register_many( $ticketing->endpoints(), $this->slug() );

		$marketing = new Marketing_Controller( $this->marketing_service() );

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
			)
		);
	}

	/**
	 * Register import targets so any provider can write events data.
	 *
	 * @return void
	 */
	public function register_import_targets(): void {
		$service = $this->service();

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
				),
				'writer'     => static function ( array $record ) use ( $service ) {
					$venue_name = trim( (string) ( $record['venue_name'] ?? '' ) );

					unset( $record['venue_name'] );

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
	}
}

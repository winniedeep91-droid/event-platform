<?php
/**
 * Analytics / Event Insights module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Modules;

use EventOS\Abstract_Module;
use EventOS\Analytics\Event_Comparison_Builder;
use EventOS\Analytics\Event_Insights_Builder;
use EventOS\Crm\Person_Repository;
use EventOS\Events\Brand_Report_Builder;
use EventOS\Events\Event_Capabilities;
use EventOS\Events\Event_Repository;
use EventOS\Events\Ticket_Order_Resolver;
use EventOS\Events\Ticket_Repository;
use EventOS\Events\Ticket_Type_Repository;
use EventOS\Export\Export_Registry;
use EventOS\Finance\Expense_Repository;
use EventOS\Finance\Finance_Capabilities;
use EventOS\Finance\Finance_Report_Builder;
use EventOS\Rest\Analytics_Controller;
use EventOS\Rest\Rest_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires organisation-wide event comparison and per-event audience insights
 * into EventOS. Owns no data of its own — no schema, nothing persisted —
 * every figure is read live from the already-established Events, Finance
 * and CRM services (see {@see Event_Comparison_Builder}'s docblock for what
 * it reuses versus the one genuinely new metric it adds).
 */
final class Analytics_Module extends Abstract_Module {

	/**
	 * Event comparison builder.
	 *
	 * @var Event_Comparison_Builder|null
	 */
	private ?Event_Comparison_Builder $comparison = null;

	/**
	 * Event insights builder.
	 *
	 * @var Event_Insights_Builder|null
	 */
	private ?Event_Insights_Builder $insights = null;

	/**
	 * Module slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'analytics';
	}

	/**
	 * Module name.
	 *
	 * @return string
	 */
	public function name(): string {
		return __( 'Analytics', 'eventos' );
	}

	/**
	 * Module description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Organisation-wide event comparison and audience insights, built on the existing Reports, Finance and CRM services.', 'eventos' );
	}

	/**
	 * Module dependencies.
	 *
	 * @return string[]
	 */
	public function dependencies(): array {
		return array( 'core', 'events', 'woocommerce', 'crm', 'finance' );
	}

	/**
	 * Admin screens contributed by the module.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function menu_items(): array {
		return array(
			array(
				'slug'       => 'eventos-analytics',
				'title'      => __( 'Analytics', 'eventos' ),
				'view'       => 'analytics/overview',
				'capability' => Event_Capabilities::VIEW_EVENTS,
			),
		);
	}

	/**
	 * Add the module's screen to the EventOS admin menu.
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
	 * Event insights builder accessor.
	 *
	 * @return Event_Insights_Builder
	 */
	public function insights(): Event_Insights_Builder {
		if ( null === $this->insights ) {
			$this->insights = new Event_Insights_Builder( new Ticket_Order_Resolver( new Ticket_Type_Repository() ), new Person_Repository() );
		}

		return $this->insights;
	}

	/**
	 * Event comparison builder accessor.
	 *
	 * @return Event_Comparison_Builder
	 */
	public function comparison(): Event_Comparison_Builder {
		if ( null === $this->comparison ) {
			$ticket_types = new Ticket_Type_Repository();
			$tickets = new Ticket_Repository();
			$order_resolver = new Ticket_Order_Resolver( $ticket_types );

			$this->comparison = new Event_Comparison_Builder(
				new Event_Repository(),
				new Brand_Report_Builder( $ticket_types, $tickets, $order_resolver, new Event_Repository() ),
				$order_resolver,
				new Finance_Report_Builder( $order_resolver, new Expense_Repository(), $tickets ),
				$this->insights()
			);
		}

		return $this->comparison;
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'eventos_register_rest_endpoints', array( $this, 'register_rest_endpoints' ) );
		add_filter( 'eventos_admin_pages', array( $this, 'register_admin_pages' ) );
		add_action( 'eventos_register_exports', array( $this, 'register_exports' ) );
	}

	/**
	 * Register the Analytics REST routes.
	 *
	 * @return void
	 */
	public function register_rest_endpoints(): void {
		$controller = new Analytics_Controller( $this->insights(), $this->comparison() );

		Rest_Registry::register_many( $controller->endpoints(), $this->slug() );
	}

	/**
	 * Register the event comparison export.
	 *
	 * Gated by {@see Finance_Capabilities::VIEW_FINANCE} rather than the
	 * general export capability: the export includes profit/margin, so it
	 * stays behind the same wall Finance's own exports use, not the
	 * lower `eventos_run_exports` bar.
	 *
	 * @return void
	 */
	public function register_exports(): void {
		$comparison = $this->comparison();

		Export_Registry::register(
			array(
				'entity'     => 'event_comparison',
				'label'      => __( 'Event comparison', 'eventos' ),
				'module'     => $this->slug(),
				'capability' => Finance_Capabilities::VIEW_FINANCE,
				'filename'   => 'eventos-event-comparison',
				'columns'    => array(
					'title'               => __( 'Event', 'eventos' ),
					'starts_at'           => __( 'Date', 'eventos' ),
					'status'              => __( 'Status', 'eventos' ),
					'tickets_sold'        => __( 'Tickets sold', 'eventos' ),
					'checked_in'          => __( 'Checked in', 'eventos' ),
					'sell_through'        => __( 'Sell-through %', 'eventos' ),
					'orders'              => __( 'Orders', 'eventos' ),
					'average_order_value' => __( 'Avg. order value', 'eventos' ),
					'revenue'             => __( 'Revenue', 'eventos' ),
					'total_expenses'      => __( 'Expenses', 'eventos' ),
					'net_profit'          => __( 'Net profit', 'eventos' ),
					'profit_margin'       => __( 'Profit margin %', 'eventos' ),
					'new_customers'       => __( 'New customers', 'eventos' ),
					'returning_customers' => __( 'Returning customers', 'eventos' ),
				),
				'provider'   => static function ( array $args ) use ( $comparison ): array {
					$result = $comparison->compare(
						array(
							'per_page' => (int) ( $args['per_page'] ?? Event_Comparison_Builder::MAX_EVENTS ),
						)
					);

					return $comparison->attach_financials( $result['items'] );
				},
			)
		);
	}
}

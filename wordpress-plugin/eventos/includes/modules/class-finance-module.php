<?php
/**
 * Finance / Profit & Loss module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Modules;

use EventOS\Abstract_Module;
use EventOS\Events\Ticket_Order_Resolver;
use EventOS\Events\Ticket_Type_Repository;
use EventOS\Finance\Expense_Repository;
use EventOS\Finance\Expense_Service;
use EventOS\Finance\Finance_Capabilities;
use EventOS\Finance\Finance_Report_Builder;
use EventOS\Finance\Finance_Schema;
use EventOS\Export\Export_Registry;
use EventOS\Rest\Finance_Controller;
use EventOS\Rest\Rest_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires event and organisation Profit & Loss into EventOS: expense
 * persistence, the P&L calculation and the REST/export surface built on
 * top of it. Revenue, refunds, discounts and payment fees are never
 * duplicated here — they are resolved live from WooCommerce through the
 * Events module's {@see Ticket_Order_Resolver}, the same source of truth
 * every other financial figure in EventOS already uses (see
 * {@see Finance_Report_Builder}'s class docblock). The view/manage
 * capability split this module gates every route with
 * (`eventos_view_finance` / `eventos_manage_finance`) already exists in
 * core — see {@see Finance_Capabilities}.
 */
final class Finance_Module extends Abstract_Module {

	/**
	 * Report builder.
	 *
	 * @var Finance_Report_Builder|null
	 */
	private ?Finance_Report_Builder $reports = null;

	/**
	 * Expense service.
	 *
	 * @var Expense_Service|null
	 */
	private ?Expense_Service $expense_service = null;

	/**
	 * Module slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'finance';
	}

	/**
	 * Module name.
	 *
	 * @return string
	 */
	public function name(): string {
		return __( 'Finance', 'eventos' );
	}

	/**
	 * Module description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Event expenses, Profit & Loss and organisation-wide financial reporting.', 'eventos' );
	}

	/**
	 * Module dependencies.
	 *
	 * @return string[]
	 */
	public function dependencies(): array {
		return array( 'core', 'events', 'woocommerce' );
	}

	/**
	 * Admin screens contributed by the module.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function menu_items(): array {
		return array(
			array(
				'slug'       => 'eventos-finance',
				'title'      => __( 'Finance', 'eventos' ),
				'view'       => 'finance/overview',
				'capability' => Finance_Capabilities::VIEW_FINANCE,
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
	 * P&L report builder accessor.
	 *
	 * @return Finance_Report_Builder
	 */
	public function reports(): Finance_Report_Builder {
		if ( null === $this->reports ) {
			$this->reports = new Finance_Report_Builder(
				new Ticket_Order_Resolver( new Ticket_Type_Repository() ),
				new Expense_Repository()
			);
		}

		return $this->reports;
	}

	/**
	 * Expense service accessor.
	 *
	 * @return Expense_Service
	 */
	public function expense_service(): Expense_Service {
		if ( null === $this->expense_service ) {
			$this->expense_service = new Expense_Service( new Expense_Repository() );
		}

		return $this->expense_service;
	}

	/**
	 * Install the module's tables on first activation.
	 *
	 * @return void
	 */
	public function activate(): void {
		Finance_Schema::install();
	}

	/**
	 * Keep the schema current on upgrades.
	 *
	 * @param string $from_version Previously installed version.
	 * @return void
	 */
	public function upgrade( string $from_version ): void {
		unset( $from_version );

		Finance_Schema::install();
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		Finance_Schema::maybe_install();

		add_action( 'eventos_register_rest_endpoints', array( $this, 'register_rest_endpoints' ) );
		add_filter( 'eventos_admin_pages', array( $this, 'register_admin_pages' ) );
		add_action( 'eventos_register_exports', array( $this, 'register_exports' ) );
	}

	/**
	 * Register the Finance REST routes.
	 *
	 * @return void
	 */
	public function register_rest_endpoints(): void {
		$controller = new Finance_Controller( $this->reports(), $this->expense_service() );

		Rest_Registry::register_many( $controller->endpoints(), $this->slug() );
	}

	/**
	 * Register Finance exports.
	 *
	 * Gated by {@see Finance_Capabilities::VIEW_FINANCE} rather than the
	 * general `eventos_run_exports` capability every other export uses —
	 * financial exports stay behind the same wall as the Finance screens
	 * themselves (see class docblock).
	 *
	 * @return void
	 */
	public function register_exports(): void {
		$reports  = $this->reports();
		$expenses = new Expense_Repository();

		Export_Registry::register_many(
			array(
				array(
					'entity'     => 'event_pnl',
					'label'      => __( 'Event P&L', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Finance_Capabilities::VIEW_FINANCE,
					'filename'   => 'eventos-event-pnl',
					'columns'    => array(
						'line'   => __( 'Line item', 'eventos' ),
						'amount' => __( 'Amount', 'eventos' ),
					),
					'provider'   => static function ( array $args ) use ( $reports ): array {
						$event_id = (int) ( $args['event_id'] ?? 0 );

						if ( $event_id <= 0 ) {
							return array();
						}

						return self::pnl_rows( $reports->build( $event_id ) );
					},
				),
				array(
					'entity'     => 'event_expenses',
					'label'      => __( 'Event expenses', 'eventos' ),
					'module'     => $this->slug(),
					'capability' => Finance_Capabilities::VIEW_FINANCE,
					'filename'   => 'eventos-event-expenses',
					'columns'    => array(
						'category'     => __( 'Category', 'eventos' ),
						'description'  => __( 'Description', 'eventos' ),
						'amount'       => __( 'Amount', 'eventos' ),
						'currency'     => __( 'Currency', 'eventos' ),
						'expense_date' => __( 'Date', 'eventos' ),
						'payee'        => __( 'Payee', 'eventos' ),
						'reference'    => __( 'Reference', 'eventos' ),
						'status'       => __( 'Status', 'eventos' ),
					),
					'provider'   => static function ( array $args ) use ( $expenses ): array {
						$event_id = (int) ( $args['event_id'] ?? 0 );

						if ( $event_id <= 0 ) {
							return array();
						}

						return $expenses->query(
							array(
								'event_id' => $event_id,
								'status'   => 'recorded',
								'per_page' => 1000,
							)
						)['items'];
					},
				),
			)
		);
	}

	/**
	 * Flatten a P&L payload into export rows, in statement order.
	 *
	 * @param array<string, mixed> $pnl P&L payload from Finance_Report_Builder::build().
	 * @return array<int, array{line: string, amount: mixed}>
	 */
	private static function pnl_rows( array $pnl ): array {
		$row = static function ( string $label, $amount ): array {
			return array( 'line' => $label, 'amount' => $amount );
		};

		return array(
			$row( __( 'Ticket revenue', 'eventos' ), $pnl['revenue']['ticket_revenue'] ),
			$row( __( 'Other revenue', 'eventos' ), $pnl['revenue']['other_revenue'] ),
			$row( __( 'Total revenue', 'eventos' ), $pnl['revenue']['total_revenue'] ),
			$row( __( 'Discounts', 'eventos' ), -$pnl['adjustments']['discounts'] ),
			$row( __( 'Refunds', 'eventos' ), -$pnl['adjustments']['refunds'] ),
			$row( __( 'Other adjustments', 'eventos' ), -$pnl['adjustments']['other_adjustments'] ),
			$row( __( 'Payment fees', 'eventos' ) . ' (' . $pnl['fees']['fee_status'] . ')', -$pnl['fees']['payment_fees'] ),
			$row( __( 'Platform fees', 'eventos' ), -$pnl['fees']['platform_fees'] ),
			$row( __( 'Total fees', 'eventos' ), -$pnl['fees']['total_fees'] ),
			$row( __( 'Total expenses', 'eventos' ), -$pnl['expenses']['total_expenses'] ),
			$row( __( 'Gross revenue', 'eventos' ), $pnl['result']['gross_revenue'] ),
			$row( __( 'Net revenue', 'eventos' ), $pnl['result']['net_revenue'] ),
			$row( __( 'Net profit', 'eventos' ), $pnl['result']['net_profit'] ),
			$row( __( 'Profit margin %', 'eventos' ), $pnl['result']['profit_margin'] ),
		);
	}
}

<?php
/**
 * REST surface for event P&L and expenses.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use EventOS\Finance\Expense_Service;
use EventOS\Finance\Finance_Capabilities;
use EventOS\Finance\Finance_Report_Builder;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request handlers backing the Event Workspace's Finance tab and the
 * brand-wide Finance overview screen. View routes require
 * {@see Finance_Capabilities::VIEW_FINANCE}; every write requires
 * {@see Finance_Capabilities::MANAGE_FINANCE} — a deliberately narrower
 * grant than the general "manage events" capability (see that class's
 * docblock for why financial data is walled off from day-to-day admin).
 */
final class Finance_Controller {

	/**
	 * P&L report builder.
	 *
	 * @var Finance_Report_Builder
	 */
	private Finance_Report_Builder $reports;

	/**
	 * Expense service.
	 *
	 * @var Expense_Service
	 */
	private Expense_Service $expenses;

	/**
	 * Constructor.
	 *
	 * @param Finance_Report_Builder $reports  P&L report builder.
	 * @param Expense_Service        $expenses Expense service.
	 */
	public function __construct( Finance_Report_Builder $reports, Expense_Service $expenses ) {
		$this->reports  = $reports;
		$this->expenses = $expenses;
	}

	/**
	 * Endpoint declarations for the REST registry.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function endpoints(): array {
		$view   = Finance_Capabilities::VIEW_FINANCE;
		$manage = Finance_Capabilities::MANAGE_FINANCE;

		$list_args = array(
			'search'   => array( 'type' => 'string' ),
			'category' => array( 'type' => 'string' ),
			'status'   => array( 'type' => 'string' ),
			'orderby'  => array( 'type' => 'string' ),
			'order'    => array( 'type' => 'string' ),
			'page'     => array( 'type' => 'integer' ),
			'per_page' => array( 'type' => 'integer' ),
		);

		return array(
			array(
				'route'      => '/events/(?P<id>\d+)/finance/summary',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'summary' ),
				'summary'    => __( 'Event Profit & Loss.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/finance/expenses',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'expenses' ),
				'summary'    => __( 'List expenses for an event.', 'eventos' ),
				'args'       => $list_args,
			),
			array(
				'route'      => '/events/(?P<id>\d+)/finance/expenses',
				'methods'    => 'POST',
				'capability' => $manage,
				'callback'   => array( $this, 'create_expense' ),
				'log_action' => 'expense_created',
				'summary'    => __( 'Record an event expense.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/finance/expenses/(?P<expense_id>\d+)',
				'methods'    => 'POST',
				'capability' => $manage,
				'callback'   => array( $this, 'update_expense' ),
				'log_action' => 'expense_updated',
				'summary'    => __( 'Update an event expense.', 'eventos' ),
			),
			array(
				'route'      => '/events/(?P<id>\d+)/finance/expenses/(?P<expense_id>\d+)',
				'methods'    => 'DELETE',
				'capability' => $manage,
				'callback'   => array( $this, 'void_expense' ),
				'log_action' => 'expense_voided',
				'summary'    => __( 'Void an event expense.', 'eventos' ),
			),
			array(
				'route'      => '/finance/summary',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'org_summary' ),
				'summary'    => __( 'Organisation-wide Profit & Loss across events.', 'eventos' ),
				'args'       => array( 'event_ids' => array( 'type' => 'string' ) ),
			),
			array(
				'route'      => '/finance/expense-categories',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'expense_categories' ),
				'summary'    => __( 'Suggested expense categories.', 'eventos' ),
			),
		);
	}

	/**
	 * Event P&L.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function summary( WP_REST_Request $request ): array {
		return $this->reports->build( (int) $request->get_param( 'id' ) );
	}

	/**
	 * Organisation-wide P&L. Accepts an optional comma-separated
	 * `event_ids`; omitted, it scopes to every event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function org_summary( WP_REST_Request $request ): array {
		$raw = (string) $request->get_param( 'event_ids' );

		$event_ids = '' === trim( $raw )
			? array()
			: array_values( array_filter( array_map( 'intval', explode( ',', $raw ) ) ) );

		return $this->reports->org_summary( $event_ids );
	}

	/**
	 * List expenses for an event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function expenses( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->expenses->list_for_event(
			(int) $request->get_param( 'id' ),
			array(
				'search'   => (string) $request->get_param( 'search' ),
				'category' => (string) $request->get_param( 'category' ),
				'status'   => (string) $request->get_param( 'status' ),
				'orderby'  => (string) $request->get_param( 'orderby' ),
				'order'    => (string) ( $request->get_param( 'order' ) ?: 'desc' ),
				'page'     => (int) ( $request->get_param( 'page' ) ?: 1 ),
				'per_page' => (int) ( $request->get_param( 'per_page' ) ?: 20 ),
			)
		);

		return Rest_Response::collection( $result['items'], $result['total'], $result['page'], $result['per_page'] );
	}

	/**
	 * Create an expense.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function create_expense( WP_REST_Request $request ) {
		return $this->expenses->create( (int) $request->get_param( 'id' ), $this->payload( $request ), get_current_user_id() );
	}

	/**
	 * Update an expense.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update_expense( WP_REST_Request $request ) {
		return $this->expenses->update(
			(int) $request->get_param( 'id' ),
			(int) $request->get_param( 'expense_id' ),
			$this->payload( $request )
		);
	}

	/**
	 * Void an expense.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function void_expense( WP_REST_Request $request ) {
		$result = $this->expenses->void( (int) $request->get_param( 'id' ), (int) $request->get_param( 'expense_id' ) );

		return is_wp_error( $result ) ? $result : array( 'voided' => true );
	}

	/**
	 * Suggested expense categories.
	 *
	 * @return array<string, mixed>
	 */
	public function expense_categories(): array {
		return array( 'categories' => Expense_Service::suggested_categories() );
	}

	/**
	 * Decode the JSON request body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function payload( WP_REST_Request $request ): array {
		$body = $request->get_json_params();

		return is_array( $body ) ? $body : array();
	}
}

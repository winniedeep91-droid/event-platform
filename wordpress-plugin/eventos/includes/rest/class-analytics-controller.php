<?php
/**
 * REST surface for event insights and organisation-wide event comparison.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use EventOS\Analytics\Event_Comparison_Builder;
use EventOS\Analytics\Event_Insights_Builder;
use EventOS\Events\Event_Capabilities;
use EventOS\Finance\Finance_Capabilities;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Both routes require {@see Event_Capabilities::VIEW_EVENTS} — the same
 * bar Reports already sets. Profit/margin/expenses on the comparison
 * endpoint are a strictly additive, server-side-gated extra: they are only
 * computed and included when the requesting user also holds
 * {@see Finance_Capabilities::VIEW_FINANCE}, so a capability that grants
 * event visibility (e.g. `event_manager`) never implies financial
 * visibility — see {@see Event_Comparison_Builder}'s docblock. The response
 * always reports whether they were included via `financials_included`
 * rather than the frontend ever guessing from field presence.
 */
final class Analytics_Controller {

	/**
	 * Event insights builder.
	 *
	 * @var Event_Insights_Builder
	 */
	private Event_Insights_Builder $insights;

	/**
	 * Event comparison builder.
	 *
	 * @var Event_Comparison_Builder
	 */
	private Event_Comparison_Builder $comparison;

	/**
	 * Constructor.
	 *
	 * @param Event_Insights_Builder   $insights   Event insights builder.
	 * @param Event_Comparison_Builder $comparison Event comparison builder.
	 */
	public function __construct( Event_Insights_Builder $insights, Event_Comparison_Builder $comparison ) {
		$this->insights   = $insights;
		$this->comparison = $comparison;
	}

	/**
	 * Endpoint declarations for the REST registry.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function endpoints(): array {
		$view = Event_Capabilities::VIEW_EVENTS;

		return array(
			array(
				'route'      => '/events/(?P<id>\d+)/insights',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'event_insights' ),
				'summary'    => __( 'Audience composition (new vs. returning customers) for an event.', 'eventos' ),
			),
			array(
				'route'      => '/analytics/event-comparison',
				'methods'    => 'GET',
				'capability' => $view,
				'callback'   => array( $this, 'event_comparison' ),
				'summary'    => __( 'Compare ticket, revenue, attendance and (if permitted) profit across events.', 'eventos' ),
				'args'       => array(
					'search'   => array( 'type' => 'string' ),
					'status'   => array( 'type' => 'string' ),
					'from'     => array( 'type' => 'string' ),
					'to'       => array( 'type' => 'string' ),
					'orderby'  => array( 'type' => 'string' ),
					'order'    => array( 'type' => 'string' ),
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
				),
			),
		);
	}

	/**
	 * Audience composition for a single event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function event_insights( WP_REST_Request $request ): array {
		return $this->insights->build( (int) $request->get_param( 'id' ) );
	}

	/**
	 * Organisation-wide event comparison.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function event_comparison( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->comparison->compare(
			array(
				'search'   => (string) $request->get_param( 'search' ),
				'status'   => (string) $request->get_param( 'status' ),
				'from'     => (string) $request->get_param( 'from' ),
				'to'       => (string) $request->get_param( 'to' ),
				'orderby'  => (string) ( $request->get_param( 'orderby' ) ?: 'starts_at' ),
				'order'    => (string) ( $request->get_param( 'order' ) ?: 'desc' ),
				'page'     => (int) ( $request->get_param( 'page' ) ?: 1 ),
				'per_page' => (int) ( $request->get_param( 'per_page' ) ?: Event_Comparison_Builder::MAX_EVENTS ),
			)
		);

		$financials_included = current_user_can( Finance_Capabilities::VIEW_FINANCE );

		if ( $financials_included ) {
			$result['items'] = $this->comparison->attach_financials( $result['items'] );
		}

		return Rest_Response::collection(
			$result['items'],
			$result['total'],
			(int) ( $request->get_param( 'page' ) ?: 1 ),
			(int) ( $request->get_param( 'per_page' ) ?: Event_Comparison_Builder::MAX_EVENTS ),
			array( 'financials_included' => $financials_included )
		);
	}
}

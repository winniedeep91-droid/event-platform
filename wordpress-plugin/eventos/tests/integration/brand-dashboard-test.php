<?php
/**
 * WordPress-integration tests for the brand-wide dashboard aggregation
 * layer: Ticket_Repository's cross-event queries, Ticket_Type_Repository's
 * product/event mapping, and Brand_Report_Builder's period bucketing and
 * batched per-event summaries.
 *
 * Requires a real WordPress + MySQL test environment (WP_TESTS_DIR) — see
 * tests/bootstrap.php. Not executable in an environment with no PHP
 * interpreter; written and manually traced against the implementation, but
 * never run via `composer test` in this session (no PHP/Composer toolchain
 * available here — see the LocalWP validation report instead for what was
 * actually exercised live). Revenue-bearing assertions are written to hold
 * regardless of whether WooCommerce is active in the test environment: when
 * it is not, Ticket_Order_Resolver::paid_orders() returns an empty set and
 * every revenue figure is asserted to be exactly 0.0, never fabricated.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Tests\Integration;

use EventOS\Events\Brand_Report_Builder;
use EventOS\Events\Event_Repository;
use EventOS\Events\Event_Schema;
use EventOS\Events\Ticket_Order_Resolver;
use EventOS\Events\Ticket_Repository;
use EventOS\Events\Ticket_Type_Repository;
use EventOS\WooCommerce;
use WP_UnitTestCase;

/**
 * WP_UnitTestCase wraps every test method in its own transaction, rolled
 * back automatically — matches Ticket_Lifecycle_Test's own convention, so
 * no manual fixture cleanup is needed here either.
 */
final class Brand_Dashboard_Test extends WP_UnitTestCase {

	private Ticket_Type_Repository $ticket_types;
	private Ticket_Repository $tickets;
	private Event_Repository $events;
	private Brand_Report_Builder $brand_reports;

	public function set_up(): void {
		parent::set_up();

		Event_Schema::install();

		$this->ticket_types = new Ticket_Type_Repository();
		$this->tickets       = new Ticket_Repository();
		$this->events        = new Event_Repository();

		$order_resolver      = new Ticket_Order_Resolver( $this->ticket_types );
		$this->brand_reports = new Brand_Report_Builder( $this->ticket_types, $this->tickets, $order_resolver, $this->events );
	}

	/**
	 * Inserts a minimal event row directly — Event_Repository's own create
	 * flow is not under test here, matching Ticket_Lifecycle_Test's fixture
	 * convention.
	 *
	 * @param string $starts_at Event start (Y-m-d H:i:s, UTC).
	 * @return int Event ID.
	 */
	private function make_event( string $starts_at = '' ): int {
		global $wpdb;

		$wpdb->insert(
			Event_Schema::events(),
			array(
				'title'      => 'Brand Dashboard Test Event',
				'slug'       => 'brand-dashboard-test-' . wp_generate_uuid4(),
				'status'     => 'published',
				'timezone'   => 'UTC',
				'starts_at'  => '' !== $starts_at ? $starts_at : null,
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Issues a ticket and back-dates its created_at — issue() always stamps
	 * "now", so day-bucketing tests need to move it after the fact.
	 *
	 * @param int    $event_id       Event ID.
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param string $created_at     Desired created_at (Y-m-d H:i:s, UTC).
	 * @param bool   $checked_in     Whether to mark it checked in.
	 * @param bool   $complimentary  Whether it is complimentary.
	 * @return array<string, mixed>
	 */
	private function issue_ticket_on( int $event_id, int $ticket_type_id, string $created_at, bool $checked_in = false, bool $complimentary = false ): array {
		global $wpdb;

		$ticket = $this->tickets->issue(
			array(
				'event_id'         => $event_id,
				'ticket_type_id'   => $ticket_type_id,
				'is_complimentary' => $complimentary,
			)
		);

		$wpdb->update(
			Event_Schema::tickets(),
			array( 'created_at' => $created_at ),
			array( 'id' => $ticket['id'] )
		);

		if ( $checked_in ) {
			$this->tickets->mark_checked_in( (int) $ticket['id'], 0 );
		}

		return $this->tickets->find( (int) $ticket['id'] );
	}

	// ── Ticket_Repository ───────────────────────────────────────────────

	public function test_totals_excludes_cancelled_and_counts_complimentary_and_checked_in(): void {
		$event_id = $this->make_event();
		$type     = $this->ticket_types->create( $event_id, array( 'name' => 'GA', 'price' => 100, 'capacity' => 50 ) );

		$this->issue_ticket_on( $event_id, (int) $type['id'], current_time( 'mysql', true ), true, false );
		$this->issue_ticket_on( $event_id, (int) $type['id'], current_time( 'mysql', true ), false, true );

		$cancelled = $this->tickets->issue( array( 'event_id' => $event_id, 'ticket_type_id' => $type['id'] ) );

		global $wpdb;
		$wpdb->update( Event_Schema::tickets(), array( 'status' => 'cancelled' ), array( 'id' => $cancelled['id'] ) );

		$totals = $this->tickets->totals();

		$this->assertSame( 2, $totals['total'] );
		$this->assertSame( 1, $totals['checked_in'] );
		$this->assertSame( 1, $totals['complimentary'] );
	}

	public function test_counts_by_day_buckets_by_date_and_excludes_out_of_range(): void {
		$event_id = $this->make_event();
		$type     = $this->ticket_types->create( $event_id, array( 'name' => 'GA', 'price' => 100, 'capacity' => 50 ) );

		$today     = gmdate( 'Y-m-d H:i:s' );
		$yesterday = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$last_week = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );

		$this->issue_ticket_on( $event_id, (int) $type['id'], $today );
		$this->issue_ticket_on( $event_id, (int) $type['id'], $today );
		$this->issue_ticket_on( $event_id, (int) $type['id'], $yesterday );
		$this->issue_ticket_on( $event_id, (int) $type['id'], $last_week );

		$rows = $this->tickets->counts_by_day( gmdate( 'Y-m-d H:i:s', strtotime( '-2 days' ) ), gmdate( 'Y-m-d H:i:s', strtotime( '+1 day' ) ) );

		$by_date = array();
		foreach ( $rows as $row ) {
			$by_date[ $row['date'] ] = $row['tickets'];
		}

		$this->assertSame( 2, $by_date[ substr( $today, 0, 10 ) ] ?? 0 );
		$this->assertSame( 1, $by_date[ substr( $yesterday, 0, 10 ) ] ?? 0 );
		$this->assertArrayNotHasKey( substr( $last_week, 0, 10 ), $by_date );
	}

	public function test_counts_by_event_batches_without_one_query_per_event_and_omits_empty_events(): void {
		$event_a = $this->make_event();
		$event_b = $this->make_event();
		$event_c = $this->make_event(); // no tickets at all

		$type_a = $this->ticket_types->create( $event_a, array( 'name' => 'GA', 'price' => 100, 'capacity' => 50 ) );
		$type_b = $this->ticket_types->create( $event_b, array( 'name' => 'GA', 'price' => 100, 'capacity' => 50 ) );

		$this->issue_ticket_on( $event_a, (int) $type_a['id'], current_time( 'mysql', true ) );
		$this->issue_ticket_on( $event_a, (int) $type_a['id'], current_time( 'mysql', true ), true );
		$this->issue_ticket_on( $event_b, (int) $type_b['id'], current_time( 'mysql', true ) );

		$summary = $this->tickets->counts_by_event( array( $event_a, $event_b, $event_c ) );

		$this->assertSame( 2, $summary[ $event_a ]['total'] );
		$this->assertSame( 1, $summary[ $event_a ]['checked_in'] );
		$this->assertSame( 1, $summary[ $event_b ]['total'] );
		$this->assertArrayNotHasKey( $event_c, $summary, 'An event with zero tickets is omitted, not zero-filled, by the repository layer.' );
	}

	// ── Ticket_Type_Repository ──────────────────────────────────────────

	public function test_product_event_map_scopes_to_requested_events_and_ignores_unlinked_types(): void {
		global $wpdb;

		$event_a = $this->make_event();
		$event_b = $this->make_event();

		$type_a = $this->ticket_types->create( $event_a, array( 'name' => 'GA', 'price' => 100, 'capacity' => 50 ) );
		$type_b = $this->ticket_types->create( $event_b, array( 'name' => 'GA', 'price' => 100, 'capacity' => 50 ) );

		// Simulate a linked WooCommerce product without requiring a live
		// WooCommerce install — the column is a plain integer FK, and
		// product_event_map() only ever reads it, never wc_get_product().
		$wpdb->update( Event_Schema::ticket_types(), array( 'wc_product_id' => 9001 ), array( 'id' => $type_a['id'] ) );
		$wpdb->update( Event_Schema::ticket_types(), array( 'wc_product_id' => 9002 ), array( 'id' => $type_b['id'] ) );

		$scoped = $this->ticket_types->product_event_map( array( $event_a ) );
		$this->assertSame( array( 9001 => $event_a ), $scoped );

		$all = $this->ticket_types->product_event_map();
		$this->assertSame( $event_a, $all[9001] );
		$this->assertSame( $event_b, $all[9002] );
	}

	// ── Brand_Report_Builder ────────────────────────────────────────────

	public function test_summary_ticket_figures_match_repository_totals(): void {
		$event_id = $this->make_event();
		$type     = $this->ticket_types->create( $event_id, array( 'name' => 'GA', 'price' => 100, 'capacity' => 50 ) );

		$this->issue_ticket_on( $event_id, (int) $type['id'], current_time( 'mysql', true ), true );
		$this->issue_ticket_on( $event_id, (int) $type['id'], current_time( 'mysql', true ), false, true );

		$summary = $this->brand_reports->summary();
		$totals  = $this->tickets->totals();

		$this->assertSame( $totals['total'], $summary['tickets_sold'] );
		$this->assertSame( $totals['checked_in'], $summary['attendance'] );
		$this->assertSame( $totals['complimentary'], $summary['complimentary'] );

		// No orders can exist without a real WooCommerce order — revenue and
		// order count must be exactly 0, never estimated, when there is
		// nothing (or no active WooCommerce) to resolve them from.
		if ( ! WooCommerce::is_active() ) {
			$this->assertSame( 0.0, $summary['total_revenue'] );
			$this->assertSame( 0, $summary['orders'] );
		}
	}

	public function test_series_excludes_tickets_outside_the_requested_period(): void {
		$event_id = $this->make_event();
		$type     = $this->ticket_types->create( $event_id, array( 'name' => 'GA', 'price' => 100, 'capacity' => 50 ) );

		$this->issue_ticket_on( $event_id, (int) $type['id'], gmdate( 'Y-m-d H:i:s' ) );
		$this->issue_ticket_on( $event_id, (int) $type['id'], gmdate( 'Y-m-d H:i:s', strtotime( '-40 days' ) ) );

		$series = $this->brand_reports->series( '7d' );

		$this->assertSame( '7d', $series['period'] );

		$total_in_series = array_sum( array_column( $series['tickets_by_day'], 'tickets' ) );
		$this->assertSame( 1, $total_in_series, 'The 40-day-old ticket must not appear in a 7-day series.' );
	}

	public function test_events_summary_zero_fills_events_with_no_activity(): void {
		$event_with_tickets = $this->make_event();
		$event_without      = $this->make_event();

		$type = $this->ticket_types->create( $event_with_tickets, array( 'name' => 'GA', 'price' => 100, 'capacity' => 50 ) );
		$this->issue_ticket_on( $event_with_tickets, (int) $type['id'], current_time( 'mysql', true ) );

		$summary = $this->brand_reports->events_summary( array( $event_with_tickets, $event_without ) );

		$this->assertSame( 1, $summary[ $event_with_tickets ]['tickets_sold'] );
		$this->assertSame( 0, $summary[ $event_without ]['tickets_sold'] );
		$this->assertSame( 0.0, $summary[ $event_without ]['revenue'] );
	}
}

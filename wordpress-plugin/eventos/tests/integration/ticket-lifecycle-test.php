<?php
/**
 * WordPress-integration tests for the ticket issuance and check-in lifecycle.
 *
 * Requires a real WordPress + MySQL test environment (WP_TESTS_DIR) — see
 * tests/bootstrap.php. Not executable in an environment with no PHP
 * interpreter; written and manually traced against the implementation, but
 * never run. Run with: composer test (after installing the WP test library).
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Tests\Integration;

use EventOS\Events\Checkin_Repository;
use EventOS\Events\Event_Schema;
use EventOS\Events\Guest_Repository;
use EventOS\Events\Ticket_Repository;
use EventOS\Events\Ticket_Type_Repository;
use WP_UnitTestCase;

/**
 * Covers the pieces of the lifecycle that need a real database: uniqueness
 * under the schema's actual unique constraints, atomic check-in, and the
 * cancel/reactivate round trip a reinstated order relies on.
 */
final class Ticket_Lifecycle_Test extends WP_UnitTestCase {

	private Ticket_Type_Repository $ticket_types;
	private Ticket_Repository $tickets;
	private Guest_Repository $guests;
	private Checkin_Repository $checkins;
	private int $event_id;
	private int $ticket_type_id;

	public function set_up(): void {
		parent::set_up();

		Event_Schema::install();

		$this->ticket_types = new Ticket_Type_Repository();
		$this->tickets       = new Ticket_Repository();
		$this->guests        = new Guest_Repository();
		$this->checkins      = new Checkin_Repository();

		global $wpdb;

		// A minimal event row — Event_Repository is not under test here, so
		// insert directly rather than pulling in the full create_event() flow.
		$wpdb->insert(
			Event_Schema::events(),
			array(
				'title'      => 'Test Event',
				'slug'       => 'test-event-' . wp_generate_uuid4(),
				'status'     => 'published',
				'timezone'   => 'UTC',
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			)
		);
		$this->event_id = (int) $wpdb->insert_id;

		$created = $this->ticket_types->create(
			$this->event_id,
			array(
				'name'     => 'General Admission',
				'price'    => 100,
				'capacity' => 5,
			)
		);

		$this->ticket_type_id = (int) $created['id'];
	}

	/** Issuing many tickets never produces a duplicate ticket_number or qr_token. */
	public function test_issued_tickets_are_all_unique(): void {
		$numbers = array();
		$tokens  = array();

		for ( $i = 0; $i < 25; $i++ ) {
			$ticket = $this->tickets->issue(
				array(
					'event_id'       => $this->event_id,
					'ticket_type_id' => $this->ticket_type_id,
				)
			);

			$numbers[ $ticket['ticket_number'] ] = true;
			$tokens[ $ticket['qr_token'] ]        = true;
		}

		$this->assertCount( 25, $numbers );
		$this->assertCount( 25, $tokens );
	}

	/** A ticket can be looked up by either its QR token or its ticket number. */
	public function test_find_by_code_matches_token_or_number(): void {
		$ticket = $this->tickets->issue(
			array(
				'event_id'       => $this->event_id,
				'ticket_type_id' => $this->ticket_type_id,
			)
		);

		$by_token  = $this->tickets->find_by_code( $ticket['qr_token'] );
		$by_number = $this->tickets->find_by_code( $ticket['ticket_number'] );

		$this->assertSame( $ticket['id'], $by_token['id'] );
		$this->assertSame( $ticket['id'], $by_number['id'] );
	}

	/**
	 * Only the first of two concurrent check-in attempts on the same ticket
	 * claims it — the second must observe it was already admitted, never
	 * silently "succeed" a second time.
	 */
	public function test_mark_checked_in_is_atomic_against_duplicate_scans(): void {
		$ticket = $this->tickets->issue(
			array(
				'event_id'       => $this->event_id,
				'ticket_type_id' => $this->ticket_type_id,
			)
		);

		$first  = $this->tickets->mark_checked_in( (int) $ticket['id'], 1 );
		$second = $this->tickets->mark_checked_in( (int) $ticket['id'], 2 );

		$this->assertTrue( $first['claimed'] );
		$this->assertFalse( $second['claimed'] );
		$this->assertSame( 1, (int) $first['ticket']['checked_in_by'] );
		// The second attempt must not have overwritten who actually admitted it.
		$this->assertSame( 1, (int) $second['ticket']['checked_in_by'] );
	}

	/** Cancelling an order only touches its active tickets, and is idempotent. */
	public function test_cancel_for_order_is_idempotent(): void {
		$order_id = 555;

		for ( $i = 0; $i < 3; $i++ ) {
			$this->tickets->issue(
				array(
					'event_id'       => $this->event_id,
					'ticket_type_id' => $this->ticket_type_id,
					'wc_order_id'    => $order_id,
				)
			);
		}

		$first_pass  = $this->tickets->cancel_for_order( $order_id );
		$second_pass = $this->tickets->cancel_for_order( $order_id );

		$this->assertSame( 3, $first_pass );
		$this->assertSame( 0, $second_pass, 'Re-cancelling an already-cancelled order should touch zero rows.' );
	}

	/** A cancelled order's tickets can be reactivated per order item (reinstated order). */
	public function test_reactivate_for_order_item_only_touches_cancelled_rows(): void {
		$order_id = 777;
		$item_id  = 42;

		$ticket = $this->tickets->issue(
			array(
				'event_id'         => $this->event_id,
				'ticket_type_id'   => $this->ticket_type_id,
				'wc_order_id'      => $order_id,
				'wc_order_item_id' => $item_id,
			)
		);

		$this->tickets->cancel_for_order( $order_id );
		$reactivated = $this->tickets->reactivate_for_order_item( $item_id );
		$again       = $this->tickets->reactivate_for_order_item( $item_id );

		$fresh = $this->tickets->find( (int) $ticket['id'] );

		$this->assertSame( 1, $reactivated );
		$this->assertSame( 0, $again, 'Reactivating twice should only touch cancelled rows once.' );
		$this->assertSame( 'active', $fresh['status'] );
	}

	/** Granular refund cancellation only cancels the requested quantity for one ticket type. */
	public function test_cancel_n_for_order_type_respects_the_limit(): void {
		$order_id = 999;
		$ids      = array();

		for ( $i = 0; $i < 4; $i++ ) {
			$ticket = $this->tickets->issue(
				array(
					'event_id'       => $this->event_id,
					'ticket_type_id' => $this->ticket_type_id,
					'wc_order_id'    => $order_id,
				)
			);
			$ids[] = (int) $ticket['id'];
		}

		$cancelled = $this->tickets->cancel_n_for_order_type( $order_id, $this->ticket_type_id, 2 );

		$this->assertSame( 2, $cancelled );

		$statuses = array_map(
			fn ( int $id ) => $this->tickets->find( $id )['status'],
			$ids
		);

		$this->assertSame( 2, count( array_filter( $statuses, static fn ( $s ) => 'cancelled' === $s ) ) );
		$this->assertSame( 2, count( array_filter( $statuses, static fn ( $s ) => 'active' === $s ) ) );
	}

	/** A ticket type's sold/available figures are computed from real ticket rows, not a cached counter. */
	public function test_ticket_type_sold_reflects_active_tickets_only(): void {
		$active = $this->tickets->issue(
			array(
				'event_id'       => $this->event_id,
				'ticket_type_id' => $this->ticket_type_id,
			)
		);
		$cancelled = $this->tickets->issue(
			array(
				'event_id'       => $this->event_id,
				'ticket_type_id' => $this->ticket_type_id,
			)
		);

		global $wpdb;
		$wpdb->update(
			Event_Schema::tickets(),
			array( 'status' => 'cancelled' ),
			array( 'id' => $cancelled['id'] )
		);

		$type = $this->ticket_types->find( $this->ticket_type_id );

		$this->assertSame( 1, $type['sold'] );
		$this->assertSame( 4, $type['available'] );
	}
}

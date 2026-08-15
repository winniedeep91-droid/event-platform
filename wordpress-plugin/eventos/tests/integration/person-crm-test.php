<?php
/**
 * WordPress-integration tests for the Phase 3 CRM data/service layer:
 * tags, notes, consent, segments, metrics, timeline, and the REST API's
 * permission gating.
 *
 * Requires a real WordPress + MySQL test environment (WP_TESTS_DIR) — see
 * tests/bootstrap.php. Not executable in an environment with no PHP
 * interpreter; written and manually traced against the implementation, but
 * never run. Run with: composer test (after installing the WP test library).
 * WooCommerce must be an active plugin in that test environment for the
 * financial-metric tests (wc_create_order() is a WooCommerce core helper).
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Tests\Integration;

use EventOS\Crm\Person_Consent_Repository;
use EventOS\Crm\Person_Identity_Repository;
use EventOS\Crm\Person_Note_Repository;
use EventOS\Crm\Person_Repository;
use EventOS\Crm\Person_Resolver;
use EventOS\Crm\Person_Schema;
use EventOS\Crm\Person_Service;
use EventOS\Crm\Person_Tag_Repository;
use EventOS\Crm\Person_Timeline_Service;
use EventOS\Crm\Segment_Repository;
use EventOS\Events\Event_Schema;
use WP_UnitTestCase;

/**
 * WP_UnitTestCase wraps every test method in its own transaction, rolled
 * back automatically — no manual fixture cleanup needed, matching
 * Ticket_Lifecycle_Test's own convention.
 */
final class Person_Crm_Test extends WP_UnitTestCase {

	private Person_Repository $persons;
	private Person_Identity_Repository $identities;
	private Person_Tag_Repository $tags;
	private Person_Note_Repository $notes;
	private Person_Consent_Repository $consents;
	private Segment_Repository $segments;
	private Person_Timeline_Service $timeline;
	private Person_Service $service;

	public function set_up(): void {
		parent::set_up();

		Person_Schema::install();
		Event_Schema::install();

		$this->persons    = new Person_Repository();
		$this->identities = new Person_Identity_Repository();
		$this->tags       = new Person_Tag_Repository();
		$this->notes      = new Person_Note_Repository();
		$this->consents   = new Person_Consent_Repository();
		$this->segments   = new Segment_Repository();
		$this->timeline   = new Person_Timeline_Service();
		$this->service    = new Person_Service(
			$this->persons,
			$this->identities,
			$this->tags,
			$this->notes,
			$this->consents,
			$this->segments,
			$this->timeline
		);
	}

	// ── Tags ─────────────────────────────────────────────────────────

	public function test_tag_attach_list_and_duplicate_prevention(): void {
		$person = $this->persons->create( array( 'display_name' => 'Tag Test' ) );

		$this->tags->attach( (int) $person['id'], 'VIP' );
		$this->tags->attach( (int) $person['id'], 'VIP' ); // duplicate, must not create a second row.
		$this->tags->attach( (int) $person['id'], 'Industry' );

		$list = $this->tags->for_person( (int) $person['id'] );

		$this->assertCount( 2, $list );
	}

	public function test_tag_detach(): void {
		$person = $this->persons->create( array( 'display_name' => 'Tag Detach Test' ) );
		$this->tags->attach( (int) $person['id'], 'Press' );

		$this->tags->detach( (int) $person['id'], 'Press' );

		$this->assertCount( 0, $this->tags->for_person( (int) $person['id'] ) );
	}

	// ── Notes ────────────────────────────────────────────────────────

	public function test_note_create_and_retrieve(): void {
		$person = $this->persons->create( array( 'display_name' => 'Note Test' ) );

		$note = $this->notes->create( (int) $person['id'], 'Regular attendee, usually with a group.', 1 );

		$this->assertNotNull( $note );

		$list = $this->notes->for_person( (int) $person['id'] );

		$this->assertCount( 1, $list );
		$this->assertSame( 'Regular attendee, usually with a group.', $list[0]['body'] );
	}

	// ── Consent ──────────────────────────────────────────────────────

	public function test_consent_grant_revoke_and_history_is_preserved(): void {
		$person = $this->persons->create( array( 'display_name' => 'Consent Test' ) );
		$id     = (int) $person['id'];

		$granted = $this->consents->grant( $id, 'email', 'checkout_optin' );
		$this->assertTrue( $granted['active'] );

		// Idempotent: granting an already-active channel returns the same row.
		$again = $this->consents->grant( $id, 'email' );
		$this->assertSame( $granted['id'], $again['id'] );

		$this->consents->revoke( $id, 'email' );

		// Re-granting after a revocation creates a NEW history row rather
		// than resurrecting the old one.
		$regranted = $this->consents->grant( $id, 'email', 'portal' );
		$this->assertNotSame( $granted['id'], $regranted['id'] );

		$history = $this->consents->for_person( $id );

		$this->assertCount( 2, $history, 'Both the original and the re-grant must remain in history.' );
	}

	// ── Segments ─────────────────────────────────────────────────────

	public function test_segment_create_update_archive_and_membership(): void {
		$segment = $this->segments->create( array( 'name' => 'High Value' ) );
		$this->assertSame( 'high-value', $segment['slug'] );

		$updated = $this->segments->update( (int) $segment['id'], array( 'name' => 'Very High Value' ) );
		$this->assertSame( 'Very High Value', $updated['name'] );

		$person = $this->persons->create( array( 'display_name' => 'Segment Member' ) );

		$this->segments->attach_person( (int) $segment['id'], (int) $person['id'] );
		$this->segments->attach_person( (int) $segment['id'], (int) $person['id'] ); // duplicate, must not error or double-count.

		$members = $this->segments->members( (int) $segment['id'] );
		$this->assertSame( 1, $members['total'] );

		$this->assertCount( 1, $this->segments->for_person( (int) $person['id'] ) );

		$this->segments->detach_person( (int) $segment['id'], (int) $person['id'] );
		$this->assertCount( 0, $this->segments->for_person( (int) $person['id'] ) );

		$archived = $this->segments->archive( (int) $segment['id'] );
		$this->assertTrue( $archived['archived'] );
		$this->assertNotContains( (int) $segment['id'], array_column( $this->segments->all(), 'id' ) );
		$this->assertContains( (int) $segment['id'], array_column( $this->segments->all( true ), 'id' ) );
	}

	// ── Person_Service ───────────────────────────────────────────────

	public function test_get_profile_returns_empty_collections_not_null_when_nothing_exists(): void {
		$person = $this->persons->create( array( 'display_name' => 'Empty Profile' ) );

		$profile = $this->service->get_profile( (int) $person['id'] );

		$this->assertIsArray( $profile['tags'] );
		$this->assertIsArray( $profile['notes'] );
		$this->assertIsArray( $profile['consents'] );
		$this->assertIsArray( $profile['segments'] );
		$this->assertIsArray( $profile['event_history'] );
		$this->assertSame( array(), $profile['tags'] );
		$this->assertNull( $profile['relationship_metrics']['attendance_rate'], 'No events touched yet, so a rate cannot be computed.' );
	}

	public function test_get_profile_for_nonexistent_person_returns_null(): void {
		$this->assertNull( $this->service->get_profile( 999999 ) );
	}

	public function test_search_by_name_email_and_person_id(): void {
		$this->persons->create(
			array(
				'display_name'  => 'Searchable Sam',
				'primary_email' => 'searchable-sam@example.invalid',
			)
		);

		$by_name  = $this->service->search( array( 'q' => 'Searchable' ) );
		$by_email = $this->service->search( array( 'q' => 'searchable-sam@example.invalid' ) );

		$this->assertSame( 1, $by_name['total'] );
		$this->assertSame( 1, $by_email['total'] );

		$person_id = $by_name['items'][0]['person_id'];
		$by_id     = $this->service->search( array( 'person_id' => $person_id ) );

		$this->assertSame( 1, $by_id['total'] );
	}

	// ── Metrics: ticket/attendance-derived (no WooCommerce dependency) ─

	public function test_metrics_zero_when_no_tickets(): void {
		$person = $this->persons->create( array( 'display_name' => 'No Tickets' ) );
		$this->identities->attach_identity( (int) $person['id'], 'email', 'no-tickets@example.invalid' );

		$recomputed = $this->metrics_service()->recompute( (int) $person['id'] );

		$this->assertSame( 0, $recomputed['total_tickets_purchased'] );
		$this->assertSame( 0, $recomputed['vip_purchase_count'] );
		$this->assertSame( 0, $recomputed['complimentary_count'] );
		$this->assertSame( 0, $recomputed['refund_count'], 'refund_count must stay at its Phase 1 default.' );
		$this->assertSame( 0, $recomputed['cancellation_count'], 'cancellation_count must stay at its Phase 1 default.' );
	}

	public function test_metrics_vip_and_complimentary_and_attendance(): void {
		global $wpdb;

		$person = $this->persons->create( array( 'display_name' => 'VIP Attendee' ) );
		$email  = 'vip-attendee@example.invalid';
		$this->identities->attach_identity( (int) $person['id'], 'email', $email );

		$event_id = $this->create_event();
		$vip_type = $this->create_ticket_type( $event_id, 'vip' );
		$gen_type = $this->create_ticket_type( $event_id, 'standard' );

		// One VIP ticket, checked in.
		$vip_ticket = $this->create_ticket( $event_id, $vip_type, array( 'checked_in' => 1, 'checked_in_at' => current_time( 'mysql', true ) ) );
		$this->create_guest( $event_id, $vip_ticket, $email );

		// One complimentary general ticket, not checked in.
		$comp_ticket = $this->create_ticket( $event_id, $gen_type, array( 'is_complimentary' => 1 ) );
		$this->create_guest( $event_id, $comp_ticket, $email );

		$recomputed = $this->metrics_service()->recompute( (int) $person['id'] );

		$this->assertSame( 2, $recomputed['total_tickets_purchased'] );
		$this->assertSame( 1, $recomputed['vip_purchase_count'] );
		$this->assertSame( 1, $recomputed['complimentary_count'] );
		$this->assertSame( 1, $recomputed['total_events_attended'] );
		$this->assertSame( $event_id, $recomputed['first_event_id'] );
		$this->assertSame( $event_id, $recomputed['last_event_id'] );
		$this->assertNotNull( $recomputed['last_attendance_at'] );
	}

	/**
	 * Financial metrics: total_spend/avg_order_value/avg_ticket_value/
	 * last_purchase_at, sourced from real WooCommerce orders, de-duplicated
	 * across a Person's wc_customer_id and email identities.
	 */
	public function test_financial_metrics_from_woocommerce_orders_deduplicated_across_identities(): void {
		if ( ! function_exists( 'wc_create_order' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this test environment.' );
		}

		$person = $this->persons->create( array( 'display_name' => 'Spender' ) );
		$id     = (int) $person['id'];

		$user_id = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'spender@example.invalid' ) );
		$this->identities->attach_identity( $id, 'wc_customer_id', (string) $user_id );
		$this->identities->attach_identity( $id, 'email', 'spender@example.invalid' );

		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		$order->set_billing_email( 'spender@example.invalid' );
		$order->set_total( 900.00 );
		$order->set_status( 'completed' );
		$order->save();

		// A second, guest-checkout-style order matched only by email — must
		// be counted once, not conflated with the customer_id order above.
		$guest_order = wc_create_order();
		$guest_order->set_billing_email( 'spender@example.invalid' );
		$guest_order->set_total( 350.00 );
		$guest_order->set_status( 'processing' );
		$guest_order->save();

		// A failed order must NOT be counted.
		$failed_order = wc_create_order( array( 'customer_id' => $user_id ) );
		$failed_order->set_total( 1000.00 );
		$failed_order->set_status( 'failed' );
		$failed_order->save();

		$event_id = $this->create_event();
		$type     = $this->create_ticket_type( $event_id, 'vip' );
		$ticket   = $this->create_ticket( $event_id, $type, array( 'wc_order_id' => $order->get_id() ) );
		$this->create_guest( $event_id, $ticket, 'spender@example.invalid', $user_id );

		$recomputed = $this->metrics_service()->recompute( $id );

		$this->assertSame( 1250.0, $recomputed['total_spend'], 'R900 + R350, the failed R1000 order excluded.' );
		$this->assertSame( 625.0, $recomputed['avg_order_value'], '1250 / 2 qualifying orders.' );
		$this->assertSame( 1250.0, $recomputed['avg_ticket_value'], 'Approximation: 1250 total spend / 1 ticket purchased.' );
		$this->assertNotNull( $recomputed['last_purchase_at'] );
	}

	// ── Timeline ─────────────────────────────────────────────────────

	public function test_relationship_history_newest_first_and_includes_derived_entries(): void {
		$person = $this->persons->create( array( 'display_name' => 'Timeline Test' ) );
		$id     = (int) $person['id'];

		$this->timeline->record( $id, 'person_created', array(), '2025-01-01 10:00:00' );
		$this->tags->attach( $id, 'VIP' ); // occurred_at = now, i.e. newest.

		$history = $this->timeline->relationship_history( $id, array(), array() );

		$this->assertGreaterThanOrEqual( 2, count( $history ) );
		$this->assertSame( 'tag_added', $history[0]['type'], 'The most recent entry (just now) must sort first.' );

		$types = array_column( $history, 'type' );
		$this->assertContains( 'person_created', $types );
	}

	// ── REST permission gating ───────────────────────────────────────

	public function test_rest_persons_route_requires_manage_crm_capability(): void {
		$request  = new \WP_REST_Request( 'GET', '/eventos/v1/crm/persons' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 401, $response->get_status(), 'An unauthenticated request must be rejected.' );
	}

	public function test_rest_person_profile_returns_404_for_nonexistent_person(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$request  = new \WP_REST_Request( 'GET', '/eventos/v1/crm/persons/999999' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	// ── Fixture helpers ──────────────────────────────────────────────

	private function metrics_service(): \EventOS\Crm\Person_Metrics_Service {
		return new \EventOS\Crm\Person_Metrics_Service( $this->persons, $this->identities );
	}

	private function create_event(): int {
		global $wpdb;

		$wpdb->insert(
			Event_Schema::events(),
			array(
				'title'      => 'CRM Test Event',
				'slug'       => 'crm-test-event-' . wp_generate_uuid4(),
				'status'     => 'published',
				'timezone'   => 'UTC',
				'starts_at'  => current_time( 'mysql', true ),
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	private function create_ticket_type( int $event_id, string $tier ): int {
		global $wpdb;

		$wpdb->insert(
			Event_Schema::ticket_types(),
			array(
				'event_id'   => $event_id,
				'name'       => ucfirst( $tier ),
				'tier'       => $tier,
				'price'      => 450.00,
				'status'     => 'active',
				'visibility' => 'public',
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $overrides Column overrides.
	 */
	private function create_ticket( int $event_id, int $ticket_type_id, array $overrides = array() ): int {
		global $wpdb;

		$row = array_merge(
			array(
				'event_id'         => $event_id,
				'ticket_type_id'   => $ticket_type_id,
				'wc_order_id'      => 0,
				'wc_customer_id'   => 0,
				'ticket_number'    => 'TT-' . wp_generate_password( 8, false ),
				'qr_token'         => wp_generate_password( 40, false ),
				'status'           => 'active',
				'is_complimentary' => 0,
				'checked_in'       => 0,
				'created_at'       => current_time( 'mysql', true ),
				'updated_at'       => current_time( 'mysql', true ),
			),
			$overrides
		);

		$wpdb->insert( Event_Schema::tickets(), $row );

		return (int) $wpdb->insert_id;
	}

	private function create_guest( int $event_id, int $ticket_id, string $email, int $wc_customer_id = 0 ): int {
		global $wpdb;

		$wpdb->insert(
			Event_Schema::guests(),
			array(
				'event_id'       => $event_id,
				'ticket_id'      => $ticket_id,
				'wc_customer_id' => $wc_customer_id,
				'name'           => 'Fixture Guest',
				'email'          => $email,
				'phone'          => '',
				'status'         => 'confirmed',
				'tags'           => wp_json_encode( array() ),
				'notes'          => wp_json_encode( array() ),
				'created_at'     => current_time( 'mysql', true ),
				'updated_at'     => current_time( 'mysql', true ),
			)
		);

		return (int) $wpdb->insert_id;
	}
}

<?php
/**
 * WordPress-integration tests for the permanent Person identity resolver
 * and the historical CRM backfill.
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

use EventOS\Crm\Person_Backfill_Service;
use EventOS\Crm\Person_Identity_Repository;
use EventOS\Crm\Person_Repository;
use EventOS\Crm\Person_Resolver;
use EventOS\Crm\Person_Schema;
use EventOS\Crm\Person_Timeline_Service;
use EventOS\Events\Event_Schema;
use WP_UnitTestCase;

/**
 * Covers the scenarios named in the Phase 2 brief: idempotency across
 * repeated resolution and repeated backfill runs, email normalization,
 * purchaser-vs-ticket-holder independence, conflict detection without
 * auto-merge, and the "never blank a meaningful value" profile rule.
 */
final class Person_Resolver_Test extends WP_UnitTestCase {

	private Person_Repository $persons;
	private Person_Identity_Repository $identities;
	private Person_Timeline_Service $timeline;
	private Person_Resolver $resolver;

	public function set_up(): void {
		parent::set_up();

		Person_Schema::install();
		Event_Schema::install();

		$this->persons    = new Person_Repository();
		$this->identities = new Person_Identity_Repository();
		$this->timeline   = new Person_Timeline_Service();
		$this->resolver   = new Person_Resolver( $this->persons, $this->identities, $this->timeline );
	}

	/** TEST 1 — a registered customer resolves to one Person; running it again returns the same Person. */
	public function test_registered_wc_customer_resolves_to_one_person_and_is_idempotent(): void {
		$signals = array(
			'wc_customer_id' => 501,
			'email'          => 'jane@example.com',
			'name'           => 'Jane Doe',
			'source'         => 'test',
		);

		$first  = $this->resolver->find_or_create( $signals );
		$second = $this->resolver->find_or_create( $signals );

		$this->assertTrue( $first['created'] );
		$this->assertFalse( $second['created'] );
		$this->assertSame( $first['person']['id'], $second['person']['id'] );
		$this->assertSame( 'attached', $first['attachments']['wc_customer_id']['status'] );
		$this->assertSame( 'already_attached', $second['attachments']['wc_customer_id']['status'] );
	}

	/** TEST 2 — the same customer appearing via two different event/guest occurrences is still one Person. */
	public function test_same_customer_across_two_events_produces_one_person(): void {
		$base = array(
			'wc_customer_id' => 77,
			'email'          => 'sam@example.com',
			'name'           => 'Sam Smith',
			'source'         => 'guest_backfill',
		);

		$event_2023 = $this->resolver->find_or_create( array_merge( $base, array( 'source_id' => 'guest-1' ) ) );
		$event_2024 = $this->resolver->find_or_create( array_merge( $base, array( 'source_id' => 'guest-2' ) ) );

		$this->assertSame( $event_2023['person']['id'], $event_2024['person']['id'] );
		$this->assertCount( 2, $this->identities->for_person( (int) $event_2023['person']['id'] ) );
	}

	/** TEST 3 — a guest-checkout purchaser (wc_customer_id = 0) in two events, same email, is one Person. */
	public function test_guest_checkout_same_email_across_events_resolves_to_one_person(): void {
		$first  = $this->resolver->find_or_create(
			array( 'wc_customer_id' => 0, 'email' => 'guest@example.com', 'name' => 'Guest Buyer', 'source_id' => 'g1' )
		);
		$second = $this->resolver->find_or_create(
			array( 'wc_customer_id' => 0, 'email' => 'guest@example.com', 'name' => 'Guest Buyer', 'source_id' => 'g2' )
		);

		$this->assertSame( $first['person']['id'], $second['person']['id'] );
		$this->assertFalse( $second['created'] );
	}

	/** TEST 4 — capitalization/whitespace variants of the same email resolve to the same identity. */
	public function test_email_capitalization_and_whitespace_variants_resolve_to_same_person(): void {
		$first  = $this->resolver->find_or_create( array( 'email' => 'John@Example.com' ) );
		$second = $this->resolver->find_or_create( array( 'email' => ' john@example.com ' ) );

		$this->assertSame( $first['person']['id'], $second['person']['id'] );
		$this->assertSame( 'john@example.com', $first['person']['primary_email'] );
	}

	/**
	 * TEST 5 — purchaser and ticket holder are different people. Uses
	 * deliberately distinct synthetic emails: today's real
	 * Ticket_Fulfillment::fulfil_order() stamps the purchaser's own
	 * billing email onto every guest row it creates, so real historical
	 * data won't yet exercise this — this proves the resolver logic keeps
	 * genuinely distinct signals separate, ready for whenever a later
	 * phase adds per-attendee detail collection.
	 */
	public function test_purchaser_and_distinct_ticket_holder_resolve_to_separate_persons(): void {
		$purchaser = $this->resolver->find_or_create( array( 'wc_customer_id' => 900, 'email' => 'sarah@example.com', 'name' => 'Sarah' ) );
		$holder    = $this->resolver->find_or_create( array( 'wc_customer_id' => 0, 'email' => 'john.holder@example.com', 'name' => 'John' ) );

		$this->assertNotSame( $purchaser['person']['id'], $holder['person']['id'] );
	}

	/** TEST 6 — a ticket holder who later purchases under their own account is resolved to their existing Person. */
	public function test_ticket_holder_later_becoming_purchaser_reuses_existing_person(): void {
		$as_holder = $this->resolver->find_or_create(
			array( 'wc_customer_id' => 0, 'email' => 'john@example.com', 'name' => 'John Smith', 'source' => 'guest_backfill' )
		);

		$as_purchaser = $this->resolver->find_or_create(
			array( 'wc_customer_id' => 1234, 'email' => 'john@example.com', 'name' => 'John Smith', 'source' => 'wc_customer_backfill' )
		);

		$this->assertSame( $as_holder['person']['id'], $as_purchaser['person']['id'] );
		$this->assertFalse( $as_purchaser['created'] );
		$this->assertSame( 'attached', $as_purchaser['attachments']['wc_customer_id']['status'] );
	}

	/** TEST 7 — signals pointing to two different existing Persons detect a conflict and never merge. */
	public function test_conflicting_signals_detect_conflict_without_merging(): void {
		$person_a = $this->resolver->find_or_create( array( 'wc_customer_id' => 42, 'email' => 'a@example.com' ) );
		$person_b = $this->resolver->find_or_create( array( 'email' => 'b@example.com' ) );

		$result = $this->resolver->find_or_create( array( 'wc_customer_id' => 42, 'email' => 'b@example.com' ) );

		$this->assertSame( $person_a['person']['id'], $result['person']['id'], 'wc_customer_id, priority 1, wins the resolved Person.' );
		$this->assertNotNull( $result['conflict'] );
		$this->assertSame( $person_b['person']['id'], $result['conflict']['owner_person_id'] );

		$b_identity = $this->identities->find_by_type_value( 'email', 'b@example.com' );
		$this->assertSame( $person_b['person']['id'], (int) $b_identity['person_id'], "Person B's email must not have been reassigned." );
	}

	/** TEST 8 — a meaningful existing phone/name is never blanked by a later source with empty values. */
	public function test_existing_meaningful_profile_data_is_not_blanked(): void {
		$this->resolver->find_or_create( array( 'email' => 'keep@example.com', 'name' => 'Keep Me', 'phone' => '0821234567' ) );

		$second = $this->resolver->find_or_create( array( 'email' => 'keep@example.com', 'name' => '', 'phone' => '' ) );

		$this->assertSame( 'Keep Me', $second['person']['display_name'] );
		$this->assertSame( '0821234567', $second['person']['primary_phone'] );
	}

	/** TEST 9 — running the full backfill twice produces no duplicate Persons or identities. */
	public function test_backfill_run_twice_creates_no_duplicates(): void {
		global $wpdb;

		self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'backfill-customer@example.com' ) );

		$wpdb->insert(
			Event_Schema::events(),
			array(
				'title'      => 'Backfill Test Event',
				'slug'       => 'backfill-test-event-' . wp_generate_uuid4(),
				'status'     => 'published',
				'timezone'   => 'UTC',
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			)
		);
		$event_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			Event_Schema::guests(),
			array(
				'event_id'       => $event_id,
				'ticket_id'      => 0,
				'wc_customer_id' => 0,
				'name'           => 'Guest Checkout Person',
				'email'          => 'guest-only@example.com',
				'phone'          => '',
				'status'         => 'confirmed',
				'tags'           => wp_json_encode( array() ),
				'notes'          => wp_json_encode( array() ),
				'created_at'     => current_time( 'mysql', true ),
				'updated_at'     => current_time( 'mysql', true ),
			)
		);

		$first_run = Person_Backfill_Service::start();
		self::run_to_completion( (int) $first_run['id'] );

		$persons_table          = Person_Schema::persons();
		$identities_table       = Person_Schema::person_identities();
		$persons_after_first    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$persons_table}" );
		$identities_after_first = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$identities_table}" );

		$second_run = Person_Backfill_Service::start();
		self::run_to_completion( (int) $second_run['id'] );

		$persons_after_second    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$persons_table}" );
		$identities_after_second = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$identities_table}" );

		$this->assertSame( $persons_after_first, $persons_after_second );
		$this->assertSame( $identities_after_first, $identities_after_second );
		$this->assertGreaterThanOrEqual( 2, $persons_after_first, 'Expected at least the WC customer and the guest-checkout Person.' );
	}

	/** TEST 10 — a guest with no WooCommerce customer ID still resolves via email. */
	public function test_guest_without_wc_customer_id_resolves_via_email(): void {
		$result = $this->resolver->find_or_create( array( 'wc_customer_id' => 0, 'email' => 'noaccountneeded@example.com', 'name' => 'No Account' ) );

		$this->assertTrue( $result['created'] );
		$this->assertSame( 'noaccountneeded@example.com', $result['person']['primary_email'] );
	}

	/** Phone is stored as profile data but is never used to find or merge a Person. */
	public function test_phone_is_never_used_as_a_matching_signal(): void {
		$first  = $this->resolver->find_or_create( array( 'email' => 'phone-a@example.com', 'phone' => '0821234567' ) );
		$second = $this->resolver->find_or_create( array( 'email' => 'phone-b@example.com', 'phone' => '0821234567' ) );

		$this->assertNotSame( $first['person']['id'], $second['person']['id'], 'Two different emails sharing a phone number must not be merged.' );
		$this->assertNull( $this->identities->find_by_type_value( 'phone', '0821234567' ), 'phone must never appear as an identity row.' );
	}

	/** A shared name alone is never a strong enough signal to merge two different people. */
	public function test_same_name_different_email_does_not_merge(): void {
		$first  = $this->resolver->find_or_create( array( 'name' => 'John Smith', 'email' => 'john@gmail.com' ) );
		$second = $this->resolver->find_or_create( array( 'name' => 'John Smith', 'email' => 'john@hotmail.com' ) );

		$this->assertNotSame( $first['person']['id'], $second['person']['id'] );
	}

	/**
	 * Drive a backfill run's batches directly (bypassing Job_Queue's own
	 * cron execution, which is out of scope here — this only exercises
	 * Person_Backfill_Service's own state machine).
	 *
	 * @param int $run_id    Run ID.
	 * @param int $max_ticks Safety limit so a stuck run fails the test
	 *                       instead of looping forever.
	 * @return void
	 */
	private static function run_to_completion( int $run_id, int $max_ticks = 50 ): void {
		for ( $i = 0; $i < $max_ticks; $i++ ) {
			$run = Person_Backfill_Service::run( $run_id );

			if ( null === $run || 'complete' === $run['status'] ) {
				return;
			}

			Person_Backfill_Service::handle_job( array( 'run_id' => $run_id ) );
		}

		self::fail( 'Backfill run did not complete within the tick budget.' );
	}
}

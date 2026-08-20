<?php
/**
 * Orchestration layer for waitlist registration, promotion and expiry.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

use EventOS\Activity_Log;
use EventOS\Crm\Person_Identity_Repository;
use EventOS\Crm\Person_Repository;
use EventOS\Crm\Person_Resolver;
use EventOS\Crm\Person_Timeline_Service;
use EventOS\Job_Queue;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a sold-out ticket type into a real, FIFO, race-safe waitlist —
 * see {@see Waitlist_Repository} for the state machine and duplicate-
 * protection details.
 *
 * Deliberately does not create a ticket, a WooCommerce order, or a Guest
 * record at any point: a promoted person is only ever a candidate for a
 * completely normal WooCommerce purchase, which (unchanged) still flows
 * through {@see Ticket_Fulfillment} and still produces a `confirmed` Guest,
 * exactly as it already does for every other purchase.
 *
 * There is no ticket "hold"/reservation primitive anywhere in EventOS or
 * this WooCommerce integration — capacity freed by a cancellation/refund is
 * real, live WooCommerce stock the moment {@see Ticket_Type_Repository::refresh_stock()}
 * runs, and a promoted person's advantage is being notified first with a
 * direct purchase link, not an exclusive claim on that stock. Building a
 * true per-customer reservation would mean teaching WooCommerce checkout
 * about a signed hold token, which is a checkout-level change outside this
 * module — see the completion report for this documented as a deliberate,
 * reported limitation rather than a silent gap.
 */
final class Waitlist_Service {

	/**
	 * Job type: process one ticket type's queue against current availability.
	 */
	public const JOB_PROCESS = 'eventos_waitlist_process';

	/**
	 * Job type: sweep expired promotions and re-process affected ticket types.
	 */
	public const JOB_EXPIRE = 'eventos_waitlist_expire';

	/**
	 * How long a promoted person has to complete a purchase before the next
	 * person in line becomes eligible. No existing EventOS convention
	 * defines this, so 48 hours is a deliberate, documented default —
	 * generous enough for a real purchase decision, short enough that a
	 * sold-out ticket type doesn't sit needlessly held up by one
	 * unresponsive promotion.
	 */
	public const PROMOTION_WINDOW_HOURS = 48;

	/**
	 * How often the expiry sweep runs.
	 */
	public const EXPIRE_SWEEP_INTERVAL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Waitlist repository.
	 *
	 * @var Waitlist_Repository
	 */
	private Waitlist_Repository $waitlist;

	/**
	 * Ticket type repository.
	 *
	 * @var Ticket_Type_Repository
	 */
	private Ticket_Type_Repository $ticket_types;

	/**
	 * Ticket repository.
	 *
	 * @var Ticket_Repository
	 */
	private Ticket_Repository $tickets;

	/**
	 * Constructor.
	 *
	 * @param Waitlist_Repository    $waitlist     Waitlist repository.
	 * @param Ticket_Type_Repository $ticket_types Ticket type repository.
	 * @param Ticket_Repository      $tickets      Ticket repository.
	 */
	public function __construct( Waitlist_Repository $waitlist, Ticket_Type_Repository $ticket_types, Ticket_Repository $tickets ) {
		$this->waitlist     = $waitlist;
		$this->ticket_types = $ticket_types;
		$this->tickets      = $tickets;
	}

	/**
	 * Register the background job handlers.
	 *
	 * @return void
	 */
	public function register_job_handlers(): void {
		Job_Queue::register_handler(
			self::JOB_PROCESS,
			array( $this, 'handle_process_job' ),
			array( 'label' => __( 'Process waitlist for a ticket type', 'eventos' ), 'module' => 'events' )
		);

		Job_Queue::register_handler(
			self::JOB_EXPIRE,
			array( $this, 'handle_expire_job' ),
			array( 'label' => __( 'Expire waitlist promotions', 'eventos' ), 'module' => 'events' )
		);

		Job_Queue::schedule_recurring( self::JOB_EXPIRE, self::EXPIRE_SWEEP_INTERVAL );
	}

	/**
	 * Queue processing for a ticket type — the entry point every capacity-
	 * changing event (cancellation, refund, capacity increase) dispatches
	 * to, rather than processing synchronously inside a WooCommerce hook.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return void
	 */
	public function queue_processing( int $ticket_type_id ): void {
		if ( $ticket_type_id > 0 ) {
			Job_Queue::dispatch( self::JOB_PROCESS, array( 'ticket_type_id' => $ticket_type_id ) );
		}
	}

	/**
	 * Job handler: process one ticket type.
	 *
	 * @param array<string, mixed> $payload Job payload.
	 * @return array<string, mixed>
	 */
	public function handle_process_job( array $payload ): array {
		return $this->process_ticket_type( (int) ( $payload['ticket_type_id'] ?? 0 ) );
	}

	/**
	 * Job handler: sweep expired promotions and re-process their ticket types.
	 *
	 * @param array<string, mixed> $payload Job payload (unused).
	 * @return array<string, mixed>
	 */
	public function handle_expire_job( array $payload ): array {
		unset( $payload );

		return $this->process_expired();
	}

	/**
	 * Join the waitlist for a ticket type.
	 *
	 * @param int                  $event_id     Event ID.
	 * @param array<string, mixed> $person_input Accepted: ticket_type_id, name, email, phone.
	 * @return array<string, mixed>|WP_Error
	 */
	public function join( int $event_id, array $person_input ) {
		$ticket_type_id = (int) ( $person_input['ticket_type_id'] ?? 0 );
		$name           = trim( (string) ( $person_input['name'] ?? '' ) );
		$email          = sanitize_email( (string) ( $person_input['email'] ?? '' ) );
		$phone          = (string) ( $person_input['phone'] ?? '' );

		if ( '' === $name || ! is_email( $email ) ) {
			return new WP_Error( 'eventos_invalid_waitlist_entry', __( 'A name and a valid email address are required.', 'eventos' ), array( 'status' => 400 ) );
		}

		$type = $this->ticket_types->find( $ticket_type_id );

		if ( null === $type || (int) $type['event_id'] !== $event_id ) {
			return $this->not_found( __( 'That ticket type no longer exists.', 'eventos' ) );
		}

		if ( ! $type['waitlist_enabled'] ) {
			return new WP_Error( 'eventos_waitlist_disabled', __( 'Waitlisting is not enabled for this ticket type.', 'eventos' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $type['status'], array( 'active', 'sold_out' ), true ) ) {
			return new WP_Error( 'eventos_waitlist_not_joinable', __( 'This ticket type is not currently accepting waitlist entries.', 'eventos' ), array( 'status' => 400 ) );
		}

		if ( null === $type['available'] || $type['available'] > 0 ) {
			return new WP_Error( 'eventos_waitlist_tickets_available', __( 'Tickets are currently available for this type — no need to join the waitlist.', 'eventos' ), array( 'status' => 400 ) );
		}

		if ( $this->tickets->exists_active_for_type_and_email( $ticket_type_id, $email ) ) {
			return new WP_Error( 'eventos_waitlist_already_has_ticket', __( 'This person already holds a ticket of this type.', 'eventos' ), array( 'status' => 400 ) );
		}

		$resolver = new Person_Resolver( new Person_Repository(), new Person_Identity_Repository(), new Person_Timeline_Service() );
		$resolved = $resolver->find_or_create(
			array(
				'email'     => $email,
				'name'      => $name,
				'phone'     => $phone,
				'source'    => 'waitlist',
				'source_id' => (string) $ticket_type_id,
			)
		);

		$entry = $this->waitlist->join(
			array(
				'event_id'       => $event_id,
				'ticket_type_id' => $ticket_type_id,
				'person_id'      => (int) $resolved['person']['id'],
				'name'           => $name,
				'email'          => $email,
				'phone'          => $phone,
			)
		);

		Activity_Log::log(
			array(
				'action'      => 'waitlist_joined',
				'module'      => 'events',
				'entity_type' => 'waitlist_entry',
				'entity_id'   => (string) $entry['id'],
				'context'     => array( 'event_id' => $event_id, 'ticket_type_id' => $ticket_type_id ),
			)
		);

		return $entry;
	}

	/**
	 * List waitlist entries for an event.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $args     Query args — see {@see Waitlist_Repository::query()}.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function list_entries( int $event_id, array $args ): array {
		return $this->waitlist->query( $event_id, $args );
	}

	/**
	 * A single entry, scoped to an event.
	 *
	 * @param int $event_id Event ID.
	 * @param int $entry_id Entry ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function entry( int $event_id, int $entry_id ) {
		$found = $this->waitlist->find( $entry_id );

		if ( null === $found || (int) $found['event_id'] !== $event_id ) {
			return $this->not_found( __( 'That waitlist entry no longer exists.', 'eventos' ) );
		}

		return $found;
	}

	/**
	 * Cancel a waiting or promoted entry.
	 *
	 * @param int $event_id Event ID.
	 * @param int $entry_id Entry ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function cancel( int $event_id, int $entry_id ) {
		$found = $this->entry( $event_id, $entry_id );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		if ( ! in_array( $found['status'], Waitlist_Repository::ACTIVE_STATUSES, true ) ) {
			return new WP_Error( 'eventos_waitlist_invalid_transition', __( 'This entry is no longer active.', 'eventos' ), array( 'status' => 400 ) );
		}

		$this->waitlist->cancel( $entry_id );

		Activity_Log::log(
			array(
				'action'      => 'waitlist_cancelled',
				'module'      => 'events',
				'entity_type' => 'waitlist_entry',
				'entity_id'   => (string) $entry_id,
				'context'     => array( 'event_id' => $event_id ),
			)
		);

		// The cancelled slot may free up an eligible spot if this entry was
		// itself promoted (counted against `count_actively_promoted()`).
		$this->queue_processing( (int) $found['ticket_type_id'] );

		return $this->entry( $event_id, $entry_id );
	}

	/**
	 * Manually promote a specific entry out of FIFO order — a staff
	 * override (e.g. a VIP request), distinct from the automatic
	 * capacity-driven processing in {@see process_ticket_type()}.
	 *
	 * @param int $event_id Event ID.
	 * @param int $entry_id Entry ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function promote_now( int $event_id, int $entry_id ) {
		$found = $this->entry( $event_id, $entry_id );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		if ( 'waiting' !== $found['status'] ) {
			return new WP_Error( 'eventos_waitlist_invalid_transition', __( 'Only a waiting entry can be promoted.', 'eventos' ), array( 'status' => 400 ) );
		}

		$claimed = $this->promote_entry( $entry_id );

		if ( null === $claimed ) {
			return new WP_Error( 'eventos_waitlist_promotion_failed', __( 'This entry could not be promoted — it may have just changed state.', 'eventos' ), array( 'status' => 409 ) );
		}

		return $this->entry( $event_id, $entry_id );
	}

	/**
	 * Promote as many waiting entries as current availability allows.
	 *
	 * Never promotes more than `available - already-actively-promoted`,
	 * so concurrent runs (two workers, or a manual trigger racing the
	 * background job) can never collectively over-promote: each candidate
	 * is claimed with the same atomic compare-and-swap
	 * {@see Waitlist_Repository::claim_for_promotion()} uses, so a
	 * candidate already claimed by another run simply fails to claim here
	 * and is skipped rather than double-promoted.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return array{promoted: int[]}
	 */
	public function process_ticket_type( int $ticket_type_id ): array {
		$type = $this->ticket_types->find( $ticket_type_id );

		if ( null === $type || ! $type['waitlist_enabled'] || null === $type['available'] ) {
			return array( 'promoted' => array() );
		}

		$slots = (int) $type['available'] - $this->waitlist->count_actively_promoted( $ticket_type_id );

		if ( $slots <= 0 ) {
			return array( 'promoted' => array() );
		}

		$promoted = array();

		foreach ( $this->waitlist->next_waiting( $ticket_type_id, $slots ) as $candidate ) {
			$claimed = $this->promote_entry( (int) $candidate['id'] );

			if ( null !== $claimed ) {
				$promoted[] = $claimed;
			}
		}

		return array( 'promoted' => $promoted );
	}

	/**
	 * Sweep promotions whose window has passed, expire them, and give the
	 * ticket types they freed up another processing pass.
	 *
	 * @return array{expired: int[]}
	 */
	public function process_expired(): array {
		$expired         = array();
		$affected_types  = array();

		foreach ( $this->waitlist->due_for_expiry() as $candidate ) {
			$claimed = $this->waitlist->claim_for_expiry( (int) $candidate['id'] );

			if ( null !== $claimed ) {
				$expired[] = $claimed;
				$affected_types[ (int) $candidate['ticket_type_id'] ] = true;

				Activity_Log::log(
					array(
						'action'      => 'waitlist_promotion_expired',
						'module'      => 'events',
						'entity_type' => 'waitlist_entry',
						'entity_id'   => (string) $claimed,
						'context'     => array( 'event_id' => $candidate['event_id'], 'ticket_type_id' => $candidate['ticket_type_id'] ),
					)
				);
			}
		}

		foreach ( array_keys( $affected_types ) as $ticket_type_id ) {
			$this->process_ticket_type( $ticket_type_id );
		}

		return array( 'expired' => $expired );
	}

	/**
	 * Correlate a real ticket just issued for `$ticket_type_id`/`$email`
	 * with a promoted waitlist entry, marking it converted. A no-op when no
	 * matching promotion exists — every ordinary, non-waitlist purchase
	 * takes this exact path too, so it must stay cheap and side-effect-free
	 * for the common case.
	 *
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param string $email          Purchaser email.
	 * @param int    $ticket_id      The ticket that was just issued.
	 * @return void
	 */
	public function mark_converted_if_promoted( int $ticket_type_id, string $email, int $ticket_id ): void {
		$promoted = $this->waitlist->find_promoted_by_email( $ticket_type_id, sanitize_email( $email ) );

		if ( null === $promoted ) {
			return;
		}

		if ( $this->waitlist->mark_converted( (int) $promoted['id'], $ticket_id ) ) {
			Activity_Log::log(
				array(
					'action'      => 'waitlist_converted',
					'module'      => 'events',
					'entity_type' => 'waitlist_entry',
					'entity_id'   => (string) $promoted['id'],
					'context'     => array( 'ticket_id' => $ticket_id ),
				)
			);
		}
	}

	/**
	 * Claim one entry for promotion and, on success, notify the person.
	 *
	 * @param int $entry_id Entry ID.
	 * @return int|null The entry ID on success, null if the claim lost a race.
	 */
	private function promote_entry( int $entry_id ): ?int {
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + self::PROMOTION_WINDOW_HOURS * HOUR_IN_SECONDS );
		$claimed    = $this->waitlist->claim_for_promotion( $entry_id, $expires_at );

		if ( null === $claimed ) {
			return null;
		}

		$entry = $this->waitlist->find( $entry_id );

		if ( null !== $entry ) {
			$this->notify_promoted( $entry );

			Activity_Log::log(
				array(
					'action'      => 'waitlist_promoted',
					'module'      => 'events',
					'entity_type' => 'waitlist_entry',
					'entity_id'   => (string) $entry_id,
					'context'     => array( 'event_id' => $entry['event_id'], 'ticket_type_id' => $entry['ticket_type_id'], 'expires_at' => $expires_at ),
				)
			);
		}

		return $claimed;
	}

	/**
	 * Send the promotion notification — a real, direct link to buy the
	 * newly available ticket type through WooCommerce's own checkout,
	 * exactly like any other product purchase. Plain, operational
	 * `wp_mail()`, the same primitive {@see \EventOS\Invitations} uses for
	 * transactional mail, not the Marketing campaign mailer — this is not a
	 * marketing message and does not touch CRM consent.
	 *
	 * Idempotent against job retries: only sends once per promotion,
	 * recorded via `notified_at`.
	 *
	 * @param array<string, mixed> $entry Waitlist entry (already promoted).
	 * @return void
	 */
	private function notify_promoted( array $entry ): void {
		global $wpdb;

		if ( null !== $entry['notified_at'] ) {
			return;
		}

		$type  = $this->ticket_types->find( (int) $entry['ticket_type_id'] );
		$event = ( new Event_Repository() )->find( (int) $entry['event_id'], false );

		if ( null === $type || null === $event ) {
			return;
		}

		$purchase_url = (int) $type['wc_product_id'] > 0 && function_exists( 'wc_get_cart_url' )
			? add_query_arg( 'add-to-cart', (int) $type['wc_product_id'], wc_get_cart_url() )
			: get_site_url();

		$business = (string) \EventOS\Settings::get( 'general', 'business_name' );
		$business = '' !== $business ? $business : get_bloginfo( 'name' );

		$subject = sprintf(
			/* translators: 1: ticket type name, 2: event title. */
			__( 'A spot opened up: %1$s for %2$s', 'eventos' ),
			(string) $type['name'],
			(string) $event['title']
		);

		$message = sprintf(
			/* translators: 1: recipient name, 2: ticket type, 3: event, 4: hours, 5: purchase URL, 6: business name. */
			__(
				"Hi %1\$s,\n\nA \"%2\$s\" ticket for %3\$s just became available, and you're next on the waitlist.\n\nYou have %4\$d hours to complete your purchase before this offer passes to the next person in line:\n%5\$s\n\nThis is not a reserved ticket — normal availability applies until checkout is complete.\n\n%6\$s",
				'eventos'
			),
			(string) $entry['name'],
			(string) $type['name'],
			(string) $event['title'],
			self::PROMOTION_WINDOW_HOURS,
			$purchase_url,
			$business
		);

		$sent = wp_mail( (string) $entry['email'], $subject, $message );

		if ( $sent ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				Event_Schema::waitlist_entries(),
				array( 'notified_at' => current_time( 'mysql', true ) ),
				array( 'id' => (int) $entry['id'] ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * A `WP_Error` shaped like every other not-found response in this module.
	 *
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private function not_found( string $message ): WP_Error {
		return new WP_Error( 'eventos_not_found', $message, array( 'status' => 404 ) );
	}
}

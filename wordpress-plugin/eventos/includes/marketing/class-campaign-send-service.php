<?php
/**
 * Orchestrates campaign message send preparation, delivery and unsubscribe.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Marketing;

use EventOS\Crm\Person_Consent_Repository;
use EventOS\Crm\Person_Identity_Repository;
use EventOS\Crm\Person_Repository;
use EventOS\Events\Campaign_Repository;
use EventOS\Events\Event_Schema;
use EventOS\Job_Queue;
use EventOS\Rest\Rest_Registry;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ties together everything Sprint 3 adds: a campaign's message
 * ({@see Campaign_Message_Repository}), its frozen recipient snapshot
 * ({@see Campaign_Recipient_Repository}), the existing Audience CRM
 * ({@see Audience_Resolver}, {@see Person_Repository},
 * {@see Person_Consent_Repository}) and delivery
 * ({@see Marketing_Mail_Service}, {@see Personalization_Renderer}) — plus
 * the existing {@see Job_Queue} for batched sending, following exactly the
 * self-requeuing pattern {@see \EventOS\Crm\Person_Backfill_Service} already
 * established (one job invocation processes one batch, then dispatches its
 * own successor if work remains).
 *
 * `prepare()` is the one-way "resolve audience -> Person IDs -> permanent
 * snapshot" step: once recipient rows exist for a campaign, sending never
 * re-consults Audience_Resolver again — see Campaign_Recipient_Repository's
 * class docblock. Consent is checked once, at that same moment, against
 * {@see Person_Consent_Repository}'s existing `channel` model — this reuses
 * the `'email'` channel the CRM's own "Marketing consent" panel already
 * writes to, rather than inventing a second, disconnected consent concept.
 */
final class Campaign_Send_Service {

	/**
	 * Job_Queue job type this service registers a handler for.
	 */
	public const JOB_TYPE = 'eventos_marketing_send_batch';

	/**
	 * Recipients attempted per batch job. Smaller than
	 * Person_Backfill_Service's 100-row batches because a wp_mail() round
	 * trip is far more expensive per item than a metrics recompute — this
	 * keeps one batch comfortably inside a normal PHP request's time limit.
	 */
	public const BATCH_SIZE = 25;

	/**
	 * Attempts allowed for a single recipient before it becomes a terminal
	 * 'failed' row instead of being retried by the next batch.
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * The Person_Consent_Repository channel that means "may receive
	 * marketing e-mail" — reuses the same `'email'` channel the existing
	 * CRM Person profile's "Marketing consent" panel already grants/revokes
	 * against, per that class's own "channel is a plain string, not an
	 * enum" design (see Person_Consent_Repository's docblock). Deliberately
	 * not a new/different string — using anything else here would fragment
	 * consent state between what a promoter sees on a Person's profile and
	 * what Marketing actually checks before sending.
	 */
	public const CONSENT_CHANNEL = 'email';

	private Campaign_Repository $campaigns;
	private Campaign_Message_Repository $messages;
	private Campaign_Recipient_Repository $recipients;
	private Audience_Repository $audiences;
	private Audience_Resolver $audience_resolver;
	private Person_Repository $persons;
	private Person_Identity_Repository $identities;
	private Person_Consent_Repository $consent;
	private Marketing_Mail_Service $mailer;
	private Personalization_Renderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository          $campaigns         Campaign repository.
	 * @param Campaign_Message_Repository  $messages          Message repository.
	 * @param Campaign_Recipient_Repository $recipients        Recipient snapshot repository.
	 * @param Audience_Repository          $audiences         Audience repository.
	 * @param Audience_Resolver            $audience_resolver Audience resolver.
	 * @param Person_Repository            $persons           Person repository.
	 * @param Person_Identity_Repository   $identities        Person identity repository.
	 * @param Person_Consent_Repository    $consent           Person consent repository.
	 * @param Marketing_Mail_Service       $mailer            Mail delivery.
	 * @param Personalization_Renderer     $renderer          Token renderer.
	 */
	public function __construct(
		Campaign_Repository $campaigns,
		Campaign_Message_Repository $messages,
		Campaign_Recipient_Repository $recipients,
		Audience_Repository $audiences,
		Audience_Resolver $audience_resolver,
		Person_Repository $persons,
		Person_Identity_Repository $identities,
		Person_Consent_Repository $consent,
		Marketing_Mail_Service $mailer,
		Personalization_Renderer $renderer
	) {
		$this->campaigns         = $campaigns;
		$this->messages          = $messages;
		$this->recipients        = $recipients;
		$this->audiences         = $audiences;
		$this->audience_resolver = $audience_resolver;
		$this->persons           = $persons;
		$this->identities        = $identities;
		$this->consent           = $consent;
		$this->mailer            = $mailer;
		$this->renderer          = $renderer;
	}

	/**
	 * Register this service's Job_Queue handler. Must be called directly
	 * from the owning module's init() — not hung off the
	 * `eventos_register_jobs` hook, which fires from inside
	 * `Core_Module::init()` and has already run by the time a
	 * dependencies-on-core module like Events_Module reaches its own
	 * init(). See {@see \EventOS\Crm\Person_Backfill_Service}'s docblock for
	 * the same gotcha.
	 *
	 * @return void
	 */
	public function register_job_handler(): void {
		Job_Queue::register_handler(
			self::JOB_TYPE,
			array( $this, 'handle_job' ),
			array(
				'label'  => __( 'Send a batch of a Marketing campaign email', 'eventos' ),
				'module' => 'events',
			)
		);
	}

	/**
	 * Job_Queue handler callback.
	 *
	 * @param array<string, mixed> $payload Job payload.
	 * @param array<string, mixed> $job     Raw job row.
	 * @return array<string, int>
	 */
	public function handle_job( array $payload, array $job ): array {
		unset( $job );

		$campaign_id = (int) ( $payload['campaign_id'] ?? 0 );

		if ( $campaign_id <= 0 ) {
			return array( 'processed' => 0 );
		}

		return $this->process_batch( $campaign_id );
	}

	/**
	 * Read a campaign's message, if any.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<string, mixed>|null
	 */
	public function get_message( int $campaign_id ): ?array {
		return $this->messages->for_campaign( $campaign_id );
	}

	/**
	 * Create or update a campaign's message.
	 *
	 * @param int                  $campaign_id Campaign ID.
	 * @param array<string, mixed> $input       Field values.
	 * @return array<string, mixed>|WP_Error
	 */
	public function save_message( int $campaign_id, array $input ) {
		$campaign = $this->campaigns->find( $campaign_id );

		if ( null === $campaign ) {
			return $this->not_found();
		}

		return $this->messages->save( $campaign_id, $input );
	}

	/**
	 * Resolve a campaign's audience into a permanent recipient snapshot.
	 * Idempotent: rows already present from a prior call are left alone,
	 * only newly-eligible people (if the live audience grew since last
	 * prepare) are appended — but see the class docblock: once sending has
	 * started, calling this again does not remove or re-evaluate anyone
	 * already snapshotted.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function prepare( int $campaign_id ) {
		$campaign = $this->campaigns->find( $campaign_id );

		if ( null === $campaign ) {
			return $this->not_found();
		}

		if ( empty( $campaign['audience_id'] ) ) {
			return new WP_Error( 'eventos_no_audience', __( 'This campaign has no audience to resolve.', 'eventos' ), array( 'status' => 400 ) );
		}

		$message = $this->messages->for_campaign( $campaign_id );

		if ( null === $message ) {
			return new WP_Error( 'eventos_no_message', __( 'Add a message before preparing recipients.', 'eventos' ), array( 'status' => 400 ) );
		}

		$audience = $this->audiences->find( (int) $campaign['audience_id'] );

		if ( null === $audience ) {
			return new WP_Error( 'eventos_audience_missing', __( 'The linked audience no longer exists.', 'eventos' ), array( 'status' => 400 ) );
		}

		$person_ids = $this->audience_resolver->resolve( $audience );
		$existing   = $this->recipients->existing_person_ids( $campaign_id );
		$new_ids    = array_values( array_diff( $person_ids, $existing ) );

		$rows = array();

		foreach ( $new_ids as $person_id ) {
			$person = $this->persons->find_by_id( $person_id );

			if ( null === $person ) {
				continue;
			}

			$classification = $this->classify_recipient( $person );

			// The unsubscribe token is deliberately NOT generated here: only
			// its hash is ever persisted (see class docblock), so the raw
			// token has to be minted at send time, used immediately to build
			// that email's link, then discarded — generating it now would
			// have nothing to embed it in until process_batch() runs anyway.
			$rows[] = array_merge(
				array(
					'person_id' => $person_id,
					'email'     => (string) $person['primary_email'],
				),
				$classification
			);
		}

		$this->recipients->insert_batch( $campaign_id, $rows );

		if ( 'draft' === $message['status'] ) {
			$this->messages->set_status( (int) $message['id'], 'ready' );
		}

		return $this->recipients->counts( $campaign_id );
	}

	/**
	 * Classify a resolved Person into a recipient row's initial status.
	 *
	 * @param array<string, mixed> $person Hydrated Person row.
	 * @return array{status: string, skip_reason: string}
	 */
	private function classify_recipient( array $person ): array {
		$email     = (string) ( $person['primary_email'] ?? '' );
		$person_id = (int) $person['id'];

		if ( '' === $email || ! is_email( $email ) ) {
			return array( 'status' => 'invalid', 'skip_reason' => 'invalid_email' );
		}

		if ( $this->consent->has_active( $person_id, self::CONSENT_CHANNEL ) ) {
			return array( 'status' => 'pending', 'skip_reason' => '' );
		}

		if ( $this->consent->was_ever_granted( $person_id, self::CONSENT_CHANNEL ) ) {
			return array( 'status' => 'unsubscribed', 'skip_reason' => '' );
		}

		return array( 'status' => 'skipped', 'skip_reason' => 'no_consent' );
	}

	/**
	 * A page of a campaign's recipient snapshot, for the history/detail view.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $page        1-based page.
	 * @param int $per_page    Rows per page.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function recipients( int $campaign_id, int $page = 1, int $per_page = 50 ): array {
		return $this->recipients->for_campaign( $campaign_id, $page, $per_page );
	}

	/**
	 * Delivery status counts for a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<string, int>
	 */
	public function counts( int $campaign_id ): array {
		return $this->recipients->counts( $campaign_id );
	}

	/**
	 * Start (or resume) sending — dispatches the first Job_Queue batch.
	 * Safe to call again on a campaign that already has some 'sent'/'failed'
	 * rows: only 'pending' rows are ever selected by a batch, so this can
	 * never re-send anyone already delivered.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function send_now( int $campaign_id ) {
		$message = $this->messages->for_campaign( $campaign_id );

		if ( null === $message ) {
			return new WP_Error( 'eventos_no_message', __( 'This campaign has no message yet.', 'eventos' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $message['status'], array( 'ready', 'sending', 'failed' ), true ) ) {
			return new WP_Error( 'eventos_not_ready', __( 'Prepare recipients before sending.', 'eventos' ), array( 'status' => 400 ) );
		}

		// Composing/previewing/preparing a message is allowed at any
		// campaign status (an operator writes the e-mail in advance, then
		// activates the campaign and sends together) — but the actual send
		// is the one consequential, external action, and Campaign_Repository
		// only keeps the linked WooCommerce coupon published while the
		// campaign's own status is 'active' (draft/paused/archived/expired
		// all unpublish it, see sync_wc_coupon()). Sending mail that
		// advertises a coupon code which no longer works at checkout would
		// be genuinely misleading, so this is the one checkpoint that
		// enforces campaign status — not prepare()/save_message()/preview().
		$campaign = $this->campaigns->find( $campaign_id );

		if ( null === $campaign || 'active' !== $campaign['status'] ) {
			return new WP_Error(
				'eventos_campaign_not_active',
				__( 'Activate this campaign before sending — the linked discount code only works at checkout while the campaign is active.', 'eventos' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->recipients->has_pending( $campaign_id ) ) {
			return new WP_Error( 'eventos_nothing_to_send', __( 'There are no eligible recipients waiting to be sent to.', 'eventos' ), array( 'status' => 400 ) );
		}

		if ( 'sending' !== $message['status'] ) {
			$this->messages->set_status( (int) $message['id'], 'sending' );
		}

		Job_Queue::dispatch( self::JOB_TYPE, array( 'campaign_id' => $campaign_id ) );

		return array(
			'status' => 'sending',
			'counts' => $this->recipients->counts( $campaign_id ),
		);
	}

	/**
	 * Process one batch of pending recipients, then either requeue itself
	 * (more pending remain) or finalize the message's status.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<string, int>
	 */
	public function process_batch( int $campaign_id ): array {
		$message = $this->messages->for_campaign( $campaign_id );

		if ( null === $message ) {
			return array( 'processed' => 0 );
		}

		$campaign = $this->campaigns->find( $campaign_id );
		$event    = $campaign && (int) $campaign['event_id'] > 0 ? $this->event_summary( (int) $campaign['event_id'] ) : null;
		$batch    = $this->recipients->next_pending( $campaign_id, self::BATCH_SIZE );
		$processed = 0;

		foreach ( $batch as $row ) {
			++$processed;

			// Atomically claim this recipient before doing anything else —
			// if a concurrent process_batch() call already claimed it (a
			// UPDATE ... WHERE status = 'pending' racing this one), this
			// returns null and we must not attempt to send again. See
			// Campaign_Recipient_Repository::claim_for_sending().
			$attempt_count = $this->recipients->claim_for_sending( (int) $row['id'] );

			if ( null === $attempt_count ) {
				continue;
			}

			$person = $this->persons->find_by_id( (int) $row['person_id'] );

			if ( null === $person ) {
				$this->recipients->mark_failed( (int) $row['id'], 'person_not_found', $attempt_count, self::MAX_ATTEMPTS );
				continue;
			}

			// Audience membership stays frozen at prepare() time (see class
			// docblock) — but consent is a separate, live compliance check
			// that must still hold at the moment of send, not just at
			// prepare time. A row only ever reached 'pending' because
			// has_active() was true when prepare() ran, which also means
			// was_ever_granted() is already guaranteed true — so if consent
			// is no longer active here, the only correct outcome is
			// 'unsubscribed', the same status prepare() itself would have
			// assigned had the revocation happened a moment earlier.
			if ( ! $this->consent->has_active( (int) $row['person_id'], self::CONSENT_CHANNEL ) ) {
				$this->recipients->mark_unsubscribed( (int) $row['id'] );
				continue;
			}

			$ticket_summary = null !== $campaign ? $this->ticket_summary( (int) $row['person_id'], (int) $campaign['event_id'] ) : null;
			$context        = Personalization_Renderer::build_context( $person, $campaign, $event, $ticket_summary );

			// Mint the raw unsubscribe token now, persist only its hash, and
			// use the raw value immediately to build this one e-mail's link
			// — it is never stored or reused, exactly mirroring how
			// Invitations::create() mints and discards its raw token.
			$raw_token = wp_generate_password( 48, false, false );
			$this->recipients->set_unsubscribe_token_hash( (int) $row['id'], hash_hmac( 'sha256', $raw_token, wp_salt( 'auth' ) ) );
			$unsubscribe_url = $this->unsubscribe_url( $raw_token );

			$html = $this->renderer->render( (string) $message['body_html'], $context, true );
			$html .= $this->unsubscribe_footer_html( $unsubscribe_url );

			$text = $this->renderer->render( (string) $message['body_text'], $context, false );
			$text .= "\n\n" . sprintf( /* translators: %s: unsubscribe URL. */ __( 'Unsubscribe: %s', 'eventos' ), $unsubscribe_url );

			$subject = $this->renderer->render( (string) $message['subject'], $context, false );

			$result = $this->mailer->send(
				(string) $row['email'],
				$subject,
				$html,
				$text,
				(string) $message['sender_name'],
				(string) $message['sender_email'],
				(string) $message['reply_to']
			);

			if ( true === $result ) {
				$this->recipients->mark_sent( (int) $row['id'], wp_generate_uuid4() );
			} else {
				$this->recipients->mark_failed( (int) $row['id'], (string) $result, $attempt_count, self::MAX_ATTEMPTS );
			}
		}

		if ( $this->recipients->has_pending( $campaign_id ) ) {
			Job_Queue::dispatch( self::JOB_TYPE, array( 'campaign_id' => $campaign_id ) );
		} else {
			$counts     = $this->recipients->counts( $campaign_id );
			$attempted  = $counts['sent'] + $counts['failed'];
			$final      = ( 0 === $counts['sent'] && $counts['failed'] > 0 && $attempted > 0 ) ? 'failed' : 'sent';

			$this->messages->set_status( (int) $message['id'], $final );
		}

		return array( 'processed' => $processed );
	}

	/**
	 * Send an immediate, synchronous test e-mail using the current message
	 * content — never touches the recipient snapshot, so it cannot affect
	 * a real send's counts or history.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param string $test_email  Address to send the test to.
	 * @return true|WP_Error
	 */
	public function send_test( int $campaign_id, string $test_email ) {
		if ( ! is_email( $test_email ) ) {
			return new WP_Error( 'eventos_invalid_email', __( 'Enter a valid e-mail address for the test.', 'eventos' ), array( 'status' => 400 ) );
		}

		$message = $this->messages->for_campaign( $campaign_id );

		if ( null === $message ) {
			return new WP_Error( 'eventos_no_message', __( 'This campaign has no message yet.', 'eventos' ), array( 'status' => 400 ) );
		}

		$campaign = $this->campaigns->find( $campaign_id );
		$context  = $this->preview_context( $campaign );

		$subject = '[' . __( 'TEST', 'eventos' ) . '] ' . $this->renderer->render( (string) $message['subject'], $context, false );
		$html    = $this->renderer->render( (string) $message['body_html'], $context, true );
		$text    = $this->renderer->render( (string) $message['body_text'], $context, false );

		$result = $this->mailer->send(
			$test_email,
			$subject,
			$html,
			$text,
			(string) $message['sender_name'],
			(string) $message['sender_email'],
			(string) $message['reply_to']
		);

		return true === $result ? true : new WP_Error( 'eventos_test_send_failed', (string) $result, array( 'status' => 500 ) );
	}

	/**
	 * Render the message for the "Campaign preview" step — uses the current
	 * WordPress user as a stand-in personalization context (labeled as a
	 * preview, never stored, never counted as a send).
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function preview( int $campaign_id ) {
		$message = $this->messages->for_campaign( $campaign_id );

		if ( null === $message ) {
			return new WP_Error( 'eventos_no_message', __( 'This campaign has no message yet.', 'eventos' ), array( 'status' => 400 ) );
		}

		$campaign = $this->campaigns->find( $campaign_id );
		$context  = $this->preview_context( $campaign );

		return array(
			'subject' => $this->renderer->render( (string) $message['subject'], $context, false ),
			'html'    => $this->renderer->render( (string) $message['body_html'], $context, true ),
			'text'    => $this->renderer->render( (string) $message['body_text'], $context, false ),
		);
	}

	/**
	 * Personalization context for previews/test sends — the signed-in
	 * WordPress user standing in for a real recipient.
	 *
	 * @param array<string, mixed>|null $campaign Campaign row.
	 * @return array<string, string>
	 */
	private function preview_context( ?array $campaign ): array {
		$user = wp_get_current_user();

		$fake_person = array(
			'first_name'       => $user->first_name ? $user->first_name : __( 'Preview', 'eventos' ),
			'last_name'        => $user->last_name,
			'display_name'     => $user->display_name ? $user->display_name : __( 'Preview Recipient', 'eventos' ),
			'primary_email'    => $user->user_email,
			'total_spend'      => 0,
			'last_purchase_at' => null,
		);

		$event = $campaign && (int) $campaign['event_id'] > 0 ? $this->event_summary( (int) $campaign['event_id'] ) : null;

		return Personalization_Renderer::build_context( $fake_person, $campaign, $event, null );
	}

	/**
	 * Look up a recipient by unsubscribe token and revoke their marketing
	 * consent. Does not alter the recipient row's own delivery status —
	 * that row correctly stays 'sent' (it genuinely was), only future
	 * prepare() calls for other campaigns will now classify this Person as
	 * 'unsubscribed'.
	 *
	 * @param string $token Raw token from the unsubscribe link.
	 * @return array<string, mixed>|WP_Error
	 */
	public function unsubscribe( string $token ) {
		$recipient = $this->recipients->find_by_unsubscribe_token( $token );

		if ( null === $recipient ) {
			return new WP_Error( 'eventos_invalid_token', __( 'This unsubscribe link is invalid or has expired.', 'eventos' ), array( 'status' => 404 ) );
		}

		$this->consent->revoke( (int) $recipient['person_id'], self::CONSENT_CHANNEL );

		return array( 'unsubscribed' => true );
	}

	/**
	 * Standard 404.
	 *
	 * @return WP_Error
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'eventos_not_found', __( 'That record no longer exists.', 'eventos' ), array( 'status' => 404 ) );
	}

	/**
	 * Minimal event context {id, title} for personalization/subject lines.
	 *
	 * @param int $event_id Event ID.
	 * @return array<string, mixed>|null
	 */
	private function event_summary( int $event_id ): ?array {
		global $wpdb;

		$table = Event_Schema::events();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, title FROM {$table} WHERE id = %d", $event_id ), ARRAY_A );

		return $row ? array( 'id' => (int) $row['id'], 'title' => (string) $row['title'] ) : null;
	}

	/**
	 * A Person's ticket type/quantity for one event — the mirror image of
	 * {@see Audience_Resolver::event_ticket_holders()} (which goes
	 * ticket -> identity -> Person); this goes Person -> identity -> ticket,
	 * since personalization starts from a known Person, not a raw signal row.
	 *
	 * @param int $person_id Person ID.
	 * @param int $event_id  Event ID.
	 * @return array{ticket_type: string, quantity: int}|null
	 */
	private function ticket_summary( int $person_id, int $event_id ): ?array {
		if ( $event_id <= 0 ) {
			return null;
		}

		global $wpdb;

		$identities     = $this->identities->for_person( $person_id );
		$wc_customer_ids = array();
		$emails          = array();

		foreach ( $identities as $identity ) {
			if ( 'wc_customer_id' === $identity['type'] ) {
				$wc_customer_ids[] = (int) $identity['value'];
			} elseif ( 'email' === $identity['type'] ) {
				$emails[] = (string) $identity['value'];
			}
		}

		if ( ! $wc_customer_ids && ! $emails ) {
			return null;
		}

		$tickets      = Event_Schema::tickets();
		$guests       = Event_Schema::guests();
		$ticket_types = Event_Schema::ticket_types();

		$where  = array( 't.event_id = %d', "t.status != 'cancelled'" );
		$params = array( $event_id );

		$signal_where  = array();
		$signal_params = array();

		if ( $wc_customer_ids ) {
			$placeholders    = implode( ',', array_fill( 0, count( $wc_customer_ids ), '%d' ) );
			$signal_where[]  = "t.wc_customer_id IN ({$placeholders})";
			$signal_params   = array_merge( $signal_params, $wc_customer_ids );
		}

		if ( $emails ) {
			$placeholders   = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
			$signal_where[] = "g.email IN ({$placeholders})";
			$signal_params  = array_merge( $signal_params, $emails );
		}

		$where[]  = '(' . implode( ' OR ', $signal_where ) . ')';
		$params   = array_merge( $params, $signal_params );
		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.name AS ticket_type, COUNT(*) AS quantity
				FROM {$tickets} t
				LEFT JOIN {$guests} g ON g.id = t.guest_id
				LEFT JOIN {$ticket_types} tt ON tt.id = t.ticket_type_id
				WHERE {$where_sql}
				GROUP BY t.ticket_type_id, tt.name
				ORDER BY quantity DESC
				LIMIT 1",
				$params
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$tickets} t LEFT JOIN {$guests} g ON g.id = t.guest_id WHERE {$where_sql}", $params )
		);

		return array(
			'ticket_type' => (string) $rows[0]['ticket_type'],
			'quantity'    => $total,
		);
	}

	/**
	 * The public unsubscribe URL embedding a raw (not-yet-hashed) token.
	 *
	 * @param string $raw_token Raw token, freshly minted by the caller.
	 * @return string
	 */
	private function unsubscribe_url( string $raw_token ): string {
		return add_query_arg(
			array( 'token' => rawurlencode( $raw_token ) ),
			rest_url( Rest_Registry::rest_namespace() . '/marketing/unsubscribe' )
		);
	}

	/**
	 * Standard unsubscribe footer appended to every sent HTML body.
	 *
	 * @param string $url Unsubscribe URL.
	 * @return string
	 */
	private function unsubscribe_footer_html( string $url ): string {
		return sprintf(
			'<p style="margin-top:24px;font-size:12px;color:#888;">%s</p>',
			sprintf(
				/* translators: %s: unsubscribe link. */
				esc_html__( 'If you no longer want to receive marketing e-mail like this, %s.', 'eventos' ),
				'<a href="' . esc_url( $url ) . '">' . esc_html__( 'unsubscribe here', 'eventos' ) . '</a>'
			)
		);
	}
}

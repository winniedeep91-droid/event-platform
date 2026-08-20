<?php
/**
 * Data access for a campaign's recipient snapshot.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This table *is* the recipient snapshot the Sprint 3 brief requires: once
 * {@see Campaign_Send_Service::prepare()} writes these rows, the live
 * audience is never re-resolved for that campaign's send — every read here
 * is against this frozen table, not against Audience_Resolver. Rows are
 * append-only for a given (campaign_id, person_id) pair (enforced by the
 * table's UNIQUE key); re-running prepare() is idempotent and only inserts
 * people not already present, it never removes an existing recipient.
 */
final class Campaign_Recipient_Repository {

	/**
	 * Statuses a recipient never gets sent for (excluded at prepare-time).
	 */
	public const EXCLUDED_STATUSES = array( 'skipped', 'unsubscribed', 'invalid' );

	/**
	 * Insert a batch of recipient rows for a campaign, skipping any
	 * (campaign_id, person_id) pair already present — safe to call more
	 * than once for the same campaign (e.g. an operator re-opening the
	 * "Recipients" step before ever sending).
	 *
	 * @param int                               $campaign_id Campaign ID.
	 * @param array<int, array<string, mixed>>   $rows        Rows: person_id, email, status, skip_reason, unsubscribe_token (raw).
	 * @return int Number of rows actually inserted.
	 */
	public function insert_batch( int $campaign_id, array $rows ): int {
		global $wpdb;

		if ( empty( $rows ) ) {
			return 0;
		}

		$existing = $this->existing_person_ids( $campaign_id );
		$table    = Marketing_Schema::campaign_recipients();
		$now      = current_time( 'mysql', true );
		$inserted = 0;

		foreach ( $rows as $row ) {
			$person_id = (int) ( $row['person_id'] ?? 0 );

			if ( $person_id <= 0 || in_array( $person_id, $existing, true ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->insert(
				$table,
				array(
					'campaign_id'             => $campaign_id,
					'person_id'               => $person_id,
					'email'                   => (string) ( $row['email'] ?? '' ),
					'status'                  => (string) ( $row['status'] ?? 'pending' ),
					'skip_reason'             => (string) ( $row['skip_reason'] ?? '' ),
					'unsubscribe_token_hash'  => (string) ( $row['unsubscribe_token_hash'] ?? '' ),
					'created_at'              => $now,
					'updated_at'              => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( $ok ) {
				++$inserted;
			}
		}

		return $inserted;
	}

	/**
	 * Person IDs already snapshotted for a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return int[]
	 */
	public function existing_person_ids( int $campaign_id ): array {
		global $wpdb;

		$table = Marketing_Schema::campaign_recipients();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT person_id FROM {$table} WHERE campaign_id = %d", $campaign_id ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * A page of pending recipients ready to attempt — the only rows a send
	 * batch job ever selects, which is what makes retries idempotent: a row
	 * that already reached 'sent' or a terminal failure/exclusion state is
	 * permanently outside this query.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $limit       Batch size.
	 * @return array<int, array<string, mixed>>
	 */
	public function next_pending( int $campaign_id, int $limit ): array {
		global $wpdb;

		$table = Marketing_Schema::campaign_recipients();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE campaign_id = %d AND status = 'pending' ORDER BY id ASC LIMIT %d",
				$campaign_id,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Atomically claim one pending recipient for sending — the only place a
	 * recipient row ever transitions out of 'pending'. The conditional
	 * `WHERE status = 'pending'` makes this a compare-and-swap at the
	 * database layer: if two overlapping process_batch() calls (e.g.
	 * overlapping Job_Queue ticks) both select the same row via
	 * next_pending() before either claims it, only one of these UPDATEs can
	 * ever affect a row — the second sees 0 rows affected and must skip it.
	 * This is the recipient-level idempotency guarantee; it does not rely on
	 * Job_Queue's own site-wide lock, which is a coarser, best-effort
	 * mitigation, not a hard guarantee under true concurrency.
	 *
	 * @param int $id Recipient row ID.
	 * @return int|null The new attempt count if this call won the claim, null if another call already claimed it.
	 */
	public function claim_for_sending( int $id ): ?int {
		global $wpdb;

		$table = Marketing_Schema::campaign_recipients();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'sending', attempts = attempts + 1, last_attempt_at = %s, updated_at = %s WHERE id = %d AND status = 'pending'",
				$now,
				$now,
				$id
			)
		);

		if ( 0 === (int) $wpdb->rows_affected ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Mark a successfully-claimed recipient as sent. Only ever called after
	 * claim_for_sending() has already transitioned the row to 'sending' and
	 * incremented attempts, so this does not touch attempts itself.
	 *
	 * @param int    $id          Recipient row ID.
	 * @param string $message_ref Internal delivery reference.
	 * @return void
	 */
	public function mark_sent( int $id, string $message_ref ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Marketing_Schema::campaign_recipients(),
			array(
				'status'      => 'sent',
				'sent_at'     => current_time( 'mysql', true ),
				'message_ref' => $message_ref,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Record a failed attempt for a successfully-claimed recipient. Reverts
	 * to 'pending' (so the next batch retries it) until `$attempts` reaches
	 * `$max_attempts`, then becomes a terminal 'failed' — the same
	 * bounded-retry shape Job_Queue itself uses for jobs, applied here
	 * per-recipient. `$attempts` is the count claim_for_sending() already
	 * returned for this row, not re-read here, so this stays consistent
	 * with whichever call actually won the claim.
	 *
	 * @param int    $id           Recipient row ID.
	 * @param string $reason       Failure reason.
	 * @param int    $attempts     Attempt count as of this call (from claim_for_sending()).
	 * @param int    $max_attempts Attempts allowed before giving up.
	 * @return void
	 */
	public function mark_failed( int $id, string $reason, int $attempts, int $max_attempts ): void {
		global $wpdb;

		$status = $attempts >= $max_attempts ? 'failed' : 'pending';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Marketing_Schema::campaign_recipients(),
			array(
				'status'         => $status,
				'failure_reason' => $reason,
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Terminally mark an already-claimed recipient as unsubscribed instead
	 * of sending to them — used when {@see \EventOS\Marketing\Campaign_Send_Service::process_batch()}
	 * finds marketing consent was revoked after {@see \EventOS\Marketing\Campaign_Send_Service::prepare()}
	 * snapshotted this row as `pending`. Only ever called on a row this
	 * same request already won via {@see claim_for_sending()}, so no
	 * separate compare-and-swap is needed here — two workers can never
	 * both reach this call for the same row. A terminal status, like
	 * `sent`/`failed`: the row is not picked up by {@see next_pending()}
	 * again, and `attempts` is left untouched since this was never a
	 * delivery attempt.
	 *
	 * @param int $id Recipient row ID.
	 * @return void
	 */
	public function mark_unsubscribed( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Marketing_Schema::campaign_recipients(),
			array(
				'status'     => 'unsubscribed',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Persist an unsubscribe token's hash — called at send time, right
	 * before the raw token (never stored) is embedded in that one e-mail's
	 * unsubscribe link. See {@see Campaign_Send_Service::process_batch()}.
	 *
	 * @param int    $id   Recipient row ID.
	 * @param string $hash HMAC hash of the raw token.
	 * @return void
	 */
	public function set_unsubscribe_token_hash( int $id, string $hash ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Marketing_Schema::campaign_recipients(),
			array(
				'unsubscribe_token_hash' => $hash,
				'updated_at'             => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Status counts for a campaign's recipient snapshot — the delivery
	 * history summary the UI shows (eligible/sent/failed/skipped/etc.).
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<string, int>
	 */
	public function counts( int $campaign_id ): array {
		global $wpdb;

		$table = Marketing_Schema::campaign_recipients();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS total FROM {$table} WHERE campaign_id = %d GROUP BY status", $campaign_id ),
			ARRAY_A
		);

		$counts = array(
			'total'        => 0,
			'pending'      => 0,
			'sent'         => 0,
			'failed'       => 0,
			'skipped'      => 0,
			'unsubscribed' => 0,
			'invalid'      => 0,
		);

		foreach ( (array) $rows as $row ) {
			$status = (string) $row['status'];
			$total  = (int) $row['total'];

			$counts[ $status ] = ( $counts[ $status ] ?? 0 ) + $total;
			$counts['total']  += $total;
		}

		return $counts;
	}

	/**
	 * Whether a campaign still has recipients awaiting an attempt.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return bool
	 */
	public function has_pending( int $campaign_id ): bool {
		global $wpdb;

		$table = Marketing_Schema::campaign_recipients();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND status = 'pending'", $campaign_id )
		);

		return $count > 0;
	}

	/**
	 * A page of recipients for the history/detail view.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $page        1-based page number.
	 * @param int $per_page    Rows per page.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function for_campaign( int $campaign_id, int $page = 1, int $per_page = 50 ): array {
		global $wpdb;

		$table    = Marketing_Schema::campaign_recipients();
		$page     = max( 1, $page );
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE campaign_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$campaign_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d", $campaign_id ) );

		return array(
			'items' => array_map( array( $this, 'hydrate' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Look up a recipient row by its raw unsubscribe token — verifies the
	 * HMAC hash the same way {@see \EventOS\Invitations} verifies invite
	 * tokens, so the raw token is never stored, only its hash.
	 *
	 * @param string $token Raw token from the unsubscribe link.
	 * @return array<string, mixed>|null
	 */
	public function find_by_unsubscribe_token( string $token ): ?array {
		global $wpdb;

		if ( '' === $token ) {
			return null;
		}

		$table = Marketing_Schema::campaign_recipients();
		$hash  = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE unsubscribe_token_hash = %s", $hash ),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Every recipient row across every campaign for a CRM Person — the
	 * lookup {@see \EventOS\Crm\Person_Privacy} needs to export or erase a
	 * data-subject's campaign delivery history.
	 *
	 * @param int $person_id CRM Person ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_by_person( int $person_id ): array {
		global $wpdb;

		if ( $person_id <= 0 ) {
			return array();
		}

		$table = Marketing_Schema::campaign_recipients();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE person_id = %d ORDER BY id DESC", $person_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Redact the email address on a Person's campaign recipient rows,
	 * leaving the delivery record itself (status, attempts, sent/failure
	 * timestamps) intact — that history is the campaign's own send-audit
	 * trail, not personal data once the address is gone. Used only by
	 * {@see \EventOS\Crm\Person_Privacy}'s eraser.
	 *
	 * @param int $person_id CRM Person ID.
	 * @return int Number of rows anonymized.
	 */
	public function anonymize_for_person( int $person_id ): int {
		global $wpdb;

		if ( $person_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . Marketing_Schema::campaign_recipients() . "
				SET email = '', updated_at = %s
				WHERE person_id = %d",
				current_time( 'mysql', true ),
				$person_id
			)
		);
	}

	/**
	 * Shape a raw row for API output.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		return array(
			'id'              => (int) $row['id'],
			'campaign_id'     => (int) $row['campaign_id'],
			'person_id'       => (int) $row['person_id'],
			'email'           => (string) $row['email'],
			'status'          => (string) $row['status'],
			'skip_reason'     => (string) $row['skip_reason'],
			'failure_reason'  => $row['failure_reason'],
			'attempts'        => (int) $row['attempts'],
			'last_attempt_at' => $row['last_attempt_at'],
			'sent_at'         => $row['sent_at'],
			'created_at'      => (string) $row['created_at'],
		);
	}
}

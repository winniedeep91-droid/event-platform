<?php
/**
 * Data access for a campaign's emailable message content.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Marketing;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A campaign's message is stored separately from the campaign row itself —
 * the campaign is the discount/coupon entity Sprint 2 already built; the
 * message is the emailable content layered on top of it. One campaign has
 * at most one message (`UNIQUE campaign_id`): editing re-saves it, it never
 * accumulates versions. `status` here is the *send* lifecycle
 * (draft/ready/sending/sent/failed) and is deliberately independent of the
 * campaign's own `status` (draft/active/paused/archived, which is the
 * discount/coupon state) — a promotion can be active without ever having
 * been emailed, exactly as the Sprint 3 brief requires.
 */
final class Campaign_Message_Repository {

	/**
	 * Valid send-lifecycle statuses.
	 */
	public const STATUSES = array( 'draft', 'ready', 'sending', 'sent', 'failed' );

	/**
	 * Columns that map straight onto the table.
	 *
	 * @var array<string, string>
	 */
	private const COLUMNS = array(
		'campaign_id'        => '%d',
		'subject'            => '%s',
		'preview_text'       => '%s',
		'sender_name'        => '%s',
		'sender_email'       => '%s',
		'reply_to'           => '%s',
		'body_html'          => '%s',
		'body_text'          => '%s',
		'status'             => '%s',
		'send_started_at'    => '%s',
		'send_completed_at'  => '%s',
		'created_at'         => '%s',
		'updated_at'         => '%s',
	);

	/**
	 * Read the message for a campaign, if one has been saved.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<string, mixed>|null
	 */
	public function for_campaign( int $campaign_id ): ?array {
		global $wpdb;

		$table = Marketing_Schema::campaign_messages();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE campaign_id = %d", $campaign_id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Read a message by its own ID.
	 *
	 * @param int $id Message ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Marketing_Schema::campaign_messages();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Create or update the message for a campaign (upsert on campaign_id).
	 * Saving content never changes `status` unless the caller explicitly
	 * asks — this is the same "preserve on edit" convention Campaign_Repository
	 * uses for its own status column, applied here for the same reason: an
	 * operator polishing copy should not silently lose "ready"/"sent".
	 *
	 * @param int                  $campaign_id Campaign ID.
	 * @param array<string, mixed> $input       Field values.
	 * @return array<string, mixed>|WP_Error
	 */
	public function save( int $campaign_id, array $input ) {
		global $wpdb;

		$existing = $this->for_campaign( $campaign_id );

		$data = $this->sanitize( $input, $existing );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$now                = current_time( 'mysql', true );
		$data['updated_at'] = $now;

		if ( null === $existing ) {
			$data['campaign_id'] = $campaign_id;
			$data['created_at']  = $now;

			$formats = array();

			foreach ( array_keys( $data ) as $column ) {
				$formats[] = self::COLUMNS[ $column ] ?? '%s';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert( Marketing_Schema::campaign_messages(), $data, $formats );

			return $this->for_campaign( $campaign_id );
		}

		$formats = array();

		foreach ( array_keys( $data ) as $column ) {
			$formats[] = self::COLUMNS[ $column ] ?? '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Marketing_Schema::campaign_messages(), $data, array( 'id' => $existing['id'] ), $formats, array( '%d' ) );

		return $this->for_campaign( $campaign_id );
	}

	/**
	 * Set only the status (and, when moving into 'sending'/'sent', the
	 * matching timestamp) — used by {@see Campaign_Send_Service} so
	 * prepare/send transitions never have to round-trip the full message
	 * body through `save()`.
	 *
	 * @param int    $id     Message ID.
	 * @param string $status New status.
	 * @return void
	 */
	public function set_status( int $id, string $status ): void {
		global $wpdb;

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return;
		}

		$data    = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		);
		$formats = array( '%s', '%s' );

		if ( 'sending' === $status ) {
			$data['send_started_at'] = current_time( 'mysql', true );
			$formats[]               = '%s';
		}

		if ( in_array( $status, array( 'sent', 'failed' ), true ) ) {
			$data['send_completed_at'] = current_time( 'mysql', true );
			$formats[]                 = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Marketing_Schema::campaign_messages(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Validate and shape message input.
	 *
	 * @param array<string, mixed>      $input    Raw input.
	 * @param array<string, mixed>|null $existing Existing row, when editing.
	 * @return array<string, mixed>|WP_Error
	 */
	private function sanitize( array $input, ?array $existing ): array|WP_Error {
		$subject = trim( (string) ( $input['subject'] ?? ( $existing['subject'] ?? '' ) ) );

		if ( '' === $subject ) {
			return new WP_Error( 'eventos_invalid_message', __( 'A subject line is required.', 'eventos' ), array( 'status' => 400 ) );
		}

		$sender_email = trim( (string) ( $input['sender_email'] ?? ( $existing['sender_email'] ?? '' ) ) );

		if ( '' === $sender_email || ! is_email( $sender_email ) ) {
			return new WP_Error( 'eventos_invalid_message', __( 'A valid sender e-mail address is required.', 'eventos' ), array( 'status' => 400 ) );
		}

		$reply_to = trim( (string) ( $input['reply_to'] ?? ( $existing['reply_to'] ?? '' ) ) );

		if ( '' !== $reply_to && ! is_email( $reply_to ) ) {
			return new WP_Error( 'eventos_invalid_message', __( 'Reply-to must be a valid e-mail address.', 'eventos' ), array( 'status' => 400 ) );
		}

		$body_html = (string) ( $input['body_html'] ?? ( $existing['body_html'] ?? '' ) );

		if ( '' === trim( wp_strip_all_tags( $body_html ) ) ) {
			return new WP_Error( 'eventos_invalid_message', __( 'The message needs content.', 'eventos' ), array( 'status' => 400 ) );
		}

		$body_text = array_key_exists( 'body_text', $input ) ? (string) $input['body_text'] : ( $existing['body_text'] ?? '' );

		// A plain-text part is required by every serious mail client's spam
		// scoring; auto-derive one from the HTML when the operator did not
		// supply their own, rather than ever sending HTML-only mail.
		if ( '' === trim( $body_text ) ) {
			$body_text = trim( wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br/>', '<br />' ), "\n", $body_html ) ) );
		}

		return array(
			'subject'      => $subject,
			'preview_text' => sanitize_text_field( (string) ( $input['preview_text'] ?? ( $existing['preview_text'] ?? '' ) ) ),
			'sender_name'  => sanitize_text_field( (string) ( $input['sender_name'] ?? ( $existing['sender_name'] ?? get_bloginfo( 'name' ) ) ) ),
			'sender_email' => sanitize_email( $sender_email ),
			'reply_to'     => sanitize_email( $reply_to ),
			'body_html'    => wp_kses_post( $body_html ),
			'body_text'    => $body_text,
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
			'id'                 => (int) $row['id'],
			'campaign_id'        => (int) $row['campaign_id'],
			'subject'            => (string) $row['subject'],
			'preview_text'       => (string) $row['preview_text'],
			'sender_name'        => (string) $row['sender_name'],
			'sender_email'       => (string) $row['sender_email'],
			'reply_to'           => (string) $row['reply_to'],
			'body_html'          => (string) $row['body_html'],
			'body_text'          => (string) $row['body_text'],
			'status'             => (string) $row['status'],
			'send_started_at'    => $row['send_started_at'],
			'send_completed_at'  => $row['send_completed_at'],
			'created_at'         => (string) $row['created_at'],
			'updated_at'         => (string) $row['updated_at'],
		);
	}
}

<?php
/**
 * WordPress-native personal data export/erasure for the CRM Person and its
 * related EventOS records.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Crm;

use EventOS\Events\Guest_Repository;
use EventOS\Events\Waitlist_Repository;
use EventOS\Marketing\Campaign_Recipient_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers EventOS with WordPress's own `wp_privacy_personal_data_exporters`
 * / `wp_privacy_personal_data_erasers` filters (Tools > Export/Erase
 * Personal Data) — the native mechanism, not a parallel one. WooCommerce
 * remains solely responsible for its own customer/order data; this class
 * never reads or writes a `WC_Order`/`WC_Customer` directly, only the CRM
 * Person record and the EventOS-owned tables that reference it.
 *
 * Scope (see class docblocks for the full reasoning on each):
 * - Person profile + cached lifetime metrics: exported, contact fields erased.
 * - Person identities (email/phone signals): exported, erased.
 * - Person tags/notes: exported, erased (free text staff could use to
 *   record something identifying).
 * - Person consent history: exported only, never erased — the grant/revoke
 *   record is itself the compliance evidence a future request or dispute
 *   would need; deleting it would remove the proof of what was agreed.
 * - Person relationship timeline: exported only, never erased — entries are
 *   structural (type + timestamp), not raw PII, by {@see Person_Timeline_Service}'s
 *   own design, so there is nothing identifying left to remove.
 * - Guest/attendance rows: exported, contact fields + tags/notes anonymized;
 *   the row and its ticket/order linkage stay, since that is the financial
 *   and attendance record WooCommerce order history and event reporting
 *   depend on.
 * - Waitlist entries: exported, contact fields anonymized; status/position/
 *   conversion linkage stays.
 * - Campaign recipient rows: exported, email anonymized; delivery status/
 *   attempts stay as the campaign's own send-audit trail.
 *
 * Explicitly out of scope for this baseline (an honest limitation, not an
 * oversight): {@see \EventOS\Activity_Log} (system/admin audit trail, not
 * customer data), Person_Reward records, and Person_Merge_Log. None of the
 * three currently exist in meaningful volume for a real installation, and
 * each would need its own deliberate retention/erasure decision rather than
 * being folded into this pass.
 *
 * Registering these hooks makes EventOS POPIA/GDPR-*compatible in
 * principle* — it does not, by itself, make the plugin or the site legally
 * compliant. That depends on the site owner's own policies, retention
 * periods and how these tools are actually used.
 */
final class Person_Privacy {

	/**
	 * Register the exporter/eraser with WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $exporters Existing exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['eventos-people'] = array(
			'exporter_friendly_name' => __( 'EventOS', 'eventos' ),
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/**
	 * @param array<string, array<string, mixed>> $erasers Existing erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['eventos-people'] = array(
			'eraser_friendly_name' => __( 'EventOS', 'eventos' ),
			'callback'              => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export everything EventOS holds for one email address.
	 *
	 * Every group here is bounded per-person (a handful of rows at most),
	 * so there is nothing that genuinely needs multiple pages — page 1
	 * returns everything and reports done; any later page is a no-op that
	 * still honours the page argument WordPress's exporter loop passes.
	 *
	 * @param string $email_address Requester's email address.
	 * @param int    $page          1-based page number.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return array( 'data' => array(), 'done' => true );
		}

		$data = array();

		$person = self::resolve_person( $email_address );

		if ( null !== $person ) {
			$person_id = (int) $person['id'];

			$data[] = self::item(
				'eventos-person',
				__( 'EventOS Customer Profile', 'eventos' ),
				'person',
				array(
					'name'         => __( 'Display name', 'eventos' ),
					'first_name'   => __( 'First name', 'eventos' ),
					'last_name'    => __( 'Last name', 'eventos' ),
					'email'        => __( 'Email', 'eventos' ),
					'phone'        => __( 'Phone', 'eventos' ),
					'location'     => __( 'Location', 'eventos' ),
					'events'       => __( 'Events attended', 'eventos' ),
					'tickets'      => __( 'Tickets purchased', 'eventos' ),
					'spend'        => __( 'Lifetime spend', 'eventos' ),
					'last_order'   => __( 'Last purchase', 'eventos' ),
					'known_since'  => __( 'Known since', 'eventos' ),
				),
				array(
					'name'         => $person['display_name'],
					'first_name'   => $person['first_name'],
					'last_name'    => $person['last_name'],
					'email'        => $person['primary_email'],
					'phone'        => $person['primary_phone'],
					'location'     => $person['location'],
					'events'       => (string) $person['total_events_attended'],
					'tickets'      => (string) $person['total_tickets_purchased'],
					'spend'        => (string) $person['total_spend'],
					'last_order'   => (string) $person['last_purchase_at'],
					'known_since'  => $person['created_at'],
				)
			);

			foreach ( ( new Person_Identity_Repository() )->for_person( $person_id ) as $identity ) {
				$data[] = self::item(
					'eventos-person-identities',
					__( 'EventOS Customer Identity Signals', 'eventos' ),
					'identity-' . $identity['id'],
					array(
						'type'       => __( 'Type', 'eventos' ),
						'value'      => __( 'Value', 'eventos' ),
						'created_at' => __( 'Linked on', 'eventos' ),
					),
					array(
						'type'       => $identity['type'],
						'value'      => $identity['value'],
						'created_at' => $identity['created_at'],
					)
				);
			}

			foreach ( ( new Person_Tag_Repository() )->for_person( $person_id ) as $tag ) {
				$data[] = self::item(
					'eventos-person-tags',
					__( 'EventOS Customer Tags', 'eventos' ),
					'tag-' . $tag['id'],
					array( 'tag' => __( 'Tag', 'eventos' ) ),
					array( 'tag' => $tag['tag'] )
				);
			}

			foreach ( ( new Person_Note_Repository() )->for_person( $person_id ) as $note ) {
				$data[] = self::item(
					'eventos-person-notes',
					__( 'EventOS Staff Notes', 'eventos' ),
					'note-' . $note['id'],
					array(
						'body'       => __( 'Note', 'eventos' ),
						'author'     => __( 'Author', 'eventos' ),
						'created_at' => __( 'Date', 'eventos' ),
					),
					array(
						'body'       => wp_strip_all_tags( $note['body'] ),
						'author'     => $note['author_name'],
						'created_at' => $note['created_at'],
					)
				);
			}

			foreach ( ( new Person_Consent_Repository() )->for_person( $person_id ) as $consent ) {
				$data[] = self::item(
					'eventos-person-consent',
					__( 'EventOS Marketing Consent History', 'eventos' ),
					'consent-' . $consent['id'],
					array(
						'channel'    => __( 'Channel', 'eventos' ),
						'status'     => __( 'Status', 'eventos' ),
						'source'     => __( 'Source', 'eventos' ),
						'granted_at' => __( 'Granted', 'eventos' ),
						'revoked_at' => __( 'Revoked', 'eventos' ),
					),
					array(
						'channel'    => $consent['channel'],
						'status'     => $consent['active'] ? __( 'Active', 'eventos' ) : __( 'Revoked', 'eventos' ),
						'source'     => $consent['source'],
						'granted_at' => (string) $consent['granted_at'],
						'revoked_at' => (string) ( $consent['revoked_at'] ?? '' ),
					)
				);
			}

			foreach ( ( new Person_Timeline_Service() )->for_person( $person_id, 200 ) as $entry ) {
				$data[] = self::item(
					'eventos-person-timeline',
					__( 'EventOS Relationship Timeline', 'eventos' ),
					'timeline-' . $entry['id'],
					array(
						'type'        => __( 'Event', 'eventos' ),
						'occurred_at' => __( 'Date', 'eventos' ),
					),
					array(
						'type'        => $entry['type'],
						'occurred_at' => $entry['occurred_at'],
					)
				);
			}

			foreach ( ( new Waitlist_Repository() )->find_by_person( $person_id ) as $entry ) {
				$data[] = self::item(
					'eventos-waitlist',
					__( 'EventOS Waitlist Entries', 'eventos' ),
					'waitlist-' . $entry['id'],
					array(
						'event_id'       => __( 'Event ID', 'eventos' ),
						'ticket_type_id' => __( 'Ticket type ID', 'eventos' ),
						'status'         => __( 'Status', 'eventos' ),
						'created_at'     => __( 'Joined on', 'eventos' ),
					),
					array(
						'event_id'       => (string) $entry['event_id'],
						'ticket_type_id' => (string) $entry['ticket_type_id'],
						'status'         => $entry['status'],
						'created_at'     => $entry['created_at'],
					)
				);
			}

			foreach ( ( new Campaign_Recipient_Repository() )->find_by_person( $person_id ) as $recipient ) {
				$data[] = self::item(
					'eventos-campaign-recipients',
					__( 'EventOS Campaign Delivery History', 'eventos' ),
					'recipient-' . $recipient['id'],
					array(
						'campaign_id' => __( 'Campaign ID', 'eventos' ),
						'status'      => __( 'Status', 'eventos' ),
						'sent_at'     => __( 'Sent on', 'eventos' ),
					),
					array(
						'campaign_id' => (string) $recipient['campaign_id'],
						'status'      => $recipient['status'],
						'sent_at'     => (string) ( $recipient['sent_at'] ?? '' ),
					)
				);
			}
		}

		foreach ( ( new Guest_Repository() )->find_by_email( $email_address ) as $guest ) {
			$data[] = self::item(
				'eventos-guest-records',
				__( 'EventOS Attendee / Ticket Records', 'eventos' ),
				'guest-' . $guest['id'],
				array(
					'event'       => __( 'Event', 'eventos' ),
					'ticket'      => __( 'Ticket number', 'eventos' ),
					'status'      => __( 'Status', 'eventos' ),
					'checked_in'  => __( 'Checked in', 'eventos' ),
					'created_at'  => __( 'Purchased on', 'eventos' ),
				),
				array(
					'event'       => $guest['event_title'],
					'ticket'      => $guest['ticket_number'],
					'status'      => $guest['status'],
					'checked_in'  => $guest['checked_in'] ? __( 'Yes', 'eventos' ) : __( 'No', 'eventos' ),
					'created_at'  => $guest['created_at'],
				)
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase everything EventOS holds for one email address.
	 *
	 * @param string $email_address Requester's email address.
	 * @param int    $page          1-based page number.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$removed  = false;
		$messages = array();

		$person = self::resolve_person( $email_address );

		if ( null !== $person ) {
			$person_id = (int) $person['id'];

			( new Person_Repository() )->update(
				$person_id,
				array(
					'display_name'  => __( 'Redacted', 'eventos' ),
					'first_name'    => '',
					'last_name'     => '',
					'primary_email' => '',
					'primary_phone' => '',
					'avatar_url'    => '',
					'location'      => '',
					'date_of_birth' => null,
				)
			);

			( new Person_Identity_Repository() )->erase_for_person( $person_id );
			( new Person_Tag_Repository() )->delete_for_person( $person_id );
			( new Person_Note_Repository() )->delete_for_person( $person_id );
			( new Waitlist_Repository() )->anonymize_for_person( $person_id );
			( new Campaign_Recipient_Repository() )->anonymize_for_person( $person_id );

			// The Person's own contact fields were always just redacted
			// above, regardless of whether any waitlist/campaign rows
			// existed to anonymize — so finding a Person here always means
			// something identifying was removed.
			$removed = true;

			$messages[] = __( 'EventOS lifetime purchase/attendance totals and marketing consent history were retained for accounting and compliance purposes; identifying contact details were removed.', 'eventos' );
		}

		if ( ( new Guest_Repository() )->anonymize_for_email( $email_address ) > 0 ) {
			$removed = true;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => true,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Resolve a CRM Person from a requester's email — via the identity
	 * table first (the same signal {@see Person_Resolver} matches on), then
	 * the Person's own primary_email as a fallback, mirroring
	 * {@see Person_Resolver::find_or_create()}'s own lookup order.
	 *
	 * @param string $email_address Raw email address.
	 * @return array<string, mixed>|null
	 */
	private static function resolve_person( string $email_address ): ?array {
		$normalized = Identity_Normalizer::normalize_email( $email_address );

		if ( '' === $normalized ) {
			return null;
		}

		$persons = new Person_Repository();

		$identity = ( new Person_Identity_Repository() )->find_by_type_value( 'email', $normalized );

		if ( null !== $identity ) {
			$person = $persons->find_by_id( (int) $identity['person_id'] );

			if ( null !== $person ) {
				return $person;
			}
		}

		return $persons->find_by_primary_email( $normalized );
	}

	/**
	 * Shape one exporter item.
	 *
	 * @param string                $group_id    Group ID.
	 * @param string                $group_label Group label.
	 * @param string                $item_id     Item ID, unique within the group.
	 * @param array<string, string> $labels      Field key => human label.
	 * @param array<string, string> $values      Field key => value.
	 * @return array<string, mixed>
	 */
	private static function item( string $group_id, string $group_label, string $item_id, array $labels, array $values ): array {
		$fields = array();

		foreach ( $labels as $key => $label ) {
			$fields[] = array(
				'name'  => $label,
				'value' => (string) ( $values[ $key ] ?? '' ),
			);
		}

		return array(
			'group_id'    => $group_id,
			'group_label' => $group_label,
			'item_id'     => $item_id,
			'data'        => $fields,
		);
	}
}

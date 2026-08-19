<?php
/**
 * Database schema owned by the Marketing module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `eventos_marketing_audiences` is the first table this module owns —
 * audiences are the "WHO" a campaign targets, always resolved against
 * Audience CRM/Events data at read time (see {@see Audience_Resolver}),
 * never a copy of Person records. This table stores only the audience
 * *definition* (name, type, criteria), matching the same "store the rule,
 * not the result" approach {@see \EventOS\Crm\Segment_Repository} already
 * uses for `rule_config`.
 */
final class Marketing_Schema {

	/**
	 * Schema version stored in the options table.
	 */
	public const VERSION = '1.1.0';

	/**
	 * Option holding the installed schema version.
	 */
	public const VERSION_OPTION = 'eventos_marketing_schema_version';

	/**
	 * Prefixed table name.
	 *
	 * @param string $name Table suffix.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'eventos_' . $name;
	}

	/**
	 * Marketing audience definitions.
	 *
	 * @return string
	 */
	public static function audiences(): string {
		return self::table( 'marketing_audiences' );
	}

	/**
	 * Campaign messages — the emailable content attached to a campaign.
	 * One row per campaign (a campaign has at most one message this phase;
	 * see {@see \EventOS\Marketing\Campaign_Message_Repository}).
	 *
	 * @return string
	 */
	public static function campaign_messages(): string {
		return self::table( 'campaign_messages' );
	}

	/**
	 * Campaign recipient snapshot rows — one per Person a campaign resolved
	 * to at prepare-time, with delivery status. This table *is* the
	 * "recipient snapshot"; once written, the live audience is never
	 * consulted again for that campaign's send (see
	 * {@see \EventOS\Marketing\Campaign_Send_Service}).
	 *
	 * @return string
	 */
	public static function campaign_recipients(): string {
		return self::table( 'campaign_recipients' );
	}

	/**
	 * Create or upgrade every Marketing table.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$schema  = array();

		$audiences = self::audiences();

		// event_id is nullable by design: an audience is either global
		// (targets the whole Audience CRM) or scoped to one event — see the
		// Marketing architecture report's "Event vs Global Marketing"
		// recommendation. `criteria` is stored as-is (JSON) and interpreted
		// only by Audience_Resolver, exactly the same "store the rule, let a
		// resolver interpret it" split Segment_Repository/rule_config
		// already established, so this does not introduce a second pattern.
		$schema[] = "CREATE TABLE {$audiences} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NULL,
			name VARCHAR(191) NOT NULL,
			description VARCHAR(500) NOT NULL DEFAULT '',
			type VARCHAR(40) NOT NULL,
			criteria LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY type (type),
			KEY status (status)
		) {$collate};";

		$messages = self::campaign_messages();

		// One message per campaign for this phase — a future sprint that
		// wants message revisions/multiple channels can relax the UNIQUE key
		// without touching anything else here.
		$schema[] = "CREATE TABLE {$messages} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT UNSIGNED NOT NULL,
			subject VARCHAR(191) NOT NULL DEFAULT '',
			preview_text VARCHAR(191) NOT NULL DEFAULT '',
			sender_name VARCHAR(191) NOT NULL DEFAULT '',
			sender_email VARCHAR(191) NOT NULL DEFAULT '',
			reply_to VARCHAR(191) NOT NULL DEFAULT '',
			body_html LONGTEXT NULL,
			body_text LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			send_started_at DATETIME NULL,
			send_completed_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY campaign_id (campaign_id),
			KEY status (status)
		) {$collate};";

		$recipients = self::campaign_recipients();

		// The recipient snapshot. `status` deliberately covers the full
		// vocabulary the product spec asks for (pending/queued/sending/sent/
		// failed/skipped/unsubscribed/invalid) even though the v1 send loop
		// only ever writes pending/sent/failed/skipped/unsubscribed/invalid —
		// queued/sending are reserved for a future finer-grained progress
		// view rather than actively used yet (see Campaign_Send_Service).
		// UNIQUE(campaign_id, person_id) is the dedupe-by-construction
		// guarantee: a person can never appear twice in one campaign's
		// recipient list even if resolve() ever returned them twice.
		$schema[] = "CREATE TABLE {$recipients} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT UNSIGNED NOT NULL,
			person_id BIGINT UNSIGNED NOT NULL,
			email VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			skip_reason VARCHAR(50) NOT NULL DEFAULT '',
			failure_reason TEXT NULL,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			last_attempt_at DATETIME NULL,
			sent_at DATETIME NULL,
			message_ref VARCHAR(64) NOT NULL DEFAULT '',
			unsubscribe_token_hash CHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY campaign_person (campaign_id, person_id),
			KEY campaign_id (campaign_id),
			KEY person_id (person_id),
			KEY status (status),
			KEY unsubscribe_token_hash (unsubscribe_token_hash)
		) {$collate};";

		foreach ( $schema as $table_sql ) {
			dbDelta( $table_sql );
		}

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Install the schema when it is missing or outdated.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( (string) get_option( self::VERSION_OPTION, '' ) === self::VERSION ) {
			return;
		}

		self::install();
	}
}

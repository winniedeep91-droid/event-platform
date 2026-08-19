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
	public const VERSION = '1.0.0';

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

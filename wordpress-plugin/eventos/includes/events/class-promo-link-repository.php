<?php
/**
 * Data access for promotional links.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A trackable label for a destination URL. The existing Marketing tab links
 * straight to the destination URL rather than through an EventOS redirect,
 * so `clicks` has no data source yet and stays at zero — see
 * {@see Marketing_Service} for the documented limitation.
 */
final class Promo_Link_Repository {

	/**
	 * Columns that map straight onto the promo_links table.
	 *
	 * @var array<string, string>
	 */
	private const COLUMNS = array(
		'event_id'     => '%d',
		'label'        => '%s',
		'url'          => '%s',
		'utm_source'   => '%s',
		'utm_medium'   => '%s',
		'utm_campaign' => '%s',
		'clicks'       => '%d',
		'created_at'   => '%s',
		'updated_at'   => '%s',
	);

	/**
	 * Every promo link for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_event( int $event_id ): array {
		global $wpdb;

		$table = Event_Schema::promo_links();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY created_at DESC, id DESC", $event_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Read a single promo link.
	 *
	 * @param int $id Link ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Event_Schema::promo_links();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Create a promo link.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $input    Field values.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create( int $event_id, array $input ) {
		global $wpdb;

		$label = trim( (string) ( $input['label'] ?? '' ) );
		$url   = esc_url_raw( trim( (string) ( $input['url'] ?? '' ) ) );

		if ( '' === $label ) {
			return new WP_Error( 'eventos_invalid_link', __( 'A promo link needs a label.', 'eventos' ), array( 'status' => 400 ) );
		}

		if ( '' === $url ) {
			return new WP_Error( 'eventos_invalid_link', __( 'A valid destination URL is required.', 'eventos' ), array( 'status' => 400 ) );
		}

		$now = current_time( 'mysql', true );

		$row = array(
			'event_id'     => $event_id,
			'label'        => $label,
			'url'          => $url,
			'utm_source'   => sanitize_text_field( (string) ( $input['utm_source'] ?? '' ) ),
			'utm_medium'   => sanitize_text_field( (string) ( $input['utm_medium'] ?? '' ) ),
			'utm_campaign' => sanitize_text_field( (string) ( $input['utm_campaign'] ?? '' ) ),
			'clicks'       => 0,
			'created_at'   => $now,
			'updated_at'   => $now,
		);

		$formats = array_map(
			static function ( string $column ): string {
				return self::COLUMNS[ $column ];
			},
			array_keys( $row )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( Event_Schema::promo_links(), $row, $formats );

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Delete a promo link.
	 *
	 * @param int $id Link ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->delete( Event_Schema::promo_links(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Shape a raw row into the PromoLink contract.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		return array(
			'id'           => (int) $row['id'],
			'event_id'     => (int) $row['event_id'],
			'label'        => (string) $row['label'],
			'url'          => (string) $row['url'],
			'utm_source'   => (string) $row['utm_source'],
			'utm_medium'   => (string) $row['utm_medium'],
			'utm_campaign' => (string) $row['utm_campaign'],
			'clicks'       => (int) $row['clicks'],
			'created_at'   => (string) $row['created_at'],
		);
	}
}

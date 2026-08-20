<?php
/**
 * Data access and WooCommerce product sync for ticket types.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

use EventOS\WooCommerce;
use EventOS\Woocommerce\Wc_Meta;
use WC_Product;
use WC_Product_Simple;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ticket types are owned by EventOS; each one creates and maintains a linked
 * WooCommerce simple product so WooCommerce can sell it through its own
 * checkout, cart and stock management. EventOS never builds a parallel
 * checkout — the WooCommerce product is the sellable object.
 */
final class Ticket_Type_Repository {

	/**
	 * Columns that map straight onto the ticket_types table.
	 *
	 * @var array<string, string>
	 */
	private const COLUMNS = array(
		'event_id'         => '%d',
		'name'             => '%s',
		'description'      => '%s',
		'tier'             => '%s',
		'price'            => '%f',
		'capacity'         => '%d',
		'visibility'       => '%s',
		'status'           => '%s',
		'sale_start'       => '%s',
		'sale_end'         => '%s',
		'min_per_order'    => '%d',
		'max_per_order'    => '%d',
		'waitlist_enabled' => '%d',
		'wc_product_id'    => '%d',
		'position'         => '%d',
		'created_at'       => '%s',
		'updated_at'       => '%s',
	);

	/**
	 * Known tiers.
	 *
	 * @return string[]
	 */
	public static function tiers(): array {
		return array( 'standard', 'early_bird', 'vip', 'table', 'backstage', 'complimentary', 'custom' );
	}

	/**
	 * Known visibilities.
	 *
	 * @return string[]
	 */
	public static function visibilities(): array {
		return array( 'public', 'private', 'hidden' );
	}

	/**
	 * Every ticket type for an event, ordered for display.
	 *
	 * @param int $event_id Event ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_event( int $event_id ): array {
		global $wpdb;

		$table = Event_Schema::ticket_types();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY position ASC, id ASC", $event_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Read a single ticket type.
	 *
	 * @param int $id Ticket type ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Event_Schema::ticket_types();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Create a ticket type and its linked WooCommerce product.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $input    Field values.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create( int $event_id, array $input ) {
		global $wpdb;

		$data = $this->sanitize( $input, $event_id );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$now                = current_time( 'mysql', true );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		$data['position']   = $this->next_position( $event_id );

		$formats = array();

		foreach ( array_keys( $data ) as $column ) {
			$formats[] = self::COLUMNS[ $column ] ?? '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( Event_Schema::ticket_types(), $data, $formats );

		$id = (int) $wpdb->insert_id;

		$product_id = $this->sync_wc_product( $id, $data, 0 );

		if ( $product_id > 0 ) {
			$this->set_wc_product_id( $id, $product_id );
		}

		return $this->find( $id );
	}

	/**
	 * Update a ticket type and re-sync its WooCommerce product.
	 *
	 * @param int                  $id    Ticket type ID.
	 * @param array<string, mixed> $input Field values.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update( int $id, array $input ) {
		global $wpdb;

		$existing = $this->find( $id );

		if ( null === $existing ) {
			return new WP_Error( 'eventos_not_found', __( 'That ticket type no longer exists.', 'eventos' ), array( 'status' => 404 ) );
		}

		$data = $this->sanitize( $input, (int) $existing['event_id'] );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		unset( $data['event_id'] );
		$data['updated_at'] = current_time( 'mysql', true );

		$formats = array();

		foreach ( array_keys( $data ) as $column ) {
			$formats[] = self::COLUMNS[ $column ] ?? '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Event_Schema::ticket_types(), $data, array( 'id' => $id ), $formats, array( '%d' ) );

		$merged     = array_merge( $existing, $data, array( 'event_id' => (int) $existing['event_id'] ) );
		$product_id = $this->sync_wc_product( $id, $merged, (int) $existing['wc_product_id'] );

		if ( $product_id > 0 && $product_id !== (int) $existing['wc_product_id'] ) {
			$this->set_wc_product_id( $id, $product_id );
		}

		return $this->find( $id );
	}

	/**
	 * Archive a ticket type and its WooCommerce product.
	 *
	 * Never hard deletes: existing tickets, orders and reports keep referring
	 * to the ticket type, so it is archived instead of removed.
	 *
	 * @param int $id Ticket type ID.
	 * @return true|WP_Error
	 */
	public function archive( int $id ) {
		global $wpdb;

		$existing = $this->find( $id );

		if ( null === $existing ) {
			return new WP_Error( 'eventos_not_found', __( 'That ticket type no longer exists.', 'eventos' ), array( 'status' => 404 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Event_Schema::ticket_types(),
			array(
				'status'     => 'archived',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$product_id = (int) $existing['wc_product_id'];

		if ( $product_id > 0 && WooCommerce::is_active() ) {
			$product = wc_get_product( $product_id );

			if ( $product instanceof WC_Product ) {
				$product->set_status( 'draft' );
				$product->save();
			}
		}

		return true;
	}

	/**
	 * Persist a new display order for a set of ticket types.
	 *
	 * @param int   $event_id Event ID.
	 * @param int[] $ids      Ticket type IDs in the desired order.
	 * @return void
	 */
	public function reorder( int $event_id, array $ids ): void {
		global $wpdb;

		$table    = Event_Schema::ticket_types();
		$position = 0;

		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'position' => $position ),
				array(
					'id'       => (int) $id,
					'event_id' => $event_id,
				),
				array( '%d' ),
				array( '%d', '%d' )
			);

			++$position;
		}
	}

	/**
	 * WooCommerce product IDs for every ticket type across an event.
	 *
	 * @param int $event_id Event ID.
	 * @return int[] Product ID => ticket type ID.
	 */
	public function product_map_for_event( int $event_id ): array {
		$map = array();

		foreach ( $this->for_event( $event_id ) as $type ) {
			if ( (int) $type['wc_product_id'] > 0 ) {
				$map[ (int) $type['wc_product_id'] ] = (int) $type['id'];
			}
		}

		return $map;
	}

	/**
	 * WooCommerce product ID => owning event ID, across every ticket type
	 * that has a linked WooCommerce product. Used to attribute a WooCommerce
	 * order line item back to the event it belongs to without a per-event
	 * query — the basis for brand-wide and batched-per-event reporting.
	 *
	 * @param int[] $event_ids Event IDs to scope to; empty scopes to every event.
	 * @return array<int, int> WC product ID => event ID.
	 */
	public function product_event_map( array $event_ids = array() ): array {
		global $wpdb;

		$table = Event_Schema::ticket_types();

		if ( empty( $event_ids ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( "SELECT event_id, wc_product_id FROM {$table} WHERE wc_product_id > 0", ARRAY_A );
		} else {
			$event_ids    = array_values( array_unique( array_map( 'intval', $event_ids ) ) );
			$placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT event_id, wc_product_id FROM {$table} WHERE wc_product_id > 0 AND event_id IN ({$placeholders})",
					$event_ids
				),
				ARRAY_A
			);
		}

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['wc_product_id'] ] = (int) $row['event_id'];
		}

		return $map;
	}

	/**
	 * Sanitize and validate ticket type input.
	 *
	 * @param array<string, mixed> $input    Raw input.
	 * @param int                  $event_id Owning event.
	 * @return array<string, mixed>|WP_Error
	 */
	private function sanitize( array $input, int $event_id ): array|WP_Error {
		$name = trim( (string) ( $input['name'] ?? '' ) );

		if ( '' === $name ) {
			return new WP_Error( 'eventos_invalid_ticket_type', __( 'A ticket type needs a name.', 'eventos' ), array( 'status' => 400 ) );
		}

		$tier = (string) ( $input['tier'] ?? 'standard' );

		if ( ! in_array( $tier, self::tiers(), true ) ) {
			$tier = 'standard';
		}

		$visibility = (string) ( $input['visibility'] ?? 'public' );

		if ( ! in_array( $visibility, self::visibilities(), true ) ) {
			$visibility = 'public';
		}

		$status = (string) ( $input['status'] ?? 'active' );

		if ( ! in_array( $status, array( 'active', 'paused', 'archived' ), true ) ) {
			$status = 'active';
		}

		$capacity     = array_key_exists( 'capacity', $input ) && null !== $input['capacity'] ? max( 0, (int) $input['capacity'] ) : null;
		$max_per_order = array_key_exists( 'max_per_order', $input ) && null !== $input['max_per_order'] ? max( 1, (int) $input['max_per_order'] ) : null;

		return array(
			'event_id'         => $event_id,
			'name'             => $name,
			'description'      => (string) ( $input['description'] ?? '' ),
			'tier'             => $tier,
			'price'            => 'complimentary' === $tier ? 0.0 : max( 0, (float) ( $input['price'] ?? 0 ) ),
			'capacity'         => $capacity,
			'visibility'       => $visibility,
			'status'           => $status,
			'sale_start'       => '' !== (string) ( $input['sale_start'] ?? '' ) ? (string) $input['sale_start'] : null,
			'sale_end'         => '' !== (string) ( $input['sale_end'] ?? '' ) ? (string) $input['sale_end'] : null,
			'min_per_order'    => max( 1, (int) ( $input['min_per_order'] ?? 1 ) ),
			'max_per_order'    => $max_per_order,
			'waitlist_enabled' => ! empty( $input['waitlist_enabled'] ) ? 1 : 0,
		);
	}

	/**
	 * Next display position for a new ticket type.
	 *
	 * @param int $event_id Event ID.
	 * @return int
	 */
	private function next_position( int $event_id ): int {
		global $wpdb;

		$table = Event_Schema::ticket_types();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$max = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(position) FROM {$table} WHERE event_id = %d", $event_id ) );

		return null === $max ? 0 : (int) $max + 1;
	}

	/**
	 * Store the linked WooCommerce product ID.
	 *
	 * @param int $id         Ticket type ID.
	 * @param int $product_id WooCommerce product ID.
	 * @return void
	 */
	private function set_wc_product_id( int $id, int $product_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Event_Schema::ticket_types(),
			array( 'wc_product_id' => $product_id ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Create or update the WooCommerce product a ticket type sells through.
	 *
	 * Stock is managed on the product itself so WooCommerce enforces capacity
	 * at checkout the same way it enforces stock for any other product.
	 *
	 * @param int                  $ticket_type_id Ticket type ID.
	 * @param array<string, mixed> $data           Ticket type field values.
	 * @param int                  $existing_id    Existing WooCommerce product ID, 0 when none.
	 * @return int WooCommerce product ID, 0 when WooCommerce is unavailable.
	 */
	private function sync_wc_product( int $ticket_type_id, array $data, int $existing_id ): int {
		if ( ! WooCommerce::is_active() ) {
			return 0;
		}

		$product = $existing_id > 0 ? wc_get_product( $existing_id ) : null;

		if ( ! $product instanceof WC_Product ) {
			$product = new WC_Product_Simple();
		}

		$status_map = array(
			'active'   => 'publish',
			'paused'   => 'private',
			'archived' => 'draft',
		);

		$product->set_name( (string) $data['name'] );
		$product->set_status( $status_map[ (string) $data['status'] ] ?? 'publish' );
		$product->set_catalog_visibility( 'search' );
		$product->set_virtual( true );
		$product->set_sold_individually( false );
		$product->set_regular_price( (string) $data['price'] );
		$product->set_price( (string) $data['price'] );
		$product->set_description( (string) $data['description'] );

		if ( null !== $data['capacity'] ) {
			$sold = $this->sold_count( $ticket_type_id );

			$product->set_manage_stock( true );
			$product->set_stock_quantity( max( 0, (int) $data['capacity'] - $sold ) );
			$product->set_backorders( 'no' );
		} else {
			$product->set_manage_stock( false );
			$product->set_stock_status( 'instock' );
		}

		$product_id = $product->save();

		if ( $product_id ) {
			update_post_meta( $product_id, Wc_Meta::EVENT_META, (int) $data['event_id'] );
			update_post_meta( $product_id, Wc_Meta::TICKET_TYPE_META, $ticket_type_id );
		}

		return (int) $product_id;
	}

	/**
	 * Refresh a ticket type's WooCommerce stock quantity from its live sold count.
	 *
	 * Called by the ticket fulfilment flow after tickets are issued or cancelled
	 * so WooCommerce's own stock enforcement never drifts from EventOS state.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return void
	 */
	public function refresh_stock( int $ticket_type_id ): void {
		$type = $this->find( $ticket_type_id );

		if ( null === $type || null === $type['capacity'] || (int) $type['wc_product_id'] <= 0 || ! WooCommerce::is_active() ) {
			return;
		}

		$product = wc_get_product( (int) $type['wc_product_id'] );

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product->set_manage_stock( true );
		$product->set_stock_quantity( max( 0, (int) $type['capacity'] - (int) $type['sold'] ) );
		$product->save();
	}

	/**
	 * Count active (non-cancelled) tickets issued for a ticket type.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int
	 */
	private function sold_count( int $ticket_type_id ): int {
		global $wpdb;

		$table = Event_Schema::tickets();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE ticket_type_id = %d AND status != 'cancelled'",
				$ticket_type_id
			)
		);
	}

	/**
	 * Count of people currently waiting (not promoted/converted/expired/
	 * cancelled) for this ticket type — a direct query rather than a
	 * {@see Waitlist_Repository} dependency, mirroring {@see sold_count()}
	 * right below.
	 *
	 * @param int $ticket_type_id Ticket type ID.
	 * @return int
	 */
	private function waiting_count( int $ticket_type_id ): int {
		global $wpdb;

		$table = Event_Schema::waitlist_entries();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ticket_type_id = %d AND status = 'waiting'", $ticket_type_id )
		);
	}

	/**
	 * Shape a raw row into the API contract, with computed sold/available figures.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		$capacity         = null === $row['capacity'] ? null : (int) $row['capacity'];
		$sold             = $this->sold_count( (int) $row['id'] );
		$effective_status = Ticket_Type_Status::effective( (string) $row['status'], $capacity, $sold );

		return array(
			'id'               => (int) $row['id'],
			'event_id'         => (int) $row['event_id'],
			'wc_product_id'    => (int) $row['wc_product_id'],
			'name'             => (string) $row['name'],
			'description'      => (string) $row['description'],
			'tier'             => (string) $row['tier'],
			'price'            => (float) $row['price'],
			'capacity'         => $capacity,
			'sold'             => $sold,
			'reserved'         => 0,
			'available'        => null !== $capacity ? max( 0, $capacity - $sold ) : null,
			'visibility'       => (string) $row['visibility'],
			'status'           => $effective_status,
			'sale_start'       => $row['sale_start'],
			'sale_end'         => $row['sale_end'],
			'min_per_order'    => (int) $row['min_per_order'],
			'max_per_order'    => null === $row['max_per_order'] ? null : (int) $row['max_per_order'],
			'waitlist_enabled' => (bool) $row['waitlist_enabled'],
			'waitlist_count'   => $row['waitlist_enabled'] ? $this->waiting_count( (int) $row['id'] ) : 0,
			'sort_order'       => (int) $row['position'],
			'created_at'       => (string) $row['created_at'],
			'updated_at'       => (string) $row['updated_at'],
		);
	}
}

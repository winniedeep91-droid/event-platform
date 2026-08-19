<?php
/**
 * Data access and WooCommerce coupon sync for discount campaigns.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

use EventOS\WooCommerce;
use EventOS\Woocommerce\Wc_Meta;
use WC_Coupon;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A discount campaign is owned by EventOS; each one creates and maintains a
 * linked WooCommerce coupon so WooCommerce keeps owning checkout validation
 * and redemption. Usage counts are always read live from the coupon —
 * EventOS never keeps its own copy. Reuses the same {@see Wc_Meta} keys the
 * WooCommerce module's own coupon-to-campaign assignment feature reads, so
 * a campaign-created coupon shows correctly assigned there too.
 */
final class Campaign_Repository {

	/**
	 * Columns that map straight onto the campaigns table.
	 *
	 * @var array<string, string>
	 */
	private const COLUMNS = array(
		'event_id'        => '%d',
		'wc_coupon_id'     => '%d',
		'audience_id'      => '%d',
		'name'             => '%s',
		'code'             => '%s',
		'type'             => '%s',
		'value'            => '%f',
		'status'           => '%s',
		'applies_to'       => '%s',
		'ticket_type_ids'  => '%s',
		'min_spend'        => '%f',
		'max_uses'         => '%d',
		'expires_at'       => '%s',
		'created_at'       => '%s',
		'updated_at'       => '%s',
	);

	/**
	 * Ticket type repository, used to resolve product restrictions.
	 *
	 * @var Ticket_Type_Repository
	 */
	private Ticket_Type_Repository $ticket_types;

	/**
	 * Constructor.
	 *
	 * @param Ticket_Type_Repository $ticket_types Ticket type repository.
	 */
	public function __construct( Ticket_Type_Repository $ticket_types ) {
		$this->ticket_types = $ticket_types;
	}

	/**
	 * Every campaign for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_event( int $event_id ): array {
		global $wpdb;

		$table = Event_Schema::campaigns();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY created_at DESC, id DESC", $event_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Read a single campaign.
	 *
	 * @param int $id Campaign ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Event_Schema::campaigns();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Create a campaign and its linked WooCommerce coupon.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $input    Field values.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create( int $event_id, array $input ) {
		global $wpdb;

		$data = $this->sanitize( $input, $event_id, 0, 'draft' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$now                = current_time( 'mysql', true );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		$db_row = $data;
		unset( $db_row['ticket_type_ids'] );
		$db_row['ticket_type_ids'] = wp_json_encode( $data['ticket_type_ids'] );

		$formats = array();

		foreach ( array_keys( $db_row ) as $column ) {
			$formats[] = self::COLUMNS[ $column ] ?? '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( Event_Schema::campaigns(), $db_row, $formats );

		$id = (int) $wpdb->insert_id;

		$coupon_id = $this->sync_wc_coupon( $id, $data, 0 );

		if ( $coupon_id > 0 ) {
			$this->set_wc_coupon_id( $id, $coupon_id );
		}

		return $this->find( $id );
	}

	/**
	 * Update a campaign and re-sync its WooCommerce coupon.
	 *
	 * @param int                  $id    Campaign ID.
	 * @param array<string, mixed> $input Field values.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update( int $id, array $input ) {
		global $wpdb;

		$existing = $this->find( $id );

		if ( null === $existing ) {
			return new WP_Error( 'eventos_not_found', __( 'That campaign no longer exists.', 'eventos' ), array( 'status' => 404 ) );
		}

		$data = $this->sanitize( $input, (int) $existing['event_id'], $id, (string) $existing['status'] );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		unset( $data['event_id'] );
		$data['updated_at'] = current_time( 'mysql', true );

		$db_row = $data;
		unset( $db_row['ticket_type_ids'] );
		$db_row['ticket_type_ids'] = wp_json_encode( $data['ticket_type_ids'] );

		$formats = array();

		foreach ( array_keys( $db_row ) as $column ) {
			$formats[] = self::COLUMNS[ $column ] ?? '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Event_Schema::campaigns(), $db_row, array( 'id' => $id ), $formats, array( '%d' ) );

		$merged     = array_merge( $existing, $data, array( 'event_id' => (int) $existing['event_id'] ) );
		$coupon_id  = $this->sync_wc_coupon( $id, $merged, (int) $existing['wc_coupon_id'] );

		if ( $coupon_id > 0 && $coupon_id !== (int) $existing['wc_coupon_id'] ) {
			$this->set_wc_coupon_id( $id, $coupon_id );
		}

		return $this->find( $id );
	}

	/**
	 * Archive a campaign and its WooCommerce coupon.
	 *
	 * @param int $id Campaign ID.
	 * @return true|WP_Error
	 */
	public function archive( int $id ) {
		global $wpdb;

		$existing = $this->find( $id );

		if ( null === $existing ) {
			return new WP_Error( 'eventos_not_found', __( 'That campaign no longer exists.', 'eventos' ), array( 'status' => 404 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Event_Schema::campaigns(),
			array(
				'status'     => 'archived',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$coupon_id = (int) $existing['wc_coupon_id'];

		if ( $coupon_id > 0 && WooCommerce::is_active() ) {
			$coupon = new WC_Coupon( $coupon_id );

			if ( $coupon->get_id() ) {
				$coupon->set_status( 'draft' );
				$coupon->save();
			}
		}

		return true;
	}

	/**
	 * Sanitize and validate campaign input.
	 *
	 * `$default_status` is the status to fall back to when `$input` omits
	 * the key entirely — `create()` passes `'draft'` (a new campaign starts
	 * there), `update()` passes the campaign's own current status, so an
	 * edit that never touches status preserves it instead of silently
	 * resetting to draft. Passing an explicit `status` in `$input` always
	 * wins either way, so intentional status changes still work.
	 *
	 * @param array<string, mixed> $input          Raw input.
	 * @param int                  $event_id       Owning event.
	 * @param int                  $exclude_id     Campaign ID to exclude from the code uniqueness check.
	 * @param string               $default_status Status to use when `$input` has none.
	 * @return array<string, mixed>|WP_Error
	 */
	private function sanitize( array $input, int $event_id, int $exclude_id, string $default_status = 'draft' ): array|WP_Error {
		$name = trim( (string) ( $input['name'] ?? '' ) );
		$code = strtoupper( trim( (string) ( $input['code'] ?? '' ) ) );

		if ( '' === $name ) {
			return new WP_Error( 'eventos_invalid_campaign', __( 'A campaign needs a name.', 'eventos' ), array( 'status' => 400 ) );
		}

		if ( '' === $code || ! preg_match( '/^[A-Z0-9_-]+$/', $code ) ) {
			return new WP_Error( 'eventos_invalid_campaign', __( 'A discount code using only letters, numbers, - and _ is required.', 'eventos' ), array( 'status' => 400 ) );
		}

		if ( $this->code_taken( $code, $exclude_id ) ) {
			return new WP_Error( 'eventos_duplicate_code', __( 'That discount code is already in use.', 'eventos' ), array( 'status' => 400 ) );
		}

		$type = 'fixed' === (string) ( $input['type'] ?? 'percent' ) ? 'fixed' : 'percent';

		$value = max( 0, (float) ( $input['value'] ?? 0 ) );

		if ( 'percent' === $type ) {
			$value = min( 100, $value );
		}

		$applies_to = 'specific_types' === (string) ( $input['applies_to'] ?? 'all' ) ? 'specific_types' : 'all';

		$ticket_type_ids = array();

		if ( 'specific_types' === $applies_to ) {
			$ticket_type_ids = array_values( array_unique( array_map( 'intval', (array) ( $input['ticket_type_ids'] ?? array() ) ) ) );
		}

		$status = (string) ( $input['status'] ?? $default_status );

		if ( ! in_array( $status, array( 'draft', 'active', 'paused', 'archived' ), true ) ) {
			$status = $default_status;
		}

		$audience_id = array_key_exists( 'audience_id', $input ) && null !== $input['audience_id'] ? max( 0, (int) $input['audience_id'] ) : 0;

		return array(
			'event_id'        => $event_id,
			'audience_id'     => $audience_id > 0 ? $audience_id : null,
			'name'            => $name,
			'code'            => $code,
			'type'            => $type,
			'value'           => $value,
			'status'          => $status,
			'applies_to'      => $applies_to,
			'ticket_type_ids' => $ticket_type_ids,
			'min_spend'       => array_key_exists( 'min_spend', $input ) && null !== $input['min_spend'] ? max( 0, (float) $input['min_spend'] ) : null,
			'max_uses'        => array_key_exists( 'max_uses', $input ) && null !== $input['max_uses'] ? max( 1, (int) $input['max_uses'] ) : null,
			'expires_at'      => '' !== (string) ( $input['expires_at'] ?? '' ) ? (string) $input['expires_at'] : null,
		);
	}

	/**
	 * Whether a discount code is already used by another campaign or coupon.
	 *
	 * @param string $code       Discount code.
	 * @param int    $exclude_id Campaign ID to exclude.
	 * @return bool
	 */
	private function code_taken( string $code, int $exclude_id ): bool {
		global $wpdb;

		$table = Event_Schema::campaigns();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s AND id != %d", $code, $exclude_id )
		);

		if ( $existing > 0 ) {
			return true;
		}

		if ( WooCommerce::is_active() && function_exists( 'wc_get_coupon_id_by_code' ) ) {
			$campaign        = $exclude_id > 0 ? $this->find( $exclude_id ) : null;
			$exclude_coupon  = null !== $campaign ? (int) $campaign['wc_coupon_id'] : 0;
			$coupon_id       = (int) wc_get_coupon_id_by_code( $code, $exclude_coupon );

			if ( $coupon_id > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Store the linked WooCommerce coupon ID.
	 *
	 * @param int $id        Campaign ID.
	 * @param int $coupon_id WooCommerce coupon ID.
	 * @return void
	 */
	private function set_wc_coupon_id( int $id, int $coupon_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Event_Schema::campaigns(),
			array( 'wc_coupon_id' => $coupon_id ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Create or update the WooCommerce coupon a campaign redeems through.
	 *
	 * @param int                  $campaign_id Campaign ID.
	 * @param array<string, mixed> $data        Campaign field values.
	 * @param int                  $existing_id Existing WooCommerce coupon ID, 0 when none.
	 * @return int WooCommerce coupon ID, 0 when WooCommerce is unavailable.
	 */
	private function sync_wc_coupon( int $campaign_id, array $data, int $existing_id ): int {
		if ( ! WooCommerce::is_active() ) {
			return 0;
		}

		$coupon = $existing_id > 0 ? new WC_Coupon( $existing_id ) : new WC_Coupon();

		if ( $existing_id > 0 && ! $coupon->get_id() ) {
			$coupon = new WC_Coupon();
		}

		$coupon->set_code( (string) $data['code'] );
		$coupon->set_discount_type( 'percent' === $data['type'] ? 'percent' : 'fixed_cart' );
		$coupon->set_amount( (string) $data['value'] );
		/* translators: %s: campaign name. */
		$coupon->set_description( sprintf( __( 'EventOS campaign: %s', 'eventos' ), (string) $data['name'] ) );
		$coupon->set_status( 'active' === $data['status'] ? 'publish' : 'draft' );

		$coupon->set_minimum_amount( null !== $data['min_spend'] ? (string) $data['min_spend'] : '' );
		$coupon->set_usage_limit( null !== $data['max_uses'] ? (int) $data['max_uses'] : 0 );
		$coupon->set_date_expires( $data['expires_at'] ? (string) $data['expires_at'] : null );

		$product_ids = array();

		if ( 'specific_types' === $data['applies_to'] ) {
			foreach ( (array) $data['ticket_type_ids'] as $ticket_type_id ) {
				$type = $this->ticket_types->find( (int) $ticket_type_id );

				if ( null !== $type && (int) $type['wc_product_id'] > 0 ) {
					$product_ids[] = (int) $type['wc_product_id'];
				}
			}
		}

		$coupon->set_product_ids( $product_ids );

		$coupon_id = $coupon->save();

		if ( $coupon_id ) {
			update_post_meta( $coupon_id, Wc_Meta::EVENT_META, (int) $data['event_id'] );
			update_post_meta( $coupon_id, Wc_Meta::CAMPAIGN_META, $campaign_id );
		}

		return (int) $coupon_id;
	}

	/**
	 * Live usage count read straight from the WooCommerce coupon.
	 *
	 * @param int $wc_coupon_id WooCommerce coupon ID.
	 * @return int
	 */
	private function usage_count( int $wc_coupon_id ): int {
		if ( $wc_coupon_id <= 0 || ! WooCommerce::is_active() ) {
			return 0;
		}

		$coupon = new WC_Coupon( $wc_coupon_id );

		return $coupon->get_id() ? (int) $coupon->get_usage_count() : 0;
	}

	/**
	 * Shape a raw row into the DiscountCampaign contract.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		$expires          = $row['expires_at'];
		$effective_status = Campaign_Status::effective( (string) $row['status'], $expires, time() );

		return array(
			'id'              => (int) $row['id'],
			'event_id'        => (int) $row['event_id'],
			'wc_coupon_id'    => (int) $row['wc_coupon_id'],
			'audience_id'     => empty( $row['audience_id'] ) ? null : (int) $row['audience_id'],
			'name'            => (string) $row['name'],
			'code'            => (string) $row['code'],
			'type'            => (string) $row['type'],
			'value'           => (float) $row['value'],
			'status'          => $effective_status,
			'applies_to'      => (string) $row['applies_to'],
			'ticket_type_ids' => (array) json_decode( (string) $row['ticket_type_ids'], true ),
			'min_spend'       => null === $row['min_spend'] ? null : (float) $row['min_spend'],
			'max_uses'        => null === $row['max_uses'] ? null : (int) $row['max_uses'],
			'uses'            => $this->usage_count( (int) $row['wc_coupon_id'] ),
			'expires_at'      => $expires,
			'created_at'      => (string) $row['created_at'],
		);
	}
}

<?php
/**
 * Provider-agnostic Event resolution/auto-provisioning from an external identity.
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
 * Resolves a (type, value) external identity signal — a WooCommerce
 * product-group key, a Quicket event ID, ... — to an EventOS Event,
 * creating one the first time a given identity is seen.
 *
 * Mirrors the role `EventOS\Crm\Person_Resolver::find_or_create()` plays
 * for CRM identity, scoped to Events instead of People. Deliberately
 * carries no WooCommerce- or provider-specific logic: callers supply the
 * `$type`/`$value`/`$event_data`, this class only owns "does an Event
 * already own this identity, and if not, create one and attach it."
 */
final class Event_Identity_Resolver {

	/**
	 * Event service — the only place event data is written.
	 *
	 * @var Event_Service
	 */
	private Event_Service $events;

	/**
	 * Event identity repository.
	 *
	 * @var Event_Identity_Repository
	 */
	private Event_Identity_Repository $identities;

	/**
	 * @param Event_Service              $events     Event service.
	 * @param Event_Identity_Repository $identities Event identity repository.
	 */
	public function __construct( Event_Service $events, Event_Identity_Repository $identities ) {
		$this->events     = $events;
		$this->identities = $identities;
	}

	/**
	 * Resolve an Event by external identity, creating one if none exists.
	 *
	 * @param string               $type       Identity type, e.g. 'wc_event_group' or 'quicket_event_id'.
	 * @param string               $value      External identity value.
	 * @param array<string, mixed> $event_data Payload for {@see Event_Service::create_event()},
	 *                                          used only when a new Event must be created.
	 * @return array{event: array<string, mixed>, created: bool, identity: array<string, mixed>}|WP_Error
	 */
	public function resolve_or_create( string $type, string $value, array $event_data = array() ) {
		$type  = sanitize_key( $type );
		$value = trim( $value );

		if ( '' === $type || '' === $value ) {
			return new WP_Error( 'eventos_event_identity_invalid', __( 'An identity type and value are required.', 'eventos' ), array( 'status' => 400 ) );
		}

		$existing = $this->identities->find_by_type_value( $type, $value );

		if ( null !== $existing ) {
			$event = $this->events->events()->find( (int) $existing['event_id'] );

			if ( null === $event ) {
				return new WP_Error( 'eventos_event_identity_orphaned', __( 'The event this identity points to no longer exists.', 'eventos' ), array( 'status' => 404 ) );
			}

			return array(
				'event'    => $event,
				'created'  => false,
				'identity' => $existing,
			);
		}

		$event = $this->events->create_event( $event_data );

		if ( is_wp_error( $event ) ) {
			return $event;
		}

		$attach = $this->identities->attach_identity( (int) $event['id'], $type, $value );

		if ( 'conflict' === $attach['status'] ) {
			// Lost a race: another request resolved/created this exact
			// identity between our lookup and our create. Keep the event we
			// just created (it simply owns no identity), and hand back the
			// event the identity actually belongs to, so two concurrent
			// resolutions of the same source identity never look like two
			// different events to the caller.
			$owner = $this->events->events()->find( (int) $attach['owner_event_id'] );

			return array(
				'event'    => $owner ?? $event,
				'created'  => false,
				'identity' => $attach['identity'],
			);
		}

		return array(
			'event'    => $event,
			'created'  => true,
			'identity' => $attach['identity'],
		);
	}
}

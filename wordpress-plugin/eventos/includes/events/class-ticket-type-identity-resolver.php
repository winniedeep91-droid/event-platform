<?php
/**
 * Provider-agnostic Ticket Type resolution/auto-provisioning from an external identity.
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
 * Resolves a (type, value) external identity signal — e.g. a Quicket
 * ticket-type ID — to an EventOS Ticket Type under a known Event, creating
 * one the first time a given identity is seen.
 *
 * Mirrors {@see Event_Identity_Resolver}, scoped to Ticket Types. Unlike an
 * Event, a Ticket Type always belongs to an already-resolved Event, so
 * `$event_id` is a required input rather than something this class creates.
 *
 * WooCommerce-sourced ticket types do not go through this class — they use
 * `ticket_types.wc_product_id` directly (see `Ticket_Type_Repository`) — so
 * this resolver carries no WooCommerce logic, only the generic
 * find-or-create-by-identity shape any non-WooCommerce provider needs.
 */
final class Ticket_Type_Identity_Resolver {

	/**
	 * Ticket type repository.
	 *
	 * @var Ticket_Type_Repository
	 */
	private Ticket_Type_Repository $ticket_types;

	/**
	 * Ticket type identity repository.
	 *
	 * @var Ticket_Type_Identity_Repository
	 */
	private Ticket_Type_Identity_Repository $identities;

	/**
	 * @param Ticket_Type_Repository          $ticket_types Ticket type repository.
	 * @param Ticket_Type_Identity_Repository $identities   Ticket type identity repository.
	 */
	public function __construct( Ticket_Type_Repository $ticket_types, Ticket_Type_Identity_Repository $identities ) {
		$this->ticket_types = $ticket_types;
		$this->identities   = $identities;
	}

	/**
	 * Resolve a Ticket Type by external identity, creating one if none exists.
	 *
	 * @param string               $type            Identity type, e.g. 'quicket_ticket_type_id'.
	 * @param string               $value           External identity value.
	 * @param int                  $event_id        Event the ticket type belongs to (already resolved).
	 * @param array<string, mixed> $ticket_type_data Payload for {@see Ticket_Type_Repository::create()},
	 *                                                used only when a new Ticket Type must be created.
	 * @return array{ticket_type: array<string, mixed>, created: bool, identity: array<string, mixed>}|WP_Error
	 */
	public function resolve_or_create( string $type, string $value, int $event_id, array $ticket_type_data = array() ) {
		$type  = sanitize_key( $type );
		$value = trim( $value );

		if ( '' === $type || '' === $value ) {
			return new WP_Error( 'eventos_ticket_type_identity_invalid', __( 'An identity type and value are required.', 'eventos' ), array( 'status' => 400 ) );
		}

		if ( $event_id <= 0 ) {
			return new WP_Error( 'eventos_ticket_type_identity_no_event', __( 'A resolved event is required before a ticket type can be created.', 'eventos' ), array( 'status' => 400 ) );
		}

		$existing = $this->identities->find_by_type_value( $type, $value );

		if ( null !== $existing ) {
			$ticket_type = $this->ticket_types->find( (int) $existing['ticket_type_id'] );

			if ( null === $ticket_type ) {
				return new WP_Error( 'eventos_ticket_type_identity_orphaned', __( 'The ticket type this identity points to no longer exists.', 'eventos' ), array( 'status' => 404 ) );
			}

			return array(
				'ticket_type' => $ticket_type,
				'created'     => false,
				'identity'    => $existing,
			);
		}

		$ticket_type = $this->ticket_types->create( $event_id, $ticket_type_data );

		if ( is_wp_error( $ticket_type ) ) {
			return $ticket_type;
		}

		$attach = $this->identities->attach_identity( (int) $ticket_type['id'], $type, $value );

		if ( 'conflict' === $attach['status'] ) {
			// Lost a race: another request resolved/created this exact
			// identity between our lookup and our create. Keep the ticket
			// type we just created (it simply owns no identity), and hand
			// back the ticket type the identity actually belongs to.
			$owner = $this->ticket_types->find( (int) $attach['owner_ticket_type_id'] );

			return array(
				'ticket_type' => $owner ?? $ticket_type,
				'created'     => false,
				'identity'    => $attach['identity'],
			);
		}

		return array(
			'ticket_type' => $ticket_type,
			'created'     => true,
			'identity'    => $attach['identity'],
		);
	}
}

<?php
/**
 * Provider-agnostic Ticket resolution/auto-provisioning from an external identity.
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
 * ticket ID — to an EventOS Ticket, issuing one the first time a given
 * identity is seen.
 *
 * Mirrors {@see Event_Identity_Resolver}/{@see Ticket_Type_Identity_Resolver}.
 * WooCommerce-sourced tickets do not go through this class — they dedupe
 * via `Ticket_Repository::exists_for_order_item()` — so this resolver
 * carries no WooCommerce logic, only the generic find-or-issue-by-identity
 * shape a non-WooCommerce provider needs.
 */
final class Ticket_Identity_Resolver {

	/**
	 * Ticket repository.
	 *
	 * @var Ticket_Repository
	 */
	private Ticket_Repository $tickets;

	/**
	 * Ticket identity repository.
	 *
	 * @var Ticket_Identity_Repository
	 */
	private Ticket_Identity_Repository $identities;

	/**
	 * @param Ticket_Repository          $tickets    Ticket repository.
	 * @param Ticket_Identity_Repository $identities Ticket identity repository.
	 */
	public function __construct( Ticket_Repository $tickets, Ticket_Identity_Repository $identities ) {
		$this->tickets    = $tickets;
		$this->identities = $identities;
	}

	/**
	 * Resolve a Ticket by external identity, issuing one if none exists.
	 *
	 * @param string               $type        Identity type, e.g. 'quicket_ticket_id'.
	 * @param string               $value       External identity value.
	 * @param array<string, mixed> $ticket_data Payload for {@see Ticket_Repository::issue()},
	 *                                          used only when a new Ticket must be issued.
	 * @return array{ticket: array<string, mixed>, created: bool, identity: array<string, mixed>}|WP_Error
	 */
	public function resolve_or_create( string $type, string $value, array $ticket_data ) {
		$type  = sanitize_key( $type );
		$value = trim( $value );

		if ( '' === $type || '' === $value ) {
			return new WP_Error( 'eventos_ticket_identity_invalid', __( 'An identity type and value are required.', 'eventos' ), array( 'status' => 400 ) );
		}

		$existing = $this->identities->find_by_type_value( $type, $value );

		if ( null !== $existing ) {
			$ticket = $this->tickets->find( (int) $existing['ticket_id'] );

			if ( null === $ticket ) {
				return new WP_Error( 'eventos_ticket_identity_orphaned', __( 'The ticket this identity points to no longer exists.', 'eventos' ), array( 'status' => 404 ) );
			}

			return array(
				'ticket'   => $ticket,
				'created'  => false,
				'identity' => $existing,
			);
		}

		$ticket = $this->tickets->issue( $ticket_data );

		$attach = $this->identities->attach_identity( (int) $ticket['id'], $type, $value );

		if ( 'conflict' === $attach['status'] ) {
			// Lost a race: another request resolved/issued this exact
			// identity between our lookup and our issue. Keep the ticket we
			// just issued (it simply owns no identity), and hand back the
			// ticket the identity actually belongs to.
			$owner = $this->tickets->find( (int) $attach['owner_ticket_id'] );

			return array(
				'ticket'   => $owner ?? $ticket,
				'created'  => false,
				'identity' => $attach['identity'],
			);
		}

		return array(
			'ticket'   => $ticket,
			'created'  => true,
			'identity' => $attach['identity'],
		);
	}
}

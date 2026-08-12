<?php
/**
 * Ticket type status and capacity rules.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure PHP, no WordPress or database dependency — the stored status
 * (`active`|`paused`|`archived`) plus live capacity/sold figures decide what
 * status a ticket type actually shows as.
 */
final class Ticket_Type_Status {

	/**
	 * The effective status shown to clients.
	 *
	 * `paused` and `archived` are explicit operator decisions and always
	 * win; otherwise a full ticket type is `sold_out`, and anything else
	 * is `active`.
	 *
	 * @param string   $stored_status Stored status column value.
	 * @param int|null $capacity      Ticket type capacity, null when unlimited.
	 * @param int      $sold          Currently sold (non-cancelled) tickets.
	 * @return string
	 */
	public static function effective( string $stored_status, ?int $capacity, int $sold ): string {
		if ( in_array( $stored_status, array( 'paused', 'archived' ), true ) ) {
			return $stored_status;
		}

		if ( null !== $capacity && $sold >= $capacity ) {
			return 'sold_out';
		}

		return 'active';
	}

	/**
	 * Whether issuing `$requested` more tickets would oversell a ticket type.
	 *
	 * Unlimited (null) capacity can never be exceeded.
	 *
	 * @param int|null $capacity  Ticket type capacity, null when unlimited.
	 * @param int      $sold      Currently sold (non-cancelled) tickets.
	 * @param int      $requested Tickets about to be issued.
	 * @return bool
	 */
	public static function would_exceed_capacity( ?int $capacity, int $sold, int $requested ): bool {
		return null !== $capacity && ( $sold + $requested ) > $capacity;
	}
}

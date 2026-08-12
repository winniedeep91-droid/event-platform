<?php
/**
 * Ticket number and QR token generation.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure PHP, no WordPress or database dependency — deliberately, so this is
 * the one piece of the ticket lifecycle that can be unit tested without a
 * WordPress runtime. {@see Ticket_Repository::issue()} is the only caller.
 */
final class Ticket_Identifier {

	/**
	 * Characters used for the random part of a ticket number.
	 *
	 * Excludes 0/O and 1/I/L, which are easy to mistype when a door
	 * operator has to key a ticket number in by hand.
	 */
	private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

	/**
	 * A human-typeable, event-scoped ticket number.
	 *
	 * Not the security boundary — {@see self::qr_token()} is — so it is
	 * intentionally readable rather than maximally random.
	 *
	 * @param int $event_id Event ID.
	 * @return string
	 */
	public static function ticket_number( int $event_id ): string {
		return sprintf( 'EVT%d-%s', $event_id, self::random_alphabet_string( 8 ) );
	}

	/**
	 * A cryptographically random, effectively unforgeable QR/check-in token.
	 *
	 * 20 random bytes (160 bits) hex-encoded to 40 characters: resistant to
	 * guessing and, combined with the database's unique constraint, to
	 * accidental duplicate issuance.
	 *
	 * @return string
	 */
	public static function qr_token(): string {
		return bin2hex( random_bytes( 20 ) );
	}

	/**
	 * A random string drawn from {@see self::ALPHABET}.
	 *
	 * @param int $length Desired length.
	 * @return string
	 */
	private static function random_alphabet_string( int $length ): string {
		$max    = strlen( self::ALPHABET ) - 1;
		$result = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$result .= self::ALPHABET[ random_int( 0, $max ) ];
		}

		return $result;
	}
}

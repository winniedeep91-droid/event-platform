<?php
/**
 * Identity signal normalization for the CRM module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Crm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure PHP, no WordPress or database dependency.
 *
 * Email is the only signal this normalizes for identity *matching* — see
 * {@see Person_Resolver}. Phone normalization exists solely so a Person's
 * stored phone number is formatted consistently; it is deliberately never
 * used as an identity-resolution signal (see the Person_Resolver docblock
 * for why: two different people can share a landline/office number, and
 * South African local-format vs. E.164 equivalence — 082... vs +2782... —
 * cannot be safely assumed without knowing it is genuinely the same
 * subscriber, so this class does not attempt that fold).
 */
final class Identity_Normalizer {

	/**
	 * Normalize an email address for identity matching and storage.
	 *
	 * Trims surrounding whitespace and lowercases the address. Does not
	 * perform RFC validation — an empty or whitespace-only input normalizes
	 * to '', which callers treat as "no email signal" rather than an error.
	 *
	 * @param string $email Raw email address.
	 * @return string
	 */
	public static function normalize_email( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Normalize a phone number for consistent storage.
	 *
	 * Strips whitespace and punctuation, keeping only digits and a leading
	 * "+" when present. Does not attempt to fold local-format and
	 * international-format numbers into an equivalent value — see the class
	 * docblock. An empty or unparsable input normalizes to ''.
	 *
	 * @param string $phone Raw phone number.
	 * @return string
	 */
	public static function normalize_phone( string $phone ): string {
		$phone = trim( $phone );

		if ( '' === $phone ) {
			return '';
		}

		$has_plus = 0 === strpos( $phone, '+' );
		$digits   = preg_replace( '/\D+/', '', $phone );

		if ( null === $digits || '' === $digits ) {
			return '';
		}

		return $has_plus ? '+' . $digits : $digits;
	}
}

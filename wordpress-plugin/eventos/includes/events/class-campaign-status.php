<?php
/**
 * Discount campaign status rules.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure PHP, no WordPress or database dependency.
 */
final class Campaign_Status {

	/**
	 * The effective status shown to clients.
	 *
	 * `draft`, `paused` and `archived` are explicit operator decisions and
	 * always win; otherwise an expired campaign is `expired`, and anything
	 * else is `active`.
	 *
	 * @param string      $stored_status Stored status column value.
	 * @param string|null $expires_at    MySQL datetime string, null when it never expires.
	 * @param int         $now           Current unix timestamp.
	 * @return string
	 */
	public static function effective( string $stored_status, ?string $expires_at, int $now ): string {
		if ( in_array( $stored_status, array( 'draft', 'paused', 'archived' ), true ) ) {
			return $stored_status;
		}

		if ( $expires_at && strtotime( $expires_at ) < $now ) {
			return 'expired';
		}

		return 'active';
	}
}

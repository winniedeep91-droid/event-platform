<?php
/**
 * Event status vocabulary and transition rules.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for event lifecycle states.
 */
final class Event_Status {

	public const DRAFT     = 'draft';
	public const PUBLISHED = 'published';
	public const ARCHIVED  = 'archived';
	public const CANCELLED = 'cancelled';
	public const POSTPONED = 'postponed';

	/**
	 * Every known status.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::DRAFT, self::PUBLISHED, self::POSTPONED, self::CANCELLED, self::ARCHIVED );
	}

	/**
	 * Status labels keyed by slug.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			self::DRAFT     => __( 'Draft', 'eventos' ),
			self::PUBLISHED => __( 'Published', 'eventos' ),
			self::POSTPONED => __( 'Postponed', 'eventos' ),
			self::CANCELLED => __( 'Cancelled', 'eventos' ),
			self::ARCHIVED  => __( 'Archived', 'eventos' ),
		);
	}

	/**
	 * Whether a status string is valid.
	 *
	 * @param string $status Status slug.
	 * @return bool
	 */
	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	/**
	 * Statuses an event may move to from its current status.
	 *
	 * @param string $status Current status.
	 * @return string[]
	 */
	public static function transitions( string $status ): array {
		$map = array(
			self::DRAFT     => array( self::PUBLISHED, self::CANCELLED, self::ARCHIVED ),
			self::PUBLISHED => array( self::POSTPONED, self::CANCELLED, self::ARCHIVED, self::DRAFT ),
			self::POSTPONED => array( self::PUBLISHED, self::CANCELLED, self::ARCHIVED ),
			self::CANCELLED => array( self::DRAFT, self::ARCHIVED ),
			self::ARCHIVED  => array( self::DRAFT ),
		);

		return $map[ $status ] ?? array();
	}

	/**
	 * Visibility options.
	 *
	 * @return array<string, string>
	 */
	public static function visibilities(): array {
		return array(
			'public'   => __( 'Public', 'eventos' ),
			'private'  => __( 'Private', 'eventos' ),
			'password' => __( 'Password protected', 'eventos' ),
		);
	}

	/**
	 * Ticket visibility options.
	 *
	 * @return array<string, string>
	 */
	public static function ticket_visibilities(): array {
		return array(
			'public'    => __( 'Visible to everyone', 'eventos' ),
			'hidden'    => __( 'Hidden', 'eventos' ),
			'members'   => __( 'Members only', 'eventos' ),
			'scheduled' => __( 'Visible when sales open', 'eventos' ),
		);
	}
}

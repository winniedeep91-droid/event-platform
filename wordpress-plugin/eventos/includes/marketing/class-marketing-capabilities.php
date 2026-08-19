<?php
/**
 * Capability vocabulary owned by the Marketing module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Existing Marketing routes (campaigns, promo links) still gate writes on
 * {@see \EventOS\Events\Event_Capabilities::MANAGE_EVENTS} — unchanged in
 * this sprint, since that surface is already working and out of scope to
 * touch. This capability is scoped to the new audience surface only, and
 * exists so the `marketing` role (already registered by
 * {@see \EventOS\Events\Event_Capabilities::grants()}, but previously only
 * ever granted `VIEW_EVENTS`) can genuinely manage something.
 */
final class Marketing_Capabilities {

	/**
	 * Manage Marketing audiences.
	 */
	public const MANAGE_MARKETING = 'eventos_manage_marketing';

	/**
	 * Capability => label map handed to the permission engine.
	 *
	 * @return array<string, string>
	 */
	public static function definitions(): array {
		return array(
			self::MANAGE_MARKETING => __( 'Manage Marketing audiences and campaigns', 'eventos' ),
		);
	}

	/**
	 * Extra grants applied to roles that already exist in core.
	 *
	 * @return array<string, string[]>
	 */
	public static function grants(): array {
		return array(
			'administrator' => array( self::MANAGE_MARKETING ),
			'event_manager' => array( self::MANAGE_MARKETING ),
			'marketing'     => array( self::MANAGE_MARKETING ),
		);
	}
}

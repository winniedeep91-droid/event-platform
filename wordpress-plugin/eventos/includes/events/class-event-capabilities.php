<?php
/**
 * Capability vocabulary owned by the Events module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability keys the Events module registers with the permission engine.
 */
final class Event_Capabilities {

	public const MANAGE_EVENTS  = 'eventos_manage_events';
	public const PUBLISH_EVENTS = 'eventos_publish_events';
	public const DELETE_EVENTS  = 'eventos_delete_events';
	public const MANAGE_VENUES  = 'eventos_manage_venues';
	public const MANAGE_ARTISTS = 'eventos_manage_artists';
	public const MANAGE_TERMS   = 'eventos_manage_event_terms';
	public const VIEW_EVENTS    = 'eventos_view_events';

	/**
	 * Capability => label map handed to the permission engine.
	 *
	 * @return array<string, string>
	 */
	public static function definitions(): array {
		return array(
			self::VIEW_EVENTS    => __( 'View events', 'eventos' ),
			self::MANAGE_EVENTS  => __( 'Manage events', 'eventos' ),
			self::PUBLISH_EVENTS => __( 'Publish and unpublish events', 'eventos' ),
			self::DELETE_EVENTS  => __( 'Delete events', 'eventos' ),
			self::MANAGE_VENUES  => __( 'Manage venues', 'eventos' ),
			self::MANAGE_ARTISTS => __( 'Manage artists', 'eventos' ),
			self::MANAGE_TERMS   => __( 'Manage event categories and tags', 'eventos' ),
		);
	}

	/**
	 * Extra grants applied to roles that already exist in core.
	 *
	 * @return array<string, string[]>
	 */
	public static function grants(): array {
		$full = array_keys( self::definitions() );

		return array(
			'administrator' => $full,
			'event_manager' => $full,
			'marketing'     => array( self::VIEW_EVENTS ),
			'finance'       => array( self::VIEW_EVENTS ),
			'door_staff'    => array( self::VIEW_EVENTS ),
		);
	}
}

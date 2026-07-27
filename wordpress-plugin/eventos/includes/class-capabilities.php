<?php
/**
 * EventOS roles and capability mapping on top of WordPress users.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability registry.
 *
 * EventOS roles are stored as user meta so a single WordPress user can hold
 * several EventOS roles at once, while WordPress roles keep their own meaning.
 */
final class Capabilities {

	/**
	 * User meta key holding the list of EventOS role slugs.
	 */
	public const USER_META_KEY = 'eventos_roles';

	/**
	 * Capability required to manage EventOS configuration.
	 */
	public const MANAGE_SETTINGS = 'eventos_manage_settings';

	/**
	 * Capability required to view the EventOS admin area.
	 */
	public const VIEW_DASHBOARD = 'eventos_view_dashboard';

	/**
	 * Capability required to manage team members and invitations.
	 */
	public const MANAGE_TEAM = 'eventos_manage_team';

	/**
	 * Every capability EventOS defines, keyed by capability with a label.
	 *
	 * @return array<string, string>
	 */
	public static function all_capabilities(): array {
		return array(
			self::VIEW_DASHBOARD     => __( 'View EventOS dashboard', 'eventos' ),
			self::MANAGE_SETTINGS    => __( 'Manage EventOS settings', 'eventos' ),
			self::MANAGE_TEAM        => __( 'Manage team and invitations', 'eventos' ),
			'eventos_manage_events'  => __( 'Manage events', 'eventos' ),
			'eventos_manage_tickets' => __( 'Manage ticketing', 'eventos' ),
			'eventos_manage_orders'  => __( 'Manage orders', 'eventos' ),
			'eventos_view_finance'   => __( 'View finance and reports', 'eventos' ),
			'eventos_manage_finance' => __( 'Manage finance', 'eventos' ),
			'eventos_manage_crm'     => __( 'Manage customers and CRM', 'eventos' ),
			'eventos_manage_market'  => __( 'Manage marketing campaigns', 'eventos' ),
			'eventos_scan_tickets'   => __( 'Scan tickets at the door', 'eventos' ),
			'eventos_view_door'      => __( 'Access door management', 'eventos' ),
		);
	}

	/**
	 * EventOS role definitions.
	 *
	 * @return array<string, array{label: string, capabilities: string[]}>
	 */
	public static function roles(): array {
		$all = array_keys( self::all_capabilities() );

		$roles = array(
			'owner'         => array(
				'label'        => __( 'Owner', 'eventos' ),
				'capabilities' => $all,
			),
			'administrator' => array(
				'label'        => __( 'Administrator', 'eventos' ),
				'capabilities' => array_values( array_diff( $all, array( 'eventos_manage_finance' ) ) ),
			),
			'finance'       => array(
				'label'        => __( 'Finance', 'eventos' ),
				'capabilities' => array(
					self::VIEW_DASHBOARD,
					'eventos_view_finance',
					'eventos_manage_finance',
					'eventos_manage_orders',
				),
			),
			'marketing'     => array(
				'label'        => __( 'Marketing', 'eventos' ),
				'capabilities' => array(
					self::VIEW_DASHBOARD,
					'eventos_manage_market',
					'eventos_manage_crm',
				),
			),
			'event_manager' => array(
				'label'        => __( 'Event Manager', 'eventos' ),
				'capabilities' => array(
					self::VIEW_DASHBOARD,
					'eventos_manage_events',
					'eventos_manage_tickets',
					'eventos_manage_orders',
					'eventos_view_door',
				),
			),
			'door_staff'    => array(
				'label'        => __( 'Door Staff', 'eventos' ),
				'capabilities' => array(
					self::VIEW_DASHBOARD,
					'eventos_view_door',
					'eventos_scan_tickets',
				),
			),
			'scanner'       => array(
				'label'        => __( 'Scanner', 'eventos' ),
				'capabilities' => array(
					'eventos_scan_tickets',
				),
			),
		);

		/**
		 * Filter the EventOS role definitions.
		 *
		 * @param array<string, array{label: string, capabilities: string[]}> $roles Role map.
		 */
		return (array) apply_filters( 'eventos_roles', $roles );
	}

	/**
	 * Register EventOS capabilities on the WordPress administrator role.
	 *
	 * WordPress administrators always retain full EventOS access so a site owner
	 * can never lock themselves out of the plugin.
	 *
	 * @return void
	 */
	public static function install_roles(): void {
		$admin = get_role( 'administrator' );

		if ( ! $admin instanceof \WP_Role ) {
			return;
		}

		foreach ( array_keys( self::all_capabilities() ) as $capability ) {
			$admin->add_cap( $capability );
		}
	}

	/**
	 * EventOS role slugs assigned to a user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string[]
	 */
	public static function get_user_roles( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::USER_META_KEY, true );
		$stored = is_array( $stored ) ? $stored : array();

		return array_values( array_intersect( $stored, array_keys( self::roles() ) ) );
	}

	/**
	 * Replace the EventOS roles assigned to a user.
	 *
	 * @param int      $user_id WordPress user ID.
	 * @param string[] $roles   Role slugs.
	 * @return string[] Roles that were stored.
	 */
	public static function set_user_roles( int $user_id, array $roles ): array {
		$valid = array_values( array_unique( array_intersect( $roles, array_keys( self::roles() ) ) ) );

		update_user_meta( $user_id, self::USER_META_KEY, $valid );

		/**
		 * Fires after a user's EventOS roles changed.
		 *
		 * @param int      $user_id User ID.
		 * @param string[] $valid   Assigned role slugs.
		 */
		do_action( 'eventos_user_roles_updated', $user_id, $valid );

		return $valid;
	}

	/**
	 * Capabilities granted to a user through their EventOS roles.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string[]
	 */
	public static function get_user_capabilities( int $user_id ): array {
		$roles        = self::roles();
		$capabilities = array();

		foreach ( self::get_user_roles( $user_id ) as $slug ) {
			$capabilities = array_merge( $capabilities, $roles[ $slug ]['capabilities'] );
		}

		return array_values( array_unique( $capabilities ) );
	}

	/**
	 * Grant EventOS capabilities derived from EventOS roles.
	 *
	 * Hooked on `user_has_cap` so `current_user_can()` works everywhere.
	 *
	 * @param array<string, bool> $allcaps All capabilities of the user.
	 * @param string[]            $caps    Required primitive capabilities.
	 * @param array               $args    Arguments passed to has_cap().
	 * @param WP_User             $user    The user object.
	 * @return array<string, bool>
	 */
	public static function filter_user_has_cap( array $allcaps, array $caps, array $args, $user ): array {
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return $allcaps;
		}

		foreach ( self::get_user_capabilities( (int) $user->ID ) as $capability ) {
			$allcaps[ $capability ] = true;
		}

		return $allcaps;
	}

	/**
	 * Register capability hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_user_has_cap' ), 10, 4 );
	}
}

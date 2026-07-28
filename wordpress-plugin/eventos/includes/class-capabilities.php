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
 * Assignment layer between WordPress users and the EventOS permission engine.
 *
 * Capability and role *definitions* live in {@see Permissions}. This class owns
 * the assignment of those roles to WordPress users and the bridge into
 * `current_user_can()`. EventOS roles are stored as user meta so a single
 * WordPress user can hold several EventOS roles at once.
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
	 * Capability required to enable, disable and inspect modules.
	 */
	public const MANAGE_MODULES = 'eventos_manage_modules';

	/**
	 * Capability required to read the activity log and job history.
	 */
	public const VIEW_LOGS = 'eventos_view_logs';

	/**
	 * Capability required to run imports.
	 */
	public const RUN_IMPORTS = 'eventos_run_imports';

	/**
	 * Capability required to export data.
	 */
	public const RUN_EXPORTS = 'eventos_run_exports';

	/**
	 * Every capability EventOS knows about, keyed by capability with a label.
	 *
	 * @return array<string, string>
	 */
	public static function all_capabilities(): array {
		return Permissions::capabilities();
	}

	/**
	 * EventOS role definitions.
	 *
	 * @return array<string, array{label: string, description: string, capabilities: string[], core: bool}>
	 */
	public static function roles(): array {
		return Permissions::roles();
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
		$previous = self::get_user_roles( $user_id );
		$valid    = array_values( array_unique( array_intersect( $roles, array_keys( self::roles() ) ) ) );

		update_user_meta( $user_id, self::USER_META_KEY, $valid );

		/**
		 * Fires after a user's EventOS roles changed.
		 *
		 * @param int      $user_id  User ID.
		 * @param string[] $valid    Assigned role slugs.
		 * @param string[] $previous Previously assigned role slugs.
		 */
		do_action( 'eventos_user_roles_updated', $user_id, $valid, $previous );

		return $valid;
	}

	/**
	 * Capabilities granted to a user through their EventOS roles.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string[]
	 */
	public static function get_user_capabilities( int $user_id ): array {
		return Permissions::capabilities_for_roles( self::get_user_roles( $user_id ) );
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
		Permissions::bootstrap();

		add_filter( 'user_has_cap', array( __CLASS__, 'filter_user_has_cap' ), 10, 4 );
	}
}

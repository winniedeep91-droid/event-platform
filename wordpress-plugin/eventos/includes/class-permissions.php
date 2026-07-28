<?php
/**
 * Permission engine: the single source of truth for EventOS capabilities and roles.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry of capabilities and roles.
 *
 * Modules register their own capabilities, whole roles, or extra grants for an
 * existing role. Nothing in core needs editing when a module ships new
 * permissions.
 */
final class Permissions {

	/**
	 * Registered capabilities keyed by capability, valued by label.
	 *
	 * @var array<string, string>
	 */
	private static array $capabilities = array();

	/**
	 * Registered roles keyed by slug.
	 *
	 * @var array<string, array{label: string, description: string, capabilities: string[], core: bool}>
	 */
	private static array $roles = array();

	/**
	 * Whether the built in definitions have been loaded.
	 *
	 * @var bool
	 */
	private static bool $bootstrapped = false;

	/**
	 * Load the core capability and role definitions once.
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		if ( self::$bootstrapped ) {
			return;
		}

		self::$bootstrapped = true;

		self::register_capabilities(
			array(
				Capabilities::VIEW_DASHBOARD  => __( 'View EventOS dashboard', 'eventos' ),
				Capabilities::MANAGE_SETTINGS => __( 'Manage EventOS settings', 'eventos' ),
				Capabilities::MANAGE_TEAM     => __( 'Manage team and invitations', 'eventos' ),
				Capabilities::MANAGE_MODULES  => __( 'Manage EventOS modules', 'eventos' ),
				Capabilities::VIEW_LOGS       => __( 'View activity log and jobs', 'eventos' ),
				Capabilities::RUN_IMPORTS     => __( 'Run data imports', 'eventos' ),
				Capabilities::RUN_EXPORTS     => __( 'Export data', 'eventos' ),
				'eventos_manage_events'       => __( 'Manage events', 'eventos' ),
				'eventos_manage_tickets'      => __( 'Manage ticketing', 'eventos' ),
				'eventos_manage_orders'       => __( 'Manage orders', 'eventos' ),
				'eventos_view_finance'        => __( 'View finance and reports', 'eventos' ),
				'eventos_manage_finance'      => __( 'Manage finance', 'eventos' ),
				'eventos_manage_crm'          => __( 'Manage customers and CRM', 'eventos' ),
				'eventos_manage_market'       => __( 'Manage marketing campaigns', 'eventos' ),
				'eventos_scan_tickets'        => __( 'Scan tickets at the door', 'eventos' ),
				'eventos_view_door'           => __( 'Access door management', 'eventos' ),
			)
		);

		$all = array_keys( self::$capabilities );

		self::register_role(
			'owner',
			__( 'Owner', 'eventos' ),
			$all,
			__( 'Unrestricted access to every EventOS feature.', 'eventos' )
		);
		self::register_role(
			'administrator',
			__( 'Administrator', 'eventos' ),
			array_values( array_diff( $all, array( 'eventos_manage_finance' ) ) ),
			__( 'Runs the platform day to day without finance controls.', 'eventos' )
		);
		self::register_role(
			'finance',
			__( 'Finance', 'eventos' ),
			array(
				Capabilities::VIEW_DASHBOARD,
				Capabilities::RUN_EXPORTS,
				'eventos_view_finance',
				'eventos_manage_finance',
				'eventos_manage_orders',
			),
			__( 'Reconciliation, payouts and financial reporting.', 'eventos' )
		);
		self::register_role(
			'marketing',
			__( 'Marketing', 'eventos' ),
			array(
				Capabilities::VIEW_DASHBOARD,
				Capabilities::RUN_EXPORTS,
				'eventos_manage_market',
				'eventos_manage_crm',
			),
			__( 'Campaigns, audiences and customer communication.', 'eventos' )
		);
		self::register_role(
			'event_manager',
			__( 'Event Manager', 'eventos' ),
			array(
				Capabilities::VIEW_DASHBOARD,
				'eventos_manage_events',
				'eventos_manage_tickets',
				'eventos_manage_orders',
				'eventos_view_door',
			),
			__( 'Builds and operates events and their ticket types.', 'eventos' )
		);
		self::register_role(
			'door_staff',
			__( 'Door Staff', 'eventos' ),
			array(
				Capabilities::VIEW_DASHBOARD,
				'eventos_view_door',
				'eventos_scan_tickets',
			),
			__( 'Guest list and door operations on event day.', 'eventos' )
		);
		self::register_role(
			'scanner',
			__( 'Scanner', 'eventos' ),
			array( 'eventos_scan_tickets' ),
			__( 'Ticket scanning only, no dashboard access.', 'eventos' )
		);

		/**
		 * Fires once so modules can register capabilities and roles.
		 *
		 * @param string $registry Permissions class name.
		 */
		do_action( 'eventos_register_permissions', __CLASS__ );
	}

	/**
	 * Register a single capability.
	 *
	 * @param string $capability Capability key.
	 * @param string $label      Human readable label.
	 * @return void
	 */
	public static function register_capability( string $capability, string $label ): void {
		$capability = sanitize_key( $capability );

		if ( '' === $capability ) {
			return;
		}

		self::$capabilities[ $capability ] = $label;
	}

	/**
	 * Register several capabilities at once.
	 *
	 * @param array<string, string> $capabilities Capability => label map.
	 * @return void
	 */
	public static function register_capabilities( array $capabilities ): void {
		foreach ( $capabilities as $capability => $label ) {
			self::register_capability( (string) $capability, (string) $label );
		}
	}

	/**
	 * Register a role.
	 *
	 * @param string   $slug         Role slug.
	 * @param string   $label        Role label.
	 * @param string[] $capabilities Capabilities granted.
	 * @param string   $description  Role description.
	 * @param bool     $core         Whether the role ships with EventOS core.
	 * @return void
	 */
	public static function register_role( string $slug, string $label, array $capabilities, string $description = '', bool $core = true ): void {
		$slug = sanitize_key( $slug );

		if ( '' === $slug ) {
			return;
		}

		self::$roles[ $slug ] = array(
			'label'        => $label,
			'description'  => $description,
			'capabilities' => array_values( array_unique( array_map( 'sanitize_key', $capabilities ) ) ),
			'core'         => $core,
		);
	}

	/**
	 * Grant extra capabilities to an existing role.
	 *
	 * @param string   $slug         Role slug.
	 * @param string[] $capabilities Capabilities to add.
	 * @return void
	 */
	public static function grant( string $slug, array $capabilities ): void {
		$slug = sanitize_key( $slug );

		if ( ! isset( self::$roles[ $slug ] ) ) {
			return;
		}

		self::$roles[ $slug ]['capabilities'] = array_values(
			array_unique( array_merge( self::$roles[ $slug ]['capabilities'], array_map( 'sanitize_key', $capabilities ) ) )
		);
	}

	/**
	 * Apply a module permission declaration.
	 *
	 * @param array<string, mixed> $declaration Declaration from Module_Interface::permissions().
	 * @return void
	 */
	public static function register_declaration( array $declaration ): void {
		if ( ! empty( $declaration['capabilities'] ) && is_array( $declaration['capabilities'] ) ) {
			self::register_capabilities( $declaration['capabilities'] );
		}

		if ( ! empty( $declaration['roles'] ) && is_array( $declaration['roles'] ) ) {
			foreach ( $declaration['roles'] as $slug => $role ) {
				self::register_role(
					(string) $slug,
					(string) ( $role['label'] ?? $slug ),
					(array) ( $role['capabilities'] ?? array() ),
					(string) ( $role['description'] ?? '' ),
					false
				);
			}
		}

		if ( ! empty( $declaration['grants'] ) && is_array( $declaration['grants'] ) ) {
			foreach ( $declaration['grants'] as $slug => $capabilities ) {
				self::grant( (string) $slug, (array) $capabilities );
			}
		}

		// The owner role always holds everything EventOS knows about.
		self::grant( 'owner', array_keys( self::$capabilities ) );
	}

	/**
	 * All registered capabilities.
	 *
	 * @return array<string, string>
	 */
	public static function capabilities(): array {
		self::bootstrap();

		/**
		 * Filter the registered EventOS capabilities.
		 *
		 * @param array<string, string> $capabilities Capability => label.
		 */
		return (array) apply_filters( 'eventos_capabilities', self::$capabilities );
	}

	/**
	 * All registered roles.
	 *
	 * @return array<string, array{label: string, description: string, capabilities: string[], core: bool}>
	 */
	public static function roles(): array {
		self::bootstrap();

		/**
		 * Filter the registered EventOS roles.
		 *
		 * @param array $roles Role definitions keyed by slug.
		 */
		return (array) apply_filters( 'eventos_roles', self::$roles );
	}

	/**
	 * Whether a capability is registered.
	 *
	 * @param string $capability Capability key.
	 * @return bool
	 */
	public static function capability_exists( string $capability ): bool {
		return array_key_exists( $capability, self::capabilities() );
	}

	/**
	 * Capabilities granted by a set of roles.
	 *
	 * @param string[] $role_slugs Role slugs.
	 * @return string[]
	 */
	public static function capabilities_for_roles( array $role_slugs ): array {
		$roles        = self::roles();
		$capabilities = array();

		foreach ( $role_slugs as $slug ) {
			if ( isset( $roles[ $slug ] ) ) {
				$capabilities = array_merge( $capabilities, $roles[ $slug ]['capabilities'] );
			}
		}

		return array_values( array_unique( $capabilities ) );
	}
}

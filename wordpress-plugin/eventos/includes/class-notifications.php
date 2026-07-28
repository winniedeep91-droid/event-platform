<?php
/**
 * Notification framework.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reusable notification store for every EventOS module.
 *
 * Transient notifications live for a single admin request, persistent ones are
 * stored in an option until dismissed. Both are rendered as WordPress admin
 * notices and exposed through the REST API for the React admin.
 */
final class Notifications {

	/**
	 * Option storing persistent notifications.
	 */
	public const OPTION = 'eventos_notifications';

	/**
	 * Transient prefix for per user one shot notifications.
	 */
	public const TRANSIENT_PREFIX = 'eventos_notices_';

	/**
	 * Supported notification types.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return array( 'success', 'warning', 'error', 'info' );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
	}

	/**
	 * Add a notification.
	 *
	 * Options: persistent (bool), dismissible (bool), key (string, dedupes),
	 * module (string), capability (string), user_id (int, transient target),
	 * actions (array of array{label,url}).
	 *
	 * @param string               $type    One of success|warning|error|info.
	 * @param string               $title   Short title.
	 * @param string               $message Longer message.
	 * @param array<string, mixed> $options Options.
	 * @return string Notification key.
	 */
	public static function add( string $type, string $title, string $message = '', array $options = array() ): string {
		$type = in_array( $type, self::types(), true ) ? $type : 'info';

		$options = wp_parse_args(
			$options,
			array(
				'persistent'  => false,
				'dismissible' => true,
				'key'         => '',
				'module'      => 'core',
				'capability'  => Capabilities::VIEW_DASHBOARD,
				'user_id'     => 0,
				'actions'     => array(),
			)
		);

		$notification = array(
			'key'         => $options['key'] ? sanitize_key( (string) $options['key'] ) : 'n_' . wp_generate_uuid4(),
			'type'        => $type,
			'title'       => $title,
			'message'     => $message,
			'module'      => sanitize_key( (string) $options['module'] ),
			'capability'  => (string) $options['capability'],
			'dismissible' => (bool) $options['dismissible'],
			'persistent'  => (bool) $options['persistent'],
			'actions'     => array_values( (array) $options['actions'] ),
			'created_at'  => current_time( 'mysql', true ),
			'dismissed'   => array(),
		);

		if ( $notification['persistent'] ) {
			$stored                               = self::stored();
			$stored[ $notification['key'] ]       = $notification;
			$stored[ $notification['key'] ]['id'] = $notification['key'];

			update_option( self::OPTION, $stored, false );
		} else {
			$user_id = (int) $options['user_id'] ?: get_current_user_id();
			$queue   = self::transient_queue( $user_id );
			$queue[] = $notification;

			set_transient( self::TRANSIENT_PREFIX . $user_id, $queue, 5 * MINUTE_IN_SECONDS );
		}

		/**
		 * Fires after a notification was added.
		 *
		 * @param array $notification Notification data.
		 */
		do_action( 'eventos_notification_added', $notification );

		return $notification['key'];
	}

	/**
	 * Shorthand helpers.
	 *
	 * @param string               $title   Title.
	 * @param string               $message Message.
	 * @param array<string, mixed> $options Options.
	 * @return string
	 */
	public static function success( string $title, string $message = '', array $options = array() ): string {
		return self::add( 'success', $title, $message, $options );
	}

	/**
	 * Warning notification.
	 *
	 * @param string               $title   Title.
	 * @param string               $message Message.
	 * @param array<string, mixed> $options Options.
	 * @return string
	 */
	public static function warning( string $title, string $message = '', array $options = array() ): string {
		return self::add( 'warning', $title, $message, $options );
	}

	/**
	 * Error notification.
	 *
	 * @param string               $title   Title.
	 * @param string               $message Message.
	 * @param array<string, mixed> $options Options.
	 * @return string
	 */
	public static function error( string $title, string $message = '', array $options = array() ): string {
		return self::add( 'error', $title, $message, $options );
	}

	/**
	 * Informational notification.
	 *
	 * @param string               $title   Title.
	 * @param string               $message Message.
	 * @param array<string, mixed> $options Options.
	 * @return string
	 */
	public static function info( string $title, string $message = '', array $options = array() ): string {
		return self::add( 'info', $title, $message, $options );
	}

	/**
	 * Notifications visible to a user.
	 *
	 * @param int  $user_id       User ID, 0 for the current user.
	 * @param bool $include_queue Whether to consume transient notifications.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_user( int $user_id = 0, bool $include_queue = true ): array {
		$user_id = $user_id ?: get_current_user_id();
		$visible = array();

		foreach ( self::stored() as $notification ) {
			if ( in_array( $user_id, (array) ( $notification['dismissed'] ?? array() ), true ) ) {
				continue;
			}

			if ( ! empty( $notification['capability'] ) && ! user_can( $user_id, (string) $notification['capability'] ) ) {
				continue;
			}

			$visible[] = $notification;
		}

		if ( $include_queue ) {
			$visible = array_merge( $visible, self::transient_queue( $user_id ) );
		}

		usort(
			$visible,
			static function ( array $a, array $b ): int {
				return strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) );
			}
		);

		return $visible;
	}

	/**
	 * Dismiss a notification for a user.
	 *
	 * @param string $key     Notification key.
	 * @param int    $user_id User ID, 0 for the current user.
	 * @return bool
	 */
	public static function dismiss( string $key, int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();
		$stored  = self::stored();
		$key     = sanitize_key( $key );

		if ( ! isset( $stored[ $key ] ) ) {
			return false;
		}

		$dismissed = (array) ( $stored[ $key ]['dismissed'] ?? array() );

		if ( ! in_array( $user_id, $dismissed, true ) ) {
			$dismissed[] = $user_id;
		}

		$stored[ $key ]['dismissed'] = array_values( $dismissed );

		update_option( self::OPTION, $stored, false );

		return true;
	}

	/**
	 * Remove a persistent notification entirely.
	 *
	 * @param string $key Notification key.
	 * @return bool
	 */
	public static function remove( string $key ): bool {
		$stored = self::stored();
		$key    = sanitize_key( $key );

		if ( ! isset( $stored[ $key ] ) ) {
			return false;
		}

		unset( $stored[ $key ] );
		update_option( self::OPTION, $stored, false );

		return true;
	}

	/**
	 * Delete every persistent notification.
	 *
	 * @return void
	 */
	public static function clear(): void {
		update_option( self::OPTION, array(), false );
	}

	/**
	 * Render notifications as WordPress admin notices.
	 *
	 * @return void
	 */
	public static function render_admin_notices(): void {
		foreach ( self::for_user() as $notification ) {
			printf(
				'<div class="notice notice-%1$s%2$s"><p><strong>%3$s</strong>%4$s</p></div>',
				esc_attr( (string) $notification['type'] ),
				! empty( $notification['dismissible'] ) ? ' is-dismissible' : '',
				esc_html( (string) $notification['title'] ),
				$notification['message'] ? ' ' . esc_html( (string) $notification['message'] ) : ''
			);
		}

		delete_transient( self::TRANSIENT_PREFIX . get_current_user_id() );
	}

	/**
	 * Stored persistent notifications.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Queued transient notifications for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function transient_queue( int $user_id ): array {
		$queue = get_transient( self::TRANSIENT_PREFIX . $user_id );

		return is_array( $queue ) ? $queue : array();
	}
}

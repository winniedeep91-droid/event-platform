<?php
/**
 * Security policy enforcement driven by the security settings group.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

use WP_Error;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies EventOS password, session and login policies to WordPress.
 */
final class Security {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'auth_cookie_expiration', array( __CLASS__, 'filter_cookie_expiration' ), 10, 3 );
		add_action( 'validate_password_reset', array( __CLASS__, 'validate_password_reset' ), 10, 2 );
		add_action( 'user_profile_update_errors', array( __CLASS__, 'validate_profile_password' ), 10, 3 );
		add_action( 'wp_login', array( __CLASS__, 'notify_login' ), 20, 2 );
	}

	/**
	 * Current security settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function policy(): array {
		return Settings::get_group( 'security' );
	}

	/**
	 * Apply the configured session timeout.
	 *
	 * @param int  $expiration Default expiration in seconds.
	 * @param int  $user_id    User ID.
	 * @param bool $remember   Whether the login was persistent.
	 * @return int
	 */
	public static function filter_cookie_expiration( $expiration, $user_id, $remember ): int {
		$minutes = (int) self::policy()['session_timeout_minutes'];

		if ( $minutes < 5 ) {
			return (int) $expiration;
		}

		$timeout = $minutes * MINUTE_IN_SECONDS;

		return $remember ? max( (int) $expiration, $timeout ) : $timeout;
	}

	/**
	 * Validate a password against the configured policy.
	 *
	 * @param string $password Plain text password.
	 * @return string[] List of human readable violations.
	 */
	public static function validate_password( string $password ): array {
		$policy = self::policy();
		$errors = array();

		if ( strlen( $password ) < (int) $policy['password_min_length'] ) {
			$errors[] = sprintf(
				/* translators: %d: minimum password length. */
				__( 'The password must be at least %d characters long.', 'eventos' ),
				(int) $policy['password_min_length']
			);
		}

		if ( $policy['password_require_mixed'] && ( ! preg_match( '/[a-z]/', $password ) || ! preg_match( '/[A-Z]/', $password ) ) ) {
			$errors[] = __( 'The password must contain both upper and lower case letters.', 'eventos' );
		}

		if ( $policy['password_require_number'] && ! preg_match( '/\d/', $password ) ) {
			$errors[] = __( 'The password must contain at least one number.', 'eventos' );
		}

		if ( $policy['password_require_symbol'] && ! preg_match( '/[^A-Za-z0-9]/', $password ) ) {
			$errors[] = __( 'The password must contain at least one symbol.', 'eventos' );
		}

		return $errors;
	}

	/**
	 * Enforce the password policy on reset.
	 *
	 * @param WP_Error         $errors Error collector.
	 * @param WP_User|WP_Error $user   User being updated.
	 * @return void
	 */
	public static function validate_password_reset( $errors, $user ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$password = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : '';

		if ( '' === $password || ! $errors instanceof WP_Error ) {
			return;
		}

		foreach ( self::validate_password( $password ) as $message ) {
			$errors->add( 'eventos_password_policy', $message );
		}
	}

	/**
	 * Enforce the password policy on profile updates.
	 *
	 * @param WP_Error $errors Error collector.
	 * @param bool     $update Whether this is an existing user.
	 * @param object   $user   User data being saved.
	 * @return void
	 */
	public static function validate_profile_password( $errors, $update, $user ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$password = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : '';

		if ( '' === $password || ! $errors instanceof WP_Error ) {
			return;
		}

		foreach ( self::validate_password( $password ) as $message ) {
			$errors->add( 'eventos_password_policy', $message );
		}
	}

	/**
	 * Check an e-mail address against the allowed domain list.
	 *
	 * @param string $email E-mail address.
	 * @return bool
	 */
	public static function is_email_domain_allowed( string $email ): bool {
		$allowed = (array) self::policy()['allowed_email_domains'];

		if ( empty( $allowed ) ) {
			return true;
		}

		$parts  = explode( '@', strtolower( $email ) );
		$domain = end( $parts );

		foreach ( $allowed as $candidate ) {
			if ( strtolower( ltrim( (string) $candidate, '@' ) ) === $domain ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Send a login notification when enabled.
	 *
	 * @param string  $user_login User login name.
	 * @param WP_User $user       Logged in user.
	 * @return void
	 */
	public static function notify_login( string $user_login, $user ): void {
		if ( ! $user instanceof WP_User ) {
			return;
		}

		Activity_Log::record( 'user_login', array( 'login' => $user_login ), 'user', (string) $user->ID );

		if ( ! self::policy()['login_notifications'] ) {
			return;
		}

		if ( ! array_intersect( Capabilities::get_user_roles( (int) $user->ID ), array( 'owner', 'administrator', 'finance' ) ) ) {
			return;
		}

		$business = (string) Settings::get( 'general', 'business_name' );
		$business = $business ? $business : get_bloginfo( 'name' );

		wp_mail(
			$user->user_email,
			sprintf(
				/* translators: %s: business name. */
				__( 'New sign-in to %s', 'eventos' ),
				$business
			),
			sprintf(
				/* translators: 1: business name, 2: date and time. */
				__( 'A sign-in to %1$s was recorded on %2$s (UTC). If this was not you, change your password immediately.', 'eventos' ),
				$business,
				current_time( 'mysql', true )
			)
		);
	}
}

<?php
/**
 * Team invitation workflow.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, sends, accepts and revokes EventOS invitations.
 */
final class Invitations {

	/**
	 * Query var carrying the invitation token.
	 */
	public const TOKEN_QUERY_VAR = 'eventos_invite';

	/**
	 * Invitation lifetime in days.
	 */
	public const LIFETIME_DAYS = 7;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_capture_token' ) );
		add_action( 'user_register', array( __CLASS__, 'apply_pending_invitations' ) );
		add_action( 'wp_login', array( __CLASS__, 'apply_on_login' ), 10, 2 );
	}

	/**
	 * Create an invitation and e-mail the recipient.
	 *
	 * @param string   $email Recipient e-mail address.
	 * @param string[] $roles EventOS role slugs.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create( string $email, array $roles ) {
		global $wpdb;

		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'eventos_invalid_email', __( 'A valid e-mail address is required.', 'eventos' ), array( 'status' => 400 ) );
		}

		if ( ! Security::is_email_domain_allowed( $email ) ) {
			return new WP_Error( 'eventos_domain_blocked', __( 'This e-mail domain is not allowed by the security settings.', 'eventos' ), array( 'status' => 400 ) );
		}

		$roles = array_values( array_intersect( $roles, array_keys( Capabilities::roles() ) ) );

		if ( empty( $roles ) ) {
			return new WP_Error( 'eventos_invalid_roles', __( 'At least one valid EventOS role is required.', 'eventos' ), array( 'status' => 400 ) );
		}

		self::expire_stale();

		$token      = wp_generate_password( 48, false, false );
		$created_at = current_time( 'mysql', true );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( self::LIFETIME_DAYS * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			Installer::invitations_table(),
			array(
				'email'      => $email,
				'roles'      => wp_json_encode( $roles ),
				'token_hash' => self::hash_token( $token ),
				'status'     => 'pending',
				'invited_by' => get_current_user_id(),
				'created_at' => $created_at,
				'expires_at' => $expires_at,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'eventos_invite_failed', __( 'The invitation could not be stored.', 'eventos' ), array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;

		self::send_email( $email, $token, $roles );
		Activity_Log::record( 'invitation_created', array( 'email' => $email, 'roles' => $roles ), 'invitation', (string) $id );

		return self::get( $id );
	}

	/**
	 * List invitations.
	 *
	 * @param string $status Optional status filter.
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( string $status = '' ): array {
		global $wpdb;

		self::expire_stale();

		$table = Installer::invitations_table();

		if ( $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC", sanitize_key( $status ) ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );
		}

		return array_map( array( __CLASS__, 'format_row' ), (array) $rows );
	}

	/**
	 * Fetch one invitation.
	 *
	 * @param int $id Invitation ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		$table = Installer::invitations_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::format_row( $row ) : null;
	}

	/**
	 * Revoke a pending invitation.
	 *
	 * @param int $id Invitation ID.
	 * @return bool
	 */
	public static function revoke( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			Installer::invitations_table(),
			array( 'status' => 'revoked' ),
			array( 'id' => $id, 'status' => 'pending' ),
			array( '%s' ),
			array( '%d', '%s' )
		);

		if ( $updated ) {
			Activity_Log::record( 'invitation_revoked', array(), 'invitation', (string) $id );
		}

		return (bool) $updated;
	}

	/**
	 * Mark expired invitations.
	 *
	 * @return int Number of rows updated.
	 */
	public static function expire_stale(): int {
		global $wpdb;

		$table = Installer::invitations_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'expired' WHERE status = 'pending' AND expires_at < %s",
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Store an invitation token in a cookie so it survives registration or login.
	 *
	 * @return void
	 */
	public static function maybe_capture_token(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET[ self::TOKEN_QUERY_VAR ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET[ self::TOKEN_QUERY_VAR ] ) );

		if ( is_user_logged_in() ) {
			self::accept( $token, get_current_user_id() );

			return;
		}

		setcookie( 'eventos_invite_token', $token, time() + DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
	}

	/**
	 * Accept an invitation for a user.
	 *
	 * @param string $token   Raw token.
	 * @param int    $user_id WordPress user ID.
	 * @return bool
	 */
	public static function accept( string $token, int $user_id ): bool {
		global $wpdb;

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		self::expire_stale();

		$table = Installer::invitations_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE token_hash = %s AND status = 'pending'",
				self::hash_token( $token )
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return false;
		}

		if ( strtolower( (string) $row['email'] ) !== strtolower( $user->user_email ) ) {
			return false;
		}

		$roles    = (array) ( json_decode( (string) $row['roles'], true ) ?: array() );
		$existing = Capabilities::get_user_roles( $user_id );

		Capabilities::set_user_roles( $user_id, array_merge( $existing, $roles ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'      => 'accepted',
				'accepted_by' => $user_id,
				'accepted_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $row['id'] ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		Activity_Log::record( 'invitation_accepted', array( 'roles' => $roles ), 'invitation', (string) $row['id'] );

		return true;
	}

	/**
	 * Apply an invitation captured before registration.
	 *
	 * @param int $user_id Newly registered user ID.
	 * @return void
	 */
	public static function apply_pending_invitations( int $user_id ): void {
		$token = isset( $_COOKIE['eventos_invite_token'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['eventos_invite_token'] ) )
			: '';

		if ( $token && self::accept( $token, $user_id ) ) {
			self::clear_cookie();
		}
	}

	/**
	 * Apply an invitation captured before login.
	 *
	 * @param string   $user_login User login name.
	 * @param \WP_User $user       Logged in user.
	 * @return void
	 */
	public static function apply_on_login( string $user_login, $user ): void {
		$token = isset( $_COOKIE['eventos_invite_token'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['eventos_invite_token'] ) )
			: '';

		if ( $token && $user instanceof \WP_User && self::accept( $token, (int) $user->ID ) ) {
			self::clear_cookie();
		}
	}

	/**
	 * Remove the invitation cookie.
	 *
	 * @return void
	 */
	private static function clear_cookie(): void {
		setcookie( 'eventos_invite_token', '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
	}

	/**
	 * Send the invitation e-mail.
	 *
	 * @param string   $email Recipient.
	 * @param string   $token Raw token.
	 * @param string[] $roles Assigned roles.
	 * @return void
	 */
	private static function send_email( string $email, string $token, array $roles ): void {
		$business = (string) Settings::get( 'general', 'business_name' );
		$business = $business ? $business : get_bloginfo( 'name' );
		$link     = add_query_arg( self::TOKEN_QUERY_VAR, rawurlencode( $token ), wp_login_url() );
		$labels   = array();

		foreach ( Capabilities::roles() as $slug => $definition ) {
			if ( in_array( $slug, $roles, true ) ) {
				$labels[] = $definition['label'];
			}
		}

		$subject = sprintf(
			/* translators: %s: business name. */
			__( 'You have been invited to %s', 'eventos' ),
			$business
		);

		$message = sprintf(
			/* translators: 1: business name, 2: role list, 3: invitation URL. */
			__( "You have been invited to join %1\$s on EventOS as: %2\$s.\n\nAccept the invitation by signing in here:\n%3\$s\n\nThis invitation expires in 7 days.", 'eventos' ),
			$business,
			implode( ', ', $labels ),
			$link
		);

		wp_mail( $email, $subject, $message );
	}

	/**
	 * Hash a raw token for storage.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private static function hash_token( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Normalise a row for API output.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function format_row( array $row ): array {
		$inviter = get_userdata( (int) $row['invited_by'] );

		return array(
			'id'          => (int) $row['id'],
			'email'       => (string) $row['email'],
			'roles'       => (array) ( json_decode( (string) $row['roles'], true ) ?: array() ),
			'status'      => (string) $row['status'],
			'created_at'  => (string) $row['created_at'],
			'expires_at'  => (string) $row['expires_at'],
			'accepted_at' => $row['accepted_at'] ? (string) $row['accepted_at'] : null,
			'invited_by'  => array(
				'id'   => (int) $row['invited_by'],
				'name' => $inviter ? $inviter->display_name : '',
			),
		);
	}
}

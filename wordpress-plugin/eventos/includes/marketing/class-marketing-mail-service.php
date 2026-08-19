<?php
/**
 * wp_mail()-backed delivery for Marketing campaign messages.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deliberately a thin wrapper around `wp_mail()` — see the Sprint 3 brief:
 * reuse WordPress's own mail abstraction rather than introducing SMTP
 * infrastructure or a hard-coded provider, exactly like
 * {@see \EventOS\Invitations::send_email()} already does for invitations.
 * Whatever SMTP plugin/transport the site has configured keeps working
 * unchanged; this class only shapes the message itself (HTML body with a
 * true plain-text alternative part, From/Reply-To headers).
 */
final class Marketing_Mail_Service {

	/**
	 * Send one personalized campaign e-mail.
	 *
	 * @param string $to           Recipient e-mail address.
	 * @param string $subject      Subject line.
	 * @param string $html_body    Rendered HTML body.
	 * @param string $text_body    Rendered plain-text alternative.
	 * @param string $sender_name  From display name.
	 * @param string $sender_email From e-mail address.
	 * @param string $reply_to     Reply-To e-mail address, optional.
	 * @return true|string True on success, an error string on failure.
	 */
	public function send(
		string $to,
		string $subject,
		string $html_body,
		string $text_body,
		string $sender_name,
		string $sender_email,
		string $reply_to = ''
	) {
		if ( ! is_email( $to ) ) {
			return 'invalid_recipient';
		}

		$headers   = array( 'Content-Type: text/html; charset=UTF-8' );
		$headers[] = sprintf( 'From: %s <%s>', $this->encode_header( $sender_name ), $sender_email );

		if ( '' !== $reply_to && is_email( $reply_to ) ) {
			$headers[] = sprintf( 'Reply-To: %s', $reply_to );
		}

		$attach_alt_body = static function ( \PHPMailer\PHPMailer\PHPMailer $mailer ) use ( $text_body ): void {
			if ( '' !== trim( $text_body ) ) {
				$mailer->AltBody = $text_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		};

		add_action( 'phpmailer_init', $attach_alt_body );

		$sent = wp_mail( $to, $subject, $html_body, $headers );

		remove_action( 'phpmailer_init', $attach_alt_body );

		if ( ! $sent ) {
			return 'wp_mail_returned_false';
		}

		return true;
	}

	/**
	 * MIME-safe encoding for a display name that may contain non-ASCII
	 * characters or the `<`/`>`/`,` delimiters wp_mail's From header parsing
	 * relies on.
	 *
	 * @param string $name Display name.
	 * @return string
	 */
	private function encode_header( string $name ): string {
		$name = str_replace( array( '<', '>', ',', '"' ), '', $name );

		return '' !== $name ? $name : get_bloginfo( 'name' );
	}
}

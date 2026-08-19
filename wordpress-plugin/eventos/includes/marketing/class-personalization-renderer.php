<?php
/**
 * Safe {{token}} personalization for campaign messages.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every token this class knows how to fill comes from data
 * {@see \EventOS\Crm\Person_Repository} or the campaign row already expose —
 * it never invents a field. A token with no value for a given Person
 * degrades to a sensible fallback rather than leaving `{{token}}` literally
 * in the sent e-mail or producing a broken sentence ("Hi ,"), and every
 * substituted value is HTML-escaped since it is injected into an HTML body.
 */
final class Personalization_Renderer {

	/**
	 * Replace every `{{token}}` in a template with escaped, person-specific
	 * values. Unknown tokens are left untouched rather than silently
	 * deleted, so a typo in an operator's template is visible instead of
	 * quietly producing missing text.
	 *
	 * @param string                $template Raw HTML or plain-text template.
	 * @param array<string, mixed>  $context  Values from {@see build_context()}.
	 * @param bool                  $escape_html Whether to HTML-escape substituted values (true for HTML bodies, false for plain-text).
	 * @return string
	 */
	public function render( string $template, array $context, bool $escape_html = true ): string {
		return (string) preg_replace_callback(
			'/\{\{\s*([a-z_]+)\s*\}\}/i',
			static function ( array $matches ) use ( $context, $escape_html ): string {
				$key = strtolower( (string) $matches[1] );

				if ( ! array_key_exists( $key, $context ) ) {
					return $matches[0];
				}

				$value = (string) $context[ $key ];

				return $escape_html ? esc_html( $value ) : $value;
			},
			$template
		);
	}

	/**
	 * Available token names, for UI hints ("insert a field") — kept here so
	 * the UI's list can never drift out of sync with what render() actually
	 * understands.
	 *
	 * @return string[]
	 */
	public static function known_tokens(): array {
		return array(
			'first_name',
			'last_name',
			'full_name',
			'email',
			'event_name',
			'discount_code',
			'total_spend',
			'last_purchase_date',
			'ticket_type',
			'ticket_quantity',
		);
	}

	/**
	 * Build the token => value context for one recipient.
	 *
	 * `$event` and `$ticket_summary` are optional because not every campaign
	 * is event-scoped (a global audience's campaign has no single event to
	 * name) and not every Person holds a ticket for that event (e.g. a
	 * `lapsed_customers` audience member from a different event) — both
	 * degrade to an empty string rather than a fatal lookup.
	 *
	 * @param array<string, mixed>      $person         A hydrated Person row (see Person_Repository::find_by_id()).
	 * @param array<string, mixed>|null $campaign       A hydrated campaign row.
	 * @param array<string, mixed>|null $event          Event row {id, title}, or null.
	 * @param array<string, mixed>|null $ticket_summary  {ticket_type, quantity}, or null.
	 * @return array<string, string>
	 */
	public static function build_context( array $person, ?array $campaign, ?array $event, ?array $ticket_summary ): array {
		$first_name = trim( (string) ( $person['first_name'] ?? '' ) );
		$last_name  = trim( (string) ( $person['last_name'] ?? '' ) );
		$full_name  = trim( (string) ( $person['display_name'] ?? '' ) );

		$total_spend = isset( $person['total_spend'] ) ? (float) $person['total_spend'] : 0.0;
		$last_purchase = $person['last_purchase_at'] ?? null;

		return array(
			'first_name'          => '' !== $first_name ? $first_name : __( 'there', 'eventos' ),
			'last_name'           => $last_name,
			'full_name'           => '' !== $full_name ? $full_name : __( 'there', 'eventos' ),
			'email'               => (string) ( $person['primary_email'] ?? '' ),
			'event_name'          => null !== $event ? (string) ( $event['title'] ?? '' ) : '',
			'discount_code'       => null !== $campaign ? (string) ( $campaign['code'] ?? '' ) : '',
			'total_spend'         => number_format_i18n( $total_spend, 2 ),
			'last_purchase_date'  => $last_purchase ? date_i18n( get_option( 'date_format' ), strtotime( (string) $last_purchase ) ) : __( 'n/a', 'eventos' ),
			'ticket_type'         => null !== $ticket_summary ? (string) ( $ticket_summary['ticket_type'] ?? '' ) : '',
			'ticket_quantity'     => null !== $ticket_summary ? (string) ( (int) ( $ticket_summary['quantity'] ?? 0 ) ) : '',
		);
	}
}

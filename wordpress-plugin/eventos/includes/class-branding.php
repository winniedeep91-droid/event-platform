<?php
/**
 * Branding accessors shared by every EventOS module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves branding settings into usable URLs and CSS variables.
 */
final class Branding {

	/**
	 * Logo slots exposed to modules.
	 *
	 * @return string[]
	 */
	public static function logo_slots(): array {
		return array( 'business', 'dashboard', 'login', 'email_header', 'pdf', 'favicon' );
	}

	/**
	 * Attachment ID for a logo slot.
	 *
	 * @param string $slot Slot name.
	 * @return int
	 */
	public static function logo_id( string $slot ): int {
		$key = 'favicon' === $slot ? 'favicon_id' : $slot . '_logo_id';

		return (int) Settings::get( 'branding', $key );
	}

	/**
	 * Logo URL for a slot, falling back to the business logo.
	 *
	 * @param string $slot Slot name.
	 * @param string $size Image size.
	 * @return string Empty string when nothing is configured.
	 */
	public static function logo_url( string $slot, string $size = 'full' ): string {
		$id = self::logo_id( $slot );

		if ( ! $id && 'favicon' !== $slot ) {
			$id = self::logo_id( 'business' );
		}

		if ( ! $id ) {
			return '';
		}

		$src = wp_get_attachment_image_src( $id, $size );

		return is_array( $src ) ? (string) $src[0] : '';
	}

	/**
	 * Brand colours keyed by role.
	 *
	 * @return array<string, string>
	 */
	public static function colors(): array {
		$branding = Settings::get_group( 'branding' );

		return array(
			'primary'   => (string) $branding['primary_color'],
			'secondary' => (string) $branding['secondary_color'],
			'accent'    => (string) $branding['accent_color'],
		);
	}

	/**
	 * Branding payload for REST responses and the admin UI.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload(): array {
		$logos = array();

		foreach ( self::logo_slots() as $slot ) {
			$logos[ $slot ] = array(
				'id'  => self::logo_id( $slot ),
				'url' => self::logo_url( $slot ),
			);
		}

		return array(
			'colors' => self::colors(),
			'logos'  => $logos,
		);
	}

	/**
	 * Inline CSS custom properties for the EventOS admin screens.
	 *
	 * The three saved brand colours are the source palette for the light
	 * EventOS shell. Keep the derived surface/border/text values here as well
	 * as the brand variables so the runtime branding layer overrides the
	 * compiled fallback tokens everywhere, not only on buttons and active
	 * navigation. Dark mode retains its dedicated `.eos-dark` token set.
	 *
	 * @return string
	 */
	public static function css_variables(): string {
		$colors = self::colors();

		return sprintf(
			'.eos{--eventos-primary:%1$s;--eventos-secondary:%2$s;--eventos-accent:%3$s;--eos-bg:#f5f5f2;--eos-surface:#ffffff;--eos-surface-elevated:#ffffff;--eos-surface-muted:#e8f7fc;--eos-border:#c6e8f2;--eos-border-strong:#91d1e2;--eos-fg:#242629;--eos-fg-muted:#5f6b70;--eos-fg-inverse:#ffffff;--eos-primary:%1$s;--eos-primary-hover:#d4142e;--eos-primary-active:#8f0010;--eos-primary-fg:#ffffff;--eos-secondary:%2$s;--eos-secondary-fg:#242629;--eos-accent:%3$s;--eos-info:#006a87;--eos-info-soft:#e3f7fc;--eos-shadow-sm:0 1px 2px rgba(36,38,41,.08);--eos-shadow:0 8px 24px rgba(36,38,41,.10);--eos-shadow-lg:0 24px 60px rgba(36,38,41,.18);}',
			esc_attr( $colors['primary'] ),
			esc_attr( $colors['secondary'] ),
			esc_attr( $colors['accent'] )
		);
	}
}
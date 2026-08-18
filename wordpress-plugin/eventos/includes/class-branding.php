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
	 * Beyond the three named brand colours, this also derives the light
	 * theme's supporting surfaces/borders as tints of the secondary colour
	 * (mixed toward white — a "tint" in the literal colour-theory sense),
	 * so a promoter's custom secondary/accent colour drives the whole light
	 * palette — table headers, hover fills, input backgrounds, borders —
	 * not just the three directly-named tokens. See ui.css's
	 * --eos-surface-muted/--eos-border/--eos-border-strong, which fall back
	 * to the equivalent tints of the default Sky Blue when unset.
	 *
	 * Deliberately does not emit a full `.eos{...}` snapshot of the palette
	 * (structural surfaces, shadows, foreground colours, etc.) — the
	 * compiled stylesheet is the single source of truth for those, and a
	 * second hardcoded copy here would drift out of sync with it exactly as
	 * happened when an earlier version of this method hardcoded a
	 * pre-role-swap palette (including a stale --eos-bg) and silently
	 * overrode the current design at runtime. Only the three named brand
	 * colours plus their directly-derived tints are runtime-brandable; every
	 * other token stays whatever ui.css defines.
	 *
	 * @return string
	 */
	public static function css_variables(): string {
		$colors      = self::colors();
		$tint_source = '' !== $colors['secondary'] ? $colors['secondary'] : $colors['accent'];

		return sprintf(
			':root{--eventos-primary:%1$s;--eventos-secondary:%2$s;--eventos-accent:%3$s;--eventos-surface-muted:%4$s;--eventos-border:%5$s;--eventos-border-strong:%6$s;}',
			esc_attr( $colors['primary'] ),
			esc_attr( $colors['secondary'] ),
			esc_attr( $colors['accent'] ),
			esc_attr( self::tint( $tint_source, 0.06 ) ),
			esc_attr( self::tint( $tint_source, 0.16 ) ),
			esc_attr( self::tint( $tint_source, 0.30 ) )
		);
	}

	/**
	 * Blends a #rrggbb colour toward white by the given weight — a tint.
	 * Falls back to white for anything that isn't a well-formed hex colour
	 * (e.g. an empty/unset setting) rather than emitting invalid CSS.
	 *
	 * @param string $hex    Source colour, #rrggbb.
	 * @param float  $weight How much of the source colour to keep, 0-1.
	 * @return string
	 */
	private static function tint( string $hex, float $weight ): string {
		$hex = ltrim( trim( $hex ), '#' );

		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return '#ffffff';
		}

		$channel = static function ( int $value ) use ( $weight ): int {
			return (int) round( 255 - ( 255 - $value ) * $weight );
		};

		return sprintf(
			'#%02x%02x%02x',
			$channel( hexdec( substr( $hex, 0, 2 ) ) ),
			$channel( hexdec( substr( $hex, 2, 2 ) ) ),
			$channel( hexdec( substr( $hex, 4, 2 ) ) )
		);
	}
}
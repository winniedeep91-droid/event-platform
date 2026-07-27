<?php
/**
 * Optional WooCommerce integration.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aligns EventOS configuration with WooCommerce when the shop plugin is active.
 */
final class WooCommerce {

	/**
	 * Register hooks when WooCommerce is available.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! self::is_active() ) {
			return;
		}

		add_filter( 'eventos_default_currency', array( __CLASS__, 'currency' ) );
		add_action( 'eventos_settings_updated', array( __CLASS__, 'sync_from_settings' ), 10, 2 );
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * WooCommerce store currency, or the EventOS default.
	 *
	 * @param string $fallback Fallback currency code.
	 * @return string
	 */
	public static function currency( string $fallback = 'USD' ): string {
		if ( self::is_active() && function_exists( 'get_woocommerce_currency' ) ) {
			return (string) get_woocommerce_currency();
		}

		return $fallback;
	}

	/**
	 * Keep the EventOS currency symbol aligned with WooCommerce.
	 *
	 * @param string               $group  Saved settings group.
	 * @param array<string, mixed> $values Saved values.
	 * @return void
	 */
	public static function sync_from_settings( string $group, array $values ): void {
		if ( 'general' !== $group || ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
			return;
		}

		$regional = Settings::get_group( 'regional' );

		if ( '' === trim( (string) $regional['currency_symbol'] ) ) {
			Settings::update_group(
				'regional',
				array( 'currency_symbol' => get_woocommerce_currency_symbol( (string) $values['currency'] ) )
			);
		}
	}

	/**
	 * Status payload for the dashboard.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		return array(
			'active'   => self::is_active(),
			'version'  => ( self::is_active() && defined( 'WC_VERSION' ) ) ? WC_VERSION : '',
			'currency' => self::is_active() ? self::currency() : '',
		);
	}
}

<?php
/**
 * WooCommerce connection status and diagnostics.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Woocommerce;

use EventOS\Platform\Diagnostics;
use EventOS\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connection health used by the WooCommerce diagnostics screen, and a
 * contribution to the shared {@see Diagnostics} report so the same checks
 * surface on the main EventOS diagnostics screen too.
 */
final class Wc_Diagnostics {

	/**
	 * Option holding the last time the connection was checked.
	 */
	public const LAST_CHECKED_OPTION = 'eventos_wc_last_checked';

	/**
	 * Register the module's contribution to the shared diagnostics report.
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		add_filter( 'eventos_diagnostics_checks', array( __CLASS__, 'checks' ) );
	}

	/**
	 * Current WooCommerce connection status.
	 *
	 * @return array<string, mixed>
	 */
	public static function connection_status(): array {
		$active       = WooCommerce::is_active();
		$last_checked = (string) get_option( self::LAST_CHECKED_OPTION, '' );

		if ( '' === $last_checked ) {
			$last_checked = current_time( 'mysql', true );
			update_option( self::LAST_CHECKED_OPTION, $last_checked );
		}

		return array(
			'connected'           => $active,
			'woocommerce_version' => $active && defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'store_currency'      => $active ? WooCommerce::currency() : '',
			'store_url'           => home_url(),
			'api_accessible'      => $active && function_exists( 'wc_get_product' ),
			'webhooks_registered' => Wc_Webhooks::enabled(),
			'last_checked'        => $last_checked,
		);
	}

	/**
	 * Re-check the connection and refresh the timestamp.
	 *
	 * @return array<string, mixed>
	 */
	public static function recheck(): array {
		update_option( self::LAST_CHECKED_OPTION, current_time( 'mysql', true ) );

		return self::connection_status();
	}

	/**
	 * Contribute WooCommerce checks to the shared diagnostics report.
	 *
	 * @param array<int, array<string, mixed>> $checks Existing checks.
	 * @return array<int, array<string, mixed>>
	 */
	public static function checks( array $checks ): array {
		$status = self::connection_status();

		$checks[] = array(
			'id'          => 'woocommerce_active',
			'label'       => __( 'WooCommerce', 'eventos' ),
			'category'    => 'configuration',
			'status'      => $status['connected'] ? Diagnostics::STATUS_PASS : Diagnostics::STATUS_WARN,
			'value'       => $status['connected']
				? ( (string) $status['woocommerce_version'] ?: __( 'Active', 'eventos' ) )
				: __( 'Not installed', 'eventos' ),
			'description' => __( 'WooCommerce provides ticket checkout and payment processing.', 'eventos' ),
		);

		$checks[] = array(
			'id'          => 'woocommerce_webhooks',
			'label'       => __( 'WooCommerce order events', 'eventos' ),
			'category'    => 'configuration',
			'status'      => ! $status['connected'] || $status['webhooks_registered'] ? Diagnostics::STATUS_PASS : Diagnostics::STATUS_WARN,
			'value'       => $status['webhooks_registered'] ? __( 'Registered', 'eventos' ) : __( 'Not registered', 'eventos' ),
			'description' => __( 'EventOS listens for WooCommerce order events to keep mappings current.', 'eventos' ),
			'hint'        => $status['webhooks_registered'] ? '' : __( 'Register webhooks from the WooCommerce Webhooks screen.', 'eventos' ),
		);

		return $checks;
	}
}

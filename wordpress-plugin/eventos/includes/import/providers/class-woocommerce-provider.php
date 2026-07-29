<?php
/**
 * WooCommerce import provider scaffold.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Import\Providers;

use EventOS\Import\Abstract_Import_Provider;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads legacy ticket data out of a WooCommerce store on the same site.
 *
 * The connector is enabled by the module that owns the target entities; until
 * then the provider reports itself as registered but not connected.
 */
final class WooCommerce_Provider extends Abstract_Import_Provider {

	/**
	 * Provider slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'woocommerce';
	}

	/**
	 * Provider label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'WooCommerce', 'eventos' );
	}

	/**
	 * Provider description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Migrate products, orders and customers from an existing WooCommerce store.', 'eventos' );
	}

	/**
	 * Readiness check.
	 *
	 * @return true|WP_Error
	 */
	public function readiness() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $this->unavailable( __( 'Activate WooCommerce on this site to enable the connector.', 'eventos' ) );
		}

		return $this->unavailable( __( 'Install an EventOS module that registers WooCommerce import targets.', 'eventos' ) );
	}

	/**
	 * Read rows from the store.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @param int                  $offset Row offset.
	 * @param int                  $limit  Maximum rows.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	protected function read_rows( array $source, int $offset, int $limit ) {
		unset( $source, $offset, $limit );

		return $this->readiness();
	}
}

<?php
/**
 * FIXR import provider scaffold.
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
 * Reads events, tickets and attendees from FIXR.
 */
final class Fixr_Provider extends Abstract_Import_Provider {

	/**
	 * Provider slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'fixr';
	}

	/**
	 * Provider label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'FIXR', 'eventos' );
	}

	/**
	 * Provider description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Migrate events, ticket types and attendees from a FIXR organiser account.', 'eventos' );
	}

	/**
	 * Readiness check.
	 *
	 * @return true|WP_Error
	 */
	public function readiness() {
		return $this->unavailable( __( 'Add FIXR API credentials in the import settings to enable the connector.', 'eventos' ) );
	}

	/**
	 * Read rows from the API.
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

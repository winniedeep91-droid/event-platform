<?php
/**
 * Shared REST controller behaviour.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use EventOS\Capabilities;
use WP_REST_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base controller providing namespace and permission helpers.
 */
abstract class Abstract_Controller extends WP_REST_Controller {

	/**
	 * REST namespace shared by every EventOS route.
	 *
	 * @var string
	 */
	protected $namespace = 'eventos/v1';

	/**
	 * Permission callback for read access to the EventOS admin area.
	 *
	 * @return bool
	 */
	public function can_view(): bool {
		return current_user_can( Capabilities::VIEW_DASHBOARD );
	}

	/**
	 * Permission callback for configuration changes.
	 *
	 * @return bool
	 */
	public function can_manage_settings(): bool {
		return current_user_can( Capabilities::MANAGE_SETTINGS );
	}

	/**
	 * Permission callback for team management.
	 *
	 * @return bool
	 */
	public function can_manage_team(): bool {
		return current_user_can( Capabilities::MANAGE_TEAM );
	}
}

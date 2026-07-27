<?php
/**
 * Core Configuration module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Modules;

use EventOS\Capabilities;
use EventOS\Cron;
use EventOS\Invitations;
use EventOS\Module_Interface;
use EventOS\Rest\Dashboard_Controller;
use EventOS\Rest\Invitations_Controller;
use EventOS\Rest\Settings_Controller;
use EventOS\Rest\Team_Controller;
use EventOS\Security;
use EventOS\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstraps configuration, roles, invitations and the REST surface.
 */
final class Core_Module implements Module_Interface {

	/**
	 * Module slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'core';
	}

	/**
	 * Module name.
	 *
	 * @return string
	 */
	public function name(): string {
		return __( 'Core Configuration', 'eventos' );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		Capabilities::init();
		Security::init();
		Invitations::init();
		Cron::init();
		WooCommerce::init();

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register the module's REST controllers.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		foreach (
			array(
				new Settings_Controller(),
				new Team_Controller(),
				new Invitations_Controller(),
				new Dashboard_Controller(),
			) as $controller
		) {
			$controller->register_routes();
		}
	}
}

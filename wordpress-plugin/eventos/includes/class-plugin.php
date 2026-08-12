<?php
/**
 * Plugin bootstrap and module container.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

use EventOS\Admin\Admin_Assets;
use EventOS\Admin\Admin_Menu;
use EventOS\Modules\Core_Module;
use EventOS\Modules\Events_Module;
use EventOS\Modules\Platform_Module;
use EventOS\Modules\Woocommerce_Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Root plugin object. Owns the module registry and the WordPress bootstrap.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Module registry.
	 *
	 * @var Module_Registry
	 */
	private Module_Registry $modules;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->modules = new Module_Registry();
	}

	/**
	 * Retrieve the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Module registry accessor.
	 *
	 * @return Module_Registry
	 */
	public function modules(): Module_Registry {
		return $this->modules;
	}

	/**
	 * Register hooks and boot every module.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'register_modules' ), 20 );
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 1 );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'eventos', false, dirname( EVENTOS_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Register and initialise modules.
	 *
	 * Future feature modules (Events, Ticketing, CRM, ...) register here through
	 * the `eventos_register_modules` filter without touching the core bootstrap.
	 *
	 * @return void
	 */
	public function register_modules(): void {
		$this->modules->add( new Core_Module() );
		$this->modules->add( new Platform_Module() );
		$this->modules->add( new Events_Module() );
		$this->modules->add( new Woocommerce_Module() );

		/**
		 * Filter the list of EventOS modules before they are initialised.
		 *
		 * @param Module_Registry $registry Module registry.
		 */
		do_action( 'eventos_register_modules', $this->modules );

		$this->modules->init();

		( new Admin_Menu() )->init();
		( new Admin_Assets() )->init();
	}
}

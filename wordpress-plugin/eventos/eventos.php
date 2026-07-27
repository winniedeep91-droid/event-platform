<?php
/**
 * Plugin Name:       EventOS
 * Plugin URI:        https://github.com/winniedeep91-droid/event-platform
 * Description:       Modular event management platform for WordPress. Core Configuration module: settings, branding, regional, security, team roles and invitations.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            EventOS
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       eventos
 * Domain Path:       /languages
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EVENTOS_VERSION', '1.0.0' );
define( 'EVENTOS_DB_VERSION', '1.0.0' );
define( 'EVENTOS_PLUGIN_FILE', __FILE__ );
define( 'EVENTOS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EVENTOS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EVENTOS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once EVENTOS_PLUGIN_DIR . 'includes/class-autoloader.php';

Autoloader::register();

register_activation_hook( __FILE__, array( Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Installer::class, 'deactivate' ) );

/**
 * Main plugin instance accessor.
 *
 * @return Plugin
 */
function eventos(): Plugin {
	return Plugin::instance();
}

eventos()->boot();

<?php
/**
 * Admin asset loading for the React powered EventOS screens.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Admin;

use EventOS\Branding;
use EventOS\Capabilities;
use EventOS\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the compiled admin application and its runtime configuration.
 */
final class Admin_Assets {

	/**
	 * Script handle.
	 */
	public const HANDLE = 'eventos-admin';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue assets on EventOS screens only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		unset( $hook_suffix );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( ! array_key_exists( $slug, Admin_Menu::pages() ) ) {
			return;
		}

		$script = EVENTOS_PLUGIN_DIR . 'assets/admin/eventos-admin.js';
		$style  = EVENTOS_PLUGIN_DIR . 'assets/admin/eventos-admin.css';

		wp_enqueue_media();

		if ( file_exists( $script ) ) {
			wp_enqueue_script(
				self::HANDLE,
				EVENTOS_PLUGIN_URL . 'assets/admin/eventos-admin.js',
				array( 'wp-i18n' ),
				(string) filemtime( $script ),
				true
			);

			wp_add_inline_script(
				self::HANDLE,
				'window.eventosAdmin = ' . wp_json_encode( $this->config() ) . ';',
				'before'
			);

			wp_set_script_translations( self::HANDLE, 'eventos', EVENTOS_PLUGIN_DIR . 'languages' );
		}

		if ( file_exists( $style ) ) {
			wp_enqueue_style(
				self::HANDLE,
				EVENTOS_PLUGIN_URL . 'assets/admin/eventos-admin.css',
				array(),
				(string) filemtime( $style )
			);

			wp_add_inline_style( self::HANDLE, Branding::css_variables() );
		}
	}

	/**
	 * Runtime configuration handed to the React application.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		$user = wp_get_current_user();

		return array(
			'restUrl'      => esc_url_raw( rest_url( 'eventos/v1/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'adminUrl'     => esc_url_raw( admin_url( 'admin.php' ) ),
			'pluginUrl'    => esc_url_raw( EVENTOS_PLUGIN_URL ),
			'version'      => EVENTOS_VERSION,
			'locale'       => get_user_locale(),
			'branding'     => Branding::payload(),
			'general'      => Settings::get_group( 'general' ),
			'menu'         => $this->menu_links(),
			'capabilities' => array(
				'view_dashboard'  => current_user_can( Capabilities::VIEW_DASHBOARD ),
				'manage_settings' => current_user_can( Capabilities::MANAGE_SETTINGS ),
				'manage_team'     => current_user_can( Capabilities::MANAGE_TEAM ),
				// Registered by core (see Permissions::bootstrap()), not by
				// the Finance module — referenced by the plain capability
				// string here rather than EventOS\Finance\Finance_Capabilities
				// so this file never depends on an optional module.
				'view_finance'    => current_user_can( 'eventos_view_finance' ),
				'manage_finance'  => current_user_can( 'eventos_manage_finance' ),
			),
			'currentUser'  => array(
				'id'     => (int) $user->ID,
				'name'   => $user->display_name,
				'email'  => $user->user_email,
				'avatar' => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
				'roles'  => Capabilities::get_user_roles( (int) $user->ID ),
			),
		);
	}

	/**
	 * Links to every EventOS admin screen the user may access.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function menu_links(): array {
		$links = array();

		foreach ( Admin_Menu::pages() as $slug => $page ) {
			if ( ! current_user_can( $page['capability'] ) ) {
				continue;
			}

			$links[] = array(
				'slug'  => $slug,
				'view'  => $page['view'],
				'title' => $page['title'],
				'url'   => esc_url_raw( admin_url( 'admin.php?page=' . $slug ) ),
			);
		}

		return $links;
	}
}

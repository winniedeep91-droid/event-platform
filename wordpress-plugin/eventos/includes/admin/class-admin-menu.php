<?php
/**
 * WordPress admin menu registration.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Admin;

use EventOS\Capabilities;
use EventOS\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the EventOS admin menu and renders the React mount points.
 */
final class Admin_Menu {

	/**
	 * Top level menu slug.
	 */
	public const ROOT_SLUG = 'eventos';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Admin pages keyed by menu slug.
	 *
	 * @return array<string, array{title: string, view: string, capability: string}>
	 */
	public static function pages(): array {
		$pages = array(
			self::ROOT_SLUG                    => array(
				'title'      => __( 'Dashboard', 'eventos' ),
				'view'       => 'dashboard',
				'capability' => Capabilities::VIEW_DASHBOARD,
			),
			self::ROOT_SLUG . '-general'       => array(
				'title'      => __( 'General', 'eventos' ),
				'view'       => 'settings/general',
				'capability' => Capabilities::MANAGE_SETTINGS,
			),
			self::ROOT_SLUG . '-branding'      => array(
				'title'      => __( 'Branding', 'eventos' ),
				'view'       => 'settings/branding',
				'capability' => Capabilities::MANAGE_SETTINGS,
			),
			self::ROOT_SLUG . '-regional'      => array(
				'title'      => __( 'Regional', 'eventos' ),
				'view'       => 'settings/regional',
				'capability' => Capabilities::MANAGE_SETTINGS,
			),
			self::ROOT_SLUG . '-security'      => array(
				'title'      => __( 'Security', 'eventos' ),
				'view'       => 'settings/security',
				'capability' => Capabilities::MANAGE_SETTINGS,
			),
			self::ROOT_SLUG . '-team'          => array(
				'title'      => __( 'Team', 'eventos' ),
				'view'       => 'settings/team',
				'capability' => Capabilities::MANAGE_TEAM,
			),
		);

		/**
		 * Filter the EventOS admin pages so modules can add their own screens.
		 *
		 * @param array $pages Admin pages keyed by menu slug.
		 */
		return (array) apply_filters( 'eventos_admin_pages', $pages );
	}

	/**
	 * Register the top level menu and its submenus.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$pages = self::pages();
		$root  = $pages[ self::ROOT_SLUG ];

		add_menu_page(
			$this->brand_label(),
			$this->brand_label(),
			$root['capability'],
			self::ROOT_SLUG,
			array( $this, 'render' ),
			'dashicons-tickets-alt',
			26
		);

		foreach ( $pages as $slug => $page ) {
			add_submenu_page(
				self::ROOT_SLUG,
				sprintf( '%1$s — %2$s', $this->brand_label(), $page['title'] ),
				$page['title'],
				$page['capability'],
				$slug,
				array( $this, 'render' )
			);
		}
	}

	/**
	 * Menu label based on the configured business name.
	 *
	 * @return string
	 */
	private function brand_label(): string {
		$name = (string) Settings::get( 'general', 'business_name' );

		return 'EventOS' . ( $name ? '' : '' );
	}

	/**
	 * Render the React mount point for the current page.
	 *
	 * @return void
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slug  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::ROOT_SLUG;
		$pages = self::pages();
		$page  = $pages[ $slug ] ?? $pages[ self::ROOT_SLUG ];

		if ( ! current_user_can( $page['capability'] ) ) {
			wp_die( esc_html__( 'You are not allowed to access this EventOS screen.', 'eventos' ), 403 );
		}

		printf(
			'<div class="wrap eventos-admin"><div id="eventos-admin-root" data-view="%1$s"><p class="eventos-admin__loading">%2$s</p></div></div>',
			esc_attr( $page['view'] ),
			esc_html__( 'Loading EventOS…', 'eventos' )
		);
	}
}
